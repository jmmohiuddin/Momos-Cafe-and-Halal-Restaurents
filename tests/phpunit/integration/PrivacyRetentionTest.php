<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/PrivacyTestCaseTest.php';

use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Db\Tables;
use Momos\Kernel\Settings;
use Momos\Privacy\Retention\Anonymizer;
use Momos\Privacy\Retention\RetentionJob;

/**
 * Retention and anonymisation job (FR-PRV-003, TC-PRV-004/005, AC-PRV-02-01…03, AC-NOT-03-04).
 * Clock frozen at 2026-11-19 10:00 JST; PII retention 12 months → cutoff requested_date < 2025-11-19.
 */
final class PrivacyRetentionTest extends PrivacyTestCaseTest {
	public function test_boundary_days_and_future_rows(): void {
		$b      = $this->make_branch( 'QPA' );
		$minus1 = $this->rich_reservation( $b, '2025-11-18' ); // retention + 1 day → purged
		$zero   = $this->rich_reservation( $b, '2025-11-19' ); // exactly 12 months → kept
		$plus1  = $this->rich_reservation( $b, '2025-11-20' ); // retention − 1 day → kept
		$future = $this->rich_reservation( $b, '2026-12-01', '19:00', [ 'status' => 'confirmed' ] );

		$out = RetentionJob::run();

		$this->assertSame( 1, $out['counts']['reservations_anonymized'] );
		$this->assertSame( 0, $out['failed'] );
		$this->assertNotNull( $this->row( $minus1 )['pii_purged_at'] );
		foreach ( [ $zero, $plus1, $future ] as $id ) {
			$this->assertNull( $this->row( $id )['pii_purged_at'] );
			$this->assertSame( 'Aisha Rahman', $this->row( $id )['guest_name'] );
		}
	}

	public function test_anonymised_row_fields_events_and_notifications(): void {
		$b  = $this->make_branch( 'QPA' );
		$id = $this->rich_reservation( $b, '2025-10-01' );
		global $wpdb;
		$status_counts_before = $wpdb->get_results( 'SELECT branch_id, status, requested_date, party_size, COUNT(*) n FROM ' . Tables::reservations() . ' GROUP BY branch_id, status, requested_date, party_size', ARRAY_A ); // phpcs:ignore
		$hooks                = [];
		add_action(
			'momos/reservation/anonymized',
			static function ( $rid ) use ( &$hooks ): void {
				$hooks[] = $rid;
			}
		);

		RetentionJob::run();

		$row = $this->row( $id );
		foreach ( Anonymizer::PII_COLUMNS as $col ) {
			$this->assertNull( $row[ $col ], $col );
		}
		$this->assertSame( '2026-11-19 01:00:00', $row['pii_purged_at'] );
		// Kept for statistics (AC-PRV-02-01: report counts unchanged).
		$this->assertSame( 'PP1.0-RP1.0', $row['consent_version'] );
		$this->assertSame( $status_counts_before, $wpdb->get_results( 'SELECT branch_id, status, requested_date, party_size, COUNT(*) n FROM ' . Tables::reservations() . ' GROUP BY branch_id, status, requested_date, party_size', ARRAY_A ) ); // phpcs:ignore

		$events = $this->events( $id );
		foreach ( $events as $e ) {
			$this->assertNull( $e['note'] );
			$this->assertStringNotContainsString( 'MARKER', (string) $e['payload'] );
		}
		$modified = array_values( array_filter( $events, static fn( $e ) => 'modified' === $e['event_type'] ) )[0];
		$this->assertSame( [ 'before' => [ 'requested_date' => '2025-01-01', 'requested_time' => '18:00', 'party_size' => 2 ], 'after' => [ 'requested_date' => '2025-01-02', 'requested_time' => '19:00', 'party_size' => 3 ] ], json_decode( (string) $modified['payload'], true ) );
		$purged = array_values( array_filter( $events, static fn( $e ) => 'pii_purged' === $e['event_type'] ) );
		$this->assertCount( 1, $purged );
		$this->assertSame( 'system', $purged[0]['actor_type'] );
		$this->assertSame( 'retention', $purged[0]['reason_code'] );

		$n = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Tables::notifications() . ' WHERE reservation_id = %d', $id ), ARRAY_A ); // phpcs:ignore
		$this->assertNull( $n['recipient_email'] );
		$this->assertSame( '[purged]', $n['recipient_masked'] );
		$this->assertSame( [ $id ], $hooks );

		// retention.run audited with counts only (no PII).
		$run = $this->audits_of( 'retention.run' );
		$this->assertCount( 1, $run );
		$this->assertSame( 'system', $run[0][5] );
		$this->assertSame( 1, $run[0][4]['counts']['reservations_anonymized'] );
		$this->assertStringNotContainsString( 'aisha', strtolower( (string) wp_json_encode( $this->audits ) ) );
		$this->assertTrue( RetentionJob::last()['ok'] );
	}

	public function test_idempotent_second_run_changes_nothing(): void {
		$b  = $this->make_branch( 'QPA' );
		$id = $this->rich_reservation( $b, '2025-01-10' );
		RetentionJob::run();
		$version = $this->row( $id )['version'];
		$out     = RetentionJob::run();
		$this->assertSame( 0, $out['counts']['reservations_anonymized'] );
		$this->assertSame( $version, $this->row( $id )['version'] );
		$this->assertCount( 1, array_filter( $this->events( $id ), static fn( $e ) => 'pii_purged' === $e['event_type'] ) );
		$this->assertFalse( Anonymizer::anonymize_reservation( $id, 'dsr' ) );
	}

	public function test_retention_period_comes_from_settings(): void {
		$b  = $this->make_branch( 'QPA' );
		$id = $this->rich_reservation( $b, '2026-05-18' ); // 6 months + 1 day
		RetentionJob::run();
		$this->assertNull( $this->row( $id )['pii_purged_at'], 'kept with the 12-month default' );
		Settings::set( 'momo_retention_pii_months', 6 );
		$this->assertSame( '2026-05-19', RetentionJob::pii_cutoff_date() );
		RetentionJob::run();
		$this->assertNotNull( $this->row( $id )['pii_purged_at'] );
		Settings::set( 'momo_retention_pii_months', 999 ); // out of bounds → documented default
		$this->assertSame( 12, RetentionJob::pii_months() );
	}

	public function test_abuse_hashes_cleared_after_configured_days(): void {
		$b   = $this->make_branch( 'QPA' );
		$old = $this->rich_reservation( $b, '2026-12-10', '19:00', [ 'status' => 'pending', 'created_at' => '2026-10-18 00:00:00' ] ); // 32 days
		$new = $this->rich_reservation( $b, '2026-12-11', '19:00', [ 'status' => 'pending', 'created_at' => '2026-10-21 02:00:00' ] ); // 29 days
		$v   = $this->row( $old )['version'];
		$out = RetentionJob::run();
		$this->assertSame( 1, $out['counts']['abuse_hashes_cleared'] );
		$this->assertNull( $this->row( $old )['ip_hash'] );
		$this->assertNull( $this->row( $old )['user_agent_hash'] );
		$this->assertSame( 'Aisha Rahman', $this->row( $old )['guest_name'] );
		$this->assertSame( $v, $this->row( $old )['version'] );
		$this->assertNotNull( $this->row( $new )['ip_hash'] );
	}

	public function test_notification_rows_and_recipients(): void {
		$b      = $this->make_branch( 'QPA' );
		$res    = $this->rich_reservation( $b, '2026-12-10', '19:00', [ 'status' => 'pending' ] );
		$recent = $this->notification( $res, 'aisha@example.org', 'sent', '2026-11-01 00:00:00' );
		$stale  = $this->notification( $res, 'aisha@example.org', 'sent', '2026-10-10 00:00:00' ); // terminal > 30 days
		$old    = $this->notification( $res, 'aisha@example.org', 'sent', '2026-05-20 00:00:00' ); // > 180 days
		$out    = RetentionJob::run();
		$this->assertSame( 'aisha@example.org', $this->notif_row( $recent )['recipient_email'] );
		$this->assertNull( $this->notif_row( $stale )['recipient_email'] );
		$this->assertSame( [], $this->notif_row( $old ) );
		$this->assertGreaterThanOrEqual( 1, $out['counts']['notifications_rows_deleted'] );
	}

	public function test_audit_rows_purged_through_audit_repository(): void {
		global $wpdb;
		$t            = Tables::audit();
		$insert       = static function ( string $action, string $when ) use ( $wpdb, $t ): int {
			$wpdb->insert( $t, [ 'occurred_at' => $when, 'actor_type' => 'system', 'action' => $action, 'object_type' => 'settings' ] );
			return (int) $wpdb->insert_id;
		};
		$old_domain   = $insert( 'settings.updated', '2024-11-01 00:00:00' ); // > 24 months
		$keep_domain  = $insert( 'settings.updated', '2025-01-01 00:00:00' ); // < 24 months
		$old_activity = $insert( 'auth.login_succeeded', '2025-10-01 00:00:00' ); // > 12 months
		$out          = RetentionJob::run();
		$exists       = static fn( int $id ): bool => (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$t} WHERE id = %d", $id ) ); // phpcs:ignore
		$this->assertFalse( $exists( $old_domain ) );
		$this->assertTrue( $exists( $keep_domain ) );
		$this->assertFalse( $exists( $old_activity ) );
		$this->assertSame( 1, $out['counts']['audit_domain'] );
		$this->assertSame( 1, $out['counts']['audit_activity'] );
	}

	public function test_dry_run_changes_nothing(): void {
		$b   = $this->make_branch( 'QPA' );
		$id  = $this->rich_reservation( $b, '2025-01-10' );
		$out = RetentionJob::run( true );
		$this->assertTrue( $out['dry_run'] );
		$this->assertSame( 1, $out['counts']['reservations_anonymized'] );
		$this->assertNull( $this->row( $id )['pii_purged_at'] );
		$this->assertSame( [], $this->audits_of( 'retention.run' ) );
		$this->assertSame( [], RetentionJob::last() );
	}

	public function test_lock_held_elsewhere_skips_run(): void {
		$other = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$key   = \Momos\Kernel\Db\NamedLock::key( RetentionJob::LOCK ); // Server-wide lock name (scoped to this database).
		$this->assertSame( '1', (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', $key ) ) );
		try {
			$this->assertNull( RetentionJob::run() );
		} finally {
			$other->query( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );
			$other->close();
		}
		$this->assertNotNull( RetentionJob::run( true ) );
	}

	public function test_failure_rolls_back_one_reservation_and_next_run_resumes(): void {
		$b    = $this->make_branch( 'QPA' );
		$bad  = $this->rich_reservation( $b, '2025-02-01' );
		$good = $this->rich_reservation( $b, '2025-02-02' );
		$tbl  = Tables::reservations();
		$fail = static function ( string $sql ) use ( $bad, $tbl ): string {
			return ( str_starts_with( $sql, "UPDATE {$tbl} SET `guest_name` = NULL" ) && str_contains( $sql, "WHERE id = {$bad} " ) ) ? 'UPDATE momos_injected_failure SET x = 1' : $sql;
		};
		add_filter( 'query', $fail );
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		$alerts   = [];
		add_action(
			'momos/monitoring/alert',
			static function ( $type ) use ( &$alerts ): void {
				$alerts[] = $type;
			}
		);
		$out = RetentionJob::run();
		remove_filter( 'query', $fail );
		$wpdb->suppress_errors( $suppress );

		$this->assertSame( 1, $out['failed'] );
		$this->assertSame( 1, $out['counts']['reservations_anonymized'] );
		$this->assertNull( $this->row( $bad )['pii_purged_at'] );
		$this->assertSame( 'Aisha Rahman', $this->row( $bad )['guest_name'] );
		$this->assertSame( 'MARKER-NOTE guest called', array_values( array_filter( $this->events( $bad ), static fn( $e ) => 'note' === $e['event_type'] ) )[0]['note'], 'rolled back' );
		$this->assertNotNull( $this->row( $good )['pii_purged_at'] );
		$failed = $this->audits_of( 'privacy.retention_failed' );
		$this->assertCount( 1, $failed );
		$this->assertSame( [ 'reservation_failed' ], $failed[0][4]['errors'] );
		$this->assertSame( [ 'retention_failed' ], $alerts );
		$this->assertFalse( RetentionJob::last()['ok'] );

		$again = RetentionJob::run();
		$this->assertSame( 0, $again['failed'] );
		$this->assertNotNull( $this->row( $bad )['pii_purged_at'] );
	}

	public function test_scheduled_handler_throws_on_failure_so_no_heartbeat(): void {
		$b   = $this->make_branch( 'QPA' );
		$bad = $this->rich_reservation( $b, '2025-02-01' );
		$tbl = Tables::reservations();
		$f   = static fn( string $sql ): string => str_starts_with( $sql, "UPDATE {$tbl} SET `guest_name` = NULL" ) ? 'UPDATE momos_injected_failure SET x = 1' : $sql;
		add_filter( 'query', $f );
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		try {
			RetentionJob::handle();
			$this->fail( 'expected exception' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'privacy.retention_failed', $e->getMessage() );
		} finally {
			remove_filter( 'query', $f );
			$wpdb->suppress_errors( $suppress );
		}
		$this->assertNull( $this->row( $bad )['pii_purged_at'] );
	}

	public function test_extra_steps_filter_receives_dry_run_flag(): void {
		$calls = [];
		add_filter(
			'momos/privacy/retention_steps',
			static function ( array $steps ) use ( &$calls ): array {
				$steps['seo_404_log'] = static function ( bool $dry, \DateTimeImmutable $now ) use ( &$calls ): int {
					$calls[] = [ $dry, $now->format( 'Y-m-d' ) ];
					return 3;
				};
				return $steps;
			}
		);
		$out = RetentionJob::run( true );
		$this->assertSame( 3, $out['counts']['seo_404_log'] );
		$this->assertSame( [ [ true, '2026-11-19' ] ], $calls );
	}

	public function test_watchdog_alerts_after_26_hours_without_success(): void {
		$alerts = [];
		add_action(
			'momos/monitoring/alert',
			static function ( $type ) use ( &$alerts ): void {
				$alerts[] = $type;
			}
		);
		update_option( RetentionJob::LAST_OPTION, [ 'last_ok_at' => '2026-11-18 00:30:00' ] ); // 24.5 h
		RetentionJob::watch();
		$this->assertSame( [], $alerts );
		update_option( RetentionJob::LAST_OPTION, [ 'last_ok_at' => '2026-11-17 22:30:00' ] ); // 26.5 h
		RetentionJob::watch();
		$this->assertSame( [ 'retention_stale' ], $alerts );
	}

	public function test_month_arithmetic_and_payload_scrub(): void {
		$tz = Clock::tz();
		$this->assertSame( '2026-02-28', RetentionJob::minus_months( new \DateTimeImmutable( '2026-03-31', $tz ), 1 )->format( 'Y-m-d' ) );
		$this->assertSame( '2025-02-28', RetentionJob::minus_months( new \DateTimeImmutable( '2026-02-28', $tz ), 12 )->format( 'Y-m-d' ) );
		$this->assertSame( '2023-12-15', RetentionJob::minus_months( new \DateTimeImmutable( '2026-01-15', $tz ), 25 )->format( 'Y-m-d' ) );
		$this->assertSame(
			[ 'notification_id' => 5, 'template' => 'E02', 'error_code' => 'smtp_timeout' ],
			Anonymizer::scrub_payload( [ 'notification_id' => 5, 'template' => 'E02', 'error_code' => 'smtp_timeout', 'previous_handler_display' => 'Staff One', 'override_reason' => 'x' ] )
		);
		$this->assertSame( [], Anonymizer::scrub_payload( [ 'reason' => 'guest Aisha Rahman', 'via' => 'a@example.org' ] ) );
	}
}
