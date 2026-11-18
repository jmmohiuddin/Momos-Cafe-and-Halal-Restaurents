<?php
declare(strict_types=1);

namespace Momos\Menu\Service;

use Momos\Menu\ContentTypes;

/**
 * Term integrity (FR-MENU-005, spec §2.4/§5.4): menu categories are top-level only (Phase 1), slugs
 * match `^[a-z0-9-]+$`, a category with ≥ 1 dish (any status) cannot be deleted, used ingredients /
 * dietary tags cannot be deleted, claim tags are edited only by CAP-DCA holders. Old category slugs are
 * kept for 301 redirects.
 */
final class CategoryGuard {
	/** @var array<int,string> */
	private static array $slug_before = [];

	public static function register(): void {
		add_filter( 'map_meta_cap', [ self::class, 'map_term_caps' ], 10, 4 );
		add_filter( 'wp_update_term_parent', [ self::class, 'force_top_level' ], 10, 3 );
		add_filter( 'pre_insert_term', [ self::class, 'validate_new_term' ], 10, 3 );
		add_action( 'edit_terms', [ self::class, 'remember_slug' ], 10, 2 );
		add_action( 'edited_' . ContentTypes::CATEGORY, [ self::class, 'after_edit' ], 10, 1 );
		add_action( 'pre_delete_term', [ self::class, 'pre_delete' ], 10, 2 );
		foreach ( [ ContentTypes::CATEGORY, ContentTypes::INGREDIENT, ContentTypes::TAG ] as $tax ) {
			add_filter( $tax . '_row_actions', [ self::class, 'row_actions' ], 10, 2 );
		}
	}

	/** Reason why a term cannot be deleted, or null. */
	public static function delete_block_reason( int $term_id ): ?string {
		$term = get_term( $term_id );
		if ( ! $term instanceof \WP_Term ) {
			return null;
		}
		$count = self::object_count( $term );
		if ( ContentTypes::CATEGORY === $term->taxonomy && $count > 0 ) {
			/* translators: %d: number of dishes */
			return sprintf( __( 'This category contains %d dishes. Move them before deleting.', 'momos-core' ), $count );
		}
		if ( in_array( $term->taxonomy, [ ContentTypes::INGREDIENT, ContentTypes::TAG ], true ) && $count > 0 ) {
			/* translators: %d: number of dishes */
			return sprintf( __( 'This term is used by %d dishes. Remove it from them before deleting.', 'momos-core' ), $count );
		}
		return null;
	}

	/** Dishes of any status (except trash/auto-draft) using the term. */
	public static function object_count( \WP_Term $term ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->term_relationships} tr JOIN {$wpdb->posts} p ON p.ID = tr.object_id
				 WHERE tr.term_taxonomy_id = %d AND p.post_type = %s AND p.post_status NOT IN ('trash','auto-draft')",
				(int) $term->term_taxonomy_id,
				ContentTypes::DISH
			)
		);
	}

	/** @param list<string> $caps @param array<int,mixed> $args @return list<string> */
	public static function map_term_caps( array $caps, string $cap, int $user_id, array $args ): array {
		if ( ! in_array( $cap, [ 'delete_term', 'edit_term' ], true ) || ! isset( $args[0] ) ) {
			return $caps;
		}
		$term = get_term( (int) $args[0] );
		if ( ! $term instanceof \WP_Term || ! in_array( $term->taxonomy, [ ContentTypes::CATEGORY, ContentTypes::INGREDIENT, ContentTypes::TAG ], true ) ) {
			return $caps;
		}
		if ( 'delete_term' === $cap && null !== self::delete_block_reason( $term->term_id ) ) {
			return [ 'do_not_allow' ];
		}
		if ( ContentTypes::TAG === $term->taxonomy && '1' === (string) get_term_meta( $term->term_id, 'momo_tag_is_claim', true ) ) {
			$caps[] = 'momo_edit_dietary_claims'; // Claim tags: CAP-DCA only (spec §2.4).
		}
		return $caps;
	}

	public static function force_top_level( int $parent, int $term_id, string $taxonomy ): int {
		return in_array( $taxonomy, [ ContentTypes::CATEGORY, ContentTypes::TAG ], true ) ? 0 : $parent;
	}

	/**
	 * @param string|\WP_Error $term
	 * @param array<string,mixed> $args
	 * @return string|\WP_Error
	 */
	public static function validate_new_term( $term, string $taxonomy, $args = [] ) {
		if ( ! in_array( $taxonomy, [ ContentTypes::CATEGORY, ContentTypes::TAG ], true ) || is_wp_error( $term ) ) {
			return $term;
		}
		if ( ! empty( $args['parent'] ) ) {
			return new \WP_Error( 'top_level_only', __( 'Categories are top-level only (no sub-categories in Phase 1).', 'momos-core' ) );
		}
		if ( ContentTypes::CATEGORY !== $taxonomy ) {
			return $term;
		}
		$slug = (string) ( $args['slug'] ?? '' );
		if ( '' === $slug ) {
			$slug = sanitize_title( (string) $term );
		}
		if ( ! preg_match( '/^[a-z0-9-]+$/', $slug ) ) {
			return new \WP_Error( 'invalid_slug', __( 'Enter a slug with lowercase letters a–z, digits and hyphens only (shared by all languages).', 'momos-core' ) );
		}
		if ( mb_strlen( (string) $term ) > 40 ) {
			return new \WP_Error( 'name_too_long', __( 'The category name can have at most 40 characters.', 'momos-core' ) );
		}
		return $term;
	}

	public static function remember_slug( int $term_id, string $taxonomy ): void {
		if ( ContentTypes::CATEGORY === $taxonomy ) {
			$t = get_term( $term_id );
			if ( $t instanceof \WP_Term ) {
				self::$slug_before[ $term_id ] = $t->slug;
			}
		}
	}

	public static function after_edit( int $term_id ): void {
		$old = self::$slug_before[ $term_id ] ?? null;
		$t   = get_term( $term_id );
		if ( null === $old || ! $t instanceof \WP_Term || $old === $t->slug ) {
			return;
		}
		if ( ! preg_match( '/^[a-z0-9-]+$/', $t->slug ) ) {
			wp_update_term( $term_id, ContentTypes::CATEGORY, [ 'slug' => $old ] ); // Restore; pattern violated.
			\Momos\Kernel\Fields\FieldRegistry::add_errors( [ __( 'Enter a slug with lowercase letters a–z, digits and hyphens only (shared by all languages).', 'momos-core' ) ] );
			return;
		}
		$olds   = (array) get_term_meta( $term_id, '_momo_old_slugs', true );
		$olds[] = $old;
		update_term_meta( $term_id, '_momo_old_slugs', array_values( array_unique( array_filter( $olds ) ) ) );
		\Momos\Menu\Support\CachePurge::tags( [ 'momo-cat-' . $term_id ] );
	}

	/** Last line of defence for programmatic deletes (UI is blocked by map_meta_cap). */
	public static function pre_delete( int $term_id, string $taxonomy ): void {
		if ( ! in_array( $taxonomy, [ ContentTypes::CATEGORY, ContentTypes::INGREDIENT, ContentTypes::TAG ], true ) ) {
			return;
		}
		$reason = self::delete_block_reason( $term_id );
		if ( null !== $reason ) {
			throw new \RuntimeException( esc_html( $reason ) );
		}
	}

	/** @param array<string,string> $actions @return array<string,string> */
	public static function row_actions( array $actions, \WP_Term $term ): array {
		$reason = self::delete_block_reason( $term->term_id );
		if ( null !== $reason ) {
			unset( $actions['delete'] );
			$actions['momos_locked'] = '<span class="momos-locked">' . esc_html( $reason ) . '</span>';
		}
		return $actions;
	}

	/** Category resolved from a current or former slug. */
	public static function category_by_any_slug( string $slug ): ?\WP_Term {
		$t = get_term_by( 'slug', $slug, ContentTypes::CATEGORY );
		if ( $t instanceof \WP_Term ) {
			return $t;
		}
		$terms = get_terms( [ 'taxonomy' => ContentTypes::CATEGORY, 'hide_empty' => false, 'meta_query' => [ [ 'key' => '_momo_old_slugs', 'value' => '"' . $slug . '"', 'compare' => 'LIKE' ] ] ] ); // phpcs:ignore
		return ( is_array( $terms ) && $terms ) ? $terms[0] : null;
	}
}
