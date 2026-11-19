<?php
declare(strict_types=1);

namespace Momos\Reservation\Migration;

use Momos\Kernel\Db\Tables;
use Momos\Kernel\Migration\MigrationInterface;

/**
 * M0031: `(handled_by, requested_start_utc)` for the "unclaimed" and "mine" inbox views (SCR-A08 /
 * API-10 `flags=unclaimed`, `handled_by=none|me`; TC-PERF-005, NFR-PERF-04). Both filter on
 * handled_by and sort by start time: this index answers them with a range read already in sort order
 * (InnoDB appends the primary key for the `id` tie-break), so no full scan + filesort even when the
 * all-status view drops the status predicate. The other views are served by idx_status_start,
 * idx_branch_status_start, idx_start (M0030) and idx_created. Idempotent.
 */
final class M0031_HandledByIndex implements MigrationInterface {
	public function id(): string {
		return 'M0031_HandledByIndex';
	}

	public function up(): void {
		global $wpdb;
		$t      = Tables::reservations();
		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$t} WHERE Key_name = %s", 'idx_handled_start' ) ); // phpcs:ignore
		if ( null === $exists ) {
			$wpdb->query( "ALTER TABLE {$t} ADD KEY idx_handled_start (handled_by, requested_start_utc)" ); // phpcs:ignore
		}
	}
}
