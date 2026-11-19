<?php
declare(strict_types=1);

namespace Momos\Api;

use Momos\Api\Http\ErrorResponder;
use Momos\Api\Http\Guards;
use Momos\Api\RateLimit\RateLimiter;
use Momos\Kernel\Http\RequestContext;
use Momos\Kernel\I18n\Lang;
use Momos\Kernel\I18n\Locale;
use Momos\Kernel\Log\Logger;
use Momos\Kernel\ModuleInterface;
use Momos\Reservation\Domain\ReservationError;

/**
 * momos/v1 REST API (TRD §4.3, SDD §2 Api): route registration, transport guards
 * (JsonBodyGuard, OriginGuard, TokenHeaderGuard) in SDD §5.2 order, error normalisation to the
 * canonical catalogue (DEC-063), response headers (X-Request-Id, no-store/noindex, rate limits),
 * one log line per error response and per staff mutation (no bodies, no PII), core REST hardening.
 */
final class ApiModule implements ModuleInterface {
	public const NS = 'momos/v1';

	private static float $started = 0.0;

	public function register(): void {
		add_action( 'rest_api_init', [ self::class, 'routes' ] );
		add_filter( 'rest_pre_dispatch', [ self::class, 'pre_dispatch' ], 10, 3 );
		add_filter( 'rest_post_dispatch', [ self::class, 'post_dispatch' ], 20, 3 );
		add_filter( 'rest_pre_serve_request', [ self::class, 'strip_cors' ], 100, 4 );
		CoreRestGuard::register();
	}

	public static function routes(): void {
		Controller\RulesController::routes();
		Controller\PublicReservationController::routes();
		Controller\StaffReservationController::routes();
	}

	private static function is_ours( \WP_REST_Request $request ): bool {
		return str_starts_with( $request->get_route(), '/' . self::NS . '/' );
	}

	/**
	 * Checks 1–2 of SDD §5.2 and the DEC-058 token-parameter guard, before argument parsing.
	 *
	 * @param mixed $result
	 * @return mixed
	 */
	public static function pre_dispatch( $result, \WP_REST_Server $server, \WP_REST_Request $request ) {
		self::$started     = microtime( true );
		RateLimiter::$last = null;
		if ( null !== $result || ! self::is_ours( $request ) ) {
			return $result;
		}
		$route  = $request->get_route();
		$method = strtoupper( $request->get_method() );
		$public = (bool) preg_match( '#^/momos/v1/reservations(/|$)#', $route );
		$staff  = str_starts_with( $route, '/momos/v1/staff/reservations' );
		if ( ! $public && ! $staff ) {
			return $result;
		}
		$lang = (string) ( $request->get_query_params()['lang'] ?? '' );
		if ( $public ) {
			$json = json_decode( (string) $request->get_body(), true );
			if ( is_array( $json ) && is_string( $json['lang'] ?? null ) ) {
				$lang = $json['lang'];
			}
		}
		$lang = Lang::is_valid( $lang ) ? $lang : Lang::SOURCE;
		try {
			if ( $public && preg_match( '#^/momos/v1/reservations/[^/]+#', $route ) ) {
				Guards::no_token_param( $request );
			}
			if ( in_array( $method, [ 'POST', 'PATCH', 'PUT' ], true ) ) {
				Guards::json_body( $request, $public ? Guards::PUBLIC_BODY_LIMIT : Guards::STAFF_BODY_LIMIT );
				if ( $public ) {
					Guards::origin( $request );
				}
			}
		} catch ( ReservationError $e ) {
			return $public ? ErrorResponder::response( $e, $lang, false ) : ErrorResponder::response( $e, Locale::admin(), true );
		}
		return $result;
	}

	/**
	 * momos/v1 is same-origin only (TC-SEC-027, SEC-022, FR-SEC-006): core `rest_send_cors_headers`
	 * (priority 10) reflects any Origin with credentials; remove those headers again for our routes,
	 * including OPTIONS preflights. Other namespaces keep core behaviour.
	 *
	 * @param mixed $served
	 * @param mixed $result
	 * @return mixed
	 */
	public static function strip_cors( $served, $result, \WP_REST_Request $request, \WP_REST_Server $server ) {
		if ( self::is_ours( $request ) && ! headers_sent() ) {
			foreach ( [ 'Access-Control-Allow-Origin', 'Access-Control-Allow-Credentials', 'Access-Control-Allow-Methods', 'Access-Control-Allow-Headers', 'Access-Control-Expose-Headers' ] as $h ) {
				header_remove( $h );
			}
		}
		return $served;
	}

	/**
	 * Normalise core error codes, add headers, log.
	 *
	 * @param \WP_HTTP_Response $response
	 */
	public static function post_dispatch( $response, \WP_REST_Server $server, \WP_REST_Request $request ) {
		if ( ! self::is_ours( $request ) || ! $response instanceof \WP_HTTP_Response ) {
			return $response;
		}
		$route  = $request->get_route();
		$staff  = str_starts_with( $route, '/momos/v1/staff/' );
		$status = $response->get_status();
		$data   = $response->get_data();

		// ErrorNormaliser: core codes → catalogue codes.
		if ( $status >= 400 && is_array( $data ) && isset( $data['code'] ) ) {
			$code   = (string) $data['code'];
			$new    = null;
			$fields = [];
			if ( in_array( $code, [ 'rest_invalid_param', 'rest_missing_callback_param' ], true ) ) {
				$new = 'invalid_param';
				foreach ( (array) ( $data['data']['params'] ?? [] ) as $k => $v ) {
					$fields[ is_int( $k ) ? (string) $v : (string) $k ] = 'rest_missing_callback_param' === $code ? 'required' : 'invalid_format';
				}
			} elseif ( 'rest_invalid_json' === $code ) {
				$new = 'invalid_json';
			} elseif ( in_array( $code, [ 'rest_not_logged_in', 'rest_cookie_invalid_nonce' ], true ) ) {
				$new = 'rest_forbidden';
			}
			if ( null !== $new ) {
				$err      = new ReservationError( $new, $fields );
				$lang     = Lang::is_valid( (string) $request->get_param( 'lang' ) ) ? (string) $request->get_param( 'lang' ) : Lang::SOURCE;
				$response = $staff ? ErrorResponder::response( $err, Locale::admin(), true ) : ErrorResponder::response( $err, $lang, false );
				if ( 'rest_not_logged_in' === $code ) {
					$response->set_status( 401 );
				}
				$data   = $response->get_data();
				$status = $response->get_status();
			}
			// A valid auth cookie sent without a nonce → 403 (core treated the request as anonymous).
			if ( 'rest_forbidden' === ( $data['code'] ?? '' ) && 401 === $status && defined( 'LOGGED_IN_COOKIE' ) && ! empty( $_COOKIE[ LOGGED_IN_COOKIE ] ) ) { // phpcs:ignore
				$response->set_status( 403 );
				$data['data']['status'] = 403;
				$response->set_data( $data );
				$status = 403;
			}
			if ( is_array( $data['data'] ?? null ) && ! isset( $data['data']['request_id'] ) ) {
				$data['data']['request_id'] = RequestContext::id();
				$response->set_data( $data );
			}
			if ( isset( $data['data']['retry_after'] ) ) {
				$response->header( 'Retry-After', (string) (int) $data['data']['retry_after'] );
			}
		}

		$response->header( 'X-Request-Id', RequestContext::id() );
		if ( ! str_contains( $route, '/reservation-rules' ) || $status >= 400 ) {
			$response->header( 'Cache-Control', 'no-store, private' );
			$response->header( 'X-Robots-Tag', 'noindex' );
		}
		if ( null !== RateLimiter::$last ) {
			$response->header( 'X-RateLimit-Limit', (string) RateLimiter::$last['limit'] );
			$response->header( 'X-RateLimit-Remaining', (string) RateLimiter::$last['remaining'] );
		}

		$method = strtoupper( $request->get_method() );
		if ( $status >= 400 || ( $staff && 'GET' !== $method ) ) {
			Logger::info(
				'api.request',
				[
					'route'       => preg_replace( '#/[0-9A-Z]{8,9}(/|$)#', '/{ref}$1', $route ),
					'method'      => $method,
					'status'      => $status,
					'ms'          => self::$started > 0 ? (int) round( ( microtime( true ) - self::$started ) * 1000 ) : null,
					'user_id'     => get_current_user_id() ?: null,
					'branch_code' => is_string( $request->get_param( 'branch_code' ) ) ? $request->get_param( 'branch_code' ) : null,
					'code'        => is_array( $data ) ? ( $data['code'] ?? null ) : null,
				]
			);
		}
		return $response;
	}
}
