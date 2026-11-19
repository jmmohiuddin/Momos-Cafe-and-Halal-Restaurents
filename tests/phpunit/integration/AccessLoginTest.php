<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/AccessTestCaseTest.php';

use Momos\Access\Login\LoginThrottle;
use Momos\Access\Login\PasswordPolicy;
use Momos\Access\Session\SessionPolicy;
use Momos\Access\TwoFactor\Challenge;
use Momos\Access\TwoFactor\LoginFlow;
use Momos\Access\TwoFactor\QrCode;
use Momos\Access\TwoFactor\Totp;
use Momos\Access\TwoFactor\TwoFactor;
use Momos\Kernel\Clock\Clock;

/**
 * TOTP 2FA, throttling, password and session policy (SEC-001/003/004/005, FR-SEC-001/002,
 * FR-ADM-006; TC-SEC-001/002/019/023/024, TC-ADM-004, AC-SEC-01-01/02).
 */
final class AccessLoginTest extends AccessTestCaseTest {
	public function test_totp_rfc6238_vectors_and_window(): void {
		$s = '12345678901234567890';
		$this->assertSame( '287082', Totp::code( $s, Totp::step( 59 ) ) );
		$this->assertSame( '005924', Totp::code( $s, Totp::step( 1234567890 ) ) );
		$this->assertSame( '279037', Totp::code( $s, Totp::step( 2000000000 ) ) );
		$t = 1234567890;
		$this->assertSame( Totp::step( $t ) - 1, Totp::verify( $s, Totp::code( $s, Totp::step( $t ) - 1 ), $t ) );
		$this->assertNull( Totp::verify( $s, Totp::code( $s, Totp::step( $t ) - 2 ), $t ), 'outside ±1 window' );
		$this->assertNull( Totp::verify( $s, Totp::code( $s, Totp::step( $t ) ), $t, Totp::step( $t ) ), 'replay rejected' );
		$this->assertSame( $s, Totp::base32_decode( Totp::base32_encode( $s ) ) );
		$this->assertStringStartsWith( 'otpauth://totp/Momo%27s:staff1?secret=', Totp::uri( $s, 'staff1', "Momo's" ) );
	}

	public function test_secret_encryption_roundtrip(): void {
		$secret = Totp::new_secret();
		$enc    = TwoFactor::encrypt( $secret );
		$this->assertStringStartsWith( 'v1:', $enc );
		$this->assertStringNotContainsString( Totp::base32_encode( $secret ), $enc );
		$this->assertSame( $secret, TwoFactor::decrypt( $enc ) );
		$this->assertNull( TwoFactor::decrypt( 'v1:' . base64_encode( str_repeat( 'x', 40 ) ) ) );
	}

	public function test_qr_svg_is_local_and_well_formed(): void {
		$svg = QrCode::svg( Totp::uri( Totp::new_secret(), 'staff1', "Momo's Cafe" ) );
		$this->assertStringStartsWith( '<svg xmlns="http://www.w3.org/2000/svg"', $svg );
		$this->assertStringNotContainsString( 'http://', str_replace( 'http://www.w3.org/2000/svg', '', $svg ) );
		$m = QrCode::matrix( 'hello' );
		$this->assertCount( 21, $m, 'version 1' );
		$this->assertTrue( $m[0][0] && $m[0][6] && ! $m[1][1], 'finder pattern' );
	}

	public function test_enrolment_flow_recovery_codes_and_login_tc_sec_001(): void {
		$this->freeze( '2026-11-18 10:00:00' );
		$uid  = $this->user_with_role( 'momo_reservation_staff' );
		$tok  = Challenge::create( $uid, '', true );
		$chal = Challenge::get( $uid, $tok );
		$this->assertNotNull( $chal );
		$this->assertSame( 'enrol', $chal['stage'] );
		$this->assertNull( Challenge::get( $uid, str_repeat( 'a', 64 ) ), 'wrong token' );
		$secret = TwoFactor::decrypt( $chal['pending'] );
		$now    = Clock::utc()->getTimestamp();
		// Wrong code counts as a failure.
		$r = LoginFlow::process( $uid, $chal, 'enrol', [ 'momos_code' => '000000' === Totp::code( $secret, Totp::step( $now ) ) ? '111111' : '000000' ] );
		$this->assertSame( 'enrol', $r['state'] );
		$this->assertSame( 'admin.2fa.err.invalid', $r['error'] );
		$this->assertFalse( TwoFactor::is_enrolled( $uid ) );
		// Correct code → 10 recovery codes shown once.
		$chal = Challenge::get( $uid, $tok );
		$r    = LoginFlow::process( $uid, $chal, 'enrol', [ 'momos_code' => Totp::code( $secret, Totp::step( $now ) ) ] );
		$this->assertSame( 'codes', $r['state'] );
		$this->assertCount( 10, $r['codes'] );
		$this->assertMatchesRegularExpression( '/^[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$/', $r['codes'][0] );
		$this->assertTrue( TwoFactor::is_enrolled( $uid ) );
		$this->assertStringNotContainsString( $r['codes'][0], (string) get_user_meta( $uid, TwoFactor::META_RECOVERY, true ), 'hashed' );
		// Must confirm "I have saved these codes".
		$chal = Challenge::get( $uid, $tok );
		$this->assertSame( 'codes', LoginFlow::process( $uid, $chal, 'enrol_done', [] )['state'] );
		$this->assertSame( 'done', LoginFlow::process( $uid, Challenge::get( $uid, $tok ), 'enrol_done', [ 'momos_saved' => '1' ] )['state'] );
		$this->assertNotEmpty( $this->audit_rows( 'user.2fa_enrolled' ) );
		// Later login: TOTP code (replay of the enrolment step rejected), then recovery code single use.
		$tok2 = Challenge::create( $uid, '/wp-admin/admin.php?page=momos-reservations&id=5', false );
		$c2   = Challenge::get( $uid, $tok2 );
		$this->assertSame( 'code', $c2['stage'] );
		$this->assertSame( 'code', LoginFlow::process( $uid, $c2, 'verify', [ 'momos_code' => Totp::code( $secret, Totp::step( $now ) ) ] )['state'], 'replay' );
		$this->freeze( '2026-11-18 10:00:30' );
		$fresh = Totp::code( $secret, Totp::step( Clock::utc()->getTimestamp() ) );
		$this->assertSame( 'done', LoginFlow::process( $uid, Challenge::get( $uid, $tok2 ), 'verify', [ 'momos_code' => $fresh ] )['state'] );
		// Recovery code: works once.
		$codes = TwoFactor::new_recovery_codes( $uid );
		$this->assertTrue( TwoFactor::use_recovery_code( $uid, strtolower( $codes[3] ) ) );
		$this->assertFalse( TwoFactor::use_recovery_code( $uid, $codes[3] ) );
		$this->assertSame( 9, TwoFactor::recovery_remaining( $uid ) );
		$this->assertNotEmpty( $this->audit_rows( 'user.2fa_recovery_used' ) );
	}

	public function test_five_wrong_codes_restart_and_count_as_failures(): void {
		$this->freeze( '2026-11-18 10:00:00' );
		$uid = $this->user_with_role( 'momo_branch_manager' );
		update_user_meta( $uid, TwoFactor::META_SECRET, TwoFactor::encrypt( Totp::new_secret() ) );
		update_user_meta( $uid, TwoFactor::META_ENROLLED, '2026-11-01 00:00:00' );
		$tok = Challenge::create( $uid, '', false );
		for ( $i = 1; $i <= 4; $i++ ) {
			$this->assertSame( 'code', LoginFlow::process( $uid, Challenge::get( $uid, $tok ), 'verify', [ 'momos_code' => 'abcdef' ] )['state'] );
		}
		$this->assertSame( 'restart', LoginFlow::process( $uid, Challenge::get( $uid, $tok ), 'verify', [ 'momos_code' => 'abcdef' ] )['state'] );
		$this->assertNull( Challenge::get( $uid, $tok ) );
		$this->assertCount( 5, $this->audit_rows( 'auth.login_failed' ) );
	}

	public function test_challenge_expires_after_five_minutes(): void {
		$this->freeze( '2026-11-18 10:00:00' );
		$uid = $this->user_with_role( 'momo_branch_manager' );
		$tok = Challenge::create( $uid, '', true );
		$this->freeze( '2026-11-18 10:05:01' );
		$this->assertNull( Challenge::get( $uid, $tok ) );
	}

	public function test_complete_login_flags_session_and_records_login(): void {
		$uid = $this->user_with_role( 'momo_reservation_staff' );
		LoginFlow::complete_login( get_userdata( $uid ) );
		$sessions = \WP_Session_Tokens::get_instance( $uid )->get_all();
		$this->assertCount( 1, $sessions );
		$this->assertSame( 1, reset( $sessions )['momo_2fa'] );
		$this->assertNotSame( '', get_user_meta( $uid, 'momo_last_login_at', true ) );
		$this->assertNotEmpty( $this->audit_rows( 'auth.login_succeeded' ) );
		$this->assertSame( admin_url( 'admin.php?page=momos-reservations&status=pending' ), LoginFlow::login_redirect( admin_url(), '', get_userdata( $uid ) ) );
		$this->assertSame( '/x', LoginFlow::login_redirect( '/x', '/x', get_userdata( $uid ) ), 'explicit redirect_to wins' );
	}

	public function test_gate_requires_second_factor_when_enforced_tc_adm_004(): void {
		$uid = $this->user_with_role( 'momo_head_office' );
		$this->login_session( $uid );
		$this->assertNull( SessionPolicy::check_current(), 'not required in development' );
		update_user_meta( $uid, TwoFactor::META_OPTIN, '1' );
		$this->assertSame( '2fa', SessionPolicy::check_current(), 'session without 2FA flag refused' );
		$err = SessionPolicy::enforce_rest( null );
		$this->assertWPError( $err );
		$this->assertSame( 401, $err->get_error_data()['status'] );
		$this->assertCount( 0, \WP_Session_Tokens::get_instance( $uid )->get_all() );
		$this->login_session( $uid, [ 'momo_2fa' => 1 ] );
		$this->assertNull( SessionPolicy::check_current() );
	}

	public function test_idle_timeout_and_absolute_lifetime_tc_sec_024(): void {
		$this->assertSame( 14 * HOUR_IN_SECONDS, apply_filters( 'auth_cookie_expiration', 2 * DAY_IN_SECONDS, 1, true ) );
		$this->freeze( '2026-11-18 10:00:00' );
		$uid = $this->user_with_role( 'momo_reservation_staff' );
		$this->login_session( $uid );
		$this->freeze( '2026-11-18 17:59:00' );
		$this->assertNull( SessionPolicy::check_current() );
		$this->freeze( '2026-11-19 01:58:00' );
		$this->assertNull( SessionPolicy::check_current(), 'activity refreshed at 17:59' );
		$this->freeze( '2026-11-19 10:00:01' );
		$this->assertSame( 'idle', SessionPolicy::check_current() );
	}

	public function test_lockout_after_ten_failures_tc_sec_002(): void {
		$uid   = $this->user_with_role( 'momo_reservation_staff' );
		$login = get_userdata( $uid )->user_login;
		wp_set_password( 'Correct-Horse-Battery-9', $uid );
		for ( $i = 0; $i < 9; $i++ ) {
			$this->assertWPError( wp_authenticate( $login, 'nope-nope-nope' ) );
		}
		$this->assertNull( LoginThrottle::locked_until( $uid, null ) );
		$this->assertWPError( wp_authenticate( $login, 'nope-nope-nope' ) );
		$this->assertNotNull( LoginThrottle::locked_until( $uid, null ), 'account locked' );
		$this->assertNotNull( LoginThrottle::locked_until( null, '203.0.113.77' ), 'IP locked' );
		$res = wp_authenticate( $login, 'Correct-Horse-Battery-9' );
		$this->assertWPError( $res, 'correct password refused while locked' );
		$this->assertSame( 'momos_locked', $res->get_error_code() );
		$this->assertStringContainsString( 'JST', $res->get_error_message() );
		$this->assertNotEmpty( $this->audit_rows( 'auth.lockout' ) );
		// Account lock applies from any IP.
		$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
		$this->assertSame( 'momos_locked', wp_authenticate( $login, 'Correct-Horse-Battery-9' )->get_error_code() );
		// No typed username in the audit log.
		foreach ( $this->audit_rows( 'auth.login_failed' ) as $row ) {
			$this->assertStringNotContainsString( $login, (string) $row['changes'] );
			$this->assertSame( '203.0.113.0/24', $row['ip_truncated'] );
		}
		// After 60 minutes the lock is gone.
		$this->freeze( wp_date( 'Y-m-d H:i:s', time() + 3700, new \DateTimeZone( 'Asia/Tokyo' ) ) );
		$this->assertNull( LoginThrottle::locked_until( $uid, '198.51.100.9' ) );
	}

	public function test_unknown_username_locks_ip_and_errors_are_generic(): void {
		$_SERVER['REMOTE_ADDR'] = '192.0.2.55';
		$this->assertWPError( wp_authenticate( 'no-such-user', 'whatever-123' ) );
		$this->assertNotNull( LoginThrottle::locked_until( null, '192.0.2.55' ) );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_REQUEST['action']        = 'login';
		LoginThrottle::reset_state();
		$this->assertSame( LoginThrottle::generic_message(), html_entity_decode( LoginThrottle::login_errors( '<strong>Error:</strong> The username is not registered' ), ENT_QUOTES ) );
		$this->assertSame( LoginThrottle::generic_message(), html_entity_decode( LoginThrottle::login_errors( 'The password you entered is incorrect' ), ENT_QUOTES ) );
		unset( $_REQUEST['action'] );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		\Momos\Kernel\Settings::set( 'momo_login_lock_invalid_username', '0' );
		$_SERVER['REMOTE_ADDR'] = '192.0.2.56';
		wp_authenticate( 'no-such-user', 'whatever-123' );
		$this->assertNull( LoginThrottle::locked_until( null, '192.0.2.56' ) );
	}

	public function test_password_policy_tc_sec_023(): void {
		$this->assertSame( 'admin.pw.err.length', PasswordPolicy::check( str_repeat( 'a', 5 ) . 'bcdef' ) );
		$this->assertSame( 'admin.pw.err.length', PasswordPolicy::check( 'Abcdefghij1' ) );
		$this->assertSame( 'admin.pw.err.length', PasswordPolicy::check( str_repeat( 'Ab1', 43 ) ) );
		$this->assertNull( PasswordPolicy::check( 'Abcdefghij12' ) );
		$this->assertSame( 'admin.pw.err.breached', PasswordPolicy::check( 'Password1234' ) );
		$this->assertSame( 'admin.pw.err.breached', PasswordPolicy::check( 'zzzzzzzzzzzzzz' ) );
		$this->assertSame( HOUR_IN_SECONDS, apply_filters( 'password_reset_expiration', DAY_IN_SECONDS ) );
		$_POST['pass1'] = 'short';
		$e              = new \WP_Error();
		PasswordPolicy::on_profile_errors( $e, true, new \stdClass() );
		unset( $_POST['pass1'] );
		$this->assertSame( esc_html( momos_t( 'admin.pw.err.length', [], LoginThrottle::lang() ) ), $e->get_error_message( 'momos_password' ) );
	}

	public function test_password_reset_destroys_sessions(): void {
		$uid = $this->user_with_role( 'momo_reservation_staff' );
		$this->login_session( $uid );
		do_action( 'after_password_reset', get_userdata( $uid ), 'New-Password-123' );
		$this->assertCount( 0, \WP_Session_Tokens::get_instance( $uid )->get_all() );
		$this->assertNotEmpty( $this->audit_rows( 'auth.password_reset' ) );
	}

	public function test_hardening_switches(): void {
		$this->assertFalse( wp_is_application_passwords_available() );
		$this->assertFalse( apply_filters( 'xmlrpc_enabled', true ) );
		$this->assertSame( '0', (string) get_option( 'users_can_register' ) );
		$this->assertFalse( comments_open( self::factory()->post->create() ) );
		$this->assertArrayNotHasKey( 'X-Pingback', apply_filters( 'wp_headers', [ 'X-Pingback' => 'x' ] ) );
		$health = \Momos\Access\Hardening::health_file_editing();
		$this->assertSame( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ? 'good' : 'critical', $health['status'] );
	}

	/**
	 * FR-SEC-004 / SEC-040 (spec §9.1): the production wp-config template locks editing, installs and auto-updates,
	 * forces SSL for admin and hides debug output; Hardening keeps auto-updates off and reports the file-edit/
	 * file-mods state in Site Health (file mods read through wp_is_file_mod_allowed()).
	 */
	public function test_fr_sec_004_production_config_and_site_health(): void {
		$sample = (string) file_get_contents( dirname( __DIR__, 3 ) . '/config/wp-config.production.sample.php' );
		$expect = [
			'DISALLOW_FILE_EDIT'         => 'true',
			'DISALLOW_FILE_MODS'         => 'true',
			'AUTOMATIC_UPDATER_DISABLED' => 'true',
			'FORCE_SSL_ADMIN'            => 'true',
			'WP_DEBUG_DISPLAY'           => 'false',
			'WP_DEBUG'                   => 'false',
		];
		foreach ( $expect as $const => $value ) {
			$this->assertMatchesRegularExpression( '/^define\(\s*\'' . $const . '\',\s*' . $value . '\s*\);/m', $sample, $const . ' must be ' . $value . ' in production' );
			$this->assertSame( 1, preg_match_all( '/define\(\s*\'' . $const . '\'/', $sample ), $const . ' is defined once' );
		}

		foreach ( [ 'auto_update_core', 'auto_update_plugin', 'auto_update_theme' ] as $hook ) {
			$this->assertFalse( apply_filters( $hook, true, (object) [] ), $hook ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hooks under test.
		}

		$tests = apply_filters( 'site_status_tests', [ 'direct' => [], 'async' => [] ] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook under test.
		$this->assertSame( [ \Momos\Access\Hardening::class, 'health_file_editing' ], $tests['direct']['momos_file_editing']['test'] );

		$edit   = defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT;
		$health = \Momos\Access\Hardening::health_file_editing();
		$this->assertStringContainsString( '<code>DISALLOW_FILE_EDIT</code> = ' . ( $edit ? 'true' : 'false' ), $health['description'] );
		$this->assertStringContainsString( '<code>DISALLOW_FILE_MODS</code> = ' . ( wp_is_file_mod_allowed( 'momos_site_health' ) ? 'false' : 'true' ), $health['description'] );

		add_filter( 'file_mod_allowed', '__return_false' );
		$locked = \Momos\Access\Hardening::health_file_editing();
		remove_filter( 'file_mod_allowed', '__return_false' );
		$this->assertStringContainsString( '<code>DISALLOW_FILE_MODS</code> = true', $locked['description'], 'file mods state comes from wp_is_file_mod_allowed()' );
		$this->assertSame( $edit ? 'good' : 'critical', $locked['status'] );
	}
}
