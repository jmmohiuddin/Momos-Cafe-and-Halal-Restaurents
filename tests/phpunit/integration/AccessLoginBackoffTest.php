<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/AccessTestCaseTest.php';

use Momos\Access\Login\LoginThrottle;
use Momos\Kernel\Clock\Clock;

/**
 * FR-SEC-002 progressive delay and AC-SEC-01-02 lockout alert (Doc 11 SEC-004, TC-SEC-002):
 *  - from the 5th failure per account or per IP the next attempt is refused with the generic error until
 *    2^(n-5) s after the last failure — even with the correct password — and no PHP sleep is used;
 *  - the hard lock at 10 failures is unchanged;
 *  - a lock of ANY account sends the ROLE-01 alert (one mail per account per hour); administrator/Head Office
 *    locks additionally escalate as `privileged_lockout`.
 */
final class AccessLoginBackoffTest extends AccessTestCaseTest {
	/** @var list<array{type:string,context:array<string,mixed>}> */
	private array $alerts = [];

	/** @var list<array<string,mixed>> */
	private array $mails = [];

	public function set_up(): void {
		parent::set_up();
		$this->alerts = [];
		$this->mails  = [];
		add_action( 'momos/monitoring/alert', [ $this, 'record_alert' ], 1, 2 );
		add_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 1, 2 );
		update_option( 'momo_alert_recipients', 'it-owner@example.test' );
	}

	public function tear_down(): void {
		remove_action( 'momos/monitoring/alert', [ $this, 'record_alert' ], 1 );
		remove_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 1 );
		parent::tear_down();
	}

	/** @param array<string,mixed> $context */
	public function record_alert( string $type, $context = [] ): void {
		$this->alerts[] = [ 'type' => $type, 'context' => (array) $context ];
	}

	/**
	 * @param null|bool           $short
	 * @param array<string,mixed> $atts
	 */
	public function capture_mail( $short, array $atts ): bool {
		if ( str_contains( (string) ( $atts['subject'] ?? '' ), 'lockout' ) ) {
			$this->mails[] = $atts; // Lockout alerts only (the site-wide failed_logins alert is separate).
		}
		return true;
	}

	private function staff( string $role = 'momo_reservation_staff' ): array {
		$uid = $this->user_with_role( $role );
		wp_set_password( 'Correct-Horse-Battery-9', $uid );
		delete_transient( 'momos_alert_account_lockout_' . $uid );
		delete_transient( 'momos_alert_privileged_lockout' );
		return [ $uid, get_userdata( $uid )->user_login ];
	}

	private function advance( int $seconds ): void {
		$this->freeze( Clock::utc()->modify( '+' . $seconds . ' seconds' )->setTimezone( new \DateTimeZone( 'Asia/Tokyo' ) )->format( 'Y-m-d H:i:s' ) );
	}

	public function test_backoff_schedule_is_exponential_and_capped(): void {
		$this->assertSame( 0, LoginThrottle::backoff_seconds( 4 ) );
		$this->assertSame( [ 1, 2, 4, 8, 16, 30, 30 ], array_map( [ LoginThrottle::class, 'backoff_seconds' ], [ 5, 6, 7, 8, 9, 10, 40 ] ) );
	}

	public function test_progressive_delay_after_five_failures_per_account_fr_sec_002(): void {
		$this->freeze( '2026-11-20 10:00:00' );
		[ $uid, $login ] = $this->staff();
		for ( $i = 0; $i < 4; $i++ ) {
			$this->assertSame( 'incorrect_password', wp_authenticate( $login, 'nope-nope-nope' )->get_error_code(), "failure {$i} is a plain password error" );
			$this->advance( 1 );
		}
		$this->assertNull( LoginThrottle::backoff_until( $uid, null ), 'no delay before the 5th failure' );
		$this->assertWPError( wp_authenticate( $login, 'nope-nope-nope' ) ); // 5th failure.
		$this->assertSame( Clock::utc()->getTimestamp() + 1, LoginThrottle::backoff_until( $uid, null ) );

		// Inside the window: refused with the generic message even with the correct password.
		$res = wp_authenticate( $login, 'Correct-Horse-Battery-9' );
		$this->assertWPError( $res );
		$this->assertSame( 'momos_throttled', $res->get_error_code() );
		$this->assertSame( LoginThrottle::generic_message(), $res->get_error_message() );
		$rows = $this->audit_rows( 'auth.login_failed' );
		$this->assertSame( 'throttled', json_decode( (string) $rows[ count( $rows ) - 1 ]['changes'], true )['reason'] ?? null );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_REQUEST['action']        = 'login';
		$this->assertSame( LoginThrottle::generic_message(), html_entity_decode( LoginThrottle::login_errors( '<strong>Error:</strong> x' ), ENT_QUOTES ), 'same generic error on the form' );
		unset( $_REQUEST['action'] );
		$_SERVER['REQUEST_METHOD'] = 'GET';

		// The refused attempt counted (6 failures) → the window doubles to 2 s.
		$this->assertSame( Clock::utc()->getTimestamp() + 2, LoginThrottle::backoff_until( $uid, null ) );
		$this->advance( 1 );
		$this->assertSame( 'momos_throttled', wp_authenticate( $login, 'Correct-Horse-Battery-9' )->get_error_code(), 'still inside the 2 s window' );
		// 7 failures → 4 s; after waiting it out, the correct password works and clears the account bucket.
		$this->advance( 4 );
		$this->assertNull( LoginThrottle::backoff_until( $uid, null ) );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.20'; // A fresh IP: only the account bucket matters here.
		$ok = wp_authenticate( $login, 'Correct-Horse-Battery-9' );
		$this->assertInstanceOf( \WP_User::class, $ok );
		LoginThrottle::success( $uid );
		$this->assertNull( LoginThrottle::backoff_until( $uid, null ) );
		$this->assertNull( LoginThrottle::locked_until( $uid, null ), 'no hard lock below 10 failures' );
	}

	public function test_progressive_delay_per_ip_hash_and_other_ips_unaffected(): void {
		$this->freeze( '2026-11-20 11:00:00' );
		[ , $login_a ] = $this->staff();
		[ , $login_b ] = $this->staff();
		$_SERVER['REMOTE_ADDR'] = '192.0.2.70';
		foreach ( [ $login_a, $login_b, $login_a, $login_b, $login_a ] as $login ) {
			$this->assertWPError( wp_authenticate( $login, 'wrong-password-xyz' ) );
		}
		$this->assertNotNull( LoginThrottle::backoff_until( null, '192.0.2.70' ), '5 failures from one IP across accounts' );
		$this->assertSame( 'momos_throttled', wp_authenticate( $login_b, 'Correct-Horse-Battery-9' )->get_error_code() );
		// Another IP is not delayed for account B (only 2 failures on that account).
		$_SERVER['REMOTE_ADDR'] = '198.51.100.21';
		$this->assertInstanceOf( \WP_User::class, wp_authenticate( $login_b, 'Correct-Horse-Battery-9' ) );
		// Raw IPs are never stored: the bucket is keyed by a hash.
		global $wpdb;
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s OR option_value LIKE %s", '%192.0.2.70%', '%192.0.2.70%' ) ) );
	}

	public function test_empty_form_submission_is_not_throttled_or_counted(): void {
		$this->freeze( '2026-11-20 12:00:00' );
		[ $uid, $login ] = $this->staff();
		for ( $i = 0; $i < 5; $i++ ) {
			wp_authenticate( $login, 'nope-nope-nope' );
		}
		$before = count( $this->audit_rows( 'auth.login_failed' ) );
		$this->assertSame( 'empty_password', wp_authenticate( $login, '' )->get_error_code() );
		$this->assertCount( $before, $this->audit_rows( 'auth.login_failed' ) );
		$this->assertNull( LoginThrottle::locked_until( $uid, null ) );
	}

	public function test_lockout_of_any_account_alerts_role_01_once_per_account_ac_sec_01_02(): void {
		$this->freeze( '2026-11-20 13:00:00' );
		[ $uid, $login ] = $this->staff();
		for ( $i = 0; $i < 10; $i++ ) {
			wp_authenticate( $login, 'nope-nope-nope' ); // Rapid attempts: refused ones still count.
		}
		$this->assertNotNull( LoginThrottle::locked_until( $uid, null ), 'hard lock at 10 failures as before' );
		$this->assertSame( 'momos_locked', wp_authenticate( $login, 'Correct-Horse-Battery-9' )->get_error_code() );
		$types = array_column( $this->alerts, 'type' );
		$this->assertContains( 'account_lockout_' . $uid, $types );
		$this->assertNotContains( 'privileged_lockout', $types, 'staff account: no privileged escalation' );
		$alert = $this->alerts[ array_search( 'account_lockout_' . $uid, $types, true ) ];
		$this->assertSame( $uid, $alert['context']['user_id'] );
		$this->assertStringNotContainsString( $login, (string) wp_json_encode( $alert['context'] ), 'no username (PII) in the alert' );
		$this->assertCount( 1, $this->mails, 'one alert mail to ROLE-01' );
		$this->assertSame( [ 'it-owner@example.test' ], (array) $this->mails[0]['to'] );
		$this->assertStringContainsString( 'account_lockout_' . $uid, (string) $this->mails[0]['subject'] );

		// Throttled per user: the same account locked again within the hour → no second mail…
		delete_transient( 'momos_lt_user_' . substr( \Momos\Kernel\Support\Hash::hmac( 'rl|' . $uid ), 0, 32 ) );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.30';
		for ( $i = 0; $i < 10; $i++ ) {
			wp_authenticate( $login, 'nope-nope-nope' );
		}
		$this->assertCount( 1, $this->mails, 'same account within the hour: throttled' );
		// …but another account's lockout is still mailed.
		[ $uid2, $login2 ] = $this->staff( 'momo_branch_manager' );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.31';
		for ( $i = 0; $i < 10; $i++ ) {
			wp_authenticate( $login2, 'nope-nope-nope' );
		}
		$this->assertCount( 2, $this->mails );
		$this->assertStringContainsString( 'account_lockout_' . $uid2, (string) $this->mails[1]['subject'] );
	}

	public function test_privileged_lockout_keeps_the_escalation(): void {
		$this->freeze( '2026-11-20 14:00:00' );
		[ $uid, $login ] = $this->staff( 'momo_head_office' );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.40';
		for ( $i = 0; $i < 10; $i++ ) {
			wp_authenticate( $login, 'nope-nope-nope' );
		}
		$types = array_column( $this->alerts, 'type' );
		$this->assertContains( 'account_lockout_' . $uid, $types );
		$this->assertContains( 'privileged_lockout', $types );
	}
}
