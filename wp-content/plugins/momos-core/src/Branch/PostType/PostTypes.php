<?php
declare(strict_types=1);

namespace Momos\Branch\PostType;

/**
 * CPT registrations momo_branch, momo_notice, momo_offer (spec §3.1, TRD §2.3).
 */
final class PostTypes {
	public const BRANCH = 'momo_branch';
	public const NOTICE = 'momo_notice';
	public const OFFER  = 'momo_offer';

	public static function register(): void {
		add_action( 'init', [ self::class, 'register_types' ], 5 );
		add_filter( 'use_block_editor_for_post_type', [ self::class, 'disable_block_editor' ], 10, 2 );
	}

	public static function register_types(): void {
		register_post_type(
			self::BRANCH,
			[
				'labels'              => self::labels( __( 'Branches', 'momos-core' ), __( 'Branch', 'momos-core' ) ),
				'public'              => true,
				'publicly_queryable'  => true,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'menu_position'       => 21,
				'menu_icon'           => 'dashicons-location',
				'show_in_rest'        => true, // GET only, public fields only (BranchVisibility::rest_*).
				'rest_base'           => 'momo_branch',
				'rewrite'             => [ 'slug' => 'branches', 'with_front' => false ],
				'has_archive'         => false,
				'hierarchical'        => false,
				'supports'            => [ 'title', 'editor', 'thumbnail', 'revisions', 'page-attributes' ],
				'capability_type'     => [ 'momo_branch', 'momo_branches' ],
				'map_meta_cap'        => true,
				'capabilities'        => [ 'create_posts' => 'create_momo_branches' ],
				'exclude_from_search' => true,
				'delete_with_user'    => false,
			]
		);
		register_post_type(
			self::NOTICE,
			[
				'labels'              => self::labels( __( 'Notices', 'momos-core' ), __( 'Notice', 'momos-core' ) ),
				'public'              => false,
				'publicly_queryable'  => false,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'menu_position'       => 22,
				'menu_icon'           => 'dashicons-megaphone',
				'show_in_rest'        => false,
				'rewrite'             => false,
				'has_archive'         => false,
				'supports'            => [ 'title', 'revisions' ],
				'capability_type'     => [ 'momo_notice', 'momo_notices' ],
				'map_meta_cap'        => true,
				'capabilities'        => [ 'create_posts' => 'create_momo_notices' ],
				'exclude_from_search' => true,
				'delete_with_user'    => false,
				'query_var'           => false,
			]
		);
		register_post_type(
			self::OFFER,
			[
				'labels'              => self::labels( __( 'Offers', 'momos-core' ), __( 'Offer', 'momos-core' ) ),
				'public'              => true,
				'publicly_queryable'  => false, // No single URLs (FR-CORP-010, AC-CORP-09-10).
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_nav_menus'   => false,
				'menu_position'       => 23,
				'menu_icon'           => 'dashicons-tag',
				'show_in_rest'        => false,
				'rewrite'             => false,
				'has_archive'         => false,
				'supports'            => [ 'title', 'thumbnail', 'revisions' ],
				'capability_type'     => [ 'momo_offer', 'momo_offers' ],
				'map_meta_cap'        => true,
				'capabilities'        => [ 'create_posts' => 'create_momo_offers' ],
				'exclude_from_search' => true,
				'delete_with_user'    => false,
				'query_var'           => false,
			]
		);
	}

	public static function disable_block_editor( bool $use, string $post_type ): bool {
		return in_array( $post_type, [ self::BRANCH, self::NOTICE, self::OFFER ], true ) ? false : $use;
	}

	/** @return array<string,string> */
	private static function labels( string $plural, string $singular ): array {
		return [
			'name'          => $plural,
			'singular_name' => $singular,
			'menu_name'     => $plural,
			/* translators: %s: item type */
			'add_new_item'  => sprintf( __( 'Add %s', 'momos-core' ), $singular ),
			/* translators: %s: item type */
			'edit_item'     => sprintf( __( 'Edit %s', 'momos-core' ), $singular ),
			'all_items'     => $plural,
			/* translators: %s: item type plural */
			'not_found'     => sprintf( __( 'No %s yet', 'momos-core' ), strtolower( $plural ) ),
		];
	}
}
