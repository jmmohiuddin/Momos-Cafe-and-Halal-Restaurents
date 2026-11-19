<?php
declare(strict_types=1);

namespace Momos\Seo\Analytics;

use Momos\Kernel\I18n\Lang;
use Momos\Kernel\I18n\Locale;
use Momos\Seo\Context;
use Momos\Seo\SeoSettings;

/**
 * First-party analytics loader (F-ANL-01/02, DEC-030, DEC-066; i18n-seo-analytics.md §12.1;
 * Doc 11 PRV-006/007/022/024). The server decides whether a page may carry analytics at all
 * (cache-safe: the same for every anonymous visitor); the theme script `assets/js/datalayer.js`
 * decides per visitor (opt-out cookie `momo_analytics_optout=1` or Global Privacy Control → GTM is
 * never injected) and enforces the event/parameter allow-list.
 *
 * Never loaded: wp-admin/login, logged-in users, C10/C11 (no third-party script at all), TEST branch
 * pages, hidden-language previews, 410 responses, pages without the momos theme. No `<noscript>` GTM
 * iframe anywhere (it would bypass opt-out/GPC). Without a valid container ID the dataLayer still
 * works (QA) but nothing is sent to Google.
 */
final class TagLoader {
	public const HANDLE = 'momos-datalayer';
	public const SCRIPT = 'assets/js/datalayer.js';

	public static function register(): void {
		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue' ], 20 );
		add_filter( 'script_loader_tag', [ self::class, 'tag' ], 10, 2 );
	}

	/** May this response carry the dataLayer / GTM loader? */
	public static function allowed(): bool {
		if ( is_admin() || is_user_logged_in() || wp_doing_ajax() || is_feed() || is_robots() ) {
			return false;
		}
		if ( ! current_theme_supports( 'momos-design-system' ) ) {
			return false;
		}
		$type = Context::page_type();
		if ( in_array( $type, [ 'reservation_submitted', 'reservation_status' ], true ) ) {
			return false; // DEC-066.
		}
		$lang = Locale::current();
		if ( Lang::SOURCE !== $lang && ! Lang::is_enabled( $lang ) ) {
			return false;
		}
		$c = Context::current();
		if ( Context::PAGE === $c['kind'] && in_array( $c['role'], Context::NOINDEX_ROLES, true ) ) {
			return false;
		}
		if ( Context::BRANCH === $c['kind'] && '1' === (string) get_post_meta( $c['id'], 'momo_is_test_branch', true ) ) {
			return false;
		}
		if ( \Momos\Seo\Redirects\RedirectMatcher::is_gone() ) {
			return false;
		}
		return (bool) apply_filters( 'momos/analytics/allowed', true, $type );
	}

	public static function enqueue(): void {
		if ( ! self::allowed() ) {
			return;
		}
		$file = get_theme_file_path( self::SCRIPT );
		if ( ! is_file( $file ) ) {
			return;
		}
		wp_enqueue_script( self::HANDLE, get_theme_file_uri( self::SCRIPT ), [], (string) filemtime( $file ), [ 'in_footer' => false, 'strategy' => 'defer' ] );
	}

	/** Adds the container ID (or nothing) as a data attribute — no inline script needed (CSP). */
	public static function tag( string $tag, string $handle ): string {
		if ( self::HANDLE !== $handle ) {
			return $tag;
		}
		$gtm = SeoSettings::gtm_id();
		$add = ' data-momo-datalayer' . ( '' !== $gtm ? ' data-gtm="' . esc_attr( $gtm ) . '"' : '' );
		return (string) preg_replace( '/<script\b/', '<script' . $add, $tag, 1 );
	}
}
