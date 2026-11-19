<?php
declare(strict_types=1);

namespace Momos\Reservation\Domain;

/**
 * A domain failure that maps 1:1 to the canonical error catalogue (Doc 04 §16.0.4, DEC-063, spec §5.1).
 * The REST layer (Api\Http\ErrorResponder) turns it into the error envelope; admin screens show the
 * staff message. `$fields` maps field => reason (required|invalid_format|too_long|out_of_range|not_allowed|…).
 *
 * @phpstan-type Fields array<string,string>
 */
final class ReservationError extends \RuntimeException {
	/** Code → HTTP status (the only codes the module returns). */
	public const STATUS = [
		'invalid_param'           => 400,
		'invalid_json'            => 400,
		'reason_required'         => 400,
		'rest_forbidden'          => 403,
		'forbidden_branch'        => 403,
		'forbidden_field'         => 403,
		'captcha_failed'          => 403,
		'origin_rejected'         => 403,
		'branch_not_found'        => 404,
		'not_found'               => 404,
		'slot_unavailable'        => 409,
		'pending_limit_reached'   => 409,
		'cancel_not_allowed'      => 409,
		'invalid_transition'      => 409,
		'version_conflict'        => 409,
		'link_expired'            => 410,
		'payload_too_large'       => 413,
		'unsupported_media_type'  => 415,
		'branch_not_accepting'    => 422,
		'party_size_out_of_range' => 422,
		'lead_time_not_met'       => 422,
		'outside_booking_window'  => 422,
		'attendance_window'       => 422,
		'rate_limited'            => 429,
		'internal_error'          => 500,
		'service_unavailable'     => 503,
	];

	/**
	 * @param array<string,string> $fields
	 * @param array<string,mixed>  $data   extra envelope data (current, branch_phone, retry_after, args…)
	 */
	public function __construct(
		public readonly string $error_code,
		public readonly array $fields = [],
		public readonly array $data = []
	) {
		parent::__construct( $error_code );
	}

	public function status(): int {
		return self::STATUS[ $this->error_code ] ?? 500;
	}

	/** @param array<string,string> $fields */
	public static function invalid( array $fields ): self {
		return new self( 'invalid_param', $fields );
	}
}
