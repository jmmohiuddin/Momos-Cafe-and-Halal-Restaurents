<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

use Momos\Kernel\Db\Tables;

/**
 * DEF-006 / QA-077: the Branch fixture reset must remove reservations that still have
 * notification and event rows (FK ON DELETE RESTRICT) without a database error.
 */
final class BranchFixtureCleanupTest extends BranchTestCaseTest {
	public function test_reset_deletes_reservations_with_notifications_and_events_without_fk_error(): void {
		global $wpdb;
		$branch = $this->make_branch( 'QFK' );
		$res    = $this->make_reservation( $branch, '2026-12-01', '19:00' );
		$wpdb->insert( Tables::notifications(), [ 'uuid' => wp_generate_uuid4(), 'dedupe_key' => 'qa-fk-' . $res, 'template_key' => 'E01', 'lang' => 'en', 'recipient_type' => 'customer', 'recipient_hash' => str_repeat( 'b', 64 ), 'recipient_masked' => 'g***@example.org', 'created_at' => '2026-11-01 00:00:00', 'updated_at' => '2026-11-01 00:00:00', 'reservation_id' => $res ] );
		$wpdb->insert( Tables::events(), [ 'reservation_id' => $res, 'event_type' => 'created', 'actor_type' => 'system', 'created_at' => '2026-11-01 00:00:00' ] );
		$this->assertSame( '', $wpdb->last_error, 'fixture rows inserted' );

		$suppress = $wpdb->suppress_errors( true );
		$this->reset_branch_tables();
		$wpdb->suppress_errors( $suppress );

		$this->assertSame( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::reservations() ) ); // phpcs:ignore
		$this->assertSame( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::notifications() ) ); // phpcs:ignore
	}
}
