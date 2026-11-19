<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/BranchTestCaseTest.php';

use Momos\Branch\Admin\ListScreens;
use Momos\Branch\PostType\BranchScopeTaxonomy;
use Momos\Branch\Service\BranchVisibility;

/**
 * Secondary channels of branch data: core search / oEmbed never expose TEST or permanently closed
 * branches (QA-009, FR-RES-026, spec §4.9); admin list view counts are branch-scoped (QA-010,
 * access spec §4.3 E3, TC-ACL-003).
 */
final class BranchVisibilityChannelsTest extends BranchTestCaseTest {
	public function test_core_search_and_oembed_exclude_test_and_closed_branches_qa_009(): void {
		$a   = $this->make_branch( 'QAA' );
		$tst = $this->make_branch( 'TST', [ 'momo_is_test_branch' => '1' ] );
		$c   = $this->make_branch( 'QAZ', [ 'momo_operating_status' => 'closed_permanently' ] );
		$req = new \WP_REST_Request( 'GET', '/wp/v2/search' );
		$req->set_query_params( [ 'search' => '支店', 'subtype' => 'any', 'per_page' => 50 ] );
		$res = rest_do_request( $req );
		$ids = array_map( static fn( $r ) => (int) $r['id'], (array) $res->get_data() );
		$this->assertContains( $a, $ids, 'public branch is searchable' );
		$this->assertNotContains( $tst, $ids, 'TEST branch not in wp/v2/search' );
		$this->assertNotContains( $c, $ids, 'closed branch not in wp/v2/search' );

		$this->assertSame( 0, BranchVisibility::oembed_post_id( $tst ) );
		$this->assertSame( 0, BranchVisibility::oembed_post_id( $c ) );
		$this->assertSame( $a, BranchVisibility::oembed_post_id( $a ) );
		$req = new \WP_REST_Request( 'GET', '/oembed/1.0/embed' );
		$req->set_query_params( [ 'url' => home_url( '/?p=' . $tst ) ] );
		$this->assertSame( 404, rest_do_request( $req )->get_status(), 'oEmbed by ?p= of the TEST branch' );
	}

	public function test_list_view_counts_are_branch_scoped_qa_010(): void {
		$a  = $this->make_branch( 'QAA' );
		$b  = $this->make_branch( 'QAB' );
		$t  = $this->make_branch( 'TST', [ 'momo_is_test_branch' => '1' ] );
		$na = self::factory()->post->create( [ 'post_type' => 'momo_notice', 'post_status' => 'publish', 'post_title' => 'A' ] );
		$nb = self::factory()->post->create( [ 'post_type' => 'momo_notice', 'post_status' => 'publish', 'post_title' => 'B' ] );
		wp_set_object_terms( $na, [ BranchScopeTaxonomy::term_for_branch( $a )->term_id ], BranchScopeTaxonomy::TAX );
		wp_set_object_terms( $nb, [ BranchScopeTaxonomy::term_for_branch( $b )->term_id ], BranchScopeTaxonomy::TAX );
		unset( $t );
		set_current_screen( 'edit-momo_notice' );
		ListScreens::register(); // Admin-only hooks (BranchModule registers them when is_admin()).
		wp_set_current_user( $this->user_with_role( 'momo_head_office' ) );
		$this->assertSame( 3, (int) wp_count_posts( 'momo_branch', 'readable' )->publish, 'HO counts every branch' );
		$this->assertSame( 2, (int) array_sum( array_map( 'intval', (array) wp_count_posts( 'momo_notice', 'readable' ) ) ), 'HO counts every notice' );
		wp_set_current_user( $this->user_with_role( 'momo_branch_manager', [ $a ] ) );
		$this->assertSame( 1, (int) wp_count_posts( 'momo_branch', 'readable' )->publish, 'BM: own branch only' );
		$total = static fn( string $pt ): int => (int) array_sum( array_map( 'intval', (array) wp_count_posts( $pt, 'readable' ) ) );
		$this->assertSame( 1, $total( 'momo_notice' ), 'BM: own branch notices only' );
		$this->assertSame( 0, $total( 'momo_offer' ) );
		set_current_screen( 'front' );
	}
}
