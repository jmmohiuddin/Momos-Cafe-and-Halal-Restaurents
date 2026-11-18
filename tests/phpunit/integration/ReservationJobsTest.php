<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/ReservationTestCaseTest.php';

use Momos\Branch\Domain\AccessContext;
use Momos\Branch\Repository\ClosureRepository;
use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Db\Tables;
use Momos\Reservation\Admin\ExportHandler;
use Momos\Reservation\Domain\Scope;
use Momos\Reservation\Job\ExpiryJob;
use Momos\Reservation\Job\SlaJob;
use Momos\Reservation\Service\FlagService;
use Momos\Reservation\Service\ReportService;
use Momos\Reservation\Service\SlaClock;

/**
 * Jobs and follow-ups: expiry (AC-RES-07), SLA (AC-RES-08), closure conflicts (BR-RES-13),
 * notification failure flag (BR-RES-12), report (AC-RES-12), export (AC-RES-11), core REST hardening.
 */
final class ReservationJobsTest extends ReservationTestCaseTest {
	private int $a;

	public function set_up(): void {
		parent::set_up();
		$this->freeze( '2026-12-01 10:00:00' );
		$this->a = $this->make_branch( 'QRA' );
	}

	public function test_expiry_job_idempotent_ac_res_07(): void {
		$id   = $this->make_reservation( $this->a, '2026-12-01', '19:00' );
		$keep = $this->make_reservation( $this->a, '2026-12-01', '21:00' );
		$conf = $this->make_reservation( $this->a, '2026-12-01', '19:00', [ 'status' => 'confirmed' ] );
		$this->freeze( '2026-12-01 17:59:00' );
		$this->assertSame( 0, ExpiryJob::run() );
		$this->freeze( '2026-12-01 18:00:00' );
		$this->assertSame( 1, ExpiryJob::run() );
		$this->assertSame( 0, ExpiryJob::run(), 're-run is a no-op' );
		$row = $this->row( $id );
		$this->assertSame( 'expired', $row['status'] );
		$this->assertNull( $row['cancelled_by'] );
		$this->assertNotNull( $row['expired_at'] );
		$this->assertCount( 1, array_filter( $this->events( $id ), static fn( $e ) => 'status_changed' === $e['event_type'] ) );
		$this->assertCount( 1, $this->hooks_of( 'status_changed' ) );
		$this->assertSame( 'pending', $this->row( $keep )['status'] );
		$this->assertSame( 'confirmed', $this->row( $conf )['status'] );
		$this->assertSame( 0, ExpiryJob::minutes_since_heartbeat() );
		$this->assertArrayHasKey( ExpiryJob::HOOK, \Momos\Kernel\Jobs\Schedule::all() );
	}

	public function test_sla_wall_clock_ac_res_08_01_02(): void {
		$id       = $this->make_reservation( $this->a, '2026-12-05', '19:00' );
		$accepted = $this->make_reservation( $this->a, '2026-12-05', '20:00' );
		$this->freeze( '2026-12-01 11:30:00' );
		global $wpdb;
		$wpdb->update( Tables::reservations(), [ 'status' => 'confirmed' ], [ 'id' => $accepted ] );
		$this->freeze( '2026-12-01 11:59:00' );
		$this->assertSame( [ 'reminders' => 0, 'escalations' => 0 ], SlaJob::run() );
		$this->freeze( '2026-12-01 12:00:00' );
		$this->assertSame( [ 'reminders' => 1, 'escalations' => 0 ], SlaJob::run() );
		$this->assertSame( [ 'reminders' => 0, 'escalations' => 0 ], SlaJob::run() );
		$this->freeze( '2026-12-01 14:00:00' );
		$this->assertSame( [ 'reminders' => 0, 'escalations' => 1 ], SlaJob::run() );
		$this->assertCount( 1, $this->hooks_of( 'sla_reminder' ) );
		$this->assertCount( 1, $this->hooks_of( 'sla_escalated' ) );
		$this->assertSame( $id, $this->hooks_of( 'sla_escalated' )[0][1] );
		$ev = array_values( array_filter( $this->events( $id ), static fn( $e ) => 'sla_escalated' === $e['event_type'] ) );
		$this->assertSame( [ 'minutes_waiting' => 240 ], json_decode( $ev[0]['payload'], true ) );
	}

	public function test_sla_business_hours_ac_res_08_03(): void {
		$b = $this->make_branch( 'QRB', [ 'momo_res_sla_clock' => 'business_hours' ], array_fill_keys( range( 1, 7 ), [ [ '11:00', '00:00', '23:30', 'all_day' ] ] ) );
		$this->freeze( '2026-12-01 23:30:00' );
		$id = $this->make_reservation( $b, '2026-12-05', '19:00' );
		$created = Clock::utc();
		$this->assertSame( 30, SlaClock::minutes( $b, $created, Clock::jst_at( '2026-12-02', '11:00' ) ) );
		$this->freeze( '2026-12-02 12:29:00' );
		SlaJob::run();
		$this->assertCount( 0, $this->hooks_of( 'sla_reminder' ) );
		$this->freeze( '2026-12-02 12:30:00' );
		SlaJob::run();
		$this->assertCount( 1, $this->hooks_of( 'sla_reminder' ) );
		$this->assertSame( 'overdue', SlaClock::state( $this->row( $id ) ) );
	}

	public function test_closure_conflict_flagged_never_cancelled_br_res_13(): void {
		$hit  = $this->make_reservation( $this->a, '2026-12-10', '19:00', [ 'status' => 'confirmed' ] );
		$safe = $this->make_reservation( $this->a, '2026-12-11', '19:00' );
		ClosureRepository::replace_dates( $this->a, [ '2026-12-10' ], 'closed_all_day', [], 'maintenance', '', AccessContext::system() );
		$row = $this->row( $hit );
		$this->assertSame( 'confirmed', $row['status'] );
		$this->assertSame( '1', (string) $row['needs_action_flag'] );
		$this->assertSame( '2', (string) $row['version'] );
		$this->assertSame( '0', (string) $this->row( $safe )['needs_action_flag'] );
		$this->assertSame( [ $hit ], array_column( $this->hooks_of( 'closure_conflict' ), 1 ) );
		$this->assertCount( 1, array_filter( $this->events( $hit ), static fn( $e ) => 'closure_conflict' === $e['event_type'] ) );
		// A second schedule change does not duplicate the flag event.
		FlagService::on_schedule_changed( $this->a, [ '2026-12-10' ] );
		$this->assertCount( 1, array_filter( $this->events( $hit ), static fn( $e ) => 'closure_conflict' === $e['event_type'] ) );
		// Temporarily closed → every future active reservation is flagged.
		FlagService::on_accepting_changed( $this->a, false, 'temporarily_closed' );
		$this->assertSame( '1', (string) $this->row( $safe )['needs_action_flag'] );
		// Branch impact preview uses the SlotGenerator.
		$this->assertFalse( apply_filters( 'momos/branch/slot_valid', true, [ 'requested_date' => '2026-12-10', 'requested_time' => '19:00' ], $this->a ) );
		$this->assertTrue( apply_filters( 'momos/branch/slot_valid', false, [ 'requested_date' => '2026-12-11', 'requested_time' => '19:00' ], $this->a ) );
	}

	public function test_notification_failure_flag_br_res_12(): void {
		$id = $this->make_reservation( $this->a, '2026-12-05', '19:00' );
		global $wpdb;
		$wpdb->insert(
			Tables::notifications(),
			[ 'uuid' => wp_generate_uuid4(), 'dedupe_key' => 'res:' . $id . ':E01', 'template_key' => 'E01', 'lang' => 'en', 'recipient_type' => 'customer', 'recipient_hash' => str_repeat( 'b', 64 ), 'recipient_masked' => 'g***@e***.org', 'reservation_id' => $id, 'status' => 'failed', 'last_error_code' => 'smtp_rejected_recipient', 'created_at' => Clock::mysql_utc(), 'updated_at' => Clock::mysql_utc() ]
		);
		do_action( 'momos/notification/failed', (int) $wpdb->insert_id, $id );
		$row = $this->row( $id );
		$this->assertSame( '0', (string) $row['customer_notified_flag'] );
		$this->assertSame( 'pending', $row['status'], 'email failure never changes the reservation' );
		$ev = array_values( array_filter( $this->events( $id ), static fn( $e ) => 'notification_failed' === $e['event_type'] ) );
		$this->assertSame( 'E01', json_decode( $ev[0]['payload'], true )['template'] );
	}

	public function test_report_rates_and_scope_ac_res_12(): void {
		$b = $this->make_branch( 'QRB' );
		$t = $this->make_branch( 'QRT', [ 'momo_is_test_branch' => '1' ] );
		$now = Clock::mysql_utc();
		for ( $i = 0; $i < 6; $i++ ) {
			$this->make_reservation( $this->a, '2026-12-05', '19:00', [ 'status' => 'confirmed', 'confirmed_at' => $now ] );
		}
		for ( $i = 0; $i < 2; $i++ ) {
			$this->make_reservation( $this->a, '2026-12-05', '19:00', [ 'status' => 'rejected' ] );
			$this->make_reservation( $this->a, '2026-12-05', '19:00', [ 'status' => 'expired' ] );
		}
		$this->make_reservation( $b, '2026-12-05', '19:00', [ 'status' => 'rejected' ] );
		$this->make_reservation( $t, '2026-12-05', '19:00', [ 'status' => 'confirmed', 'confirmed_at' => $now ] );
		$f   = [ 'date_from' => '2026-12-01', 'date_to' => '2026-12-01', 'basis' => 'created', 'branch_ids' => null, 'lang' => null ];
		$all = ReportService::build( Scope::system(), $f );
		$this->assertSame( 11, $all['total']['created'], 'TEST branch excluded' );
		$bm  = $this->staff( 'momo_branch_manager', [ $this->a ] );
		$rep = ReportService::build( Scope::for_user( $bm ), $f );
		$this->assertSame( 10, $rep['total']['created'] );
		$this->assertEqualsWithDelta( 0.6, $rep['total']['acceptance_rate'], 0.0001 );
		$this->assertEqualsWithDelta( 0.2, $rep['total']['expiry_rate'], 0.0001 );
		$this->assertNull( $rep['total']['no_show_rate'], 'zero denominator → not enough data' );
		$this->assertSame( [ 'QRA' ], array_keys( $rep['by_branch'] ) );
		// Survives anonymisation.
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Tables::reservations() . ' SET guest_name = NULL, email = NULL, token_hash = NULL, pii_purged_at = %s', $now ) ); // phpcs:ignore
		$this->assertSame( 10, ReportService::build( Scope::for_user( $bm ), $f )['total']['created'] );
		$this->assertSame( 5, ReportService::percentile( [ 1, 2, 3, 4, 5, 6, 7, 8, 9, 10 ], 0.5 ) );
		$this->assertSame( 9, ReportService::percentile( [ 1, 2, 3, 4, 5, 6, 7, 8, 9, 10 ], 0.9 ) );
	}

	public function test_export_formula_safe_and_scoped_ac_res_11(): void {
		$this->make_reservation( $this->a, '2026-12-05', '19:00', [ 'guest_name' => '=HYPERLINK("http://x")', 'special_request' => '+cmd' ] );
		$this->assertSame( "'=HYPERLINK(\"http://x\")", ExportHandler::cell( '=HYPERLINK("http://x")' ) );
		$this->assertSame( "'@SUM(1)", ExportHandler::cell( '@SUM(1)' ) );
		$this->assertSame( 'Aisha', ExportHandler::cell( 'Aisha' ) );
		$ho     = $this->staff( 'momo_head_office' );
		$parsed = ExportHandler::parse( [ 'reason' => 'Monthly review', 'date_from' => '2026-12-01', 'date_to' => '2026-12-31', 'include_special_requests' => '1' ], Scope::for_user( $ho ) );
		$this->assertIsArray( $parsed );
		$this->assertTrue( $parsed['notes'] );
		$where = \Momos\Reservation\Repository\ReservationRepository::scoped_where( Scope::for_user( $ho ), $parsed['filters'] );
		$out   = fopen( 'php://memory', 'w+' );
		$this->assertSame( 1, ExportHandler::stream( $where, true, $out ) );
		rewind( $out );
		$csv = stream_get_contents( $out );
		$this->assertStringStartsWith( "\xEF\xBB\xBF" . 'reference,branch_code', $csv );
		$this->assertStringContainsString( "\"'=HYPERLINK(\"\"http://x\"\")\"", $csv );
		$this->assertStringContainsString( "'+cmd", $csv );
		$this->assertIsString( ExportHandler::parse( [ 'reason' => '', 'date_from' => '2026-12-01', 'date_to' => '2026-12-31' ], Scope::for_user( $ho ) ), 'reason required' );
		$this->assertIsString( ExportHandler::parse( [ 'reason' => 'x', 'date_from' => '2025-01-01', 'date_to' => '2026-12-31' ], Scope::for_user( $ho ) ), '≤ 12 months' );
		$this->assertFalse( user_can( $this->staff( 'momo_branch_manager', [ $this->a ] ), 'momo_export_reservations' ), 'BM export → 403' );
	}

	public function test_core_rest_guard(): void {
		$this->assertSame( 401, $this->rest( 'GET', '/wp/v2/users' )->get_status() );
		$this->assertSame( 401, $this->rest( 'GET', '/wp/v2/users/1' )->get_status() );
		wp_set_current_user( $this->staff( 'momo_reservation_staff', [ $this->a ] ) );
		$this->assertSame( 403, $this->rest( 'GET', '/wp/v2/users' )->get_status() );
		$this->assertSame( 200, $this->rest( 'GET', '/wp/v2/users/me' )->get_status() );
		wp_set_current_user( $this->staff( 'administrator' ) );
		$this->assertSame( 200, $this->rest( 'GET', '/wp/v2/users' )->get_status() );
		$r = $this->rest( 'POST', '/wp/v2/momo_branch/' . $this->a, [ 'title' => 'x' ] );
		$this->assertSame( 405, $r->get_status() );
		$this->assertSame( 'method_not_allowed', $r->get_data()['code'] );
		$this->assertFalse( wp_is_application_passwords_available() );
	}

	public function test_every_momos_route_has_permission_callback(): void {
		foreach ( rest_get_server()->get_routes( 'momos/v1' ) as $route => $handlers ) {
			foreach ( $handlers as $h ) {
				if ( isset( $h['methods'] ) && is_array( $h ) && '/momos/v1' !== $route ) {
					$this->assertNotEmpty( $h['permission_callback'] ?? null, $route );
				}
			}
		}
	}
}
