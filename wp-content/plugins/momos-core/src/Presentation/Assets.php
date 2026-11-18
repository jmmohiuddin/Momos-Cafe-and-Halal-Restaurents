<?php
declare(strict_types=1);

namespace Momos\Presentation;

use Momos\Kernel\I18n\Locale;

/**
 * Front-end assets of the `momos` theme (ui-design-system.md §2, §4.3; NFR-PERF-03, DEC-061):
 * tokens.css → momos-ui.css → screens.css (screen layouts, TASK 2) → fonts-{lang}.css (current language
 * only), one preloaded text face, deferred frame.js + screens.js. No Google Fonts/CDN, no emoji script, no global-styles CSS of block themes.
 */
final class Assets {
	/** Preloaded text face (400) per language — the file the first paint needs most. */
	public const PRELOAD = [
		'ja' => 'noto-sans-jp/noto-sans-jp-400-119.woff2',
		'en' => 'plus-jakarta-sans/plus-jakarta-sans-400.woff2',
		'ar' => 'noto-naskh-arabic/noto-naskh-arabic-400.woff2',
		'ur' => 'noto-nastaliq-urdu/noto-nastaliq-urdu-400.woff2',
		'hi' => 'noto-sans-devanagari/noto-sans-devanagari-400.woff2',
		'bn' => 'noto-sans-bengali/noto-sans-bengali-400.woff2',
	];

	public static function register(): void {
		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue' ], 5 );
		add_action( 'wp_enqueue_scripts', [ self::class, 'dequeue' ], 100 );
		add_action( 'wp_head', [ self::class, 'preload' ], 2 );
		add_filter( 'should_load_separate_core_block_assets', '__return_true' );
		add_action( 'init', [ self::class, 'disable_emoji' ] );
		add_action( 'after_setup_theme', [ self::class, 'disable_global_styles' ], 20 );
	}

	public static function active(): bool {
		return current_theme_supports( 'momos-design-system' );
	}

	public static function version( string $rel ): string {
		$file = get_theme_file_path( $rel );
		return is_file( $file ) ? (string) filemtime( $file ) : MOMOS_CORE_VERSION;
	}

	public static function enqueue(): void {
		if ( ! self::active() ) {
			return;
		}
		$lang = Locale::current();
		wp_enqueue_style( 'momos-tokens', get_theme_file_uri( 'assets/css/tokens.css' ), [], self::version( 'assets/css/tokens.css' ) );
		wp_enqueue_style( 'momos-fonts', get_theme_file_uri( 'assets/css/fonts-' . $lang . '.css' ), [], self::version( 'assets/css/fonts-' . $lang . '.css' ) );
		wp_enqueue_style( 'momos-ui', get_theme_file_uri( 'assets/css/momos-ui.css' ), [ 'momos-tokens' ], self::version( 'assets/css/momos-ui.css' ) );
		wp_enqueue_style( 'momos-screens', get_theme_file_uri( 'assets/css/screens.css' ), [ 'momos-ui' ], self::version( 'assets/css/screens.css' ) );
		wp_enqueue_script( 'momos-screens', get_theme_file_uri( 'assets/js/screens.js' ), [], self::version( 'assets/js/screens.js' ), [ 'in_footer' => true, 'strategy' => 'defer' ] );
		wp_enqueue_script( 'momos-frame', get_theme_file_uri( 'assets/js/frame.js' ), [], self::version( 'assets/js/frame.js' ), [ 'in_footer' => true, 'strategy' => 'defer' ] );
	}

	public static function dequeue(): void {
		if ( ! self::active() ) {
			return;
		}
		foreach ( [ 'global-styles', 'classic-theme-styles' ] as $h ) {
			wp_dequeue_style( $h );
		}
	}

	public static function preload(): void {
		if ( ! self::active() ) {
			return;
		}
		$rel = self::PRELOAD[ Locale::current() ] ?? null;
		if ( null === $rel ) {
			return;
		}
		printf( '<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n", esc_url( get_theme_file_uri( 'assets/fonts/' . $rel ) ) );
	}

	/** Block-theme global styles/presets are not used: tokens.css is the single source (§0, Doc 09 §2.10). */
	public static function disable_global_styles(): void {
		if ( ! self::active() ) {
			return;
		}
		remove_action( 'wp_enqueue_scripts', 'wp_enqueue_global_styles' );
		remove_action( 'wp_footer', 'wp_enqueue_global_styles', 1 );
		remove_action( 'wp_body_open', 'wp_global_styles_render_svg_filters' );
		add_theme_support( 'editor-color-palette', [] );
		add_theme_support( 'editor-gradient-presets', [] );
		add_theme_support( 'disable-custom-colors' );
		add_theme_support( 'disable-custom-gradients' );
		add_theme_support( 'disable-custom-font-sizes' );
	}

	public static function disable_emoji(): void {
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
	}

	/** Icon sprite URL (same origin; cached). */
	public static function sprite_url(): string {
		return get_theme_file_uri( 'assets/icons/sprite.svg' ) . '?v=' . self::version( 'assets/icons/sprite.svg' );
	}
}
