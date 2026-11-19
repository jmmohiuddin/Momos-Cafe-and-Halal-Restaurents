<?php
declare(strict_types=1);

namespace Momos\Seo\Redirects;

use Momos\Kernel\I18n\Router;
use Momos\Kernel\I18n\Url;

/**
 * Front-end redirect handling before the main query (i18n-seo-analytics.md §8.1 rule 2, §11):
 *  1. redirect map (`momos_redirects`): exact, case-insensitive path match with/without trailing slash
 *     (+ query when the rule has one) → single-hop 301/302 with `Cache-Control: public, max-age=3600`;
 *     410 rules answer "Gone" with the localised 404 template;
 *  2. URL normalisation (FR-SEO-001, AC-SEO-01-04): an upper-case path or a missing trailing slash →
 *     one 301 to the lower-case path with slash (language prefix and query string kept). Exempt: files,
 *     `/wp-json/`, `/wp-admin/`, `wp-login.php`, sitemaps and `/reservation/status/{REF}/` (C-8).
 * Loop protection: a target equal to the request is never followed.
 */
final class RedirectMatcher {
	private static bool $gone = false;

	public static function register(): void {
		add_action( 'parse_request', [ self::class, 'handle' ], 1 );
		add_action( 'template_redirect', [ self::class, 'gone' ], 0 );
	}

	public static function handle(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! in_array( $method, [ 'GET', 'HEAD' ], true ) ) {
			return;
		}
		$decision = self::decide( Router::original_uri() );
		if ( null === $decision ) {
			return;
		}
		if ( 410 === $decision['status'] ) {
			self::$gone = true;
			return;
		}
		header( 'Cache-Control: public, max-age=3600' );
		wp_redirect( $decision['url'], $decision['status'], 'momos' ); // phpcs:ignore WordPress.Security.SafeRedirect -- same-host targets validated on save.
		exit;
	}

	/**
	 * Pure decision for a request URI (relative to the server root).
	 *
	 * @return array{status:int,url:string,rule:?string}|null
	 */
	public static function decide( string $uri ): ?array {
		$path  = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$query = (string) wp_parse_url( $uri, PHP_URL_QUERY );
		$base  = (string) wp_parse_url( Url::raw_home(), PHP_URL_PATH );
		if ( '' !== $base && str_starts_with( $path, $base ) ) {
			$path = substr( $path, strlen( $base ) );
		}
		$path = '' === $path ? '/' : $path;
		if ( self::exempt( $path ) ) {
			return null;
		}
		$rule = RedirectStore::match( $path, $query );
		if ( null !== $rule ) {
			$status = (int) $rule['status'];
			if ( 410 === $status ) {
				return [ 'status' => 410, 'url' => '', 'rule' => (string) $rule['id'] ];
			}
			$target = RedirectStore::target_url( $rule );
			if ( '' !== $target && RedirectStore::key( $target ) !== RedirectStore::key( $path . ( '' !== $query ? '?' . $query : '' ) )
				&& RedirectStore::key( (string) wp_parse_url( $target, PHP_URL_PATH ) ) !== RedirectStore::key( $path ) ) {
				return [ 'status' => $status, 'url' => $target, 'rule' => (string) $rule['id'] ];
			}
			return null; // Loop guard.
		}
		$normal = self::normalise( $path );
		if ( $normal !== $path ) {
			return [ 'status' => 301, 'url' => Url::raw_home() . $normal . ( '' !== $query ? '?' . $query : '' ), 'rule' => null ];
		}
		return null;
	}

	/** Lower-case + trailing slash form of a page path. */
	public static function normalise( string $path ): string {
		if ( self::is_file( $path ) || self::is_status( $path ) ) {
			return $path;
		}
		$lower = strtolower( $path );
		return str_ends_with( $lower, '/' ) ? $lower : $lower . '/';
	}

	public static function exempt( string $path ): bool {
		$lower = strtolower( $path );
		foreach ( [ '/wp-json', '/wp-admin', '/wp-login.php', '/wp-content/', '/wp-includes/', '/xmlrpc.php', '/sitemap.xml', '/sitemaps/', '/robots.txt', '/wp-cron.php', '/wp-sitemap' ] as $p ) {
			if ( str_starts_with( $lower, $p ) ) {
				return true;
			}
		}
		return str_contains( $path, '//' );
	}

	private static function is_file( string $path ): bool {
		$last = (string) substr( (string) strrchr( rtrim( $path, '/' ), '/' ), 1 );
		return str_contains( $last, '.' );
	}

	/** `/{lang}/reservation/status/{REF}/` keeps its case (C-8). */
	private static function is_status( string $path ): bool {
		return (bool) preg_match( '#^(?:/[a-z]{2})?/reservation/status(?:/|$)#i', $path );
	}

	/** 410 rules: localised error page with HTTP 410, noindex (via Head/robots). */
	public static function gone(): void {
		if ( ! self::$gone ) {
			return;
		}
		global $wp_query;
		$wp_query->set_404();
		status_header( 410 );
		nocache_headers();
	}

	public static function is_gone(): bool {
		return self::$gone;
	}
}
