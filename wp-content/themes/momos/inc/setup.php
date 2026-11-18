<?php
/**
 * Theme supports and image sizes (ui-design-system.md §2, §10; TK-03-01/11). Navigation is defined in
 * code (Momos\Presentation\View\Navigation) — no WP menu locations.
 *
 * @package momos
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'after_setup_theme',
	static function (): void {
		add_theme_support( 'title-tag' );
		add_theme_support( 'post-thumbnails' );
		add_theme_support( 'responsive-embeds' );
		add_theme_support( 'html5', [ 'search-form', 'gallery', 'caption', 'style', 'script', 'navigation-widgets' ] );
		// Read by momos-core Presentation\Assets: this theme ships the design system files.
		add_theme_support( 'momos-design-system' );
		// IMPL-001: pages stay editable with Elementor if the owner installs it; header/footer remain theme-rendered.
		add_theme_support( 'elementor' );
		remove_theme_support( 'core-block-patterns' );
		remove_theme_support( 'block-templates' );

		add_image_size( 'momo-hero', 1600, 0 );
		add_image_size( 'momo-card', 600, 0 );
		add_image_size( 'momo-thumb', 300, 300, true );
	}
);

/**
 * Elementor Pro theme locations (only when Elementor Pro is active; harmless otherwise).
 *
 * @param object $manager Elementor Theme Builder locations manager.
 */
add_action(
	'elementor/theme/register_locations',
	static function ( $manager ): void {
		if ( is_object( $manager ) && method_exists( $manager, 'register_core_location' ) ) {
			$manager->register_core_location( 'single' );
			$manager->register_core_location( 'archive' );
		}
	}
);

// No customer accounts, comments or feeds on the public site (DEC-041, i18n-seo-analytics.md §8).
add_filter( 'comments_open', '__return_false' );
add_filter( 'pings_open', '__return_false' );
remove_action( 'wp_head', 'feed_links', 2 );
remove_action( 'wp_head', 'feed_links_extra', 3 );
remove_action( 'wp_head', 'rsd_link' );
remove_action( 'wp_head', 'wlwmanifest_link' );
remove_action( 'wp_head', 'wp_generator' );
