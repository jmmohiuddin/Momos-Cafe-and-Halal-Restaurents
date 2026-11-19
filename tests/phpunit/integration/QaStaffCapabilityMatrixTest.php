<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/AccessTestCaseTest.php';

use Momos\Access\Roles\RoleInstaller;
use Momos\Access\Users\AssignmentService;
use Momos\Kernel\Db\Tables;

/**
 * QA (staff team) — capability grant table, data-driven: every role × every capability.
 *
 * The expected table below is transcribed INDEPENDENTLY from Doc 09 TRD §2.7.2 (authoritative grant
 * table) plus the IMPL additions recorded in docs/IMPLEMENTATION_DECISIONS.md (IMPL-002 translation caps,
 * IMPL-010 SEO caps, notification log). It is deliberately not read from config/capabilities.php, so a
 * drift in the config is caught here (TC-ACL-001, AC-ADM-02-05, SEC-007).
 *
 * Also covers the meta-capability rules M2/M8/M9/M10/M11/M12 per role on an assigned and a foreign
 * branch (TRD §2.7.3; TC-ACL-002…013).
 */
final class QaStaffCapabilityMatrixTest extends AccessTestCaseTest {

	private const ROLES = [
		'SA' => 'administrator',
		'HO' => 'momo_head_office',
		'MM' => 'momo_menu_manager',
		'CE' => 'momo_content_editor',
		'TR' => 'momo_translator',
		'BM' => 'momo_branch_manager',
		'RS' => 'momo_reservation_staff',
	];

	/** Capability => roles granted (TRD §2.7.2; "Y(b)" counts as granted at role level). */
	private const TABLE = [
		'read'                            => 'SA HO MM CE TR BM RS',
		'upload_files'                    => 'SA HO MM CE BM',
		'edit_posts'                      => 'SA HO CE',
		'edit_others_posts'               => 'SA HO CE',
		'edit_published_posts'            => 'SA HO CE',
		'publish_posts'                   => 'SA HO CE',
		'delete_posts'                    => 'SA HO CE',
		'delete_others_posts'             => 'SA HO CE',
		'delete_published_posts'          => 'SA HO CE',
		'manage_categories'               => 'SA HO CE',
		'edit_pages'                      => 'SA HO CE',
		'edit_others_pages'               => 'SA HO CE',
		'edit_published_pages'            => 'SA HO CE',
		'publish_pages'                   => 'SA HO CE',
		'delete_pages'                    => 'SA HO CE',
		'delete_others_pages'             => 'SA HO CE',
		'delete_published_pages'          => 'SA HO CE',
		'momo_publish_legal_pages'        => 'SA HO',
		'edit_theme_options'              => 'SA HO',
		'list_users'                      => 'SA HO',
		'create_users'                    => 'SA HO',
		'edit_users'                      => 'SA HO',
		'promote_users'                   => 'SA HO',
		'delete_users'                    => 'SA',
		'remove_users'                    => 'SA',
		'manage_options'                  => 'SA',
		'activate_plugins'                => 'SA',
		'install_plugins'                 => 'SA',
		'update_plugins'                  => 'SA',
		'delete_plugins'                  => 'SA',
		'switch_themes'                   => 'SA',
		'edit_themes'                     => 'SA',
		'install_themes'                  => 'SA',
		'update_core'                     => 'SA',
		'export'                          => 'SA',
		'import'                          => 'SA',
		'unfiltered_html'                 => 'SA',
		'unfiltered_upload'               => 'SA',
		'edit_dashboard'                  => 'SA',
		'create_momo_branches'            => 'SA HO',
		'publish_momo_branches'           => 'SA HO',
		'delete_momo_branches'            => 'SA HO',
		'delete_published_momo_branches'  => 'SA HO',
		'delete_others_momo_branches'     => 'SA HO',
		'read_private_momo_branches'      => 'SA HO',
		'edit_private_momo_branches'      => 'SA HO',
		'delete_private_momo_branches'    => 'SA HO',
		'edit_momo_branches'              => 'SA HO CE BM',
		'edit_others_momo_branches'       => 'SA HO CE BM',
		'edit_published_momo_branches'    => 'SA HO CE BM',
		'momo_edit_branch_identity'       => 'SA HO',
		'momo_edit_branch_settings'       => 'SA HO',
		'momo_edit_branch_hours'          => 'SA HO BM',
		'create_momo_notices'             => 'SA HO CE BM',
		'edit_momo_notices'               => 'SA HO CE BM',
		'edit_others_momo_notices'        => 'SA HO CE BM',
		'edit_published_momo_notices'     => 'SA HO CE BM',
		'publish_momo_notices'            => 'SA HO CE BM',
		'delete_momo_notices'             => 'SA HO CE BM',
		'delete_others_momo_notices'      => 'SA HO CE BM',
		'delete_published_momo_notices'   => 'SA HO CE BM',
		'create_momo_offers'              => 'SA HO CE BM',
		'edit_momo_offers'                => 'SA HO CE BM',
		'edit_others_momo_offers'         => 'SA HO CE BM',
		'edit_published_momo_offers'      => 'SA HO',
		'publish_momo_offers'             => 'SA HO',
		'delete_momo_offers'              => 'SA HO',
		'delete_others_momo_offers'       => 'SA HO',
		'delete_published_momo_offers'    => 'SA HO',
		'read_private_momo_offers'        => 'SA HO',
		'create_momo_dishes'              => 'SA HO MM',
		'edit_momo_dishes'                => 'SA HO MM',
		'edit_others_momo_dishes'         => 'SA HO MM',
		'edit_published_momo_dishes'      => 'SA HO MM',
		'read_private_momo_dishes'        => 'SA HO MM',
		'publish_momo_dishes'             => 'SA HO', // MM: "opt" (per-user grant, DEC-026) — not by role.
		'delete_momo_dishes'              => 'SA HO',
		'delete_others_momo_dishes'       => 'SA HO',
		'delete_published_momo_dishes'    => 'SA HO',
		'momo_manage_menu_categories'     => 'SA HO MM',
		'momo_edit_branch_menu'           => 'SA HO MM',
		'momo_edit_branch_prices'         => 'SA HO MM',
		'momo_edit_branch_availability'   => 'SA HO MM BM RS',
		'momo_edit_dietary_claims'        => '', // CAP-DCA: per user (U), never by role.
		'create_momo_certificates'        => 'SA HO',
		'edit_momo_certificates'          => 'SA HO',
		'edit_others_momo_certificates'   => 'SA HO',
		'edit_published_momo_certificates' => 'SA HO',
		'publish_momo_certificates'       => 'SA HO',
		'read_private_momo_certificates'  => 'SA HO MM BM',
		'momo_manage_certificates'        => 'SA HO',
		'momo_manage_brand'               => 'SA HO',
		'momo_view_reservations'          => 'SA HO BM RS',
		'momo_process_reservations'       => 'SA HO BM RS',
		'momo_view_customer_pii'          => 'SA HO BM RS',
		'momo_export_reservations'        => 'SA HO',
		'momo_view_reservation_reports'   => 'SA HO BM',
		'momo_manage_staff'               => 'SA HO',
		'momo_view_audit_log'             => 'SA HO',
		'momo_manage_dsr'                 => 'SA HO',
		'momo_translate'                  => 'SA HO MM CE TR',
		'momo_manage_languages'           => 'SA HO',
		'momo_access_all_branches'        => 'SA HO MM CE',
		// IMPL additions (access spec §3 [IMPL], IMPL-002 / IMPL-010).
		'momo_approve_translations'       => 'SA HO TR',
		'momo_preview_languages'          => 'SA HO TR',
		'momo_view_notification_log'      => 'SA HO',
		'momo_manage_redirects'           => 'SA HO',
		'momo_edit_seo_meta'              => 'SA HO MM CE',
	];

	/** @return array<string, array{0:string,1:string,2:bool}> */
	public static function role_cap_cases(): array {
		$out = [];
		foreach ( self::TABLE as $cap => $holders ) {
			$granted = array_filter( explode( ' ', $holders ) );
			foreach ( array_keys( self::ROLES ) as $short ) {
				$out[ "{$short} {$cap}" ] = [ $short, $cap, in_array( $short, $granted, true ) ];
			}
		}
		return $out;
	}

	/**
	 * TC-ACL-001: effective role grant of a freshly created user of each role equals the TRD table.
	 *
	 * @dataProvider role_cap_cases
	 */
	public function test_role_capability_grant_matches_trd_table( string $short, string $cap, bool $expected ): void {
		RoleInstaller::sync();
		$user = get_userdata( $this->user_with_role( self::ROLES[ $short ] ) );
		$this->assertSame(
			$expected,
			! empty( $user->allcaps[ $cap ] ),
			sprintf( 'TRD §2.7.2: %s %s hold `%s`', $short, $expected ? 'must' : 'must NOT', $cap )
		);
	}

	/** @return array<string, array{0:string}> */
	public static function momos_roles(): array {
		$out = [];
		foreach ( self::ROLES as $short => $slug ) {
			if ( 'SA' !== $short ) {
				$out[ $short ] = [ $short ];
			}
		}
		return $out;
	}

	/**
	 * No momos role carries a capability outside the TRD table (no silent extras such as core
	 * `level_*` caps, `edit_dashboard`, `manage_options`).
	 *
	 * @dataProvider momos_roles
	 */
	public function test_momos_role_has_no_capability_outside_the_table( string $short ): void {
		RoleInstaller::sync();
		$role = get_role( self::ROLES[ $short ] );
		$this->assertNotNull( $role, 'role registered' );
		$expected = [];
		foreach ( self::TABLE as $cap => $holders ) {
			if ( in_array( $short, explode( ' ', $holders ), true ) ) {
				$expected[] = $cap;
			}
		}
		$granted = array_keys( array_filter( $role->capabilities ) );
		sort( $granted );
		sort( $expected );
		$this->assertSame( [], array_values( array_diff( $granted, $expected ) ), "{$short}: extra capabilities beyond TRD §2.7.2" );
		$this->assertSame( [], array_values( array_diff( $expected, $granted ) ), "{$short}: missing capabilities from TRD §2.7.2" );
	}

	public function test_role_display_names_and_removed_core_roles(): void {
		RoleInstaller::sync();
		$names = wp_roles()->get_names();
		$this->assertSame( 'Head Office Administrator', $names['momo_head_office'] ?? null );
		$this->assertSame( 'Menu Manager', $names['momo_menu_manager'] ?? null );
		$this->assertSame( 'Content Editor', $names['momo_content_editor'] ?? null );
		$this->assertSame( 'Translator', $names['momo_translator'] ?? null );
		$this->assertSame( 'Branch Manager', $names['momo_branch_manager'] ?? null );
		$this->assertSame( 'Reservation Staff', $names['momo_reservation_staff'] ?? null );
		foreach ( [ 'subscriber', 'contributor', 'author', 'editor' ] as $core ) {
			$this->assertNull( get_role( $core ), "core role {$core} removed (access spec §2.1)" );
		}
		$this->assertSame( '0', (string) get_option( 'users_can_register' ) );
		$this->assertSame( '', (string) get_option( 'default_role' ) );
	}

	// ------------------------------------------------------------------ meta capabilities per role

	/** @return array<string, array{0:string}> */
	public static function all_roles(): array {
		$out = [];
		foreach ( array_keys( self::ROLES ) as $short ) {
			$out[ $short ] = [ $short ];
		}
		return $out;
	}

	private function grants( string $short, string $cap ): bool {
		return in_array( $short, explode( ' ', self::TABLE[ $cap ] ), true );
	}

	private function all_branches( string $short ): bool {
		return $this->grants( $short, 'momo_access_all_branches' );
	}

	/**
	 * M2 (branch aspects), M8 (reservation view/process), M10 (branch menu item fields), per role, on
	 * an own branch (assigned for BM/RS) and a foreign one (TC-ACL-002…010).
	 *
	 * @dataProvider all_roles
	 */
	public function test_branch_scoped_meta_caps_per_role( string $short ): void {
		RoleInstaller::sync();
		$own     = $this->branch( 'QOA' );
		$foreign = $this->branch( 'QOB' );
		$uid     = $this->user_with_role( self::ROLES[ $short ] );
		if ( in_array( $short, [ 'BM', 'RS' ], true ) ) {
			AssignmentService::set( $uid, [ $own ], 1 );
		}
		wp_set_current_user( $uid );
		$res_own     = $this->reservation( $own );
		$res_foreign = $this->reservation( $foreign );

		$aspects = [
			'hours'        => 'momo_edit_branch_hours',
			'settings'     => 'momo_edit_branch_settings',
			'identity'     => 'momo_edit_branch_identity',
			'menu'         => 'momo_edit_branch_menu',
			'prices'       => 'momo_edit_branch_prices',
			'availability' => 'momo_edit_branch_availability',
		];
		foreach ( [ 'own' => $own, 'foreign' => $foreign ] as $which => $branch ) {
			$in_scope = 'own' === $which || $this->all_branches( $short );
			foreach ( $aspects as $aspect => $prim ) {
				$this->assertSame(
					$this->grants( $short, $prim ) && $in_scope,
					user_can( $uid, 'momo_edit_branch', $branch, $aspect ),
					"M2 {$short} momo_edit_branch({$which}, {$aspect})"
				);
			}
			foreach ( [ 'offered' => 'momo_edit_branch_menu', 'price' => 'momo_edit_branch_prices', 'availability' => 'momo_edit_branch_availability' ] as $field => $prim ) {
				$this->assertSame(
					$this->grants( $short, $prim ) && $in_scope,
					user_can( $uid, 'momo_edit_branch_menu_item', $branch, 0, [ $field ] ),
					"M10 {$short} momo_edit_branch_menu_item({$which}, {$field})"
				);
			}
		}
		foreach ( [ 'own' => $res_own, 'foreign' => $res_foreign ] as $which => $rid ) {
			$in_scope = 'own' === $which || in_array( $short, [ 'SA', 'HO' ], true ) || $this->all_branches( $short );
			$this->assertSame( $this->grants( $short, 'momo_view_reservations' ) && $in_scope, user_can( $uid, 'momo_view_reservation', $rid ), "M8 {$short} momo_view_reservation({$which})" );
			$this->assertSame( $this->grants( $short, 'momo_process_reservations' ) && $in_scope, user_can( $uid, 'momo_process_reservation', $rid ), "M8 {$short} momo_process_reservation({$which})" );
		}
		$this->assertFalse( user_can( $uid, 'momo_view_reservation', 987654 ), "M8 {$short}: unknown reservation id → do_not_allow" );
	}

	/**
	 * M1: core edit_post on a branch post — BM only on the assigned branch; CE on every branch
	 * (all-branches scope); RS/MM/TR never.
	 *
	 * @dataProvider all_roles
	 */
	public function test_m1_branch_post_edit_per_role( string $short ): void {
		RoleInstaller::sync();
		$own     = $this->branch( 'QMA' );
		$foreign = $this->branch( 'QMB' );
		$uid     = $this->user_with_role( self::ROLES[ $short ] );
		if ( in_array( $short, [ 'BM', 'RS' ], true ) ) {
			AssignmentService::set( $uid, [ $own ], 1 );
		}
		$can_edit = $this->grants( $short, 'edit_momo_branches' );
		$this->assertSame( $can_edit, user_can( $uid, 'edit_post', $own ), "M1 {$short} edit own branch post" );
		$this->assertSame( $can_edit && $this->all_branches( $short ), user_can( $uid, 'edit_post', $foreign ), "M1 {$short} edit foreign branch post" );
		$this->assertSame( $this->grants( $short, 'delete_momo_branches' ), user_can( $uid, 'delete_post', $own ), "M1 {$short} delete branch" );
	}

	/**
	 * M9 per role: only SA may edit/promote an administrator; nobody deletes users in web requests;
	 * HO edits other staff; staff roles cannot edit other users at all.
	 *
	 * @dataProvider all_roles
	 */
	public function test_m9_user_management_per_role( string $short ): void {
		RoleInstaller::sync();
		$actor = $this->user_with_role( self::ROLES[ $short ] );
		$sa    = $this->user_with_role( 'administrator' );
		$staff = $this->user_with_role( 'momo_reservation_staff' );
		$this->assertSame( 'SA' === $short, user_can( $actor, 'edit_user', $sa ) || ( 'SA' === $short && $actor === $sa ), "M9 {$short} edit an administrator" );
		$this->assertSame( in_array( $short, [ 'SA', 'HO' ], true ), user_can( $actor, 'edit_user', $staff ), "M9 {$short} edit a staff user" );
		$this->assertSame( in_array( $short, [ 'SA', 'HO' ], true ), user_can( $actor, 'promote_user', $staff ), "M9 {$short} promote a staff user" );
		$this->assertFalse( user_can( $actor, 'promote_user', $actor ), "M9 {$short} cannot change own role" );
		$this->assertFalse( user_can( $actor, 'delete_user', $staff ), "M9 {$short}: delete is never offered (BR-ADM-01)" );
		$this->assertFalse( user_can( $actor, 'remove_user', $staff ), "M9 {$short}: remove is never offered (BR-ADM-01)" );
	}

	public function test_editable_roles_hide_administrator_from_head_office(): void {
		RoleInstaller::sync();
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_set_current_user( $this->user_with_role( 'momo_head_office' ) );
		$roles = array_keys( get_editable_roles() );
		$this->assertNotContains( 'administrator', $roles, 'HO cannot select ROLE-01 (BR-ADM-03)' );
		foreach ( [ 'momo_head_office', 'momo_menu_manager', 'momo_content_editor', 'momo_translator', 'momo_branch_manager', 'momo_reservation_staff' ] as $r ) {
			$this->assertContains( $r, $roles );
		}
		wp_set_current_user( $this->user_with_role( 'administrator' ) );
		$this->assertContains( 'administrator', array_keys( get_editable_roles() ) );
	}

	/**
	 * M4/M11/M12 per role: certificate edit needs CAP-DCA; evidence download needs
	 * momo_manage_certificates + CAP-DCA; register read needs read_private_momo_certificates and,
	 * for scoped roles, a linked assigned branch.
	 *
	 * @dataProvider all_roles
	 */
	public function test_certificate_meta_caps_per_role( string $short ): void {
		RoleInstaller::sync();
		$own     = $this->branch( 'QCA' );
		$foreign = $this->branch( 'QCB' );
		$uid     = $this->user_with_role( self::ROLES[ $short ] );
		if ( in_array( $short, [ 'BM', 'RS' ], true ) ) {
			AssignmentService::set( $uid, [ $own ], 1 );
		}
		$cert_own     = (int) wp_insert_post( [ 'post_type' => 'momo_certificate', 'post_status' => 'publish', 'post_title' => 'QA cert own' ] );
		$cert_foreign = (int) wp_insert_post( [ 'post_type' => 'momo_certificate', 'post_status' => 'publish', 'post_title' => 'QA cert foreign' ] );
		update_post_meta( $cert_own, 'momo_cert_branches', [ $own ] );
		update_post_meta( $cert_foreign, 'momo_cert_branches', [ $foreign ] );

		$read = $this->grants( $short, 'read_private_momo_certificates' );
		$this->assertSame( $read, user_can( $uid, 'read_post', $cert_own ), "M12 {$short} read certificate of own branch" );
		$this->assertSame( $read && $this->all_branches( $short ), user_can( $uid, 'read_post', $cert_foreign ), "M12 {$short} read certificate of foreign branch" );
		$this->assertFalse( user_can( $uid, 'edit_post', $cert_own ), "M4 {$short} without CAP-DCA cannot edit a certificate" );
		$this->assertFalse( user_can( $uid, 'momo_download_evidence', $cert_own ), "M11 {$short} without CAP-DCA cannot download evidence" );

		if ( in_array( $short, [ 'SA', 'HO' ], true ) ) {
			get_userdata( $uid )->add_cap( 'momo_edit_dietary_claims' );
			$this->assertTrue( user_can( $uid, 'edit_post', $cert_own ), "M4 {$short} with CAP-DCA edits certificates" );
			$this->assertTrue( user_can( $uid, 'momo_download_evidence', $cert_own ), "M11 {$short} with CAP-DCA downloads evidence" );
		}
	}

	/**
	 * CAP-DCA is grantable only to HO (or SA non-vendor); RoleInstaller strips it from any other role
	 * on the next profile update (§5.7).
	 *
	 * @dataProvider all_roles
	 */
	public function test_cap_dca_only_effective_for_eligible_roles( string $short ): void {
		RoleInstaller::sync();
		$uid  = $this->user_with_role( self::ROLES[ $short ] );
		$user = get_userdata( $uid );
		$user->add_cap( 'momo_edit_dietary_claims' );
		RoleInstaller::apply_user_denies( $uid );
		$this->assertSame( in_array( $short, [ 'SA', 'HO' ], true ), user_can( $uid, 'momo_edit_dietary_claims' ), "CAP-DCA effective for {$short}" );
	}

	/**
	 * Vendor accounts never hold the four PII caps, whatever their role (DEC-035, SEC-008).
	 *
	 * @dataProvider all_roles
	 */
	public function test_vendor_denies_apply_to_every_role( string $short ): void {
		RoleInstaller::sync();
		$uid = $this->user_with_role( self::ROLES[ $short ] );
		update_user_meta( $uid, 'momo_vendor', '1' );
		RoleInstaller::apply_user_denies( $uid );
		foreach ( [ 'momo_view_customer_pii', 'momo_view_reservations', 'momo_export_reservations', 'momo_manage_dsr' ] as $cap ) {
			$this->assertFalse( user_can( $uid, $cap ), "vendor {$short} must not hold {$cap}" );
		}
	}

	/** Disabled and expired accounts lose every branch-scoped object capability (TC-ACL-011). */
	public function test_disabled_or_expired_scoped_user_loses_object_caps(): void {
		RoleInstaller::sync();
		$b   = $this->branch( 'QDX' );
		$rid = $this->reservation( $b );
		foreach ( [ 'momo_branch_manager', 'momo_head_office' ] as $role ) {
			$uid = $this->user_with_role( $role, 'momo_branch_manager' === $role ? [ $b ] : [] );
			$this->assertTrue( user_can( $uid, 'momo_view_reservation', $rid ), "{$role} active" );
			update_user_meta( $uid, 'momo_account_disabled', '1' );
			$this->assertFalse( user_can( $uid, 'momo_view_reservation', $rid ), "{$role} disabled" );
			$this->assertFalse( user_can( $uid, 'momo_edit_branch', $b, 'hours' ), "{$role} disabled: hours" );
			delete_user_meta( $uid, 'momo_account_disabled' );
			update_user_meta( $uid, 'momo_access_expires_at', gmdate( 'Y-m-d H:i:s', time() - 60 ) );
			$this->assertFalse( user_can( $uid, 'momo_view_reservation', $rid ), "{$role} expired" );
		}
		global $wpdb;
		$this->assertGreaterThan( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Tables::user_branches() . ' WHERE branch_id = %d', $b ) ), 'rows kept' ); // phpcs:ignore
	}
}
