<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/AccessTestCaseTest.php';

use Momos\Access\AccountStatus;
use Momos\Access\Admin\ProfileGuard;
use Momos\Access\Admin\UserAccessFields;
use Momos\Access\BranchScope;
use Momos\Access\Job\AccessExpiryJob;
use Momos\Access\Users\AccountService;
use Momos\Access\Users\AssignmentService;

/**
 * User lifecycle, branch assignments, vendor access and CAP-DCA (FR-ADM-002/007/008, SEC-002/006/
 * 007/008, DEC-035; TC-ACL-011/013/022, TC-ADM-008/009, TC-SEC-022, US-ADM-001).
 */
final class AccessUsersTest extends AccessTestCaseTest {
	private int $a;
	private int $b;
	private int $ho;

	public function set_up(): void {
		parent::set_up();
		$this->freeze( '2026-11-18 10:00:00' );
		$this->a  = $this->branch( 'QAA' );
		$this->b  = $this->branch( 'QAB' );
		$this->ho = $this->user_with_role( 'momo_head_office' );
	}

	public function test_assignment_change_audits_fires_hook_and_destroys_sessions_tc_adm_009(): void {
		$rs = $this->user_with_role( 'momo_reservation_staff' );
		$this->login_session( $rs );
		$this->login_session( $rs );
		$this->assertCount( 2, \WP_Session_Tokens::get_instance( $rs )->get_all() );
		$fired = [];
		add_action( 'momos/user/assignment_changed', static function ( $u, $add, $rem ) use ( &$fired ) {
			$fired[] = [ $u, $add, $rem ];
		}, 5, 3 );
		AssignmentService::set( $rs, [ $this->a ], $this->ho );
		$this->assertSame( [ $this->a ], BranchScope::rows( $rs ) );
		$this->assertSame( [ [ $rs, [ $this->a ], [] ] ], $fired );
		$this->assertCount( 0, \WP_Session_Tokens::get_instance( $rs )->get_all(), 'sessions destroyed (FR-ADM-008)' );
		// Transfer A → B.
		AssignmentService::set( $rs, [ $this->b ], $this->ho );
		$this->assertSame( [ $this->b ], momos_user_branch_ids( $rs ) );
		$rows = $this->audit_rows( 'user.branch_unassigned' );
		$this->assertSame( (string) $this->a, (string) end( $rows )['branch_id'] );
		$this->assertCount( 2, $this->audit_rows( 'user.branch_assigned' ) );
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . \Momos\Kernel\Db\Tables::user_branches() . ' WHERE user_id = %d', $rs ), ARRAY_A );
		$this->assertSame( (string) $this->ho, (string) $row['assigned_by'] );
	}

	public function test_role_change_to_unscoped_role_removes_rows_and_sessions(): void {
		$bm = $this->user_with_role( 'momo_branch_manager' );
		AssignmentService::set( $bm, [ $this->a ], $this->ho );
		$this->login_session( $bm );
		( new \WP_User( $bm ) )->set_role( 'momo_content_editor' );
		$this->assertSame( [], BranchScope::rows( $bm ) );
		$this->assertCount( 0, \WP_Session_Tokens::get_instance( $bm )->get_all() );
		$changed = $this->audit_rows( 'user.role_changed' );
		$this->assertNotEmpty( $changed );
		$this->assertStringContainsString( 'momo_content_editor', (string) end( $changed )['changes'] );
	}

	public function test_disable_blocks_login_destroys_sessions_keeps_rows_tc_acl_011(): void {
		$rs = $this->user_with_role( 'momo_reservation_staff' );
		wp_set_password( 'Correct-Horse-Battery-9', $rs );
		AssignmentService::set( $rs, [ $this->a ], $this->ho );
		$this->login_session( $rs );
		AccountService::disable( $rs, $this->ho );
		$this->assertTrue( AccountStatus::is_disabled( $rs ) );
		$this->assertCount( 0, \WP_Session_Tokens::get_instance( $rs )->get_all() );
		$this->assertSame( [ $this->a ], BranchScope::rows( $rs ), 'rows kept' );
		$this->assertNotEmpty( $this->audit_rows( 'user.disabled' ) );
		$login = get_userdata( $rs )->user_login;
		$res   = wp_authenticate( $login, 'Correct-Horse-Battery-9' );
		$this->assertWPError( $res );
		$bad   = wp_authenticate( $login, 'wrong-password-123' );
		$this->assertSame( wp_strip_all_tags( $res->get_error_message() ), wp_strip_all_tags( \Momos\Access\Login\LoginThrottle::generic_message() ), 'generic error' );
		$this->assertNotEmpty( $this->audit_rows( 'auth.disabled_login' ) );
		unset( $bad );
		AccountService::enable( $rs, $this->ho );
		$this->assertFalse( AccountStatus::is_disabled( $rs ) );
		$this->assertSame( [ $this->a ], momos_user_branch_ids( $rs ), 're-enable restores kept scope' );
	}

	public function test_ho_cannot_manage_sa_or_self(): void {
		$sa = $this->user_with_role( 'administrator' );
		$this->expectException( \InvalidArgumentException::class );
		AccountService::disable( $sa, $this->ho );
	}

	public function test_self_disable_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		AccountService::disable( $this->ho, $this->ho );
	}

	public function test_vendor_window_limit_and_expiry_job_tc_adm_008(): void {
		$sa     = $this->user_with_role( 'administrator' );
		$vendor = $this->user_with_role( 'administrator' );
		AccountService::set_vendor( $vendor, true, $sa );
		$this->assertTrue( AccountStatus::is_disabled( $vendor ), 'vendor kept disabled' );
		$this->assertFalse( user_can( $vendor, 'momo_view_customer_pii' ) );
		$now = \Momos\Kernel\Clock\Clock::utc();
		try {
			AccountService::set_vendor_window( $vendor, $now->modify( '+73 hours' ), $this->ho );
			$this->fail( '73 h accepted' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( '72', $e->getMessage() );
		}
		// HO may open a window on a vendor administrator (narrow M9 exception).
		AccountService::set_vendor_window( $vendor, $now->modify( '+72 hours' ), $this->ho );
		$this->assertFalse( AccountStatus::is_inactive( $vendor ) );
		$this->assertNotEmpty( $this->audit_rows( 'user.access_window_set' ) );
		$this->assertFalse( user_can( $vendor, 'momo_export_reservations' ), 'denies stay in force' );
		// Expiry.
		$this->freeze( '2026-11-21 11:00:00' );
		$this->assertTrue( AccountStatus::is_expired( $vendor ) );
		$this->assertSame( [], momos_user_branch_ids( $vendor ) );
		$out = AccessExpiryJob::run();
		$this->assertContains( $vendor, $out['disabled'] );
		$this->assertTrue( AccountStatus::is_disabled( $vendor ) );
		$this->assertSame( [], AccessExpiryJob::run()['disabled'], 'idempotent' );
	}

	public function test_breakglass_rules_tc_acl_022_tc_sec_022(): void {
		$sa     = $this->user_with_role( 'administrator' );
		$vendor = $this->user_with_role( 'momo_head_office' );
		AccountService::set_vendor( $vendor, true, $sa );
		$now = \Momos\Kernel\Clock\Clock::utc();
		foreach ( [ [ $this->ho, '+2 hours', 'REF-1' ], [ $sa, '+9 hours', 'REF-1' ], [ $sa, '+2 hours', '' ] ] as [ $actor, $mod, $ref ] ) {
			try {
				AccountService::grant_breakglass( $vendor, $now->modify( $mod ), $ref, $actor );
				$this->fail( "accepted {$mod} {$ref}" );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
		AccountService::grant_breakglass( $vendor, $now->modify( '+8 hours' ), 'OWNER-2026-11', $sa );
		$this->assertTrue( user_can( $vendor, 'momo_view_customer_pii' ) );
		$this->assertFalse( user_can( $vendor, 'momo_export_reservations' ) );
		$this->assertFalse( user_can( $vendor, 'momo_manage_dsr' ) );
		$this->freeze( '2026-11-18 18:01:00' );
		$out = AccessExpiryJob::run();
		$this->assertContains( $vendor, $out['revoked'] );
		$this->assertFalse( user_can( $vendor, 'momo_view_customer_pii' ) );
		$this->assertNotEmpty( $this->audit_rows( 'vendor.breakglass_revoked' ) );
		$granted = $this->audit_rows( 'vendor.breakglass_granted' );
		$this->assertStringContainsString( 'OWNER-2026-11', (string) $granted[0]['changes'] );
	}

	public function test_cap_dca_grant_rules(): void {
		$ho2 = $this->user_with_role( 'momo_head_office' );
		$mm  = $this->user_with_role( 'momo_menu_manager' );
		$sa  = $this->user_with_role( 'administrator' );
		AccountService::set_dca( $ho2, true, $this->ho );
		$this->assertTrue( user_can( $ho2, 'momo_edit_dietary_claims' ) );
		$this->assertNotEmpty( $this->audit_rows( 'user.cap_dca_granted' ) );
		foreach ( [ [ $mm, $this->ho, '' ], [ $this->ho, $this->ho, '' ], [ $sa, $this->user_with_role( 'administrator' ), '' ] ] as [ $target, $actor, $ref ] ) {
			try {
				AccountService::set_dca( $target, true, $actor, $ref );
				$this->fail( 'DCA granted to ineligible target' );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertFalse( user_can( $target, 'momo_edit_dietary_claims' ) );
			}
		}
		$sa_actor = $this->user_with_role( 'administrator' );
		AccountService::set_dca( $sa, true, $sa_actor, 'OWNER-REQ-7' );
		$this->assertTrue( user_can( $sa, 'momo_edit_dietary_claims' ) );
		// Vendor flag strips CAP-DCA.
		AccountService::set_vendor( $ho2, true, $sa_actor );
		$this->assertFalse( user_can( $ho2, 'momo_edit_dietary_claims' ) );
		AccountService::set_dca( $sa, false, $sa_actor );
		$this->assertNotEmpty( $this->audit_rows( 'user.cap_dca_revoked' ) );
	}

	public function test_validation_branch_required_username_and_email(): void {
		wp_set_current_user( $this->ho );
		$_POST[ UserAccessFields::NONCE ] = wp_create_nonce( UserAccessFields::ACTION );
		$errors = new \WP_Error();
		$user   = (object) [ 'user_login' => 'branch12', 'first_name' => '', 'last_name' => '', 'user_email' => 'x@gmail.com', 'role' => 'momo_reservation_staff' ];
		UserAccessFields::validate( $errors, false, $user );
		$codes = $errors->get_error_codes();
		$this->assertContains( 'momos_login', $codes );
		$this->assertContains( 'momos_name', $codes );
		$this->assertContains( 'momos_email', $codes );
		$this->assertContains( 'momos_branches', $codes );
		$this->assertSame( 'Assign at least one branch.', $errors->get_error_message( 'momos_branches' ) );
		$_POST['momos_branches'] = [ $this->a ];
		$ok = new \WP_Error();
		UserAccessFields::validate( $ok, false, (object) [ 'user_login' => 'tanaka', 'first_name' => '太郎', 'last_name' => '', 'user_email' => 't@momos.example', 'role' => 'momo_reservation_staff' ] );
		$this->assertFalse( $ok->has_errors() );
		unset( $_POST[ UserAccessFields::NONCE ], $_POST['momos_branches'] );
		$this->assertContains( 'admin', apply_filters( 'illegal_user_logins', [] ) );
	}

	public function test_apply_writes_access_fields_for_managers_only(): void {
		$rs = $this->user_with_role( 'momo_reservation_staff' );
		wp_set_current_user( $this->ho );
		UserAccessFields::apply( $rs, $this->ho, [ 'momos_branches' => [ $this->a, $this->b ], 'momos_phone' => '03-1234-5678', 'momos_disabled_present' => '1' ] );
		$this->assertSame( [ $this->a, $this->b ], BranchScope::rows( $rs ) );
		$this->assertSame( '+81312345678', get_user_meta( $rs, 'momo_phone', true ) );
		$this->assertFalse( AccountStatus::is_disabled( $rs ) );
		UserAccessFields::apply( $rs, $this->ho, [ 'momos_branches' => [ $this->a ], 'momos_disabled_present' => '1', 'momos_disabled' => '1' ] );
		$this->assertTrue( AccountStatus::is_disabled( $rs ) );
		$this->assertSame( [ $this->a ], BranchScope::rows( $rs ) );
	}

	public function test_profile_guard_strips_own_access_fields_tc_acl_013(): void {
		$bm = $this->user_with_role( 'momo_branch_manager', [ $this->a ] );
		wp_set_current_user( $bm );
		$_POST = [ 'momos_branches' => [ $this->b ], 'role' => 'momo_head_office', 'momos_dca' => '1', 'email' => 'x@momos.example' ];
		ProfileGuard::own_profile( $bm );
		$this->assertArrayNotHasKey( 'momos_branches', $_POST );
		$this->assertArrayNotHasKey( 'role', $_POST );
		$this->assertArrayHasKey( 'email', $_POST );
		$denied = $this->audit_rows( 'access.denied' );
		$this->assertStringContainsString( 'self_edit', (string) end( $denied )['changes'] );
		$_POST = [];
		$this->assertSame( [ $this->a ], BranchScope::rows( $bm ) );
		$this->assertFalse( UserAccessFields::can_manage( $bm ) );
	}

	public function test_profile_guard_blocks_administrator_assignment_by_ho(): void {
		wp_set_current_user( $this->ho );
		$_POST['role'] = 'administrator';
		try {
			ProfileGuard::guard_role_assignment( 'create-user' );
			$this->fail( 'not blocked' );
		} catch ( \WPDieException $e ) {
			$this->assertNotEmpty( $this->audit_rows( 'access.denied' ) );
		} finally {
			unset( $_POST['role'] );
		}
	}

	public function test_reset_2fa_audited_and_sessions_destroyed(): void {
		$rs = $this->user_with_role( 'momo_reservation_staff', [ $this->a ] );
		update_user_meta( $rs, \Momos\Access\TwoFactor\TwoFactor::META_SECRET, 'v1:x' );
		update_user_meta( $rs, \Momos\Access\TwoFactor\TwoFactor::META_ENROLLED, '2026-11-01 00:00:00' );
		$this->login_session( $rs );
		AccountService::reset_2fa( $rs, $this->ho );
		$this->assertFalse( \Momos\Access\TwoFactor\TwoFactor::is_enrolled( $rs ) );
		$this->assertCount( 0, \WP_Session_Tokens::get_instance( $rs )->get_all() );
		$this->assertNotEmpty( $this->audit_rows( 'user.2fa_reset' ) );
	}
}
