<?php
declare(strict_types=1);

namespace Momos\Presentation\Templates;

use Momos\Kernel\I18n\Locale;
use Momos\Kernel\I18n\Translations;
use Momos\Presentation\SystemPages;

/**
 * Picks the theme template for system pages (screens.md §0.1) and guards staff-only templates. A page
 * with an explicit page template keeps it; otherwise the role decides. Missing template files fall back
 * to WordPress' normal hierarchy (page.php), so pages built by later waves light up automatically.
 *
 * Also owns the screen-level routing rules of the frame:
 *  - `reservation/status/{REF}/` rewrite → the `status` system page (SCR-C11 shell; REF in `momo_status_ref`);
 *  - C10/C11 headers: `noindex` meta + `X-Robots-Tag`, `Referrer-Policy: no-referrer`, C11 `no-store`
 *    (i18n-seo-analytics.md §9.3, DEC-066);
 *  - `noindex, follow` for query variants (`?branch=`, filters, `?notice=`), pages flagged `momo_noindex`
 *    and `/offers/` while no offer is active in the language;
 *  - BR-I18N-02 / DEC-027: a page or news post that is not visible in the URL language is a 404 (no
 *    mixed-language fallback). Staff with `edit_post` still see it (preview) with `noindex`.
 */
final class TemplateRouter {
	public const ROLE_TEMPLATES = [
		'about'       => 'page-templates/about.php',
		'halal'       => 'page-templates/halal.php',
		'branches'    => 'page-templates/branches.php',
		'menu'        => 'page-templates/menu-hub.php',
		'reservation' => 'page-templates/reservation.php',
		'submitted'   => 'page-templates/reservation-submitted.php',
		'status'      => 'page-templates/reservation-status.php',
		'contact'     => 'page-templates/contact.php',
		'faq'         => 'page-templates/faq.php',
		'offers'      => 'page-templates/offers.php',
		'gallery'     => 'page-templates/gallery.php',
		'legal'       => 'page-templates/legal.php',
	];

	public const STAFF_ONLY = [ 'page-templates/style-guide.php' ];

	/**
	 * System pages whose template renders catalog strings + module data only (no page title/body): they are
	 * never 404 for a missing page translation (their strings are LCCS-gated per language, FR-I18N-009).
	 */
	public const STRING_DRIVEN = [ 'home', 'halal', 'branches', 'menu', 'offers', 'reservation', 'submitted', 'status' ];

	/** Query var carrying the reference of the C11 status URL (validated by the Reservation module / page JS). */
	public const QV_REF = 'momo_status_ref';

	/** Option storing the rewrite signature (rules are flushed once when it changes). */
	public const REWRITE_OPTION = 'momos_presentation_rewrite';

	/** Query parameters that make a URL a non-canonical variant (i18n-seo-analytics.md §8.1 rule 4). */
	public const VARIANT_PARAMS = [ 'branch', 'diet', 'spice', 'max_spice', 'exclude', 'notice', 'momo_notice', 'sent', 'contact_error', 'date', 'time' ];

	public static function register(): void {
		add_filter( 'template_include', [ self::class, 'template_include' ], 20 );
		add_action( 'template_redirect', [ self::class, 'guard_staff_only' ], 5 );
		add_action( 'template_redirect', [ self::class, 'guard_untranslated' ], 6 );
		add_action( 'template_redirect', [ self::class, 'private_headers' ], 7 );
		add_filter( 'wp_robots', [ self::class, 'robots' ] );
		add_action( 'init', [ self::class, 'rewrite' ], 21 );
		add_filter( 'query_vars', [ self::class, 'query_vars' ] );
		add_filter( 'redirect_canonical', [ self::class, 'keep_status_url' ], 1 );
	}

	/**
	 * The C11 URL `reservation/status/{REF}/` is canonical as is (core would redirect to the page permalink).
	 *
	 * @param string|false $redirect
	 * @return string|false
	 */
	public static function keep_status_url( $redirect ) {
		return '' !== (string) get_query_var( self::QV_REF ) ? false : $redirect;
	}

	/** `reservation/status/{REF}/` → status page (REF format checked by the page; never logged here). */
	public static function rewrite(): void {
		$page = SystemPages::id( 'status' );
		if ( ! $page ) {
			return;
		}
		$regex = '^reservation/status/([0-9A-Za-z-]{8,9})/?$';
		add_rewrite_rule( $regex, 'index.php?page_id=' . $page . '&' . self::QV_REF . '=$matches[1]', 'top' );
		$sig = $regex . '|' . $page;
		if ( get_option( self::REWRITE_OPTION ) !== $sig ) {
			flush_rewrite_rules( false );
			update_option( self::REWRITE_OPTION, $sig, true );
		}
	}

	/** @param list<string> $vars @return list<string> */
	public static function query_vars( array $vars ): array {
		$vars[] = self::QV_REF;
		return $vars;
	}

	public static function template_include( string $template ): string {
		if ( ! is_page() ) {
			return $template;
		}
		$id = (int) get_queried_object_id();
		if ( '' !== (string) get_page_template_slug( $id ) ) {
			return $template;
		}
		$rel = self::ROLE_TEMPLATES[ SystemPages::role_of( $id ) ] ?? null;
		if ( null === $rel ) {
			return $template;
		}
		$file = locate_template( [ $rel ] );
		return '' !== $file ? $file : $template;
	}

	public static function is_staff_only(): bool {
		if ( ! is_page() ) {
			return false;
		}
		return in_array( (string) get_page_template_slug( (int) get_queried_object_id() ), self::STAFF_ONLY, true );
	}

	/** Living style guide: logged-in staff only (no customer accounts exist, DEC-041); others get 404. */
	public static function guard_staff_only(): void {
		if ( self::is_staff_only() && ! is_user_logged_in() ) {
			self::not_found();
		} elseif ( self::is_staff_only() ) {
			nocache_headers();
		}
	}

	/**
	 * Untranslated singular page / news post in the URL language → 404 (BR-I18N-02, screens.md §2 "Error").
	 * The front page is exempt (its content comes from strings and module data, and the switcher sends
	 * visitors to it with `?notice=untranslated`). Branches and dishes are guarded by their modules.
	 */
	public static function guard_untranslated(): void {
		if ( ! self::is_untranslated_singular() ) {
			return;
		}
		if ( current_user_can( 'edit_post', (int) get_queried_object_id() ) ) {
			return; // Staff preview; robots() adds noindex.
		}
		self::not_found();
	}

	public static function is_untranslated_singular(): bool {
		if ( is_front_page() || ! ( is_page() || is_singular( 'post' ) ) ) {
			return false;
		}
		$id = (int) get_queried_object_id();
		if ( is_page() && 'halal' === SystemPages::role_of( $id ) ) {
			return ! \Momos\Presentation\View\HalalPage::published( Locale::current() ); // AC-CORP-03-03 per-language gate.
		}
		if ( is_page() && in_array( SystemPages::role_of( $id ), self::STRING_DRIVEN, true ) ) {
			return false;
		}
		return $id > 0 && ! Translations::post_visible( $id, Locale::current() );
	}

	/** C10/C11: never indexed, no referrer, never stored in any cache (DEC-066, i18n-seo-analytics.md §9.3). */
	public static function private_headers(): void {
		if ( headers_sent() || ! is_page() ) {
			return;
		}
		$role = SystemPages::role_of( (int) get_queried_object_id() );
		if ( ! in_array( $role, SystemPages::NOINDEX, true ) ) {
			return;
		}
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
		// C10 is rendered from the tab's sessionStorage and C11 from the token header: neither may be
		// stored by a browser, proxy or CDN cache (FR-RES-006, DEC-058/066).
		nocache_headers();
		header( 'Cache-Control: no-store, private' );
	}

	/** Is the request a query-string variant of a canonical URL (`?branch=`, filters, notices)? */
	public static function is_variant(): bool {
		foreach ( self::VARIANT_PARAMS as $p ) {
			if ( isset( $_GET[ $p ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- read-only flag.
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<string,bool|string> $robots
	 * @return array<string,bool|string>
	 */
	public static function robots( array $robots ): array {
		$noindex = is_404() || self::is_staff_only() || self::is_variant() || self::is_untranslated_singular()
			|| \Momos\Presentation\View\LanguageSwitcher::untranslated_notice();
		if ( is_page() ) {
			$id      = (int) get_queried_object_id();
			$role    = SystemPages::role_of( $id );
			$noindex = $noindex || in_array( $role, SystemPages::NOINDEX, true ) || '1' === (string) get_post_meta( $id, 'momo_noindex', true );
			if ( 'offers' === $role && ! \Momos\Presentation\View\Navigation::offers_active() ) {
				$noindex = true; // FR-CORP-011: /offers/ noindex while empty (TC-CORP-012).
			}
		}
		if ( $noindex ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
		}
		return $robots;
	}

	private static function not_found(): void {
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}
}
