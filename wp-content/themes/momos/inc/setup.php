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
		// Cards are 4:3 (CMP-OfferCard, screens.md §3.17; Doc 08 §2.10 card ≤ 80 KB): hard-cropped 600×450 plus a
		// 400×300 step so `srcset` offers ≥ 2 same-ratio candidates and never falls back to the full original.
		add_image_size( 'momo-card', 600, 450, true );
		add_image_size( 'momo-card-sm', 400, 300, true );
		add_image_size( 'momo-thumb', 300, 300, true );
	}
);

/**
 * Sub-sizes are written as WebP when the image editor supports it (Doc 08 §2.10, NFR-PERF-03, TC-PERF-003).
 * The uploaded original keeps its format; it is dropped from `srcset` below whenever WebP candidates exist.
 */
add_filter(
	'image_editor_output_format',
	static function ( array $formats ): array {
		if ( function_exists( 'wp_image_editor_supports' ) && wp_image_editor_supports( [ 'mime_type' => 'image/webp' ] ) ) {
			$formats['image/jpeg'] = 'image/webp';
			$formats['image/png']  = 'image/webp';
		}
		return $formats;
	}
);

// WebP sub-size quality: photos stay sharp at 2× while cards fit the 80 KB budget (Doc 08 §2.10).
add_filter(
	'wp_editor_set_quality',
	static function ( $quality, $mime_type = '' ) {
		return 'image/webp' === $mime_type ? 78 : $quality;
	},
	10,
	2
);

/**
 * `srcset`: keep only WebP/AVIF candidates when the attachment has them, so a high-DPR phone never picks the
 * unoptimised JPEG original (QA-020: 218 KB original chosen for a card).
 *
 * @param array<int,array{url:string,descriptor:string,value:int}>|mixed $sources
 * @return array<int,array{url:string,descriptor:string,value:int}>|mixed
 */
add_filter(
	'wp_calculate_image_srcset',
	static function ( $sources ) {
		if ( ! is_array( $sources ) ) {
			return $sources;
		}
		$modern = array_filter( $sources, static fn( $s ) => is_array( $s ) && (bool) preg_match( '/\.(webp|avif)$/i', (string) ( $s['url'] ?? '' ) ) );
		return count( $modern ) >= 1 && count( $modern ) < count( $sources ) ? $modern : $sources;
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
