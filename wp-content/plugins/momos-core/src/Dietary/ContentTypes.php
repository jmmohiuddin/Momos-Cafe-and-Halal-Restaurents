<?php
declare(strict_types=1);

namespace Momos\Dietary;

use Momos\Kernel\I18n\Translations;

/**
 * Taxonomy `momo_allergen` (ENT-09) and private CPT `momo_certificate` (ENT-12, G14).
 * Certificates: not public, not in REST (SEC-023), never deleted (revoke instead), caps
 * `*_momo_certificates` effective only together with CAP-DCA (M4, enforced by CertificateGuard).
 */
final class ContentTypes {
	public const CERTIFICATE = 'momo_certificate';

	public static function register(): void {
		register_taxonomy(
			Allergens::TAXONOMY,
			'momo_dish',
			[
				'labels'            => [
					'name'          => __( 'Allergens', 'momos-core' ),
					'singular_name' => __( 'Allergen', 'momos-core' ),
					'add_new_item'  => __( 'Add custom allergen', 'momos-core' ),
				],
				'public'            => false,
				'show_ui'           => true,
				'show_in_menu'      => true,
				'show_admin_column' => false,
				'show_in_rest'      => false,
				'meta_box_cb'       => false,
				'hierarchical'      => false,
				'rewrite'           => false,
				'query_var'         => false,
				'capabilities'      => [
					'manage_terms' => 'momo_edit_dietary_claims',
					'edit_terms'   => 'momo_edit_dietary_claims',
					'delete_terms' => 'momo_edit_dietary_claims',
					'assign_terms' => 'momo_edit_dietary_claims',
				],
			]
		);
		Translations::register( 'term:' . Allergens::TAXONOMY, [ 'name' => [ 'label' => 'Name', 'type' => 'text', 'source' => 'name', 'max' => 60 ] ] );

		register_post_type(
			self::CERTIFICATE,
			[
				'labels'              => [
					'name'          => __( 'Halal register', 'momos-core' ),
					'singular_name' => __( 'Claim record', 'momos-core' ),
					'add_new_item'  => __( 'Add claim record', 'momos-core' ),
					'edit_item'     => __( 'Edit claim record', 'momos-core' ),
					'all_items'     => __( 'Claim records', 'momos-core' ),
				],
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => 'momos',
				'show_in_rest'        => false,
				'show_in_nav_menus'   => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'hierarchical'        => false,
				'supports'            => [ 'title' ],
				'capability_type'     => [ 'momo_certificate', 'momo_certificates' ],
				'map_meta_cap'        => true,
				'capabilities'        => [ 'create_posts' => 'create_momo_certificates' ],
			]
		);
	}

	/** Block editor off for certificates. */
	public static function disable_block_editor( bool $use, string $post_type ): bool {
		return self::CERTIFICATE === $post_type ? false : $use;
	}
}
