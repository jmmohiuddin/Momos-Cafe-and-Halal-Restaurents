<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/AccessTestCaseTest.php';

use Momos\Access\AccountStatus;
use Momos\Access\MetaCapMapper;

/**
 * Meta capabilities M1–M12 and branch isolation (TRD §2.7.3; TC-ACL-002…012, 013, 018, 021;
 * BR-RES-02, SEC-010/011/012).
 */
final class AccessMetaCapTest extends AccessTestCaseTest {
	private int $a;
	private int $b;

	public function set_up(): void {
		parent::set_up();
		$this->a = $this->branch( 'QAA' );
		$this->b = $this->branch( 'QAB' );
	}

	public function test_m8_reservation_scope_tc_acl_002(): void {
		$ra = $this->reservation( $this->a );
		$rb = $this->reservation( $this->b );
		$rs = $this->user_with_role( 'momo_reservation_staff', [ $this->a ] );
		$ho = $this->user_with_role( 'momo_head_office' );
		$mm = $this->user_with_role( 'momo_menu_manager' );
		$this->assertTrue( user_can( $rs, 'momo_view_reservation', $ra ) );
		$this->assertTrue( user_can( $rs, 'momo_process_reservation', $ra ) );
		$this->assertFalse( user_can( $rs, 'momo_view_reservation', $rb ), 'other branch denied' );
		$this->assertFalse( user_can( $rs, 'momo_view_reservation', 999999 ), 'unknown id denied' );
		$this->assertTrue( user_can( $ho, 'momo_view_reservation', $rb ) );
		$this->assertFalse( user_can( $mm, 'momo_view_reservation', $ra ), 'all-branch scope grants no action' );
	}

	public function test_disabled_and_expired_accounts_lose_scope_tc_acl_011(): void {
		$ra = $this->reservation( $this->a );
		$bm = $this->user_with_role( 'momo_branch_manager', [ $this->a ] );
		$this->assertSame( [ $this->a ], momos_user_branch_ids( $bm ) );
		update_user_meta( $bm, AccountStatus::META_DISABLED, '1' );
		$this->assertSame( [], momos_user_branch_ids( $bm ) );
		$this->assertFalse( user_can( $bm, 'momo_view_reservation', $ra ) );
		$this->assertFalse( user_can( $bm, 'momo_edit_branch', $this->a, 'hours' ) );
		// Rows are kept while disabled; re-enabling restores scope.
		delete_user_meta( $bm, AccountStatus::META_DISABLED );
		$this->assertSame( [ $this->a ], momos_user_branch_ids( $bm ) );
		// Expired access window → inactive. HO (all branches) also loses scope.
		$ho = $this->user_with_role( 'momo_head_office' );
		$this->freeze( '2026-11-18 12:00:00' );
		update_user_meta( $ho, AccountStatus::META_EXPIRES, '2026-11-18 02:59:00' );
		$this->assertSame( [], momos_user_branch_ids( $ho ) );
		update_user_meta( $ho, AccountStatus::META_EXPIRES, '2026-11-18 03:01:00' );
		$this->assertNull( momos_user_branch_ids( $ho ) );
	}

	public function test_m1_m2_branch_edit_aspects(): void {
		$bm = $this->user_with_role( 'momo_branch_manager', [ $this->a ] );
		$this->assertTrue( user_can( $bm, 'momo_edit_branch', $this->a, 'hours' ) );
		$this->assertFalse( user_can( $bm, 'momo_edit_branch', $this->b, 'hours' ), 'TC-ACL-007 other branch' );
		$this->assertFalse( user_can( $bm, 'momo_edit_branch', $this->a, 'settings' ), 'settings is HO only' );
		$this->assertFalse( user_can( $bm, 'momo_edit_branch', $this->a, 'bogus' ) );
		$this->assertTrue( user_can( $bm, 'edit_post', $this->a ) );
		$this->assertFalse( user_can( $bm, 'edit_post', $this->b ) );
		$ce = $this->user_with_role( 'momo_content_editor' );
		$this->assertTrue( user_can( $ce, 'momo_edit_branch', $this->b, 'content' ) );
		$this->assertFalse( user_can( $ce, 'momo_edit_branch', $this->b, 'hours' ) );
	}

	public function test_m10_branch_menu_item_fields(): void {
		$rs = $this->user_with_role( 'momo_reservation_staff', [ $this->a ] );
		$mm = $this->user_with_role( 'momo_menu_manager' );
		$this->assertTrue( user_can( $rs, 'momo_edit_branch_menu_item', $this->a, 1, [ 'availability' ] ) );
		$this->assertFalse( user_can( $rs, 'momo_edit_branch_menu_item', $this->a, 1, [ 'price' ] ) );
		$this->assertFalse( user_can( $rs, 'momo_edit_branch_menu_item', $this->b, 1, [ 'availability' ] ) );
		$this->assertTrue( user_can( $mm, 'momo_edit_branch_menu_item', $this->b, 1, [ 'price', 'offered', 'availability' ] ) );
		$this->assertFalse( user_can( $mm, 'momo_edit_branch_menu_item', $this->b, 1, [ 'unknown' ] ) );
	}

	public function test_m11_evidence_needs_dca_and_m4_certificates(): void {
		$cert = wp_insert_post( [ 'post_type' => 'momo_certificate', 'post_title' => 'C', 'post_status' => 'draft' ] );
		$ho   = $this->user_with_role( 'momo_head_office' );
		$this->assertFalse( user_can( $ho, 'momo_download_evidence', $cert ) );
		$this->assertFalse( user_can( $ho, 'edit_post', $cert ) );
		( new \WP_User( $ho ) )->add_cap( 'momo_edit_dietary_claims' );
		$this->assertTrue( user_can( $ho, 'momo_download_evidence', $cert ) );
		$this->assertTrue( user_can( $ho, 'edit_post', $cert ) );
		$this->assertFalse( user_can( $ho, 'delete_post', $cert ), 'claim records are never deleted' );
	}

	public function test_m12_certificate_read_scope(): void {
		$cert = wp_insert_post( [ 'post_type' => 'momo_certificate', 'post_title' => 'C', 'post_status' => 'publish' ] );
		update_post_meta( $cert, 'momo_cert_branches', [ $this->b ] );
		$bm_a = $this->user_with_role( 'momo_branch_manager', [ $this->a ] );
		$bm_b = $this->user_with_role( 'momo_branch_manager', [ $this->b ] );
		$mm   = $this->user_with_role( 'momo_menu_manager' );
		$tr   = $this->user_with_role( 'momo_translator' );
		$this->assertFalse( user_can( $bm_a, 'read_post', $cert ) );
		$this->assertTrue( user_can( $bm_b, 'read_post', $cert ) );
		$this->assertTrue( user_can( $mm, 'read_post', $cert ) );
		$this->assertFalse( user_can( $tr, 'read_post', $cert ) );
	}

	public function test_m5_legal_pages_and_m7_customize(): void {
		$page = wp_insert_post( [ 'post_type' => 'page', 'post_title' => 'Privacy', 'post_status' => 'publish' ] );
		update_post_meta( $page, 'momo_page_type', 'legal' );
		$ce = $this->user_with_role( 'momo_content_editor' );
		$ho = $this->user_with_role( 'momo_head_office' );
		$this->assertFalse( user_can( $ce, 'edit_post', $page ), 'published legal page needs momo_publish_legal_pages' );
		$this->assertTrue( user_can( $ho, 'edit_post', $page ) );
		$draft = wp_insert_post( [ 'post_type' => 'page', 'post_title' => 'Terms', 'post_status' => 'draft' ] );
		update_post_meta( $draft, 'momo_page_type', 'legal' );
		$this->assertTrue( user_can( $ce, 'edit_post', $draft ), 'CE drafts legal pages' );
		$this->assertFalse( user_can( $ce, 'publish_post', $draft ) );
		$this->assertFalse( user_can( $ho, 'customize' ), 'M7 customize = manage_options' );
	}

	public function test_m9_users_tc_acl_013(): void {
		$sa  = $this->user_with_role( 'administrator' );
		$sa2 = $this->user_with_role( 'administrator' );
		$ho  = $this->user_with_role( 'momo_head_office' );
		$rs  = $this->user_with_role( 'momo_reservation_staff', [ $this->a ] );
		$this->assertFalse( user_can( $ho, 'edit_user', $sa ), 'HO cannot touch ROLE-01' );
		$this->assertFalse( user_can( $ho, 'promote_user', $sa ) );
		$this->assertTrue( user_can( $ho, 'edit_user', $rs ) );
		$this->assertTrue( user_can( $sa, 'edit_user', $sa2 ) );
		$this->assertFalse( user_can( $ho, 'promote_user', $ho ), 'no own role change' );
		$this->assertFalse( user_can( $sa, 'delete_user', $rs ), 'never deleted (BR-ADM-01)' );
		$this->assertFalse( user_can( $sa, 'remove_user', $rs ) );
		wp_set_current_user( $ho );
		$roles = MetaCapMapper::editable_roles( wp_roles()->roles );
		$this->assertArrayNotHasKey( 'administrator', $roles );
		$this->assertArrayHasKey( 'momo_branch_manager', $roles );
		wp_set_current_user( $sa );
		$this->assertArrayHasKey( 'administrator', MetaCapMapper::editable_roles( wp_roles()->roles ) );
	}

	public function test_m3_offer_scope_and_published_offer_edit(): void {
		$bm    = $this->user_with_role( 'momo_branch_manager', [ $this->a ] );
		$offer = wp_insert_post( [ 'post_type' => 'momo_offer', 'post_title' => 'O', 'post_status' => 'publish', 'post_author' => $bm ] );
		update_post_meta( $offer, 'momo_offer_scope', 'site_wide' );
		$this->assertFalse( user_can( $bm, 'edit_post', $offer ) );
		$ho = $this->user_with_role( 'momo_head_office' );
		$this->assertTrue( user_can( $ho, 'edit_post', $offer ) );
	}

	public function test_map_failure_fails_closed(): void {
		$rs = $this->user_with_role( 'momo_reservation_staff', [ $this->a ] );
		$cb = static function () {
			throw new \RuntimeException( 'boom' );
		};
		add_filter( 'momos/access/user_branch_ids', $cb, 1 );
		$this->assertSame( [ 'do_not_allow' ], MetaCapMapper::map( [ 'momo_edit_branch' ], 'momo_edit_branch', $rs, [ $this->a, 'hours' ] ) );
		remove_filter( 'momos/access/user_branch_ids', $cb, 1 );
	}
}
