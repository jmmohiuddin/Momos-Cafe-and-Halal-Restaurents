<?php
declare(strict_types=1);

namespace Momos\Seo\Redirects;

use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Db\NamedLock;
use Momos\Kernel\I18n\Locale;
use Momos\Kernel\I18n\Router;
use Momos\Kernel\I18n\Url;
use Momos\Seo\SeoSettings;

/**
 * Aggregated 404 log (FR-SEO-008(b), FR-CORP-007(b), FR-PRV-005; i18n-seo-analytics.md §3.3, §11):
 * option `momos_404_log` (not autoloaded) = ring buffer of ≤ 1,000 rows
 * `{path, lang, referrer_host, hits, first_seen_utc, last_seen_utc}` keyed by lang + path.
 * Never stored: IP, user agent, full referrer, query strings (only a valid `branch` code is kept),
 * path segments that look like personal data (`@`, long digit runs) — replaced by `{redacted}`.
 * Rows not seen for `log_retention_days` are purged daily. Weekly reviews (8 weeks after launch) are
 * recorded in `momos_404_reviews`.
 */
final class NotFoundLog {
	public const OPTION  = 'momos_404_log';
	public const REVIEWS = 'momos_404_reviews';
	public const MAX     = 1000;
	public const JOB     = 'momos_job_seo_404_retention';

	public static function register(): void {
		add_action( 'template_redirect', [ self::class, 'maybe_log' ], 100 );
		\Momos\Kernel\Jobs\Schedule::daily_at( self::JOB, '04:20' );
		add_action( self::JOB, [ self::class, 'purge' ] );
	}

	public static function maybe_log(): void {
		if ( ! is_404() || RedirectMatcher::is_gone() || is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( 'GET' !== $method ) {
			return;
		}
		$ref = isset( $_SERVER['HTTP_REFERER'] ) ? (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- host only is kept.
		self::record( Router::original_uri(), Locale::current(), $ref );
	}

	/** Sanitised log path: no query except a valid `branch` code; personal-looking segments redacted. */
	public static function clean_path( string $uri ): string {
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$base = (string) wp_parse_url( Url::raw_home(), PHP_URL_PATH );
		if ( '' !== $base && str_starts_with( $path, $base ) ) {
			$path = substr( $path, strlen( $base ) );
		}
		$segments = [];
		foreach ( explode( '/', '' === $path ? '/' : $path ) as $seg ) {
			$dec = rawurldecode( $seg );
			if ( str_contains( $dec, '@' ) || preg_match( '/\d{7,}/', $dec ) || preg_match( '/^[0-9A-HJKMNP-TV-Z]{8}$/i', $dec ) && preg_match( '/\d/', $dec ) ) {
				$segments[] = '{redacted}';
			} else {
				$segments[] = sanitize_text_field( $seg );
			}
		}
		$path = mb_substr( '/' . ltrim( implode( '/', $segments ), '/' ), 0, 255 );
		parse_str( (string) wp_parse_url( $uri, PHP_URL_QUERY ), $q );
		if ( isset( $q['branch'] ) && is_string( $q['branch'] ) && preg_match( '/^[A-Z]{3}$/', $q['branch'] ) ) {
			$path .= '?branch=' . $q['branch'];
		}
		return $path;
	}

	public static function referrer_host( string $referrer ): string {
		$host = strtolower( (string) wp_parse_url( $referrer, PHP_URL_HOST ) );
		return preg_match( '/^[a-z0-9.-]{1,190}$/', $host ) ? $host : '';
	}

	public static function record( string $uri, string $lang, string $referrer = '' ): void {
		if ( ! NamedLock::acquire( 'momos_404_log', 0 ) ) {
			return; // Contended: drop this hit rather than block the response.
		}
		try {
			$path = self::clean_path( $uri );
			$key  = md5( $lang . '|' . $path );
			$now  = Clock::mysql_utc();
			$rows = self::rows();
			if ( isset( $rows[ $key ] ) ) {
				$rows[ $key ]['hits']          = (int) $rows[ $key ]['hits'] + 1;
				$rows[ $key ]['last_seen_utc'] = $now;
				$host                          = self::referrer_host( $referrer );
				if ( '' !== $host ) {
					$rows[ $key ]['referrer_host'] = $host;
				}
			} else {
				$rows[ $key ] = [
					'path'           => $path,
					'lang'           => $lang,
					'referrer_host'  => self::referrer_host( $referrer ),
					'hits'           => 1,
					'first_seen_utc' => $now,
					'last_seen_utc'  => $now,
				];
			}
			if ( count( $rows ) > self::MAX ) {
				uasort( $rows, static fn( $a, $b ) => strcmp( (string) $b['last_seen_utc'], (string) $a['last_seen_utc'] ) );
				$rows = array_slice( $rows, 0, self::MAX, true );
			}
			update_option( self::OPTION, $rows, false );
		} finally {
			NamedLock::release( 'momos_404_log' );
		}
	}

	/** @return array<string, array{path:string,lang:string,referrer_host:string,hits:int,first_seen_utc:string,last_seen_utc:string}> */
	public static function rows(): array {
		$raw = get_option( self::OPTION, [] );
		return is_array( $raw ) ? array_filter( $raw, 'is_array' ) : [];
	}

	/** @return list<array<string,mixed>> sorted by hits desc */
	public static function top( int $limit = 200 ): array {
		$rows = array_values( self::rows() );
		usort( $rows, static fn( $a, $b ) => [ (int) $b['hits'], (string) $b['last_seen_utc'] ] <=> [ (int) $a['hits'], (string) $a['last_seen_utc'] ] );
		return array_slice( $rows, 0, $limit );
	}

	/** Retention job: drop rows not seen within the retention window. Idempotent. */
	public static function purge(): int {
		if ( ! NamedLock::acquire( 'momos_404_log', 10 ) ) {
			return 0;
		}
		try {
			$cut    = Clock::mysql_utc( Clock::utc()->modify( '-' . SeoSettings::retention_days() . ' days' ) );
			$rows   = self::rows();
			$kept   = array_filter( $rows, static fn( $r ) => (string) $r['last_seen_utc'] >= $cut );
			$purged = count( $rows ) - count( $kept );
			if ( $purged > 0 ) {
				update_option( self::OPTION, $kept, false );
			}
			return $purged;
		} finally {
			NamedLock::release( 'momos_404_log' );
		}
	}

	public static function clear(): void {
		update_option( self::OPTION, [], false );
	}

	/** @return list<array{week_of:string,reviewed_by:int,reviewed_at:string,actions:string}> */
	public static function reviews(): array {
		$raw = get_option( self::REVIEWS, [] );
		return is_array( $raw ) ? array_values( array_filter( $raw, 'is_array' ) ) : [];
	}

	/** @return array<string,string> errors (empty = saved) */
	public static function add_review( string $week_of, string $actions, int $user_id ): array {
		$week_of = sanitize_text_field( $week_of );
		$actions = mb_substr( sanitize_textarea_field( $actions ), 0, 500 );
		$d       = \DateTimeImmutable::createFromFormat( '!Y-m-d', $week_of, Clock::tz() );
		if ( ! $d || $d->format( 'Y-m-d' ) !== $week_of || $week_of > Clock::jst()->format( 'Y-m-d' ) ) {
			return [ 'week_of' => __( 'Enter the week as a date (YYYY-MM-DD), not in the future.', 'momos-core' ) ];
		}
		$rows   = self::reviews();
		$rows[] = [ 'week_of' => $week_of, 'reviewed_by' => $user_id, 'reviewed_at' => Clock::mysql_utc(), 'actions' => $actions ];
		update_option( self::REVIEWS, array_slice( $rows, -200 ), false );
		return [];
	}
}
