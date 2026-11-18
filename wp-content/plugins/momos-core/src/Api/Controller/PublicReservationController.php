<?php
declare(strict_types=1);

namespace Momos\Api\Controller;

use Momos\Api\ApiModule;
use Momos\Api\Captcha\TurnstileVerifier;
use Momos\Api\Http\ErrorResponder;
use Momos\Api\Http\Guards;
use Momos\Api\Http\SchemaValidator;
use Momos\Api\RateLimit\RateLimiter;
use Momos\Branch\Repository\BranchRepository;
use Momos\Kernel\Http\RequestContext;
use Momos\Kernel\I18n\Lang;
use Momos\Kernel\I18n\Locale;
use Momos\Kernel\Log\Logger;
use Momos\Reservation\Domain\CaptchaStatus;
use Momos\Reservation\Domain\ReservationError;
use Momos\Reservation\Service\ExpiryService;
use Momos\Reservation\Service\Presenter;
use Momos\Reservation\Service\SubmissionService;
use Momos\Reservation\Service\TokenService;
use Momos\Reservation\Service\TransitionService;
use Momos\Reservation\Support\Options;
use Momos\Reservation\Validation\Normalizer;

/**
 * Public reservation routes (ROLE-00):
 *   API-02 POST /momos/v1/reservations                       submission (FR-RES-001..007, SDD §5.2)
 *   API-03 GET  /momos/v1/reservations/{public_ref}           status (F-RES-03, X-Momo-Token header)
 *   API-04 POST /momos/v1/reservations/{public_ref}/cancel    customer cancel (FR-RES-020)
 * Transport/origin/token-parameter guards run earlier in ApiModule::pre_dispatch (checks 1–2).
 */
final class PublicReservationController {
	public const REF = '(?P<ref>[0-9A-Za-z-]{8,9})';

	/** API-02 request schema (additionalProperties:false). */
	public static function submit_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'branch_code', 'date', 'time', 'party_size', 'name', 'email', 'lang', 'preferred_lang', 'consent', 'idempotency_key', 'captcha_token' ],
			'properties'           => [
				'branch_code'             => [ 'type' => 'string', 'pattern' => '^[A-Z]{3}$' ],
				'date'                    => [ 'type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$' ],
				'time'                    => [ 'type' => 'string', 'pattern' => '^([01]\d|2[0-3]):[0-5]\d$' ],
				'party_size'              => [ 'type' => [ 'integer', 'string' ], 'x-digits' => true, 'minimum' => 1, 'maximum' => 50 ],
				'name'                    => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 100 ],
				'email'                   => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 254 ],
				'phone'                   => [ 'type' => [ 'string', 'null' ], 'maxLength' => 32 ],
				'special_request'         => [ 'type' => [ 'string', 'null' ], 'maxLength' => 1000 ],
				'lang'                    => [ 'type' => 'string', 'enum' => Lang::codes() ],
				'preferred_lang'          => [ 'type' => 'string', 'enum' => Lang::codes() ],
				'consent'                 => [ 'type' => 'boolean' ],
				'idempotency_key'         => [ 'type' => 'string', 'pattern' => '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-4[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$' ],
				'captcha_token'           => [ 'type' => 'string', 'maxLength' => 2048 ],
				'website'                 => [ 'type' => [ 'string', 'null' ], 'maxLength' => 200 ],
				'special_request_consent' => [ 'type' => 'boolean' ],
			],
		];
	}

	public static function routes(): void {
		register_rest_route(
			ApiModule::NS,
			'/reservations',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'submit' ],
				'permission_callback' => '__return_true', // Public; origin, rate limits, honeypot and captcha are enforced in order inside.
			]
		);
		register_rest_route(
			ApiModule::NS,
			'/reservations/' . self::REF,
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'status' ],
				'permission_callback' => '__return_true', // Public; possession of the X-Momo-Token is the authorisation.
			]
		);
		register_rest_route(
			ApiModule::NS,
			'/reservations/' . self::REF . '/cancel',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'cancel' ],
				'permission_callback' => '__return_true', // Public; origin + X-Momo-Token checked inside.
			]
		);
	}

	// ------------------------------------------------------------------ API-02

	public static function submit( \WP_REST_Request $request ): \WP_REST_Response {
		$body = (array) $request->get_json_params();
		$lang = Lang::is_valid( $body['lang'] ?? null ) ? (string) $body['lang'] : Lang::SOURCE;
		Locale::set( $lang );
		$ctx  = self::branch_context( (string) ( $body['branch_code'] ?? '' ), $lang );
		$ip   = RequestContext::ip() ?? 'unknown';
		try {
			// 3. Per-IP limit (tighter while a captcha outage was seen in the last 10 min).
			$rl = RateLimiter::hit( TurnstileVerifier::outage_active() ? 'res_ip_degraded' : 'res_ip', $ip );
			if ( ! $rl['allowed'] ) {
				throw new ReservationError( 'rate_limited', [], [ 'retry_after' => $rl['retry_after'] ] );
			}
			// 4. Honeypot.
			if ( isset( $body['website'] ) && '' !== trim( (string) $body['website'] ) ) {
				Logger::info( 'reservation.honeypot', [ 'route' => 'API-02' ] );
				throw new ReservationError( 'captcha_failed' );
			}
			// 5. Schema.
			$errors = SchemaValidator::validate( $body, self::submit_schema() );
			if ( isset( $body['special_request_consent'] ) && ! Options::bool( 'momo_special_request_separate_consent' ) ) {
				$errors['special_request_consent'] = 'not_allowed';
			}
			if ( $errors ) {
				throw ReservationError::invalid( $errors );
			}
			// 6. Idempotent replay.
			$existing = SubmissionService::replay( (string) $body['idempotency_key'] );
			if ( null !== $existing ) {
				$b = BranchRepository::get( (int) $existing['branch_id'] );
				if ( Normalizer::email_key( (string) $existing['email'] ) !== Normalizer::email_key( (string) $body['email'] ) || ! $b || $b['code'] !== $body['branch_code'] ) {
					throw ReservationError::invalid( [ 'idempotency_key' => 'not_allowed' ] );
				}
				return new \WP_REST_Response( Presenter::submission( $existing, $lang, false ), 200 );
			}
			// 7. Captcha (TEST-branch submissions by assigned staff: not_applicable).
			$user_id = get_current_user_id();
			$captcha = CaptchaStatus::Verified->value;
			$test    = SubmissionService::resolve_branch( (string) $body['branch_code'], $user_id );
			if ( null !== $test && $test['is_test'] && $user_id ) {
				$captcha = CaptchaStatus::NotApplicable->value;
			} else {
				$result = TurnstileVerifier::verify( (string) $body['captcha_token'] );
				if ( TurnstileVerifier::FAILED === $result ) {
					throw new ReservationError( 'captcha_failed' );
				}
				if ( TurnstileVerifier::OUTAGE === $result ) {
					$captcha = CaptchaStatus::UnverifiedOutage->value;
					momos_audit( 'security.captcha_degraded', 'reservation', null, null, [ 'route' => 'API-02' ], 'system' );
					self::count_degraded();
				}
			}
			// 8. Per-email limit.
			$rl = RateLimiter::hit( 'res_email', Normalizer::email_key( (string) $body['email'] ) );
			if ( ! $rl['allowed'] ) {
				throw new ReservationError( 'rate_limited', [], [ 'retry_after' => $rl['retry_after'] ] );
			}
			// 9–20.
			$result = SubmissionService::submit(
				$body,
				[
					'captcha_status' => $captcha,
					'ip'             => RequestContext::ip(),
					'user_agent'     => RequestContext::user_agent(),
					'user_id'        => $user_id,
				]
			);
			return new \WP_REST_Response( Presenter::submission( $result['row'], $lang, $result['duplicate'] ), $result['http'] );
		} catch ( ReservationError $e ) {
			return ErrorResponder::response( $e, $lang, false, $ctx );
		} catch ( \Throwable $e ) {
			Logger::error( 'reservation.submit_exception', [ 'error' => $e->getMessage() ] );
			return ErrorResponder::response( new ReservationError( 'internal_error' ), $lang, false, $ctx );
		}
	}

	/** Alert when degraded submissions exceed the hourly threshold. */
	private static function count_degraded(): void {
		$key = 'momos_captcha_degraded_' . gmdate( 'YmdH' );
		$n   = (int) get_transient( $key ) + 1;
		set_transient( $key, $n, HOUR_IN_SECONDS );
		if ( $n === Options::int( 'momo_degraded_alert_per_hour' ) + 1 ) {
			Logger::critical( 'captcha.degraded_submissions', [ 'count' => $n ] );
		}
	}

	// ------------------------------------------------------------------ API-03 / API-04

	public static function status( \WP_REST_Request $request ): \WP_REST_Response {
		$lang = self::query_lang( $request );
		try {
			$token = Guards::header_token( $request );
			self::limit( 'status_ip' );
			$row = TokenService::verify( (string) $request['ref'], $token );
			ExpiryService::expire_if_due( (int) $row['id'] );
			$row = (array) \Momos\Reservation\Repository\ReservationRepository::find( (int) $row['id'] );
			return new \WP_REST_Response( Presenter::customer_status( $row, $lang ), 200 );
		} catch ( ReservationError $e ) {
			return ErrorResponder::response( $e, $lang, false, self::row_context( (string) $request['ref'], $lang ) );
		}
	}

	public static function cancel( \WP_REST_Request $request ): \WP_REST_Response {
		$body = (array) $request->get_json_params();
		$lang = Lang::is_valid( $body['lang'] ?? null ) ? (string) $body['lang'] : self::query_lang( $request );
		Locale::set( $lang );
		try {
			$token = Guards::header_token( $request );
			self::limit( 'cancel_ip' );
			$errors = SchemaValidator::validate( $body, [ 'type' => 'object', 'additionalProperties' => false, 'properties' => [ 'lang' => [ 'type' => 'string', 'enum' => Lang::codes() ] ] ] );
			if ( $errors ) {
				throw ReservationError::invalid( $errors );
			}
			$row = TokenService::verify( (string) $request['ref'], $token );
			$row = TransitionService::customer_cancel( $row );
			return new \WP_REST_Response( Presenter::customer_status( $row, $lang ), 200 );
		} catch ( ReservationError $e ) {
			return ErrorResponder::response( $e, $lang, false, self::row_context( (string) $request['ref'], $lang ) );
		}
	}

	/** @throws ReservationError */
	private static function limit( string $bucket ): void {
		$rl = RateLimiter::hit( $bucket, RequestContext::ip() ?? 'unknown' );
		if ( ! $rl['allowed'] ) {
			throw new ReservationError( 'rate_limited', [], [ 'retry_after' => $rl['retry_after'] ] );
		}
	}

	private static function query_lang( \WP_REST_Request $request ): string {
		$lang = (string) ( $request->get_query_params()['lang'] ?? '' );
		$lang = Lang::is_valid( $lang ) ? $lang : Lang::SOURCE;
		Locale::set( $lang );
		return $lang;
	}

	/** Placeholder values {branch} {phone} for customer messages. @return array<string,string> */
	private static function branch_context( string $code, string $lang ): array {
		$b = preg_match( '/^[A-Z]{3}$/', $code ) ? BranchRepository::find_by_code( $code, false ) : null;
		if ( ! $b ) {
			return [ 'branch' => '', 'phone' => '' ];
		}
		return [ 'branch' => Presenter::branch_name( (int) $b['id'], $lang ), 'phone' => (string) $b['phone_display'] ];
	}

	/** Phone of the reservation's branch for cancel/status messages (only the public branch phone). @return array<string,string> */
	private static function row_context( string $ref, string $lang ): array {
		$row = \Momos\Reservation\Repository\ReservationRepository::find_by_ref( $ref );
		if ( ! $row ) {
			return [ 'branch' => '', 'phone' => '' ];
		}
		$b = BranchRepository::get( (int) $row['branch_id'] );
		return [ 'branch' => Presenter::branch_name( (int) $row['branch_id'], $lang ), 'phone' => $b ? (string) $b['phone_display'] : '' ];
	}
}
