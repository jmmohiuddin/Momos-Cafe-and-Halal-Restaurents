<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/ReservationTestCaseTest.php';

use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Db\Tables;
use Momos\Kernel\I18n\Translations;
use Momos\Reservation\Front\CustomerPages;
use Momos\Reservation\Job\IntegrityJob;
use Momos\Reservation\Service\Presenter;

/**
 * Wave 2 reservation-frontend: customer screen view models (SCR-C09/C10/C11) and the cross-module
 * follow-ups (closure conflicts from real Branch hooks, notification failure/resend flags, A09 hook,
 * API-11 notifications, integrity check "pending past start > 1 h").
 */
final class ReservationFrontendTest extends ReservationTestCaseTest {
	private int $a;

	public function set_up(): void {
		parent::set_up();
		$this->freeze( '2026-12-01 10:00:00' );
		$this->a = $this->make_branch( 'QRA' );
		Translations::save( 'post', $this->a, 'en', [ 'title' => 'Umeda Test' ], 'approved' );
		\Momos\Presentation\View\BranchLinks::flush();
		unset( $_GET['branch'], $_GET['date'], $_GET['time'] );
	}

	public function test_branch_selector_hides_untranslated_test_and_closed_branches(): void {
		$b    = $this->make_branch( 'QRB' ); // No approved en translation → hidden in en (BR-I18N-02).
		$test = $this->make_branch( 'QRT', [ 'momo_is_test_branch' => '1' ] );
		Translations::save( 'post', $test, 'en', [ 'title' => 'Test branch' ], 'approved' );
		$off = $this->make_branch( 'QRC', [ 'momo_res_enabled' => '0' ] );
		Translations::save( 'post', $off, 'en', [ 'title' => 'Not accepting' ], 'approved' );
		\Momos\Presentation\View\BranchLinks::flush();

		$en = array_column( CustomerPages::branches( 'en' ), null, 'code' );
		$this->assertSame( [ 'QRA', 'QRC' ], array_keys( $en ) );
		$this->assertTrue( $en['QRA']['accepting'] );
		$this->assertFalse( $en['QRC']['accepting'] );
		$this->assertSame( 'Umeda Test', $en['QRA']['name'] );
		$this->assertSame( 'tel:+81600000000', $en['QRA']['tel'] );
		$this->assertContains( 'QRB', array_column( CustomerPages::branches( 'ja' ), 'code' ) );

		// TEST branch only for logged-in staff assigned to it (FR-RES-026).
		wp_set_current_user( $this->staff( 'momo_reservation_staff', [ $test ] ) );
		$this->assertContains( 'QRT', array_column( CustomerPages::branches( 'en' ), 'code' ) );
		wp_set_current_user( 0 );
		unset( $b );
	}

	public function test_form_view_prefill_and_config_carry_no_personal_data(): void {
		$_GET['branch'] = 'QRA';
		$_GET['date']   = '2026-12-05';
		$_GET['time']   = '19:00';
		$v              = CustomerPages::form_view( 'en' );
		$this->assertSame( [ 'branch' => 'QRA', 'date' => '2026-12-05', 'time' => '19:00' ], $v['prefill'] );
		$this->assertTrue( $v['any_accepting'] );
		$this->assertSame( 'test', $v['config']['captcha']['mode'] );
		$this->assertNotSame( '', $v['config']['captcha']['siteKey'] );
		$this->assertStringContainsString( 'momos/v1/', $v['config']['api'] );
		foreach ( CustomerPages::FORM_KEYS as $k ) {
			$this->assertNotSame( $k, $v['config']['i18n'][ $k ], "missing string {$k}" );
		}
		// Unknown branch: left empty, no error; malformed date/time ignored.
		$_GET['branch'] = 'ZZZ';
		$_GET['time']   = '7pm';
		$this->assertSame( [ 'branch' => '', 'date' => '', 'time' => '' ], CustomerPages::form_view( 'en' )['prefill'] );
		$json = CustomerPages::json( CustomerPages::form_view( 'en' )['config'] );
		$this->assertStringNotContainsString( '</script', $json );
		$this->assertStringNotContainsString( 'inbox@', $json, 'no recipient addresses in the public page' );
		$this->assertStringNotContainsString( '@momos.example', $json );
	}

	public function test_all_screen_strings_exist_in_six_languages(): void {
		$keys = array_unique( array_merge( CustomerPages::FORM_KEYS, CustomerPages::SUBMITTED_KEYS, CustomerPages::STATUS_KEYS, [ 'res.noscript', 'res.empty.none', 'status.no_js.list', 'res.label.special_consent' ] ) );
		foreach ( [ 'ja', 'en', 'ar', 'ur', 'hi', 'bn' ] as $lang ) {
			foreach ( $keys as $k ) {
				$this->assertTrue( \Momos\Kernel\I18n\Strings::has( $k, $lang ) || \Momos\Kernel\I18n\Strings::has( $k, 'en' ), "{$lang}: {$k}" );
			}
			foreach ( [ 'res.noscript', 'res.empty.none', 'status.history', 'status.cancel.deadline', 'res.next.title' ] as $k ) {
				$this->assertTrue( \Momos\Kernel\I18n\Strings::has( $k, $lang ), "{$lang}: {$k}" );
			}
		}
		// BR-RES-01: no booking wording in the new pending-context English strings.
		foreach ( [ 'res.noscript', 'res.empty.none', 'res.next.title', 'status.details', 'res.summary.empty' ] as $k ) {
			$this->assertDoesNotMatchRegularExpression( '/\bbooked\b|your reservation|reservation confirmed|booking confirmed/i', momos_t( $k, [], 'en' ) );
		}
	}

	public function test_status_reference_normalisation(): void {
		set_query_var( \Momos\Presentation\Templates\TemplateRouter::QV_REF, '7q2k-x9md' );
		$this->assertSame( '7Q2KX9MD', CustomerPages::status_ref() );
		set_query_var( \Momos\Presentation\Templates\TemplateRouter::QV_REF, 'oiL0-1234' );
		$this->assertSame( '0110' . '1234', CustomerPages::status_ref() );
		set_query_var( \Momos\Presentation\Templates\TemplateRouter::QV_REF, 'ABC' );
		$this->assertNull( CustomerPages::status_ref() );
		set_query_var( \Momos\Presentation\Templates\TemplateRouter::QV_REF, 'UUUU-UUUU' ); // U is not Crockford.
		$this->assertNull( CustomerPages::status_ref() );
		set_query_var( \Momos\Presentation\Templates\TemplateRouter::QV_REF, '' );
	}

	public function test_real_branch_hours_change_flags_conflicts_br_res_13(): void {
		$hit  = $this->make_reservation( $this->a, '2026-12-10', '21:00', [ 'status' => 'confirmed' ] );
		$safe = $this->make_reservation( $this->a, '2026-12-10', '12:00' );
		$past = $this->make_reservation( $this->a, '2026-11-30', '21:00', [ 'status' => 'confirmed' ] );
		// Branch shortens the evening (fires momos/branch/schedule_changed after commit).
		$this->set_week( $this->a, array_fill_keys( range( 1, 7 ), [ [ '11:00', '20:00', '19:30', 'all_day' ] ] ) );
		$this->assertSame( '1', (string) $this->row( $hit )['needs_action_flag'] );
		$this->assertSame( 'confirmed', $this->row( $hit )['status'], 'never auto-cancelled' );
		$this->assertSame( '0', (string) $this->row( $safe )['needs_action_flag'] );
		$this->assertSame( '0', (string) $this->row( $past )['needs_action_flag'], 'past reservations are not re-checked' );
		$this->assertSame( [ $hit ], array_column( $this->hooks_of( 'closure_conflict' ), 1 ) );
		$ev = array_values( array_filter( $this->events( $hit ), static fn( $e ) => 'closure_conflict' === $e['event_type'] ) );
		$this->assertCount( 1, $ev );
		$this->assertSame( 'system', $ev[0]['actor_type'] );
	}

	public function test_accepting_changed_hook_flags_all_future_active(): void {
		$p = $this->make_reservation( $this->a, '2026-12-03', '19:00' );
		$c = $this->make_reservation( $this->a, '2026-12-04', '19:00', [ 'status' => 'confirmed' ] );
		$x = $this->make_reservation( $this->a, '2026-12-04', '20:00', [ 'status' => 'cancelled', 'cancelled_by' => 'customer' ] );
		do_action( 'momos/branch/accepting_changed', $this->a, false, 'temporarily_closed' );
		$this->assertSame( '1', (string) $this->row( $p )['needs_action_flag'] );
		$this->assertSame( '1', (string) $this->row( $c )['needs_action_flag'] );
		$this->assertSame( '0', (string) $this->row( $x )['needs_action_flag'] );
		$this->assertSame( 'temporarily_closed', json_decode( (string) array_values( array_filter( $this->events( $p ), static fn( $e ) => 'closure_conflict' === $e['event_type'] ) )[0]['payload'], true )['trigger'] );
		$this->assertCount( 2, $this->hooks_of( 'closure_conflict' ) );
		// Re-opening never clears or adds flags.
		do_action( 'momos/branch/accepting_changed', $this->a, true, 'open' );
		$this->assertCount( 2, $this->hooks_of( 'closure_conflict' ) );
	}

	private function notification( int $reservation_id, string $status, string $dedupe ): int {
		global $wpdb;
		$wpdb->insert(
			Tables::notifications(),
			[ 'uuid' => wp_generate_uuid4(), 'dedupe_key' => $dedupe, 'template_key' => 'E01', 'lang' => 'en', 'recipient_type' => 'customer', 'recipient_email' => 'guest@example.org', 'recipient_hash' => str_repeat( 'b', 64 ), 'recipient_masked' => 'g***@e***.org', 'reservation_id' => $reservation_id, 'branch_id' => $this->a, 'status' => $status, 'attempts' => 'failed' === $status ? 5 : 1, 'last_error_code' => 'failed' === $status ? 'smtp_rejected_recipient' : null, 'last_error' => 'failed' === $status ? '550 mailbox unavailable' : null, 'created_at' => Clock::mysql_utc(), 'updated_at' => Clock::mysql_utc(), 'sent_at' => 'sent' === $status ? Clock::mysql_utc() : null, 'failed_at' => 'failed' === $status ? Clock::mysql_utc() : null ]
		);
		return (int) $wpdb->insert_id;
	}

	public function test_failure_then_resend_sent_resets_flag_and_logs_contact(): void {
		$id   = $this->make_reservation( $this->a, '2026-12-05', '19:00' );
		$fail = $this->notification( $id, 'failed', 'res:' . $id . ':E01' );
		do_action( 'momos/notification/failed', $fail, $id );
		$this->assertSame( '0', (string) $this->row( $id )['customer_notified_flag'] );
		$v    = (int) $this->row( $id )['version'];
		$sent = $this->notification( $id, 'sent', 'resend:' . $fail . ':1' );
		do_action( 'momos/notification/resend_sent', $sent, $id );
		$row = $this->row( $id );
		$this->assertSame( '1', (string) $row['customer_notified_flag'] );
		$this->assertSame( $v + 1, (int) $row['version'] );
		$ev = array_values( array_filter( $this->events( $id ), static fn( $e ) => 'customer_contacted' === $e['event_type'] ) );
		$this->assertCount( 1, $ev );
		$this->assertSame( 'system', $ev[0]['actor_type'] );
		$this->assertSame( [ 'via' => 'email_resend', 'notification_id' => $sent ], json_decode( (string) $ev[0]['payload'], true ) );
		$this->assertStringNotContainsString( 'example.org', (string) $ev[0]['payload'] );
	}

	public function test_api11_notifications_come_from_notification_panel(): void {
		$id   = $this->make_reservation( $this->a, '2026-12-05', '19:00' );
		$fail = $this->notification( $id, 'failed', 'res:' . $id . ':E01' );
		$rs   = $this->staff( 'momo_reservation_staff', [ $this->a ] );
		wp_set_current_user( $rs );
		$rows = Presenter::detail( $this->row( $id ), $rs )['notifications'];
		$this->assertCount( 1, $rows );
		$n = $rows[0];
		$this->assertSame( $fail, $n['id'] );
		$this->assertSame( 'E01', $n['template'] );
		$this->assertSame( 'en', $n['language'] );
		$this->assertSame( 'g***@e***.org', $n['recipient_masked'] );
		$this->assertTrue( $n['can_resend'] );
		$this->assertSame( 'smtp_rejected_recipient', $n['last_error'], 'branch staff never see raw provider text' );
		$this->assertArrayNotHasKey( 'recipient_email', $n );
		$this->assertMatchesRegularExpression( '/\+09:00$/', (string) $n['failed_at'] );
		$this->assertStringNotContainsString( 'guest@example.org', (string) wp_json_encode( $rows ) );
		wp_set_current_user( 0 );
	}

	public function test_detail_screen_fires_extension_hook_for_assigned_staff(): void {
		$id = $this->make_reservation( $this->a, '2026-12-05', '19:00' );
		$rs = $this->staff( 'momo_reservation_staff', [ $this->a ] );
		wp_set_current_user( $rs );
		$fired = [];
		if ( ! has_action( 'momos/admin/reservation_detail', [ \Momos\Notification\Admin\NotificationPanel::class, 'render' ] ) ) {
			\Momos\Notification\Admin\NotificationPanel::register(); // Registered on admin requests only.
		}
		add_action( 'momos/admin/reservation_detail', static function ( $rid ) use ( &$fired ): void { $fired[] = $rid; }, 5 );
		$_GET['id'] = (string) $id;
		set_current_screen( 'toplevel_page_momos-reservations' );
		ob_start();
		\Momos\Reservation\Admin\InboxPage::render();
		$html = (string) ob_get_clean();
		unset( $_GET['id'] );
		$this->assertSame( [ $id ], $fired );
		$this->assertStringContainsString( 'momos-notifications', $html, 'Notification panel rendered inside the detail screen' );
		wp_set_current_user( 0 );
	}

	public function test_integrity_check_reports_pending_past_start_only(): void {
		$stale = $this->make_reservation( $this->a, '2026-12-01', '08:30' ); // 1.5 h ago.
		$this->make_reservation( $this->a, '2026-12-01', '09:30' );          // 30 min ago: within grace.
		$this->make_reservation( $this->a, '2026-12-01', '08:00', [ 'status' => 'expired' ] );
		$logged = [];
		add_action( 'momos/log/critical', static function ( $event, $ctx ) use ( &$logged ): void { $logged[] = [ $event, $ctx ]; }, 10, 2 );
		$out = IntegrityJob::run();
		$this->assertSame( [ 'count' => 1, 'ids' => [ $stale ] ], $out );
		$this->assertSame( 'reservation.integrity.pending_past_start', $logged[0][0] ?? null );
		$this->assertSame( 'pending', $this->row( $stale )['status'], 'read-only check' );
		$this->assertTrue( (bool) has_action( IntegrityJob::HOOK ) );
	}
}
