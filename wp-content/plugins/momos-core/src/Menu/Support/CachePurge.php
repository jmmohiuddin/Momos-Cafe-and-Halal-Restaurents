<?php
declare(strict_types=1);

namespace Momos\Menu\Support;

/**
 * Cache-tag purge requests (spec §3.18): `momo-dish-{id}`, `momo-cat-{term}`, `momo-home`, `momo-halal`,
 * `momo-branch-{id}`. The page-cache layer (Presentation/host integration) listens to
 * `momos/cache/purge` (list<string> $tags) and must purge within 60 s. Object caches of the posts are
 * cleaned here directly.
 */
final class CachePurge {
	/** @param list<string> $tags */
	public static function tags( array $tags ): void {
		$tags = array_values( array_unique( array_filter( $tags ) ) );
		if ( $tags ) {
			\Momos\Kernel\Events\AfterCommit::dispatch( 'momos/cache/purge', $tags );
		}
	}

	/** Dish page + its categories (+ home when featured). */
	public static function dish( int $dish_id ): void {
		clean_post_cache( $dish_id );
		$tags  = [ 'momo-dish-' . $dish_id ];
		$terms = wp_get_object_terms( $dish_id, 'momo_menu_category', [ 'fields' => 'ids' ] );
		foreach ( is_array( $terms ) ? $terms : [] as $t ) {
			$tags[] = 'momo-cat-' . (int) $t;
		}
		if ( '1' === (string) get_post_meta( $dish_id, 'momo_featured', true ) ) {
			$tags[] = 'momo-home';
		}
		self::tags( $tags );
	}

	/** Claim-related change: halal page, home and the given branches/dishes. */
	public static function claims( array $branch_ids = [], array $dish_ids = [] ): void {
		$tags = [ 'momo-halal', 'momo-home' ];
		foreach ( $branch_ids as $b ) {
			$tags[] = 'momo-branch-' . (int) $b;
		}
		foreach ( $dish_ids as $d ) {
			$tags[] = 'momo-dish-' . (int) $d;
		}
		self::tags( $tags );
	}
}
