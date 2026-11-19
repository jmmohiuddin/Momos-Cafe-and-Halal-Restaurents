<?php
declare(strict_types=1);

namespace Momos\Api\Http;

use Momos\Kernel\Http\RequestContext;
use Momos\Kernel\I18n\Locale;
use Momos\Reservation\Domain\ReservationError;

/**
 * Error envelope (spec §5, DEC-063):
 * { code, message, data: { status, request_id, message_key, fields?, branch_phone?, current?, … } }
 * Codes and statuses come only from the canonical catalogue (ReservationError::STATUS). Messages are
 * localised in the request language on public routes and in the user's admin locale on staff routes.
 */
final class ErrorResponder {
	/** Customer message key per code (public routes). */
	private const PUBLIC_KEYS = [
		'invalid_json'            => 'res.err.system',
		'payload_too_large'       => 'res.err.system',
		'unsupported_media_type'  => 'res.err.system',
		'internal_error'          => 'res.err.system',
		'service_unavailable'     => 'res.err.system',
		'captcha_failed'          => 'res.err.captcha',
		'origin_rejected'         => 'res.err.captcha',
		'branch_not_found'        => 'res.err.branch.not_found',
		'not_found'               => 'status.link_invalid',
		'link_expired'            => 'status.link_invalid',
		'slot_unavailable'        => 'res.err.time.unavailable',
		'pending_limit_reached'   => 'res.err.limit.active',
		'cancel_not_allowed'      => 'status.cancel.not_allowed',
		'invalid_transition'      => 'status.cancel.not_allowed',
		'branch_not_accepting'    => 'res.err.branch.unavailable',
		'party_size_out_of_range' => 'res.err.party.max_call',
		'lead_time_not_met'       => 'res.err.time.too_soon',
		'outside_booking_window'  => 'res.err.date.window',
		'rate_limited'            => 'res.err.rate',
	];

	/** Staff message key per code (staff routes). */
	private const STAFF_KEYS = [
		'reason_required'         => 'staff.err.reason_required',
		'forbidden_branch'        => 'staff.err.forbidden_branch',
		'forbidden_field'         => 'staff.err.forbidden_field',
		'invalid_transition'      => 'staff.err.invalid_transition',
		'version_conflict'        => 'staff.err.version_conflict',
		'attendance_window'       => 'staff.err.attendance_window',
		'not_found'               => 'staff.err.not_found',
		'rest_forbidden'          => 'staff.err.rest_forbidden',
		'invalid_param'           => 'staff.err.invalid_param',
		'slot_unavailable'        => 'res.err.time.unavailable',
		'branch_not_accepting'    => 'staff.err.branch_not_accepting',
		'party_size_out_of_range' => 'staff.err.party_size',
		'lead_time_not_met'       => 'staff.err.lead_time',
		'outside_booking_window'  => 'staff.err.booking_window',
		'rate_limited'            => 'staff.err.rate',
	];

	/** Field → message key prefix for invalid_param on the public form (res.err.{field}.*). */
	private const FIELD_KEYS = [
		'branch_code'     => [ 'required' => 'res.err.branch.required', '*' => 'res.err.branch.not_found' ],
		'date'            => [ 'required' => 'res.err.date.required', '*' => 'res.err.date.required' ],
		'time'            => [ 'required' => 'res.err.time.required', '*' => 'res.err.time.unavailable' ],
		'party_size'      => [ 'required' => 'res.err.party.required', '*' => 'res.err.party.required' ],
		'name'            => [ 'required' => 'res.err.name.required', 'too_long' => 'res.err.name.length', '*' => 'res.err.name.required' ],
		'email'           => [ 'required' => 'res.err.email.required', '*' => 'res.err.email.format' ],
		'phone'           => [ '*' => 'res.err.phone.format' ],
		'special_request' => [ '*' => 'res.err.note.length' ],
		'consent'         => [ '*' => 'res.err.consent.required' ],
	];

	/**
	 * @param array<string,string|int|float> $context placeholder values (branch, phone)
	 */
	public static function response( ReservationError $e, string $lang, bool $staff, array $context = [] ): \WP_REST_Response {
		$code   = $e->error_code;
		$status = $e->status();
		$key    = self::message_key( $e, $staff );
		$args   = array_merge( $context, (array) ( $e->data['args'] ?? [] ) );
		if ( isset( $e->data['branch_phone']['display'] ) && ! isset( $args['phone'] ) ) {
			$args['phone'] = (string) $e->data['branch_phone']['display'];
		}
		if ( 'invalid_param' === $code ) {
			$args['n'] = max( 1, count( $e->fields ) );
		}
		if ( 'invalid_transition' === $code && $staff ) {
			$args['status'] = self::status_label( (string) ( $e->data['current']['status'] ?? '' ) );
		}
		$args['request_id'] = RequestContext::id();
		$message            = $staff ? Locale::with( Locale::admin(), static fn() => momos_t( $key, $args ) ) : momos_t( $key, $args, $lang );
		$message            = (string) preg_replace( '/\{[a-z_]+\}/', '', $message );

		$data = [
			'status'      => $status,
			'request_id'  => RequestContext::id(),
			'message_key' => $key,
		];
		if ( $e->fields ) {
			$data['fields'] = $e->fields;
		}
		foreach ( [ 'branch_phone', 'current', 'retry_after', 'reason', 'reopen_date' ] as $k ) {
			if ( array_key_exists( $k, $e->data ) && null !== $e->data[ $k ] ) {
				$data[ $k ] = $e->data[ $k ];
			}
		}
		if ( isset( $e->data['args'] ) && in_array( $code, [ 'outside_booking_window', 'pending_limit_reached', 'party_size_out_of_range' ], true ) ) {
			$data['args'] = $e->data['args'];
		}
		$response = new \WP_REST_Response( [ 'code' => $code, 'message' => $message, 'data' => $data ], $status );
		if ( 'rate_limited' === $code ) {
			$response->header( 'Retry-After', (string) max( 1, (int) ( $e->data['retry_after'] ?? 60 ) ) );
		}
		return $response;
	}

	/**
	 * Same envelope as a WP_Error (for permission callbacks).
	 *
	 * @param array<string,string|int|float> $context
	 */
	public static function wp_error( ReservationError $e, string $lang, bool $staff, array $context = [] ): \WP_Error {
		$r    = self::response( $e, $lang, $staff, $context );
		$body = (array) $r->get_data();
		return new \WP_Error( (string) $body['code'], (string) $body['message'], $body['data'] );
	}

	public static function message_key( ReservationError $e, bool $staff ): string {
		if ( ! empty( $e->data['message_key'] ) ) {
			$k = (string) $e->data['message_key'];
			if ( ! $staff || str_starts_with( $k, 'staff.' ) ) {
				return $k;
			}
		}
		$code = $e->error_code;
		if ( $staff ) {
			return self::STAFF_KEYS[ $code ] ?? 'res.err.system';
		}
		if ( 'invalid_param' === $code ) {
			$fields = $e->fields;
			if ( 1 === count( $fields ) ) {
				$field  = (string) array_key_first( $fields );
				$reason = (string) $fields[ $field ];
				$map    = self::FIELD_KEYS[ $field ] ?? null;
				if ( null !== $map ) {
					return $map[ $reason ] ?? $map['*'] ?? 'res.err.summary';
				}
			}
			return 'res.err.summary';
		}
		return self::PUBLIC_KEYS[ $code ] ?? 'res.err.system';
	}

	private static function status_label( string $status ): string {
		$s = \Momos\Reservation\Domain\ReservationStatus::tryFrom( $status );
		return $s ? $s->staffLabel() : $status;
	}
}
