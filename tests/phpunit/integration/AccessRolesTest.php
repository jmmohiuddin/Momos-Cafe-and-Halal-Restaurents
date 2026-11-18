<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/AccessTestCaseTest.php';

use Momos\Access\AccountStatus;
use Momos\Access\Roles\RoleInstaller;

/**
 * Roles and capability matrix (TRD §2.7.2; TC-ACL-001, AC-ADM-02-05, SEC-007/008, DEC-035).
 */
final class AccessRolesTest extends AccessTestCaseTest {
	public function test_effective_caps_per_role_equal_the_table_tc_acl_001(): void {
		RoleInstaller::sync();
		$cfg     = RoleInstaller::config();
		$by_role = RoleInstaller::caps_by_role();
		foreach ( array_keys( $cfg['roles'] ) as $role ) {
			$uid  = $this->user_with_role( $role );
			$user = get_userdata( $uid );
			foreach ( $cfg['caps'] as $cap => $roles ) {
				// Raw grants (allcaps): certificate primitives are additionally gated by CAP-DCA at map_meta_cap (M4).
				$this->assertSame( in_array( $role, $roles, true ), ! empty( $user->allcaps[ $cap ] ), "{$role} / {$cap}" );
			}
			$granted = array_keys( array_filter( get_role( $role )->capabilities ) );
			sort( $granted );
			$expected = $by_role[ $role ];
			sort( $expected );
			$this->assertSame( $expected, $granted, "{$role} has exactly the table capabilities" );
			$this->assertFalse( $user->has_cap( 'momo_edit_dietary_claims' ), 'CAP-DCA is never role-granted' );
		}
		$sa = get_userdata( $this->user_with_role( 'administrator' ) );
		foreach ( $by_role['administrator'] as $cap ) {
			$this->assertTrue( ! empty( $sa->allcaps[ $cap ] ), "administrator / {$cap}" );
		}
		$this->assertFalse( isset( get_role( 'administrator' )->capabilities['momo_edit_dietary_claims'] ) );
	}

	public function test_core_roles_removed_and_registration_closed(): void {
		RoleInstaller::sync();
		foreach ( RoleInstaller::REMOVED_CORE_ROLES as $r ) {
			$this->assertNull( get_role( $r ), $r );
		}
		$this->assertSame( '0', (string) get_option( 'users_can_register' ) );
		$this->assertSame( '', (string) get_option( 'default_role' ) );
	}

	public function test_sync_removes_extra_caps_and_is_idempotent(): void {
		get_role( 'momo_translator' )->add_cap( 'manage_options' );
		RoleInstaller::sync();
		RoleInstaller::sync();
		$this->assertArrayNotHasKey( 'manage_options', get_role( 'momo_translator' )->capabilities );
	}

	public function test_resync_on_signature_change(): void {
		update_option( RoleInstaller::SIGNATURE_OPTION, 'old' );
		get_role( 'momo_menu_manager' )->add_cap( 'delete_users' );
		RoleInstaller::maybe_sync();
		$this->assertArrayNotHasKey( 'delete_users', get_role( 'momo_menu_manager' )->capabilities );
		$this->assertSame( RoleInstaller::signature(), get_option( RoleInstaller::SIGNATURE_OPTION ) );
	}

	public function test_vendor_denies_and_breakglass_lift_tc_acl_022(): void {
		$v = $this->user_with_role( 'momo_head_office' );
		update_user_meta( $v, AccountStatus::META_VENDOR, '1' );
		RoleInstaller::apply_user_denies( $v );
		$u = new \WP_User( $v );
		foreach ( RoleInstaller::config()['vendor_denies'] as $cap ) {
			$this->assertFalse( $u->has_cap( $cap ), $cap );
		}
		$this->assertTrue( $u->has_cap( 'momo_manage_brand' ), 'other HO caps kept' );
		// Break-glass lifts PII + view only; export and DSR stay denied.
		update_user_meta( $v, AccountStatus::META_BG_UNTIL, gmdate( 'Y-m-d H:i:s', time() + 3600 ) );
		$this->freeze( wp_date( 'Y-m-d H:i:s', time(), new \DateTimeZone( 'Asia/Tokyo' ) ) );
		RoleInstaller::apply_user_denies( $v );
		$u = new \WP_User( $v );
		$this->assertTrue( $u->has_cap( 'momo_view_customer_pii' ) );
		$this->assertTrue( $u->has_cap( 'momo_view_reservations' ) );
		$this->assertFalse( $u->has_cap( 'momo_export_reservations' ) );
		$this->assertFalse( $u->has_cap( 'momo_manage_dsr' ) );
		// Re-applied on profile update when the window is gone.
		delete_user_meta( $v, AccountStatus::META_BG_UNTIL );
		do_action( 'profile_update', $v, get_userdata( $v ), [] );
		$this->assertFalse( ( new \WP_User( $v ) )->has_cap( 'momo_view_customer_pii' ) );
	}

	public function test_role_display_names_in_japanese_for_ja_admins(): void {
		$uid = $this->user_with_role( 'momo_head_office' );
		update_user_meta( $uid, 'locale', 'ja' );
		wp_set_current_user( $uid );
		$this->assertSame( '本部管理者', RoleInstaller::role_label( 'momo_head_office' ) );
		$this->assertSame( '店長', translate_user_role( 'Branch Manager' ) );
		update_user_meta( $uid, 'locale', 'en_US' );
		$this->assertSame( 'Branch Manager', RoleInstaller::role_label( 'momo_branch_manager' ) );
	}
}
