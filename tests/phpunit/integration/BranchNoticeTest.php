<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/BranchTestCaseTest.php';

use Momos\Branch\Fields\NoticeFields;
use Momos\Branch\PostType\BranchScopeTaxonomy;
use Momos\Branch\Query\NoticeQuery;
use Momos\Branch\Service\OfferScopeGuard;
use Momos\Kernel\I18n\Translations;

/**
 * Notices (FR-BR-009, spec §4.10): TC-BR-012, AC-BR-05-01..04.
 */
final class BranchNoticeTest extends BranchTestCaseTest {
	private int $qaa;
	private int $qad;

	public function set_up(): void {
		parent::set_up();
		$this->freeze( '2026-12-01 10:00:00' );
		$this->qaa = $this->make_branch( 'QAA' );
		$this->qad = $this->make_branch( 'QAD' );
	}

	/** @param list<int> $branches */
	private function make_notice( string $start, ?string $end, array $branches = [], string $severity = 'info' ): int {
		$id = self::factory()->post->create( [ 'post_type' => 'momo_notice', 'post_status' => 'draft', 'post_title' => 'お知らせ' ] );
		update_post_meta( $id, 'momo_notice_body', '本日は18時まで営業します。' );
		update_post_meta( $id, 'momo_notice_severity', $severity );
		update_post_meta( $id, 'momo_notice_scope', $branches ? 'branches' : 'site_wide' );
		update_post_meta( $id, 'momo_notice_start_at', $start );
		if ( null !== $end ) {
			update_post_meta( $id, 'momo_notice_end_at', $end );
		}
		if ( $branches ) {
			$terms = array_map( static fn( $b ) => BranchScopeTaxonomy::term_for_branch( $b )->term_id, $branches );
			wp_set_object_terms( $id, $terms, BranchScopeTaxonomy::TAX );
			update_post_meta( $id, 'momo_notice_branches', $terms );
		}
		wp_update_post( [ 'ID' => $id, 'post_status' => 'publish' ] );
		return $id;
	}

	public function test_auto_expiry_at_end_ac_br_05_01(): void {
		$id = $this->make_notice( '2026-12-01 09:00', '2026-12-01 18:00' );
		$this->assertSame( 'publish', get_post_status( $id ) );
		$this->assertSame( [ $id ], array_column( NoticeQuery::site_wide( 'ja' ), 'id' ) );
		$this->freeze( '2026-12-01 18:01:00' );
		$this->assertSame( [], NoticeQuery::site_wide( 'ja' ) );
		$this->assertSame( 'expired', NoticeQuery::state( $id ) );
		$this->assertContains( 'notice.published', $this->audit_actions() );
	}

	public function test_branch_notice_only_on_its_branch_ac_br_05_03(): void {
		$d = $this->make_notice( '2026-12-01 09:00', '2026-12-03 18:00', [ $this->qad ] );
		$this->assertSame( [ $d ], array_column( NoticeQuery::for_branch( $this->qad, 'ja' ), 'id' ) );
		$this->assertSame( [], NoticeQuery::for_branch( $this->qaa, 'ja' ) );
		$this->assertSame( [], NoticeQuery::site_wide( 'ja' ) );
	}

	public function test_untranslated_notice_hidden_in_en_ac_br_05_04(): void {
		$id = $this->make_notice( '2026-12-01 09:00', null );
		$this->assertSame( [], NoticeQuery::site_wide( 'en' ) );
		Translations::save( 'post', $id, 'en', [ 'title' => 'Notice', 'momo_notice_body' => 'Open until 18:00 today.' ], 'approved' );
		$n = NoticeQuery::site_wide( 'en' );
		$this->assertSame( 'Open until 18:00 today.', $n[0]['body'] );
		$this->assertTrue( $n[0]['dismissible'] );
	}

	public function test_order_warning_first_then_newest_and_upcoming_window(): void {
		$old  = $this->make_notice( '2026-11-30 09:00', null );
		$new  = $this->make_notice( '2026-12-01 09:00', null );
		$warn = $this->make_notice( '2026-11-01 09:00', null, [], 'warning' );
		$soon = $this->make_notice( '2026-12-01 20:00', null );
		$this->assertSame( [ $warn, $new, $old ], array_column( NoticeQuery::site_wide( 'ja' ), 'id' ) );
		$with = NoticeQuery::site_wide( 'ja', 24 );
		$this->assertContains( $soon, array_column( $with, 'id' ) );
		$this->assertTrue( array_values( array_filter( $with, static fn( $n ) => $n['id'] === $soon ) )[0]['upcoming'] );
	}

	public function test_bm_rules_end_required_max_60_days_and_scope_ac_br_05_02(): void {
		$bm   = $this->user_with_role( 'momo_branch_manager', [ $this->qaa ] );
		$term = BranchScopeTaxonomy::term_for_branch( $this->qaa )->term_id;
		$base = [ 'momo_notice_scope' => 'branches', 'momo_notice_branches' => [ $term ], 'momo_notice_start_at' => '2026-12-01 09:00' ];
		$this->assertContains( 'Set an end date and time for the notice.', NoticeFields::errors( $base, 0, $bm ) );
		$this->assertContains( 'A notice can run for at most 60 days.', NoticeFields::errors( $base + [ 'momo_notice_end_at' => '2027-02-15 09:00' ], 0, $bm ) );
		$this->assertSame( [], NoticeFields::errors( $base + [ 'momo_notice_end_at' => '2026-12-20 09:00' ], 0, $bm ) );
		$this->assertSame( [], NoticeFields::errors( [ 'momo_notice_scope' => 'site_wide', 'momo_notice_start_at' => '2026-12-01 09:00' ], 0, $this->user_with_role( 'momo_head_office' ) ), 'HO: end optional' );
		$this->assertContains( 'End must be after start.', NoticeFields::errors( $base + [ 'momo_notice_end_at' => '2026-12-01 08:00' ], 0, $bm ) );
		$this->assertNotEmpty( NoticeFields::errors( [ 'momo_notice_scope' => 'site_wide', 'momo_notice_start_at' => '2026-12-01 09:00', 'momo_notice_link_url' => 'http://evil.example' ], 0, 1 ) );
		$this->assertSame( 'forbidden_branch', OfferScopeGuard::error_for_submission( 'momo_notice', 0, [ 'momo_notice_scope' => 'site_wide' ], $bm )['code'] );
		$other = BranchScopeTaxonomy::term_for_branch( $this->qad )->term_id;
		$this->assertSame( $this->qad, OfferScopeGuard::error_for_submission( 'momo_notice', 0, [ 'momo_notice_scope' => 'branches', 'momo_notice_branches' => [ $other ] ], $bm )['branch_id'] );
	}

	public function test_bm_can_edit_own_branch_notice_only(): void {
		$own   = $this->make_notice( '2026-12-01 09:00', '2026-12-05 09:00', [ $this->qaa ] );
		$other = $this->make_notice( '2026-12-01 09:00', '2026-12-05 09:00', [ $this->qad ] );
		$site  = $this->make_notice( '2026-12-01 09:00', '2026-12-05 09:00' );
		wp_set_current_user( $this->user_with_role( 'momo_branch_manager', [ $this->qaa ] ) );
		$this->assertTrue( current_user_can( 'edit_post', $own ) );
		$this->assertFalse( current_user_can( 'edit_post', $other ) );
		$this->assertFalse( current_user_can( 'edit_post', $site ) );
		$this->assertTrue( current_user_can( 'publish_momo_notices' ) );
	}

	/**
	 * QA-002 / TC-ACL-021 (M3): core post.php `tax_input[momo_branch_scope]` (no momos_fields) must not
	 * re-scope a BM's notice to an unassigned branch, nor create new scope terms; refusal is audited.
	 * Assigned branches and HO stay allowed.
	 */
	public function test_core_tax_input_scope_is_guarded_qa_002(): void {
		$own = $this->make_notice( '2026-12-01 09:00', '2026-12-05 09:00', [ $this->qaa ] );
		$bm  = $this->user_with_role( 'momo_branch_manager', [ $this->qaa ] );
		wp_set_current_user( $bm );
		$qad = BranchScopeTaxonomy::term_for_branch( $this->qad );
		$qaa = BranchScopeTaxonomy::term_for_branch( $this->qaa );
		foreach ( [ $qad->name, $qad->slug, [ (int) $qad->term_id ], $qaa->name . ',' . $qad->name, 'NEW-TERM' ] as $value ) {
			$this->audits = [];
			$died         = false;
			try {
				wp_update_post( [ 'ID' => $own, 'post_title' => 'QA rescope', 'tax_input' => [ BranchScopeTaxonomy::TAX => $value ] ] );
			} catch ( \WPDieException $e ) {
				$died = true;
			}
			$label = wp_json_encode( $value );
			$this->assertTrue( $died, "refused: {$label}" );
			$this->assertSame( [ $this->qaa ], BranchScopeTaxonomy::branch_ids_of_post( $own ), "scope kept: {$label}" );
			$this->assertContains( 'access.denied', $this->audit_actions(), "audited: {$label}" );
		}
		$this->assertEmpty( term_exists( 'NEW-TERM', BranchScopeTaxonomy::TAX ) );
		$this->assertNull( OfferScopeGuard::error_for_tax_input( 'momo_notice', $own, [], $qaa->name, $bm ) );
		$this->assertNull( OfferScopeGuard::error_for_tax_input( 'momo_notice', $own, [], $qad->name, $this->user_with_role( 'momo_head_office' ) ) );
		wp_update_post( [ 'ID' => $own, 'post_title' => 'QA own', 'tax_input' => [ BranchScopeTaxonomy::TAX => $qaa->name ] ] );
		$this->assertSame( [ $this->qaa ], BranchScopeTaxonomy::branch_ids_of_post( $own ) );
	}
}
