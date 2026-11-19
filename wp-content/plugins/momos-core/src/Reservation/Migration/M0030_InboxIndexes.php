<?php
declare(strict_types=1);

namespace Momos\Reservation\Migration;

use Momos\Kernel\Db\Tables;
use Momos\Kernel\Migration\MigrationInterface;

/**
 * M0030: index for the all-branch inbox views sorted by start time (SCR-A08 / API-10 with
 * view=all, unclaimed, has-special-request; TC-PERF-005, NFR-PERF-04). InnoDB appends the primary
 * key, so `(requested_start_utc)` serves `ORDER BY requested_start_utc, id … LIMIT` without a
 * filesort; `idx_created` already serves the created-at sort. Idempotent.
 */
final class M0030_InboxIndexes implements MigrationInterface {
	public function id(): string {
		return 'M0030_InboxIndexes';
	}

	public function up(): void {
		global $wpdb;
		$t      = Tables::reservations();
		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$t} WHERE Key_name = %s", 'idx_start' ) ); // phpcs:ignore
		if ( null === $exists ) {
			$wpdb->query( "ALTER TABLE {$t} ADD KEY idx_start (requested_start_utc)" ); // phpcs:ignore
		}
	}
}
