<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/BranchTestCaseTest.php';

use Momos\Branch\Fields\OfferFields;
use Momos\Branch\Job\OfferExpiryJob;
use Momos\Branch\PostType\BranchScopeTaxonomy;
use Momos\Branch\Query\OfferQuery;
use Momos\Branch\Service\BranchAccess;
use Momos\Branch\Service\OfferScopeGuard;
use Momos\Branch\Service\OfferService;
use Momos\Kernel\I18n\Translations;

/**
 * Offers (FR-CORP-010/011, BR-CORP-01/02/03, DEC-060): TC-CORP-010..014, TC-ACL-021,
 * AC-CORP-09-01..11.
 */
final class BranchOfferTest extends BranchTestCaseTest {
	private int $qaa;
	private int $qab;

	public function set_up(): void {
		parent::set_up();
		$this->freeze( '2026-12-01 10:00:00' );
		$this->qaa = $this->make_branch( 'QAA' );
		$this->qab = $this->make_branch( 'QAB' );
	}

	/** @param list<int> $branches */
	private function make_offer( string $start, string $end, array $branches = [], array $meta = [], string $status = 'publish' ): int {
		$id = self::factory()->post->create( [ 'post_type' => 'momo_offer', 'post_status' => 'draft', 'post_title' => 'ランチセット' ] );
		$m  = array_merge(
			[
				'momo_offer_summary'    => 'お得なランチ',
				'momo_offer_conditions' => '平日のみ。他の特典と併用不可。',
				'momo_offer_start_at'   => $start,
				'momo_offer_end_at'     => $end,
				'momo_offer_scope'      => $branches ? 'branches' : 'all_branches',
				'momo_offer_cta_type'   => 'request_table',
			],
			$meta
		);
		foreach ( $m as $k => $v ) {
			update_post_meta( $id, $k, $v );
		}
		if ( $branches ) {
			$terms = array_map( static fn( $b ) => BranchScopeTaxonomy::term_for_branch( $b )->term_id, $branches );
			wp_set_object_terms( $id, $terms, BranchScopeTaxonomy::TAX );
			update_post_meta( $id, 'momo_offer_branches', $terms );
		}
		if ( 'draft' !== $status ) {
			wp_update_post( [ 'ID' => $id, 'post_status' => $status ] );
		}
		return $id;
	}

	public function test_content_editor_submits_for_review_and_cannot_publish_ac_corp_09_01(): void {
		wp_set_current_user( $this->user_with_role( 'momo_content_editor' ) );
		$id = $this->make_offer( '2026-12-01 00:00', '2026-12-31 23:59' );
		$this->assertSame( 'pending', get_post_status( $id ), 'publish forced to pending for non-publishers' );
		$this->assertContains( 'offer.submitted', $this->audit_actions() );
		$this->assertFalse( OfferQuery::is_active( $id ) );
	}

	public function test_head_office_publish_stamps_and_audits_ac_corp_09_02(): void {
		$ho = $this->user_with_role( 'momo_head_office' );
		wp_set_current_user( $ho );
		$id = $this->make_offer( '2026-12-01 00:00', '2026-12-31 23:59' );
		$this->assertSame( 'publish', get_post_status( $id ) );
		$this->assertSame( (string) $ho, (string) get_post_meta( $id, 'momo_offer_approved_by', true ) );
		$this->assertSame( '2026-12-01 01:00:00', get_post_meta( $id, 'momo_offer_approved_at', true ) );
		$this->assertContains( 'offer.published', $this->audit_actions() );
		$this->assertTrue( OfferQuery::is_active( $id ) );
		$this->assertSame( 'active', OfferQuery::state( $id ) );
	}

	public function test_active_window_boundaries_and_utc_storage(): void {
		$id = $this->make_offer( '2026-12-02 11:00', '2026-12-31 23:59' );
		$this->assertSame( '2026-12-02 02:00:00', get_post_meta( $id, '_momo_offer_start_utc', true ) );
		$this->assertSame( 'scheduled', OfferQuery::state( $id ) );
		$this->freeze( '2026-12-02 10:59:00' );
		$this->assertFalse( OfferQuery::is_active( $id ) );
		$this->assertContains( $id, OfferQuery::upcoming( 'ja' ) );
		$this->freeze( '2026-12-02 11:00:00' );
		$this->assertTrue( OfferQuery::is_active( $id ), 'start = now is active' );
		$this->freeze( '2026-12-31 23:59:00' );
		$this->assertFalse( OfferQuery::is_active( $id ), 'end = now is not active' );
		$this->assertSame( 'expired', OfferQuery::state( $id ) );
	}

	public function test_expiry_job_stamps_expired_at_ac_corp_09_04(): void {
		$id = $this->make_offer( '2026-12-01 00:00', '2026-12-31 23:59' );
		$this->freeze( '2027-01-01 00:01:00' );
		$result = OfferExpiryJob::run();
		$this->assertSame( 1, $result['expired'] );
		$this->assertSame( '2026-12-31 15:01:00', get_post_meta( $id, 'momo_offer_expired_at', true ) );
		$this->assertSame( 0, OfferExpiryJob::run()['expired'], 'idempotent' );
		$this->assertFalse( OfferQuery::has_active( 'ja' ) );
	}

	public function test_branch_scoping_and_non_open_branches(): void {
		$all = $this->make_offer( '2026-12-01 00:00', '2026-12-31 23:59', [], [ 'momo_offer_homepage_featured' => '1', 'momo_offer_order' => '2' ] );
		$b   = $this->make_offer( '2026-12-01 00:00', '2026-12-31 23:59', [ $this->qab ], [ 'momo_offer_order' => '1' ] );
		$y   = $this->make_branch( 'QAY', [ 'momo_operating_status' => 'temporarily_closed' ] );
		$off = $this->make_offer( '2026-12-01 00:00', '2026-12-31 23:59', [ $y ] );
		$this->assertSame( [ $b, $all ], OfferQuery::all_active( 'ja' ), 'ordered by momo_offer_order' );
		$this->assertSame( [ $all ], OfferQuery::for_branch( $this->qaa, 'ja' ) );
		$this->assertSame( [ $b, $all ], OfferQuery::for_branch( $this->qab, 'ja' ) );
		$this->assertSame( [], OfferQuery::for_branch( $y, 'ja' ), 'offers hidden on a closed branch' );
		$this->assertNotContains( $off, OfferQuery::all_active( 'ja' ) );
		$this->assertSame( [ $all ], OfferQuery::home_featured( 'ja' ) );
		$this->assertSame( [ $this->qab ], OfferQuery::card_branch_ids( $b ) );
		$cta = OfferQuery::cta( $b, 'ja' );
		$this->assertStringContainsString( 'branch=QAB', $cta['url'] );
		$this->assertStringContainsString( 'offer=' . $b, $cta['url'] );
	}

	public function test_translation_no_fallback_ac_corp_09_09(): void {
		$id = $this->make_offer( '2026-12-01 00:00', '2026-12-31 23:59' );
		$this->assertFalse( OfferQuery::has_active( 'en' ) );
		Translations::save( 'post', $id, 'en', [ 'title' => 'Lunch set', 'momo_offer_summary' => 'Lunch deal', 'momo_offer_conditions' => 'Weekdays only.' ], 'approved' );
		$this->assertSame( [ $id ], OfferQuery::all_active( 'en' ) );
	}

	public function test_visibility_changed_fires_on_zero_to_one(): void {
		update_option( OfferQuery::STATE_OPTION, [] );
		$this->events = [];
		$id           = $this->make_offer( '2026-12-01 00:00', '2026-12-31 23:59' );
		$ev           = $this->events_of( 'momos/offer/visibility_changed' );
		$this->assertNotEmpty( $ev );
		$this->assertSame( [ 'momos/offer/visibility_changed', $id, true ], end( $ev ) );
		$this->assertTrue( (bool) get_option( OfferQuery::STATE_OPTION )['ja'] );
		$second = $this->make_offer( '2026-12-01 00:00', '2026-12-31 23:59' );
		$ev     = $this->events_of( 'momos/offer/visibility_changed' );
		$this->assertSame( [ 'momos/offer/visibility_changed', $second, false ], end( $ev ) );
	}

	public function test_bm_scope_rules_m3_ac_corp_09_06(): void {
		$bm = $this->user_with_role( 'momo_branch_manager', [ $this->qaa ] );
		$this->assertSame( 'forbidden_branch', BranchAccess::scope_error( true, [], $bm ) );
		$this->assertSame( 'forbidden_branch', BranchAccess::scope_error( false, [ $this->qab ], $bm ) );
		$this->assertNull( BranchAccess::scope_error( false, [ $this->qaa ], $bm ) );
		$term_b = BranchScopeTaxonomy::term_for_branch( $this->qab )->term_id;
		$this->assertSame( 'forbidden_branch', OfferScopeGuard::error_for_submission( 'momo_offer', 0, [ 'momo_offer_scope' => 'branches', 'momo_offer_branches' => [ $term_b ] ], $bm )['code'] );
		$this->assertSame( 'forbidden_branch', OfferScopeGuard::error_for_submission( 'momo_offer', 0, [ 'momo_offer_scope' => 'all_branches' ], $bm )['code'] );
		$this->assertSame( 'forbidden_branch', OfferScopeGuard::error_for_submission( 'momo_offer', 0, [ 'momo_offer_scope' => 'branches', 'momo_offer_branches' => [ 999999 ] ], $bm )['code'] );
		$own = BranchScopeTaxonomy::term_for_branch( $this->qaa )->term_id;
		$this->assertNull( OfferScopeGuard::error_for_submission( 'momo_offer', 0, [ 'momo_offer_scope' => 'branches', 'momo_offer_branches' => [ $own ] ], $bm ) );

		// Crafted POST through the editor path → 403 + access.denied.
		wp_set_current_user( $bm );
		$_POST = [ 'momos_fields' => [ 'momo_offer_scope' => 'all_branches' ] ];
		try {
			wp_insert_post( [ 'post_type' => 'momo_offer', 'post_status' => 'draft', 'post_title' => 'x' ] );
			$this->fail( 'Expected wp_die' );
		} catch ( \WPDieException $e ) {
			$this->assertContains( 'access.denied', $this->audit_actions() );
		}
	}

	public function test_bm_cannot_edit_published_or_foreign_offers(): void {
		$pub = $this->make_offer( '2026-12-01 00:00', '2026-12-31 23:59', [ $this->qaa ] );
		$fb  = $this->make_offer( '2026-12-01 00:00', '2026-12-31 23:59', [ $this->qab ], [], 'draft' );
		$bm  = $this->user_with_role( 'momo_branch_manager', [ $this->qaa ] );
		wp_set_current_user( $bm );
		$this->assertFalse( current_user_can( 'edit_post', $pub ), 'published offers: HO only' );
		$this->assertFalse( current_user_can( 'edit_post', $fb ), 'M3 unassigned branch' );
		$copy = OfferService::duplicate( $pub, $bm );
		$this->assertSame( 'draft', get_post_status( $copy ) );
		$this->assertSame( '', get_post_meta( $copy, 'momo_offer_approved_by', true ) );
		$this->assertSame( [ $this->qaa ], BranchScopeTaxonomy::branch_ids_of_post( $copy ) );
		$this->assertTrue( current_user_can( 'edit_post', $copy ) );
	}

	public function test_validation_rules(): void {
		$e = OfferFields::errors( [ 'momo_offer_start_at' => '2026-12-10 10:00', 'momo_offer_end_at' => '2026-12-10 09:00', 'momo_offer_scope' => 'branches', 'momo_offer_branches' => [] ], 0 );
		$this->assertContains( 'The end must be after the start.', $e );
		$this->assertContains( 'Select at least one branch.', $e );
		$e = OfferFields::errors( [ 'momo_offer_start_at' => '2026-12-10 10:00', 'momo_offer_end_at' => '2028-12-10 09:00', 'momo_offer_scope' => 'all_branches', 'momo_offer_cta_type' => 'dish', 'momo_offer_cta_dish' => 0, 'momo_offer_reference_price_jpy' => '2000' ], 0 );
		$this->assertContains( 'An offer can run for at most 366 days.', $e );
		$this->assertContains( 'The linked dish is not published.', $e );
		$this->assertContains( 'A reference price needs written evidence (internal note).', $e );
		$p = OfferFields::publish_errors( [], [ 'momo_offer_conditions' => 'x', 'momo_offer_start_at' => '2026-11-01 00:00', 'momo_offer_end_at' => '2026-11-30 00:00', 'momo_offer_scope' => 'all_branches' ], [ 'post_title' => 'Old' ], 0 );
		$this->assertContains( 'This offer has already ended.', $p );
		$this->assertContains( 'Complete title, conditions and validity dates.', OfferFields::publish_errors( [], [ 'momo_offer_scope' => 'all_branches' ], [ 'post_title' => '' ], 0 ) );
	}

	public function test_absolute_claim_wording_blocked_when_dietary_guard_present(): void {
		if ( ! class_exists( \Momos\Dietary\Guard\WordingGuard::class ) ) {
			$this->markTestSkipped( 'Dietary module not present' );
		}
		$e = OfferFields::errors( [ 'momo_offer_scope' => 'all_branches', 'momo_offer_summary' => '100% halal lunch' ], 0, 'Lunch' );
		$this->assertNotEmpty( array_filter( $e, static fn( $m ) => str_contains( $m, 'not allowed in offers' ) ) );
	}

	public function test_no_public_single_url_or_rest(): void {
		$this->assertFalse( get_post_type_object( 'momo_offer' )->publicly_queryable );
		$this->assertFalse( get_post_type_object( 'momo_offer' )->show_in_rest );
		$this->assertFalse( get_post_type_object( 'momo_notice' )->show_in_rest );
	}
}
