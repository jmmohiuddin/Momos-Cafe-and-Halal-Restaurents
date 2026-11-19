<?php
declare(strict_types=1);

namespace Momos\Seo;

use Momos\Kernel\Env;
use Momos\Kernel\I18n\Locale;

/**
 * SEO head output (F-SEO-01, i18n-seo-analytics.md §9.1–§9.4; replaces Rank Math, IMPL-001):
 * `<title>` via `pre_get_document_title`, meta description, canonical, hreflang alternates (+ x-default
 * → en), robots meta via `wp_robots`, Open Graph + Twitter card, JSON-LD; response headers
 * (X-Robots-Tag for non-indexable variants, hidden-language previews and non-production).
 */
final class Head {
	public static function register(): void {
		add_filter( 'pre_get_document_title', [ self::class, 'document_title' ], 20 );
		add_action( 'wp_head', [ self::class, 'print' ], 1 );
		add_filter( 'wp_robots', [ self::class, 'robots' ], 20 );
		add_action( 'template_redirect', [ self::class, 'headers' ], 99 );
		add_action( 'send_headers', [ self::class, 'environment_header' ] );
		add_action( 'wp', [ self::class, 'reset_context' ], 0 );
		// Core prints its own canonical/shortlink; this module is the single source.
		remove_action( 'wp_head', 'rel_canonical' );
		remove_action( 'wp_head', 'wp_shortlink_wp_head', 10 );
		remove_action( 'template_redirect', 'wp_shortlink_header', 11 );
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
	}

	/** The main query is final once `wp` runs: drop anything computed earlier. */
	public static function reset_context(): void {
		Context::reset();
	}

	public static function is_front(): bool {
		return ! is_admin() && ! wp_doing_ajax() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) && ! is_feed() && ! is_robots();
	}

	public static function document_title( string $title ): string {
		if ( ! self::is_front() ) {
			return $title;
		}
		$t = Meta::title( Locale::current() );
		return '' !== $t ? $t : $title;
	}

	public static function print(): void {
		if ( ! self::is_front() ) {
			return;
		}
		echo self::markup(); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
	}

	/** Head markup (title is printed by core `_wp_render_title_tag`). */
	public static function markup(): string {
		$lang = Locale::current();
		$out  = '';
		$desc = Meta::description( $lang );
		if ( '' !== $desc ) {
			$out .= '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
		}
		$canonical = Context::canonical();
		if ( null !== $canonical ) {
			$out .= '<link rel="canonical" href="' . esc_url( $canonical ) . '">' . "\n";
		}
		$alts = Context::alternates();
		foreach ( $alts as $code => $url ) {
			$out .= '<link rel="alternate" hreflang="' . esc_attr( $code ) . '" href="' . esc_url( $url ) . '">' . "\n";
		}
		if ( isset( $alts['en'] ) ) {
			$out .= '<link rel="alternate" hreflang="x-default" href="' . esc_url( $alts['en'] ) . '">' . "\n";
		}
		$reason = Context::noindex_reason();
		if ( ! in_array( $reason, [ '404', 'private', 'hidden_language', 'test_branch' ], true ) ) {
			$out .= self::social( $lang, $canonical, $alts );
		}
		$out .= self::json_ld();
		return $out;
	}

	private static function json_ld(): string {
		ob_start();
		Schema\JsonLd::print();
		return (string) ob_get_clean();
	}

	/**
	 * Open Graph (§9.1 step 3) + Twitter card.
	 *
	 * @param array<string,string> $alts
	 */
	private static function social( string $lang, ?string $canonical, array $alts ): string {
		$c     = Context::current();
		$title = Meta::field( $c['kind'], $c['id'], 'og_title', $lang );
		$title = '' !== $title ? $title : Meta::title( $lang );
		$desc  = Meta::field( $c['kind'], $c['id'], 'og_description', $lang );
		$desc  = '' !== $desc ? Meta::truncate( $desc, $lang ) : Meta::description( $lang );
		$img   = Meta::og_image( $lang );
		$site  = class_exists( \Momos\Presentation\View\Brand::class ) ? \Momos\Presentation\View\Brand::trading_name( $lang ) : (string) get_bloginfo( 'name' );
		$tags  = [
			'og:type'      => 'website',
			'og:site_name' => $site,
			'og:locale'    => Meta::OG_LOCALE[ $lang ] ?? 'ja_JP',
			'og:title'     => $title,
		];
		if ( null !== $canonical ) {
			$tags['og:url'] = $canonical;
		}
		if ( '' !== $desc ) {
			$tags['og:description'] = $desc;
		}
		$out = '';
		foreach ( $tags as $p => $v ) {
			$out .= '<meta property="' . esc_attr( $p ) . '" content="' . ( 'og:url' === $p ? esc_url( $v ) : esc_attr( $v ) ) . '">' . "\n";
		}
		foreach ( array_keys( $alts ) as $code ) {
			if ( $code !== $lang ) {
				$out .= '<meta property="og:locale:alternate" content="' . esc_attr( Meta::OG_LOCALE[ $code ] ) . '">' . "\n";
			}
		}
		if ( null !== $img ) {
			$out .= '<meta property="og:image" content="' . esc_url( $img['url'] ) . '">' . "\n";
			if ( $img['width'] > 0 && $img['height'] > 0 ) {
				$out .= '<meta property="og:image:width" content="' . (int) $img['width'] . '">' . "\n";
				$out .= '<meta property="og:image:height" content="' . (int) $img['height'] . '">' . "\n";
			}
			if ( '' !== $img['alt'] ) {
				$out .= '<meta property="og:image:alt" content="' . esc_attr( $img['alt'] ) . '">' . "\n";
			}
		}
		$out .= '<meta name="twitter:card" content="' . ( null !== $img ? 'summary_large_image' : 'summary' ) . '">' . "\n";
		return $out;
	}

	/**
	 * Robots meta (§9.3): `noindex, follow` for every non-indexable public variant; `noindex, nofollow`
	 * for TEST branches and hidden-language previews. Indexable pages print no robots meta.
	 *
	 * @param array<string,bool|string> $robots
	 * @return array<string,bool|string>
	 */
	public static function robots( array $robots ): array {
		if ( ! self::is_front() ) {
			return $robots;
		}
		$reason = Context::noindex_reason();
		if ( '' === $reason ) {
			return $robots;
		}
		$robots['noindex'] = true;
		if ( in_array( $reason, [ 'test_branch', 'hidden_language' ], true ) ) {
			unset( $robots['follow'] );
			$robots['nofollow'] = true;
		} elseif ( empty( $robots['nofollow'] ) ) {
			$robots['follow'] = true;
		}
		unset( $robots['max-image-preview'] );
		return $robots;
	}

	/** Header twins of the robots rules (§9.3 rows 1, 3, 5). */
	public static function headers(): void {
		if ( headers_sent() || ! self::is_front() ) {
			return;
		}
		$reason = Context::noindex_reason();
		if ( 'hidden_language' === $reason || 'test_branch' === $reason ) {
			header( 'X-Robots-Tag: noindex, nofollow' );
			nocache_headers();
			header( 'Cache-Control: no-store, private' );
		} elseif ( in_array( $reason, [ '404', 'private', 'preview', 'staff_only' ], true ) ) {
			header( 'X-Robots-Tag: noindex' );
		}
	}

	/** Non-production: every response `X-Robots-Tag: noindex, nofollow` (FR-SEO-009, §9.3 staging row). */
	public static function environment_header(): void {
		if ( ! Env::is_production() && ! headers_sent() ) {
			header( 'X-Robots-Tag: noindex, nofollow' );
		}
	}
}
