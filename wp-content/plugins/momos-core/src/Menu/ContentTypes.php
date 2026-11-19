<?php
declare(strict_types=1);

namespace Momos\Menu;

use Momos\Kernel\I18n\Translations;

/**
 * CPT `momo_dish` and taxonomies `momo_menu_category`, `momo_ingredient`, `momo_dietary_tag`
 * (spec §2.3/§2.4, TRD §2.2). Rewrites are custom (Rewrite\MenuRewrite), REST is GET-only
 * (writes are refused by Api\CoreRestGuard and by the capability model).
 */
final class ContentTypes {
	public const DISH       = 'momo_dish';
	public const CATEGORY   = 'momo_menu_category';
	public const INGREDIENT = 'momo_ingredient';
	public const TAG        = 'momo_dietary_tag';

	public static function register(): void {
		register_post_type(
			self::DISH,
			[
				'labels'              => [
					'name'          => __( 'Dishes', 'momos-core' ),
					'singular_name' => __( 'Dish', 'momos-core' ),
					'add_new_item'  => __( 'Add dish', 'momos-core' ),
					'edit_item'     => __( 'Edit dish', 'momos-core' ),
					'all_items'     => __( 'All dishes', 'momos-core' ),
					'search_items'  => __( 'Search dishes', 'momos-core' ),
					'not_found'     => __( 'No dishes found.', 'momos-core' ),
					'menu_name'     => __( 'Menu', 'momos-core' ),
				],
				'public'              => true,
				'publicly_queryable'  => true,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'menu_position'       => 5,
				'menu_icon'           => 'dashicons-carrot',
				'show_in_rest'        => true,
				'rest_base'           => 'momo_dish',
				'hierarchical'        => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => 'momo_dish',
				'supports'            => [ 'title', 'editor', 'thumbnail', 'revisions' ],
				'capability_type'     => [ 'momo_dish', 'momo_dishes' ],
				'map_meta_cap'        => true,
				'capabilities'        => [ 'create_posts' => 'create_momo_dishes' ],
				'taxonomies'          => [ self::CATEGORY, self::INGREDIENT, self::TAG ],
			]
		);

		$term_caps = [
			'manage_terms' => 'momo_manage_menu_categories',
			'edit_terms'   => 'momo_manage_menu_categories',
			'delete_terms' => 'momo_manage_menu_categories',
			'assign_terms' => 'edit_momo_dishes',
		];

		register_taxonomy(
			self::CATEGORY,
			self::DISH,
			[
				'labels'             => [
					'name'          => __( 'Menu categories', 'momos-core' ),
					'singular_name' => __( 'Menu category', 'momos-core' ),
					'add_new_item'  => __( 'Add menu category', 'momos-core' ),
					'edit_item'     => __( 'Edit menu category', 'momos-core' ),
				],
				'public'             => true,
				'publicly_queryable' => true,
				'hierarchical'       => true, // Registered hierarchical; Phase 1 enforces top-level only (CategoryGuard).
				'show_ui'            => true,
				'show_admin_column'  => true,
				'show_in_rest'       => true,
				'rest_base'          => 'momo_menu_category',
				'rewrite'            => false,
				'query_var'          => 'momo_menu_category',
				'capabilities'       => $term_caps,
			]
		);
		register_taxonomy(
			self::INGREDIENT,
			self::DISH,
			[
				'labels'            => [
					'name'          => __( 'Key ingredients', 'momos-core' ),
					'singular_name' => __( 'Ingredient', 'momos-core' ),
					'add_new_item'  => __( 'Add ingredient', 'momos-core' ),
				],
				'public'            => false,
				'show_ui'           => true,
				'show_admin_column' => false,
				'show_in_rest'      => false,
				'hierarchical'      => false,
				'rewrite'           => false,
				'query_var'         => false,
				'capabilities'      => $term_caps,
			]
		);
		register_taxonomy(
			self::TAG,
			self::DISH,
			[
				'labels'            => [
					'name'          => __( 'Dietary tags', 'momos-core' ),
					'singular_name' => __( 'Dietary tag', 'momos-core' ),
					'add_new_item'  => __( 'Add dietary tag', 'momos-core' ),
				],
				'public'            => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => false,
				'hierarchical'      => true, // Checkbox UI; tags are flat.
				'rewrite'           => false,
				'query_var'         => false,
				'capabilities'      => $term_caps,
			]
		);

		Translations::register(
			'post:' . self::DISH,
			[
				'title'   => [ 'label' => 'Dish name', 'type' => 'text', 'source' => 'post_title', 'max' => 100, 'lccs' => true ],
				'content' => [ 'label' => 'Description', 'type' => 'html', 'source' => 'post_content', 'lccs' => true ],
			]
		);
		Translations::register(
			'term:' . self::CATEGORY,
			[
				'name'        => [ 'label' => 'Name', 'type' => 'text', 'source' => 'name', 'max' => 40 ],
				'description' => [ 'label' => 'Intro', 'type' => 'textarea', 'source' => 'description', 'max' => 300 ],
			]
		);
		Translations::register( 'term:' . self::INGREDIENT, [ 'name' => [ 'label' => 'Name', 'type' => 'text', 'source' => 'name', 'max' => 60 ] ] );
		Translations::register(
			'term:' . self::TAG,
			[
				'name'        => [ 'label' => 'Name', 'type' => 'text', 'source' => 'name' ],
				'description' => [ 'label' => 'Legend text', 'type' => 'textarea', 'source' => 'description' ],
			]
		);
	}

	/**
	 * Core REST (`wp/v2/momo_dish`) and core sitemaps never list sample dishes in production
	 * (BR-CORP-03, FR-MENU-009); drafts/pending/retired are excluded by core (not published).
	 *
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public static function exclude_samples( array $args, mixed $context = null ): array {
		if ( ! \Momos\Kernel\Env::is_production() || ( is_string( $context ) && self::DISH !== $context ) ) {
			return $args;
		}
		$args['meta_query']   = (array) ( $args['meta_query'] ?? [] ); // phpcs:ignore
		$args['meta_query'][] = [
			'relation' => 'OR',
			[ 'key' => 'momo_is_sample', 'compare' => 'NOT EXISTS' ],
			[ 'key' => 'momo_is_sample', 'value' => '1', 'compare' => '!=' ],
		];
		return $args;
	}

	/** Classic editor for dishes (spec: block editor disabled). */
	public static function disable_block_editor( bool $use, string $post_type ): bool {
		return self::DISH === $post_type ? false : $use;
	}
}
