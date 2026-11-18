<?php
declare(strict_types=1);

namespace Momos\Dietary\Guard;

use Momos\Kernel\Events\AfterCommit;
use Momos\Menu\Support\CachePurge;

/**
 * BR-DIET-07 / AC-DIET-02-04: when key ingredients or the recipe link (post_parent / momo_variant_of) of a
 * published dish change, set `momo_allergen_review_required = 1` (banner in all languages until a CAP-DCA
 * re-save of G10), audit `dietary.review_required_set`, emit `momos/dietary/review_required (int $dish_id)`
 * and purge the dish caches.
 */
final class IngredientChangeWatcher {
	public static function register(): void {
		add_action( 'set_object_terms', [ self::class, 'on_set_terms' ], 10, 6 );
		add_action( 'post_updated', [ self::class, 'on_post_updated' ], 10, 3 );
	}

	/**
	 * @param array<int,mixed> $terms
	 * @param list<int>        $tt_ids
	 * @param list<int>        $old_tt_ids
	 */
	public static function on_set_terms( int $object_id, $terms, array $tt_ids, string $taxonomy, bool $append, array $old_tt_ids ): void {
		if ( 'momo_ingredient' !== $taxonomy || 'publish' !== get_post_status( $object_id ) || 'momo_dish' !== get_post_type( $object_id ) ) {
			return;
		}
		$new = array_map( 'intval', $tt_ids );
		$old = array_map( 'intval', $old_tt_ids );
		sort( $new );
		sort( $old );
		if ( $new === $old ) {
			return;
		}
		self::flag( $object_id, [ 'ingredients_added' => self::names( array_diff( $new, $old ) ), 'ingredients_removed' => self::names( array_diff( $old, $new ) ) ] );
	}

	public static function on_post_updated( int $post_id, \WP_Post $after, \WP_Post $before ): void {
		if ( 'momo_dish' === $after->post_type && 'publish' === $before->post_status && 'publish' === $after->post_status && (int) $after->post_parent !== (int) $before->post_parent ) {
			self::flag( $post_id, [ 'recipe_link' => [ (int) $before->post_parent, (int) $after->post_parent ] ] );
		}
	}

	/** @param array<string,mixed> $changes */
	public static function flag( int $dish_id, array $changes ): void {
		ClaimFieldGuard::system( static fn() => update_post_meta( $dish_id, 'momo_allergen_review_required', '1' ) );
		momos_audit( 'dietary.review_required_set', 'dish', $dish_id, null, $changes );
		AfterCommit::dispatch( 'momos/dietary/review_required', $dish_id );
		CachePurge::dish( $dish_id );
	}

	/** @param array<int> $tt_ids @return list<string> */
	private static function names( array $tt_ids ): array {
		$out = [];
		foreach ( $tt_ids as $tt ) {
			$t = get_term_by( 'term_taxonomy_id', (int) $tt, 'momo_ingredient' );
			if ( $t instanceof \WP_Term ) {
				$out[] = $t->name;
			}
		}
		return $out;
	}
}
