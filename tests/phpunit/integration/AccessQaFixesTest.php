<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/AccessTestCaseTest.php';

use Momos\Access\AccountStatus;
use Momos\Access\Admin\ProfileGuard;
use Momos\Access\Admin\UsersList;
use Momos\Access\Login\LoginCaptcha;
use Momos\Access\Login\LoginLanguage;
use Momos\Access\Login\LoginThrottle;
use Momos\Access\Roles\RoleInstaller;
use Momos\Access\Session\SessionPolicy;
use Momos\Access\TwoFactor\LoginFlow;
use Momos\Admin\Dashboard\Widgets;
use Momos\Audit\Admin\AuditLogScreen;

/**
 * Regression tests for the access-audit-admin QA findings (QA-001, -008, -022, -037, -038, -039,
 * -041, -042, -064, -071, -072, -074, -078). Run:
 * MOMOS_TEST_DB_NAME=<db>_test vendor/bin/phpunit --filter AccessQaFixesTest
 */
final class AccessQaFixesTest extends AccessTestCaseTest {
	private ?string $method = null;

	public function set_up(): void {
		parent::set_up();
		$this->method = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : null;
	}

	public function tear_down(): void {
		$_POST    = [];
		$_GET     = [];
		$_REQUEST = [];
		if ( null === $this->method ) {
			unset( $_SERVER['REQUEST_METHOD'] );
		} else {
			$_SERVER['REQUEST_METHOD'] = $this->method;
		}
		unset( $_COOKIE['wp_lang'] );
		$GLOBALS['pagenow'] = 'index.php';
		LoginCaptcha::reset_state();
		\Momos\Kernel\I18n\Locale::reset();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function vendor( string $role = 'administrator' ): int {
		$v = $this->user_with_role( $role );
		update_user_meta( $v, AccountStatus::META_VENDOR, '1' );
		RoleInstaller::apply_user_denies( $v );
		clean_user_cache( $v );
		return $v;
	}

	// ---------------------------------------------------------------- QA-001 vendor denies (TC-ACL-022)

	public function test_qa001_vendor_holds_no_reservation_processing_or_notification_cap_and_breakglass_keeps_them_denied(): void {
		RoleInstaller::sync();
		$v = $this->vendor();
		foreach ( [ 'momo_view_reservations', 'momo_process_reservations', 'momo_view_customer_pii', 'momo_view_notification_log', 'momo_export_reservations', 'momo_manage_dsr' ] as $cap ) {
			$this->assertFalse( user_can( $v, $cap ), "vendor must not hold {$cap}" );
		}
		$this->assertTrue( user_can( $v, 'manage_options' ), 'other administrator caps kept' );
		$rid = $this->reservation( $this->branch( 'QVA' ) );
		$this->assertFalse( user_can( $v, 'momo_process_reservation', $rid ) );

		update_user_meta( $v, AccountStatus::META_BG_UNTIL, gmdate( 'Y-m-d H:i:s', time() + 3600 ) );
		$this->freeze( wp_date( 'Y-m-d H:i:s', time(), new \DateTimeZone( 'Asia/Tokyo' ) ) );
		RoleInstaller::apply_user_denies( $v );
		$u = new \WP_User( $v );
		$this->assertTrue( $u->has_cap( 'momo_view_reservations' ), 'break-glass lifts view' );
		$this->assertTrue( $u->has_cap( 'momo_view_customer_pii' ), 'break-glass lifts PII' );
		foreach ( [ 'momo_process_reservations', 'momo_view_notification_log', 'momo_export_reservations', 'momo_manage_dsr' ] as $cap ) {
			$this->assertFalse( $u->has_cap( $cap ), "break-glass keeps {$cap} denied" );
		}
		// Non-vendor administrators keep everything (the denies are removed when the flag goes).
		delete_user_meta( $v, AccountStatus::META_VENDOR );
		RoleInstaller::apply_user_denies( $v );
		$this->assertTrue( ( new \WP_User( $v ) )->has_cap( 'momo_process_reservations' ) );
	}

	public function test_qa001_processing_meta_cap_requires_the_view_primitive(): void {
		RoleInstaller::sync();
		$b   = $this->branch( 'QVB' );
		$rid = $this->reservation( $b );
		$u   = $this->user_with_role( 'momo_reservation_staff', [ $b ] );
		$this->assertTrue( user_can( $u, 'momo_process_reservation', $rid ) );
		// Access rule M8 (MetaCapMapper itself; the Reservation module's own map_meta_cap callback at the
		// same priority currently replaces the result — cross-module request filed).
		$mapped = \Momos\Access\MetaCapMapper::map( [ 'momo_process_reservation' ], 'momo_process_reservation', $u, [ $rid ] );
		$this->assertSame( [ 'momo_view_reservations', 'momo_process_reservations' ], $mapped, 'process requires the view primitive too' );
		$this->assertSame( [ 'momo_view_reservations' ], \Momos\Access\MetaCapMapper::map( [], 'momo_view_reservation', $u, [ $rid ] ) );
	}

	// ---------------------------------------------------------------- QA-064 vendor cannot mint PII accounts

	public function test_qa064_vendor_cannot_grant_or_take_over_pii_roles(): void {
		RoleInstaller::sync();
		$v  = $this->vendor();
		$ho = $this->user_with_role( 'momo_head_office' );
		$mm = $this->user_with_role( 'momo_menu_manager' );
		wp_set_current_user( $v );
		$roles = array_keys( get_editable_roles() );
		foreach ( [ 'administrator', 'momo_head_office', 'momo_branch_manager', 'momo_reservation_staff' ] as $r ) {
			$this->assertNotContains( $r, $roles, "vendor may not assign {$r}" );
		}
		$this->assertContains( 'momo_menu_manager', $roles, 'non-PII roles stay assignable' );
		$this->assertFalse( current_user_can( 'edit_user', $ho ), 'vendor cannot edit (take over) a PII-holding account' );
		$this->assertFalse( current_user_can( 'promote_user', $ho ) );
		$this->assertTrue( current_user_can( 'edit_user', $mm ), 'vendor can still edit non-PII staff' );
		$this->assertTrue( current_user_can( 'edit_user', $v ), 'own profile' );

		$owner = $this->user_with_role( 'administrator' );
		wp_set_current_user( $owner );
		$this->assertContains( 'momo_head_office', array_keys( get_editable_roles() ), 'non-vendor SA unaffected' );
		$this->assertTrue( current_user_can( 'edit_user', $ho ) );
	}

	// ---------------------------------------------------------------- QA-008 expiry ends the live session

	public function test_qa008_expired_access_window_ends_the_current_session_and_all_others(): void {
		$u = $this->user_with_role( 'momo_reservation_staff' );
		update_user_meta( $u, AccountStatus::META_EXPIRES, gmdate( 'Y-m-d H:i:s', time() + 3600 ) );
		$this->login_session( $u, [ 'momo_2fa' => 1 ] );
		\WP_Session_Tokens::get_instance( $u )->create( time() + 3600 ); // Second device.
		$this->assertNull( SessionPolicy::check_current() );
		update_user_meta( $u, AccountStatus::META_EXPIRES, gmdate( 'Y-m-d H:i:s', time() - 30 ) );
		$this->assertSame( 'inactive', SessionPolicy::check_current() );
		$res = SessionPolicy::enforce_rest( null );
		$this->assertInstanceOf( \WP_Error::class, $res );
		$this->assertSame( 401, $res->get_error_data()['status'] ?? null );
		$this->assertSame( [], \WP_Session_Tokens::get_instance( $u )->get_all(), 'every session destroyed' );
	}

	// ---------------------------------------------------------------- QA-038 role landing page

	public function test_qa038_core_default_redirect_to_is_not_an_explicit_target(): void {
		$rs = get_userdata( $this->user_with_role( 'momo_reservation_staff' ) );
		$ho = get_userdata( $this->user_with_role( 'momo_head_office' ) );
		$inbox = admin_url( 'admin.php?page=momos-reservations&status=pending' );
		$this->assertSame( $inbox, LoginFlow::login_redirect( admin_url(), admin_url(), $rs ), 'hidden redirect_to=/wp-admin/ from the core form' );
		$this->assertSame( $inbox, LoginFlow::login_redirect( admin_url(), '', $rs ) );
		$this->assertSame( admin_url(), LoginFlow::login_redirect( admin_url(), admin_url(), $ho ) );
		$deep = admin_url( 'admin.php?page=momos-reservations&id=42' );
		$this->assertSame( $deep, LoginFlow::login_redirect( $deep, $deep, $rs ), 'E07 deep link wins' );
		$this->assertFalse( LoginFlow::is_default_target( admin_url( 'profile.php' ) ) );
	}

	// ---------------------------------------------------------------- QA-022 lost password, no oracle

	public function test_qa022_lost_password_answers_identically_for_unknown_and_existing_accounts(): void {
		$u = self::factory()->user->create( [ 'role' => 'momo_menu_manager', 'user_login' => 'qa_lostpw_known', 'user_email' => 'qa-lostpw@example.org' ] );
		$this->assertGreaterThan( 0, $u );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$out                       = [];
		foreach ( [ 'nobody_' . wp_rand(), 'qa_lostpw_known', 'nobody@example.org' ] as $login ) {
			$_POST    = [ 'user_login' => $login ];
			$_REQUEST = $_POST;
			$out[]    = LoginThrottle::lostpassword_outcome();
		}
		$this->assertSame( [ 'wp-login.php?checkemail=confirm', 'wp-login.php?checkemail=confirm', 'wp-login.php?checkemail=confirm' ], $out );
		$_POST = [ 'user_login' => '' ];
		$this->assertNull( LoginThrottle::lostpassword_outcome(), 'empty field: core message' );
	}

	// ---------------------------------------------------------------- QA-037 captcha from the 3rd failure

	public function test_qa037_captcha_required_from_third_failure_and_independent_of_the_password(): void {
		$ip = '203.0.113.77';
		$this->assertFalse( LoginCaptcha::required( $ip ) );
		LoginThrottle::fail( null, $ip );
		LoginThrottle::fail( null, $ip );
		$this->assertFalse( LoginCaptcha::required( $ip ), '2 failures: no captcha' );
		LoginThrottle::fail( null, $ip );
		$this->assertTrue( LoginCaptcha::required( $ip ), '3rd failure: captcha' );

		$user                      = get_userdata( $this->user_with_role( 'momo_menu_manager' ) );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = [ 'log' => $user->user_login, 'pwd' => 'x' ];
		$right                     = LoginCaptcha::authenticate( $user, $user->user_login, 'x' );
		$wrong                     = LoginCaptcha::authenticate( new \WP_Error( 'incorrect_password', 'x' ), $user->user_login, 'y' );
		$this->assertSame( 'momos_captcha', $right->get_error_code(), 'right password without token refused' );
		$this->assertSame( 'momos_captcha', $wrong->get_error_code(), 'same answer for a wrong password (no oracle)' );
		$locked = new \WP_Error( 'momos_locked', 'locked' );
		$this->assertSame( $locked, LoginCaptcha::authenticate( $locked, $user->user_login, 'x' ), 'lockout message wins' );
		$_POST[ LoginCaptcha::FIELD ] = 'test-pass'; // Test mode token (never accepted in production: live mode).
		$this->assertSame( $user, LoginCaptcha::authenticate( $user, $user->user_login, 'x' ) );
		ob_start();
		LoginCaptcha::render();
		$this->assertStringContainsString( 'class="cf-turnstile"', (string) ob_get_clean() );
	}

	// ---------------------------------------------------------------- QA-042 login language

	public function test_qa042_login_screen_is_japanese_by_default_and_follows_the_selector(): void {
		$GLOBALS['pagenow'] = 'wp-login.php';
		$this->assertSame( 'ja', LoginLanguage::determine_locale( 'en_US' ) );
		$this->assertSame( 'ja', LoginThrottle::lang() );
		$this->assertSame( 'ユーザー名またはパスワードが正しくありません。', LoginThrottle::generic_message() );
		$this->assertSame( 'ユーザー名またはメールアドレス', LoginLanguage::core_fallback( 'Username or Email Address', 'Username or Email Address', 'default' ) );
		$this->assertSame( '← %s へ移動', LoginLanguage::core_fallback_with_context( '&larr; Go to %s', '&larr; Go to %s', 'site', 'default' ) );
		$this->assertSame( 'Gesperrt', LoginLanguage::core_fallback( 'Gesperrt', 'Log In', 'default' ), 'installed core translation wins' );
		ob_start();
		LoginLanguage::selector();
		$nav = (string) ob_get_clean();
		$this->assertStringContainsString( 'wp_lang=en_US', $nav );
		$this->assertStringContainsString( 'aria-current="true">日本語', $nav );
		$_GET['wp_lang'] = 'en_US';
		$this->assertSame( 'en_US', LoginLanguage::determine_locale( 'ja' ) );
		$this->assertSame( 'Log In', LoginLanguage::core_fallback( 'Log In', 'Log In', 'default' ) );
		$GLOBALS['pagenow'] = 'index.php';
		unset( $_GET['wp_lang'] );
		$this->assertSame( 'en_US', LoginLanguage::determine_locale( 'en_US' ), 'other screens untouched' );
	}

	// ---------------------------------------------------------------- QA-041 user-edit 403

	public function test_qa041_head_office_opening_the_super_admin_gets_403_and_an_audit_row(): void {
		RoleInstaller::sync();
		$sa = $this->user_with_role( 'administrator' );
		$ho = $this->user_with_role( 'momo_head_office' );
		$mm = $this->user_with_role( 'momo_menu_manager' );
		wp_set_current_user( $ho );
		$_GET['user_id']     = (string) $sa;
		$_REQUEST['user_id'] = (string) $sa;
		try {
			ProfileGuard::guard_user_edit();
			$this->fail( 'expected wp_die' );
		} catch ( \WPDieException $e ) {
			$this->assertStringContainsString( 'You do not have permission', $e->getMessage() );
		}
		$this->assertNotEmpty( $this->audit_rows( 'access.denied' ) );
		$_REQUEST['user_id'] = (string) $mm;
		ProfileGuard::guard_user_edit(); // Allowed target: no exception.
		$this->assertTrue( true );
	}

	public function test_qa041_wp_die_status_is_403(): void {
		$sa = $this->user_with_role( 'administrator' );
		wp_set_current_user( $this->user_with_role( 'momo_head_office' ) );
		$_REQUEST['user_id'] = (string) $sa;
		$status              = null;
		$handler             = static function () use ( &$status ) {
			return static function ( $message, $title, $args ) use ( &$status ) {
				$status = (int) ( $args['response'] ?? 0 );
				throw new \WPDieException( (string) $message );
			};
		};
		add_filter( 'wp_die_handler', $handler, 99 );
		try {
			ProfileGuard::guard_user_edit();
		} catch ( \WPDieException $e ) {
			unset( $e );
		}
		remove_filter( 'wp_die_handler', $handler, 99 );
		$this->assertSame( 403, $status );
	}

	// ---------------------------------------------------------------- QA-072 / QA-071 admin screens

	public function test_qa072_reset_two_factor_row_action_for_every_manageable_user(): void {
		RoleInstaller::sync();
		$ho = $this->user_with_role( 'momo_head_office' );
		$rs = $this->user_with_role( 'momo_reservation_staff' );
		$sa = $this->user_with_role( 'administrator' );
		wp_set_current_user( $ho );
		$actions = UsersList::row_actions( [ 'edit' => 'Edit', 'delete' => 'Delete' ], get_userdata( $rs ) );
		$this->assertArrayHasKey( 'momos_2fa', $actions, 'not enrolled yet: action still offered' );
		$this->assertArrayHasKey( 'momos_status', $actions );
		$this->assertArrayNotHasKey( 'delete', $actions );
		$this->assertArrayNotHasKey( 'momos_2fa', UsersList::row_actions( [], get_userdata( $sa ) ), 'HO never on ROLE-01' );
	}

	public function test_qa071_audit_viewer_shows_role_and_action_labels(): void {
		$ho = $this->user_with_role( 'momo_head_office' );
		update_user_meta( $ho, 'locale', 'ja' );
		wp_set_current_user( $ho );
		$this->assertSame( '本部管理者', AuditLogScreen::role_label( 'momo_head_office' ) );
		$this->assertSame( 'ログイン成功', AuditLogScreen::action_label( 'auth.login_succeeded' ) );
		update_user_meta( $ho, 'locale', 'en_US' );
		$this->assertSame( 'Head Office Administrator', AuditLogScreen::role_label( 'momo_head_office' ) );
		$this->assertSame( 'Access denied', AuditLogScreen::action_label( 'access.denied' ) );
		$this->assertSame( '', AuditLogScreen::action_label( 'unknown.action' ) );
		foreach ( \Momos\Audit\Catalogue::all() as $action ) {
			$this->assertNotSame( '', AuditLogScreen::action_label( $action ), "label for {$action}" );
		}
	}

	// ---------------------------------------------------------------- QA-039 dashboard widgets

	/** @return list<string> momos widget ids registered for a user */
	private function widgets_for( int $uid ): array {
		global $wp_meta_boxes;
		require_once ABSPATH . 'wp-admin/includes/dashboard.php';
		require_once ABSPATH . 'wp-admin/includes/template.php';
		wp_set_current_user( $uid );
		set_current_screen( 'dashboard' );
		$wp_meta_boxes = [];
		Widgets::setup();
		$ids = [];
		foreach ( (array) ( $wp_meta_boxes['dashboard'] ?? [] ) as $ctx ) {
			foreach ( (array) $ctx as $prio ) {
				foreach ( array_keys( (array) $prio ) as $id ) {
					if ( str_starts_with( (string) $id, 'momos_' ) ) {
						$ids[] = (string) $id;
					}
				}
			}
		}
		sort( $ids );
		return $ids;
	}

	public function test_qa039_role_aware_widget_sets(): void {
		RoleInstaller::sync();
		$b  = $this->branch( 'QDW' );
		$rs = $this->widgets_for( $this->user_with_role( 'momo_reservation_staff', [ $b ] ) );
		$this->assertSame( [ 'momos_attendance', 'momos_needs_action', 'momos_pending' ], $rs );
		$bm = $this->widgets_for( $this->user_with_role( 'momo_branch_manager', [ $b ] ) );
		$this->assertSame( [ 'momos_attendance', 'momos_needs_action', 'momos_offers_ending', 'momos_offers_own', 'momos_pending' ], $bm );
		$mm = $this->widgets_for( $this->user_with_role( 'momo_menu_manager' ) );
		$this->assertSame( [ 'momos_allergens', 'momos_demo_content', 'momos_dishes', 'momos_translation_gaps' ], $mm );
		$ce = $this->widgets_for( $this->user_with_role( 'momo_content_editor' ) );
		$this->assertSame( [ 'momos_demo_content', 'momos_offers_ending', 'momos_offers_own', 'momos_translation_gaps' ], $ce );
		$tr = $this->widgets_for( $this->user_with_role( 'momo_translator' ) );
		$this->assertContains( 'momos_translation_jobs', $tr );
		$ho = $this->widgets_for( $this->user_with_role( 'momo_head_office' ) );
		$this->assertNotContains( 'momos_attendance', $ho );
		$this->assertNotContains( 'momos_translation_jobs', $ho );
		$this->assertGreaterThanOrEqual( 10, count( $ho ), implode( ',', $ho ) . ' (+ Branch module momos_offers_pending)' );
	}

	public function test_qa039_pending_widget_lists_the_soonest_three_of_own_branches_only(): void {
		RoleInstaller::sync();
		$a = $this->branch( 'QDA' );
		$b = $this->branch( 'QDB' );
		$own = [];
		for ( $i = 0; $i < 4; $i++ ) {
			$own[] = $this->reservation( $a );
		}
		$foreign = $this->reservation( $b );
		$bm      = $this->user_with_role( 'momo_branch_manager', [ $a ] );
		wp_set_current_user( $bm );
		ob_start();
		Widgets::pending();
		$html = (string) ob_get_clean();
		$this->assertSame( 3, preg_match_all( '/page=momos-reservations&(?:amp;|#038;)?id=(\d+)/', $html, $m ) );
		$this->assertSame( [], array_diff( array_map( 'intval', $m[1] ), $own ) );
		$this->assertStringNotContainsString( 'id=' . $foreign, $html );
		$this->assertStringContainsString( '<strong style="font-size:1.6em">4</strong>', $html );
	}

	// ---------------------------------------------------------------- QA-074 / QA-078 headers

	public function test_qa078_report_only_csp_has_no_upgrade_insecure_requests(): void {
		[ $name, $value ] = \Momos\Access\Http\SecurityHeaders::public_csp();
		$this->assertSame( 'Content-Security-Policy-Report-Only', $name );
		$this->assertStringNotContainsString( 'upgrade-insecure-requests', $value );
		$this->assertStringContainsString( "frame-ancestors 'self'; report-uri", $value );
	}

	public function test_qa074_powered_by_removal_is_hooked_for_every_request(): void {
		$this->assertSame( 0, has_action( 'init', [ \Momos\Access\Http\SecurityHeaders::class, 'remove_powered_by' ] ) );
	}
}
