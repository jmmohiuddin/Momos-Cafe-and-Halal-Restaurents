<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/NotificationTestCaseTest.php';

use Momos\Kernel\Clock\Clock;
use Momos\Notification\Admin\NotificationLogScreen;
use Momos\Notification\Admin\NotificationPanel;
use Momos\Notification\Job\DigestJob;
use Momos\Notification\Queue\Dispatcher;
use Momos\Notification\Repository\NotificationRepository;
use Momos\Notification\Rest\ResendController;
use Momos\Notification\Service\ResendService;
use Momos\Notification\Service\Retention;

/**
 * E10 digest, log view/resend permissions, retention (FR-NOT-002(e), F-NOT-03, FR-NOT-004,
 * FR-PRV-003/004): TC-NOT-011, AC-NOT-02-04, TC-ACL-008, resend rules §8.3, AC-NOT-03-04/TC-PRV-005.
 */
final class NotificationDigestAdminTest extends NotificationTestCaseTest {
	public function test_tc_not_011_branch_digest_sections_and_ac_not_02_04_no_items_no_digest(): void {
		$a = $this->branch( 'QAA', self::recipients( 'qaa@momos.example' ) );
		$b = $this->branch( 'QAB', self::recipients( 'qab@momos.example' ) );
		$this->freeze( '2026-12-02 09:00:00' );
		$y = Clock::mysql_utc( Clock::utc()->modify( '-3 hours' ) );
		$this->reservation( $a, [ 'status' => 'expired', 'expired_at' => $y ] );
		$this->reservation( $a, [ 'status' => 'expired', 'expired_at' => $y ] );
		$this->reservation( $a, [ 'status' => 'confirmed', 'requested_date' => '2026-11-30', 'requested_time' => '19:00:00' ] );
		$f = $this->reservation( $a );
		do_action( 'momos/reservation/created', $f );
		$e01              = (int) $this->rows( 'E01' )[0]['id'];
		$this->fail_queue = [ [ 'smtp_rejected_recipient', '550 unknown' ] ];
		Dispatcher::send( $e01 );

		$ids = DigestJob::run();
		$e10 = $this->rows( 'E10' );
		$this->assertCount( 1, $e10, 'branch A only; no E10 for B (AC-NOT-02-04); no HO list configured' );
		$this->assertSame( 'qaa@momos.example', $e10[0]['recipient_email'], 'branch digest → own recipients (TC-ACL-008)' );
		$this->assertSame( 'branch', $e10[0]['template_variant'] );
		$this->assertSame( $ids, [ (int) $e10[0]['id'] ] );
		$this->assertSame( [], DigestJob::run(), 'second run the same day sends nothing' );

		$sections = array_column( DigestJob::sections( $a, Clock::utc() ), 'count', 'key' );
		$this->assertSame( 2, $sections['expired'] );
		$this->assertSame( 1, $sections['failed'] );
		$this->assertSame( 1, $sections['attendance'] );
		$this->assertFalse( DigestJob::has_items( DigestJob::sections( $b, Clock::utc() ) ) );

		$this->assertSame( 'sent', Dispatcher::send( (int) $e10[0]['id'] ) );
		$m = end( $this->sent );
		$this->assertStringContainsString( '[QAA] 日次サマリー・2026-12-02', $m->subject );
		$this->assertStringContainsString( '期限切れ', $m->html );
		$this->assertStringNotContainsString( 'Aisha', $m->html, 'no guest names in digests' );
		$this->assertStringNotContainsString( 'aisha.guest', $m->html );
	}

	public function test_ho_digest_and_disabled_digest(): void {
		$a = $this->branch( 'QAA', self::recipients( 'qaa@momos.example' ) );
		\Momos\Kernel\Settings::set( 'momo_ho_escalation_recipients', [ [ 'email' => 'ho@momos.example', 'language' => 'en', 'active' => '1' ] ] );
		\Momos\Kernel\Settings::set( 'momo_digest_recipients', [ [ 'email' => 'ho@momos.example', 'language' => 'en', 'active' => '1' ], [ 'email' => 'ops@momos.example', 'language' => 'ja', 'active' => '1' ] ] );
		$this->reservation( $a, [ 'status' => 'expired', 'expired_at' => Clock::mysql_utc( Clock::utc()->modify( '-1 hour' ) ) ] );
		\Momos\Kernel\Settings::set( 'momo_digest_enabled', '0' );
		$this->assertSame( [], DigestJob::run(), 'skipped when disabled' );
		\Momos\Kernel\Settings::set( 'momo_digest_enabled', '1' );
		DigestJob::run();
		$ho = array_values( array_filter( $this->rows( 'E10' ), static fn( $r ) => 'head_office' === $r['template_variant'] ) );
		$this->assertEqualsCanonicalizing( [ 'ho@momos.example', 'ops@momos.example' ], array_column( $ho, 'recipient_email' ), 'HO list ∪ digest list, deduped' );
		$this->assertSame( 'sent', Dispatcher::send( (int) $ho[0]['id'] ) );
		$this->assertStringContainsString( '[QAA] Expired', end( $this->sent )->html );
	}

	public function test_resend_rules_scope_and_audit(): void {
		$a = $this->branch( 'QAA', self::recipients( 'qaa@momos.example' ) );
		$b = $this->branch( 'QAB', self::recipients( 'qab@momos.example' ) );
		$r = $this->reservation( $a );
		do_action( 'momos/reservation/created', $r );
		$id               = (int) $this->rows( 'E01' )[0]['id'];
		$this->fail_queue = [ [ 'smtp_rejected_recipient', '550' ] ];
		Dispatcher::send( $id );

		$bm_b = $this->user_with_role( 'momo_branch_manager', [ $b ] );
		$rs_a = $this->user_with_role( 'momo_reservation_staff', [ $a ] );
		$ho   = $this->user_with_role( 'momo_head_office' );
		$this->assertSame( 'forbidden_branch', ResendService::resend( $id, $bm_b )['code'], 'BM of B cannot resend A' );
		$this->assertContains( 'access.denied', array_column( $this->audits, 0 ) );
		$this->assertSame( 'not_found', ResendService::resend( $id, $rs_a, $r + 999 )['code'], 'row not in this reservation' );
		$this->assertTrue( ResendService::is_latest_resendable( NotificationRepository::find( $id ), $rs_a ) );
		$out = ResendService::resend( $id, $rs_a, $r );
		$this->assertTrue( $out['ok'] );
		$new = NotificationRepository::find( (int) $out['id'] );
		$this->assertSame( 'resend:' . $id . ':1', $new['dedupe_key'] );
		$this->assertSame( 'queued', $new['status'] );
		$this->assertSame( '0', (string) $new['attempts'] );
		$this->assertContains( 'notification.resent', array_column( $this->audits, 0 ) );
		$this->assertTrue( ResendService::resend( $id, $ho )['ok'], 'resend 2' );
		$this->assertTrue( ResendService::resend( $id, $ho )['ok'], 'resend 3' );
		$this->assertSame( 3, NotificationRepository::resend_count( $id ) );
		$this->assertSame( 'invalid_transition', ResendService::resend( $id, $ho )['code'], '≤ 3 resends per row' );

		// Template must still match the current status; staff rows are never resendable.
		$r2 = $this->reservation( $a );
		do_action( 'momos/reservation/created', $r2 );
		$e01              = (int) array_values( array_filter( $this->rows( 'E01' ), static fn( $x ) => (int) $x['reservation_id'] === $r2 ) )[0]['id'];
		$this->fail_queue = [ [ 'smtp_rejected_recipient', '550' ] ];
		Dispatcher::send( $e01 );
		$this->update_reservation( $r2, [ 'status' => 'confirmed' ] );
		$this->assertSame( 'invalid_transition', ResendService::resend( $e01, $ho )['code'] );
		$e07 = (int) $this->rows( 'E07' )[0]['id'];
		$this->assertSame( 'invalid_transition', ResendService::check( NotificationRepository::find( $e07 ), $ho ) );

		// Resent row sent → hook for the Reservation flag reset.
		$fired = [];
		add_action(
			'momos/notification/resend_sent',
			function ( $nid, $rid ) use ( &$fired ): void {
				$fired[] = [ $nid, $rid ];
			},
			10,
			2
		);
		Dispatcher::send( (int) $out['id'] );
		$this->assertSame( [ [ (int) $out['id'], $r ] ], $fired );
	}

	public function test_rest_resend_route_permissions(): void {
		$a = $this->branch( 'QAA', [] );
		$b = $this->branch( 'QAB', [] );
		$r = $this->reservation( $a );
		do_action( 'momos/reservation/created', $r );
		$id               = (int) $this->rows( 'E01' )[0]['id'];
		$this->fail_queue = [ [ 'smtp_rejected_recipient', '550' ] ];
		Dispatcher::send( $id );
		do_action( 'rest_api_init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook
		$req = new \WP_REST_Request( 'POST', '/momos/v1/staff/reservations/' . $r . '/notifications/' . $id . '/resend' );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( '{}' );
		wp_set_current_user( 0 );
		$this->assertSame( 401, rest_do_request( $req )->get_status() );
		wp_set_current_user( $this->user_with_role( 'momo_content_editor' ) );
		$this->assertSame( 403, rest_do_request( $req )->get_status() );
		wp_set_current_user( $this->user_with_role( 'momo_reservation_staff', [ $b ] ) );
		$res = rest_do_request( $req );
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 'forbidden_branch', $res->get_data()['code'] );
		wp_set_current_user( $this->user_with_role( 'momo_reservation_staff', [ $a ] ) );
		$res = rest_do_request( $req );
		$this->assertSame( 201, $res->get_status() );
		$this->assertSame( 'queued', $res->get_data()['status'] );
		unset( $b );
		$this->assertTrue( class_exists( ResendController::class ) );
	}

	/**
	 * CHG-010: resend additionally needs `momo_view_reservations` and the RESERVATION's branch in
	 * scope (not only the notification row's branch_id), in the service, REST route and admin-post gate.
	 */
	public function test_resend_requires_view_reservations_and_the_reservation_branch_scope(): void {
		$a = $this->branch( 'QAA', [] );
		$b = $this->branch( 'QAB', [] );
		$r = $this->reservation( $a );
		do_action( 'momos/reservation/created', $r );
		$id               = (int) $this->rows( 'E01' )[0]['id'];
		$this->fail_queue = [ [ 'smtp_rejected_recipient', '550' ] ];
		Dispatcher::send( $id );

		// Process cap without the view primitive (per-user deny, as for vendor accounts) → refused.
		$rs_a = $this->user_with_role( 'momo_reservation_staff', [ $a ] );
		( new \WP_User( $rs_a ) )->add_cap( 'momo_view_reservations', false );
		$this->assertFalse( user_can( $rs_a, 'momo_view_reservations' ) );
		$this->assertTrue( user_can( $rs_a, 'momo_process_reservations' ) );
		$this->assertSame( 'forbidden_branch', ResendService::resend( $id, $rs_a, $r )['code'] );
		$this->assertFalse( ResendService::is_latest_resendable( NotificationRepository::find( $id ), $rs_a ) );
		do_action( 'rest_api_init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook
		$req = new \WP_REST_Request( 'POST', '/momos/v1/staff/reservations/' . $r . '/notifications/' . $id . '/resend' );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( '{}' );
		wp_set_current_user( $rs_a );
		$this->assertSame( 403, rest_do_request( $req )->get_status() );

		// The row claims branch B but the reservation is in A: a B user is refused, and so is an A
		// user (the row does not belong to the reservation's branch).
		global $wpdb;
		$wpdb->update( \Momos\Kernel\Db\Tables::notifications(), [ 'branch_id' => $b ], [ 'id' => $id ] );
		$rs_b = $this->user_with_role( 'momo_reservation_staff', [ $b ] );
		$this->assertFalse( ResendService::user_can( NotificationRepository::find( $id ), $rs_b ), 'B staff never resend an A reservation' );
		$rs_a2 = $this->user_with_role( 'momo_reservation_staff', [ $a ] );
		$this->assertFalse( ResendService::user_can( NotificationRepository::find( $id ), $rs_a2 ) );
		$wpdb->update( \Momos\Kernel\Db\Tables::notifications(), [ 'branch_id' => $a ], [ 'id' => $id ] );
		$this->assertTrue( ResendService::user_can( NotificationRepository::find( $id ), $rs_a2 ) );
		$this->assertFalse( ResendService::user_can( NotificationRepository::find( $id ), $rs_b ) );
		$this->assertTrue( ResendService::user_can( NotificationRepository::find( $id ), $this->user_with_role( 'momo_head_office' ) ) );
		wp_set_current_user( 0 );
	}

	public function test_log_screen_requires_cap_and_shows_masked_recipients_only(): void {
		$a = $this->branch( 'QAA', self::recipients( 'qaa@momos.example' ) );
		$r = $this->reservation( $a );
		do_action( 'momos/reservation/created', $r );
		$this->fail_queue = [ [ 'smtp_rejected_recipient', '550' ] ];
		Dispatcher::send( (int) $this->rows( 'E01' )[0]['id'] );

		wp_set_current_user( $this->user_with_role( 'momo_branch_manager', [ $a ] ) );
		$this->assertFalse( current_user_can( NotificationLogScreen::CAP ) );
		add_filter(
			'wp_die_handler',
			static fn() => static function (): void {
			throw new \RuntimeException( 'wp_die' );
			}
		);
		try {
			NotificationLogScreen::render();
			$this->fail( 'BM must not see the global log' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_die', $e->getMessage() );
		}

		wp_set_current_user( $this->user_with_role( 'momo_head_office' ) );
		$_GET = [ 'page' => NotificationLogScreen::SLUG ];
		ob_start();
		NotificationLogScreen::render();
		$html = (string) ob_get_clean();
		$_GET = [];
		$this->assertStringContainsString( 'a***@e***.test', $html );
		$this->assertStringNotContainsString( 'aisha.guest@example.test', $html, 'masked only' );
		$this->assertStringContainsString( 'momos_notification_resend', $html );
		$this->assertStringContainsString( 'E01 Request received', $html );

		$f = NotificationLogScreen::filters( [ 'status' => 'bogus', 'template' => 'e02', 'from' => '2020-01-01', 'to' => '2026-12-01' ] );
		$this->assertSame( 'failed', $f['status'] );
		$this->assertSame( 'E02', $f['template'] );
		$this->assertSame( '2026-06-04', $f['from'], 'range clamped to 180 days' );

		$rows = NotificationPanel::api_rows( $r, get_current_user_id() );
		$this->assertArrayNotHasKey( 'recipient_email', $rows[0] );
		$this->assertArrayHasKey( 'can_resend', $rows[0] );
	}

	public function test_retention_purges_recipients_after_30_days_and_deletes_after_180(): void {
		$a = $this->branch( 'QAA', self::recipients( 'qaa@momos.example' ) );
		$r = $this->reservation( $a );
		do_action( 'momos/reservation/created', $r );
		foreach ( $this->rows() as $row ) {
			Dispatcher::send( (int) $row['id'] );
		}
		$this->advance( '+31 days' );
		$out = Retention::run();
		$this->assertSame( 2, $out['recipients_purged'] );
		foreach ( $this->rows() as $row ) {
			$this->assertNull( $row['recipient_email'] );
			$this->assertNotSame( '', $row['recipient_masked'] );
			$this->assertSame( 64, strlen( $row['recipient_hash'] ), 'hash kept' );
		}
		$this->advance( '+150 days' );
		$this->assertSame( 2, Retention::run()['rows_deleted'] );
		$this->assertCount( 0, $this->rows() );
	}

	public function test_dsr_export_metadata_only(): void {
		$a = $this->branch( 'QAA', self::recipients( 'qaa@momos.example' ) );
		$r = $this->reservation( $a );
		do_action( 'momos/reservation/created', $r );
		$out = Retention::export( 'Aisha.Guest@example.test' );
		$this->assertTrue( $out['done'] );
		$this->assertCount( 1, $out['data'], 'only the customer rows of this address' );
		$names = array_column( $out['data'][0]['data'], 'name' );
		$this->assertContains( 'Template', $names );
		$this->assertStringNotContainsString( 'qaa@', (string) wp_json_encode( $out ) );
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', [] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook
		$this->assertArrayHasKey( 'momos-notifications', $exporters );
	}

	public function test_test_send_reports_masked_results_and_audits(): void {
		$a = $this->branch( 'QAA', self::recipients( 'qaa@momos.example' ) );
		wp_set_current_user( $this->user_with_role( 'momo_head_office' ) );
		$out = NotificationLogScreen::test_send( 'branch:' . $a );
		$this->assertSame( [ [ 'masked' => 'q***@m***.example', 'result' => 'sent' ] ], $out );
		$this->assertStringContainsString( 'テストメッセージ', $this->sent[0]->subject );
		$this->assertContains( 'notification.test_sent', array_column( $this->audits, 0 ) );
	}
}
