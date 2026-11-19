<?php
declare(strict_types=1);

namespace Momos\Access\Http;

use Momos\Kernel\Settings;

/**
 * Security headers (SEC-029/030, FR-SEC-005, SDD §6.11, TC-SEC-004/029/030, TC-PRV-016/018; spec §8).
 * Global headers are also set at the web server/CDN; this class sets the route-specific ones and
 * acts as the fallback. CSP mode (`momo_csp_mode`: report_only|enforce) and the HSTS ramp
 * (`momo_hsts_max_age`: 300 → 86400 → 31536000) come from G3 Security settings.
 */
final class SecurityHeaders {
	/** Canonical CSP (Doc 11 §6.4 — the only copy; change only through the PRV-024 process). */
	public const CSP = "default-src 'self'; script-src 'self' 'unsafe-inline' https://*.googletagmanager.com https://challenges.cloudflare.com; style-src 'self' 'unsafe-inline'; img-src 'self' data: https://*.google-analytics.com https://*.googletagmanager.com; font-src 'self' data:; connect-src 'self' https://*.google-analytics.com https://*.analytics.google.com https://*.googletagmanager.com https://challenges.cloudflare.com; frame-src https://challenges.cloudflare.com https://www.google.com; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'; upgrade-insecure-requests; report-uri /wp-json/momos/v1/csp-report";

	/** wp-admin / wp-login.php CSP (no third-party origins, PRV-024). */
	public const ADMIN_CSP = "frame-ancestors 'self'; object-src 'none'; base-uri 'self'";

	public const PERMISSIONS = 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), browsing-topics=()';

	public const HSTS_STEPS = [ 300, 86400, 31536000 ];

	public static function register(): void {
		// Every response (front, REST, admin-ajax, admin-post, wp-login): no PHP version token (spec §8).
		add_action( 'init', [ self::class, 'remove_powered_by' ], 0 );
		add_filter( 'wp_headers', [ self::class, 'public_headers' ], 20 );
		add_action( 'login_init', [ self::class, 'send_admin_headers' ] );
		add_action( 'admin_init', [ self::class, 'send_admin_headers' ] );
		add_filter( 'rest_post_dispatch', [ self::class, 'rest_headers' ], 20 );
		add_action( 'rest_api_init', [ CspReport::class, 'register_route' ] );
	}

	public static function remove_powered_by(): void {
		if ( function_exists( 'header_remove' ) && ! headers_sent() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			header_remove( 'X-Powered-By' );
		}
	}

	/**
	 * Public CSP header name and value for the current mode (DEF-007): browsers ignore
	 * `upgrade-insecure-requests` in a report-only policy and warn in the console, so the directive is
	 * sent only when the policy is enforced. The canonical string itself is unchanged (PRV-024).
	 *
	 * @return array{0:string,1:string}
	 */
	public static function public_csp(): array {
		if ( self::csp_enforced() ) {
			return [ 'Content-Security-Policy', self::CSP ];
		}
		return [ 'Content-Security-Policy-Report-Only', (string) preg_replace( '/\s*upgrade-insecure-requests;/', '', self::CSP ) ];
	}

	/** @return array<string,string> headers every HTTPS response carries */
	public static function global_headers(): array {
		$h = [
			'X-Content-Type-Options'     => 'nosniff',
			'X-Frame-Options'            => 'SAMEORIGIN',
			'Permissions-Policy'         => self::PERMISSIONS,
			'Cross-Origin-Opener-Policy' => 'same-origin',
		];
		if ( is_ssl() ) {
			$h['Strict-Transport-Security'] = 'max-age=' . self::hsts_max_age();
		}
		return $h;
	}

	public static function hsts_max_age(): int {
		$v = (int) Settings::get( 'momo_hsts_max_age', 300 );
		return in_array( $v, self::HSTS_STEPS, true ) ? $v : 300;
	}

	public static function csp_enforced(): bool {
		return 'enforce' === (string) Settings::get( 'momo_csp_mode', 'report_only' );
	}

	/** Reservation result/status pages (DEC-058/066): no-referrer, no-store, noindex. */
	public static function is_private_result_page( string $uri ): bool {
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		return (bool) preg_match( '#^(?:/(?:en|ar|ur|hi|bn))?/reservation/(?:status|submitted)(?:/|$)#', $path );
	}

	/**
	 * Front-end headers (wp_headers runs before output on every front-end request).
	 *
	 * @param array<string,string> $headers
	 * @return array<string,string>
	 */
	public static function public_headers( $headers ): array {
		$headers                    = array_merge( (array) $headers, self::global_headers() );
		$headers['Referrer-Policy'] = 'strict-origin-when-cross-origin';
		[ $csp_name, $csp_value ]   = self::public_csp();
		$headers[ $csp_name ]       = $csp_value;
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore
		if ( self::is_private_result_page( $uri ) ) {
			$headers['Referrer-Policy'] = 'no-referrer';
			$headers['Cache-Control']   = 'no-store, private';
			$headers['X-Robots-Tag']    = 'noindex, nofollow';
		}
		if ( 'staging' === \Momos\Kernel\Env::name() ) {
			$headers['X-Robots-Tag'] = 'noindex, nofollow';
		}
		if ( function_exists( 'header_remove' ) && ! headers_sent() ) {
			header_remove( 'X-Powered-By' );
		}
		unset( $headers['X-Pingback'] );
		return $headers;
	}

	/** @return array<string,string> */
	public static function admin_headers(): array {
		$h                            = self::global_headers();
		$h['Content-Security-Policy'] = self::ADMIN_CSP;
		$h['Referrer-Policy']         = 'same-origin';
		$page                         = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore
		if ( 'wp-login.php' === ( $GLOBALS['pagenow'] ?? '' ) || str_starts_with( $page, 'momo' ) ) {
			$h['Cache-Control'] = 'no-store';
		}
		return $h;
	}

	public static function send_admin_headers(): void {
		if ( headers_sent() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		header_remove( 'X-Powered-By' );
		foreach ( self::admin_headers() as $k => $v ) {
			header( $k . ': ' . $v );
		}
	}

	/** REST: global headers on every response (momos/v1 no-store/noindex are set by the Api module). */
	public static function rest_headers( $response ) {
		self::remove_powered_by();
		if ( $response instanceof \WP_HTTP_Response ) {
			foreach ( self::global_headers() as $k => $v ) {
				$response->header( $k, $v );
			}
		}
		return $response;
	}
}
