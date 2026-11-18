<?php
declare(strict_types=1);

namespace Momos\Notification\Queue;

/**
 * What to queue (spec §3.5). No body data is carried: the renderer reloads data by ID at send time.
 *
 * Audiences: `customer`, `branch_recipients`, `head_office`, `head_office_digest` (HO ∪ digest list),
 * `ho_and_dca` (E11), `explicit` (recipients given by the caller).
 */
final class NotificationRequest {
	public const AUDIENCES = [ 'customer', 'branch_recipients', 'head_office', 'head_office_digest', 'ho_and_dca', 'explicit' ];

	/**
	 * @param list<array{type:string,email:string,lang:string,user_id:?int}> $explicit_recipients
	 * @param list<int>                                                      $extra_user_ids e.g. `handled_by` for E08 sla_reminder
	 */
	public function __construct(
		public readonly string $template_key,
		public readonly string $audience,
		public readonly string $dedupe_base,
		public readonly ?string $variant = null,
		public readonly ?int $reservation_id = null,
		public readonly ?int $branch_id = null,
		public readonly ?string $object_type = null,
		public readonly ?int $object_id = null,
		public readonly array $explicit_recipients = [],
		public readonly array $extra_user_ids = [],
	) {
		if ( ! in_array( $audience, self::AUDIENCES, true ) ) {
			throw new \InvalidArgumentException( 'Unknown audience ' . $audience );
		}
	}
}
