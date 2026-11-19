<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/MenuTest.php';

use Momos\Kernel\I18n\Lang;
use Momos\Menu\Repository\BranchMenuItemRepository;
use Momos\Menu\Service\BranchMenuService;
use Momos\Menu\Service\MenuQueryService;
use Momos\Menu\Service\PriceResolver;
use Momos\Tests\TestCase;

/**
 * QA-052 regression (NFR-PERF-02, TRD §5 "no queries inside template loops"): listing a category and
 * building every dish card costs a fixed number of queries, independent of the number of dishes
 * (NFR-SCL-01: up to 200 dishes). The request-scoped branch-menu cache never serves stale rows after a write.
 */
final class MenuQueryBudgetTest extends TestCase {
	use MenuDietaryFixtures;

	private int $a;
	private int $b;

	public function set_up(): void {
		parent::set_up();
		$this->fixtures_up();
		$this->a = $this->branch( 'QBA' );
		$this->b = $this->branch( 'QBB' );
	}

	public function tear_down(): void {
		$this->fixtures_down();
		parent::tear_down();
	}

	/** @return list<int> */
	private function dishes( int $cat, int $n, int $base ): array {
		$prefix = (string) $base;
		$tag = wp_insert_term( 'ベジタリアン' . $prefix, 'momo_dietary_tag', [ 'slug' => 'veg-' . strtolower( $prefix ) ] );
		$ids = [];
		for ( $i = 0; $i < $n; $i++ ) {
			$id = $this->dish( sprintf( 'D%04d', $base + $i ), $cat, 1000 + $i );
			wp_set_object_terms( $id, [ (int) $tag['term_id'] ], 'momo_dietary_tag' );
			$this->row( $this->a, $id );
			$this->row( $this->b, $id, [ 'price_override_jpy' => 900 + $i ] );
			$ids[] = $id;
		}
		return $ids;
	}

	/** Queries to list a category with all cards (with and without branch context) and the category tiles. */
	private function measure( int $cat, ?int $branch ): array {
		wp_cache_flush();
		$n     = 0;
		$count = static function ( $q ) use ( &$n ) {
			++$n;
			return $q;
		};
		add_filter( 'query', $count );
		$ids   = MenuQueryService::dishes_in_category( $cat, Lang::SOURCE, $branch );
		$cards = array_map( static fn( int $id ) => MenuQueryService::card( $id, Lang::SOURCE, $branch ), $ids );
		MenuQueryService::filter_options( $ids, Lang::SOURCE );
		MenuQueryService::categories( Lang::SOURCE, $branch );
		remove_filter( 'query', $count );
		return [ $n, $cards ];
	}

	public function test_category_listing_and_cards_do_not_query_per_dish(): void {
		$small = $this->category( 'qb-small' );
		$large = $this->category( 'qb-large' );
		$this->dishes( $small, 2, 7100 );
		$this->dishes( $large, 12, 7200 );
		foreach ( [ null, $this->a ] as $branch ) {
			[ $q_small, $c_small ] = $this->measure( $small, $branch );
			[ $q_large, $c_large ] = $this->measure( $large, $branch );
			$this->assertCount( 2, $c_small );
			$this->assertCount( 12, $c_large );
			$this->assertLessThanOrEqual( $q_small, $q_large, sprintf( 'branch %s: 2 dishes cost %d queries, 12 dishes %d — per-dish queries in the listing', var_export( $branch, true ), $q_small, $q_large ) );
		}
		// Card content is still right when served from the primed caches.
		[ , $cards ] = $this->measure( $large, null );
		$this->assertTrue( $cards[0]['prices_may_differ'] );
		$this->assertNull( $cards[0]['availability'] );
		$this->assertSame( 'veg-7200', $cards[0]['tags'][0]['code'] );
		[ , $cards ] = $this->measure( $large, $this->b );
		$this->assertSame( 900, $cards[0]['price'] );
	}

	/** A write in the same request is visible to the next read (no stale cached rows). */
	public function test_branch_menu_cache_is_invalidated_by_writes(): void {
		$cat  = $this->category( 'qb-cache' );
		$dish = $this->dish( 'D7300', $cat, 1500 );
		BranchMenuItemRepository::prime_offered( [ $dish ] );
		BranchMenuItemRepository::prime_rows( $this->a, [ $dish ] );
		$this->assertSame( [], PriceResolver::prices_by_branch( $dish ) );
		$this->assertNull( BranchMenuItemRepository::find( $this->a, $dish ) );

		$mm  = $this->user( 'momo_menu_manager' );
		$row = BranchMenuService::update( $this->a, $dish, [ 'version' => 0, 'offered' => true ], $mm );
		$this->assertSame( [ 'QBA' => 1500 ], PriceResolver::prices_by_branch( $dish ) );
		$this->assertSame( 1, BranchMenuItemRepository::find( $this->a, $dish )['is_offered'] );

		BranchMenuService::update( $this->a, $dish, [ 'version' => $row['version'], 'price_override_jpy' => 1700 ], $mm );
		$this->assertSame( 1700, PriceResolver::price( $dish, $this->a ) );
		$this->assertSame( [ 'QBA' => 1700 ], PriceResolver::prices_by_branch( $dish ) );

		BranchMenuItemRepository::end_offers_for_dish( $dish, $mm );
		$this->assertSame( [], BranchMenuItemRepository::offered_for_dish( $dish ) );
		$this->assertNull( PriceResolver::price( $dish, $this->a ) );
	}
}
