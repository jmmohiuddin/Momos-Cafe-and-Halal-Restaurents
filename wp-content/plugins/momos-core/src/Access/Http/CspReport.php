<?php
declare(strict_types=1);

namespace Momos\Access\Http;

use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Http\RequestContext;
use Momos\Kernel\Support\Hash;

/**
 * API-17 `POST /momos/v1/csp-report` (spec §8, Could): accepts `application/csp-report` and
 * `application/reports+json` bodies ≤ 8 KB, ⟦30⟧ reports per IP per hour; stores only counters
 * (day, directive, blocked origin, path template) in the non-autoloaded option
 * `momos_csp_reports`, kept 30 days. No IP, user agent, full URL or body is stored. Always 204.
 */
final class CspReport {
	public const OPTION    = 'momos_csp_reports';
	public const MAX_BYTES = 8192;
	public const PER_HOUR  = 30;

	/** Retention: drop counters older than 30 days even when no new report arrives (Privacy retention step). */
	public static function prune( bool $dry_run = false ): int {
		$all = get_option( self::OPTION, [] );
		$all = is_array( $all ) ? $all : [];
		$cut = Clock::utc()->modify( '-30 days' )->format( 'Y-m-d' );
		$old = array_filter( array_keys( $all ), static fn( $k ) => substr( (string) $k, 0, 10 ) < $cut );
		if ( $old && ! $dry_run ) {
			update_option( self::OPTION, array_diff_key( $all, array_flip( $old ) ), false );
		}
		return count( $old );
	}

	public static function register_route(): void {
		register_rest_route(
			'momos/v1',
			'/csp-report',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'handle' ],
				'permission_callback' => '__return_true', // Public by design (API-17); controls inside.
			]
		);
	}

	public static function handle( \WP_REST_Request $request ): \WP_REST_Response {
		$response = new \WP_REST_Response( null, 204 );
		$ct       = $request->get_content_type();
		$type     = strtolower( (string) ( $ct['value'] ?? '' ) );
		$body     = (string) $request->get_body();
		if ( strlen( $body ) > self::MAX_BYTES || ! in_array( $type, [ 'application/csp-report', 'application/reports+json', 'application/json' ], true ) || ! self::allowed() ) {
			return $response;
		}
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return $response;
		}
		$reports = isset( $data['csp-report'] ) ? [ $data['csp-report'] ] : array_map( static fn( $r ) => (array) ( $r['body'] ?? [] ), array_is_list( $data ) ? $data : [] );
		foreach ( array_slice( $reports, 0, 10 ) as $r ) {
			if ( is_array( $r ) ) {
				self::count( $r );
			}
		}
		return $response;
	}

	private static function allowed(): bool {
		$ip  = RequestContext::ip() ?? 'unknown';
		$key = 'momos_csp_rl_' . substr( Hash::hmac( 'rl|csp|' . $ip ), 0, 24 ) . '_' . Clock::utc()->format( 'YmdH' );
		$n   = (int) get_transient( $key ) + 1;
		set_transient( $key, $n, 2 * HOUR_IN_SECONDS );
		return $n <= self::PER_HOUR;
	}

	/** @param array<string,mixed> $r */
	public static function count( array $r ): void {
		$directive = sanitize_key( (string) ( $r['effective-directive'] ?? $r['effectiveDirective'] ?? $r['violated-directive'] ?? 'unknown' ) );
		$blocked   = self::origin( (string) ( $r['blocked-uri'] ?? $r['blockedURL'] ?? '' ) );
		$template  = self::template( (string) ( $r['document-uri'] ?? $r['documentURL'] ?? '' ) );
		$day       = Clock::utc()->format( 'Y-m-d' );
		$key       = substr( $day . '|' . $directive . '|' . $blocked . '|' . $template, 0, 300 );
		$all       = get_option( self::OPTION, [] );
		$all       = is_array( $all ) ? $all : [];
		$cutoff    = Clock::utc()->modify( '-30 days' )->format( 'Y-m-d' );
		foreach ( array_keys( $all ) as $k ) {
			if ( substr( (string) $k, 0, 10 ) < $cutoff ) {
				unset( $all[ $k ] );
			}
		}
		if ( count( $all ) >= 2000 && ! isset( $all[ $key ] ) ) {
			return;
		}
		$all[ $key ] = (int) ( $all[ $key ] ?? 0 ) + 1;
		update_option( self::OPTION, $all, false );
	}

	/** Blocked URI reduced to its origin or keyword (inline, eval, data…). */
	public static function origin( string $uri ): string {
		if ( '' === $uri ) {
			return 'none';
		}
		$scheme = wp_parse_url( $uri, PHP_URL_SCHEME );
		$host   = wp_parse_url( $uri, PHP_URL_HOST );
		if ( $scheme && $host ) {
			return strtolower( $scheme . '://' . $host );
		}
		return sanitize_key( $uri ) ?: 'other';
	}

	/** Document URI reduced to a path template: /{lang}/first-segment/{slug}… (no query/fragment). */
	public static function template( string $uri ): string {
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$segs = array_values( array_filter( explode( '/', $path ), 'strlen' ) );
		$out  = [];
		if ( $segs && in_array( $segs[0], [ 'en', 'ar', 'ur', 'hi', 'bn' ], true ) ) {
			array_shift( $segs );
			$out[] = '{lang}';
		}
		foreach ( $segs as $i => $s ) {
			$out[] = 0 === $i ? sanitize_title( $s ) : '{slug}';
		}
		return '/' . implode( '/', $out );
	}
}
