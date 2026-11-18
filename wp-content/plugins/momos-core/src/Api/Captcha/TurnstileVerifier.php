<?php
declare(strict_types=1);

namespace Momos\Api\Captcha;

use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Env;
use Momos\Kernel\Log\Logger;

/**
 * Cloudflare Turnstile verification (FR-RES-007, SEC-013, spec §3.1 check 7).
 *
 * Live mode (`MOMOS_TURNSTILE_MODE=live`, default in production): POST siteverify with secret and
 * token only (no remoteip), 5 s timeout, one retry on a network error. Requires success, hostname =
 * site host, action `reservation_submit` and challenge_ts ≤ 300 s old. A network error, timeout, 5xx
 * or a missing secret is an OUTAGE → degraded mode (captcha_status unverified_outage, outage flag for
 * 10 min, tighter IP limit, audit security.captcha_degraded).
 *
 * Test mode (`MOMOS_TURNSTILE_MODE=test`, never in production): token "test-fail" fails, "test-outage"
 * simulates an outage, any other non-empty token passes.
 */
final class TurnstileVerifier {
	public const VERIFIED = 'verified';
	public const FAILED   = 'failed';
	public const OUTAGE   = 'outage';

	public const ACTION      = 'reservation_submit';
	public const OUTAGE_FLAG = 'momos_captcha_outage';
	public const ENDPOINT    = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

	public static function mode(): string {
		$mode = defined( 'MOMOS_TURNSTILE_MODE' ) ? (string) MOMOS_TURNSTILE_MODE : (string) getenv( 'MOMOS_TURNSTILE_MODE' );
		if ( Env::is_production() ) {
			return 'live';
		}
		return 'live' === $mode ? 'live' : 'test';
	}

	/** @return self::VERIFIED|self::FAILED|self::OUTAGE */
	public static function verify( string $token, string $action = self::ACTION ): string {
		if ( '' === trim( $token ) ) {
			return self::FAILED;
		}
		$result = 'test' === self::mode() ? self::verify_test( $token ) : self::verify_live( $token, $action );
		if ( self::OUTAGE === $result ) {
			self::set_outage();
		}
		return $result;
	}

	private static function verify_test( string $token ): string {
		return match ( $token ) {
			'test-fail'   => self::FAILED,
			'test-outage' => self::OUTAGE,
			default       => self::VERIFIED,
		};
	}

	/** Public site key for rendering the widget ('' when not configured; test mode uses Cloudflare's always-pass key). */
	public static function site_key(): string {
		$key = defined( 'MOMOS_TURNSTILE_SITE_KEY' ) ? (string) MOMOS_TURNSTILE_SITE_KEY : (string) getenv( 'MOMOS_TURNSTILE_SITE_KEY' );
		return '' !== $key ? $key : ( 'test' === self::mode() ? '1x00000000000000000000AA' : '' );
	}

	private static function verify_live( string $token, string $action = self::ACTION ): string {
		$secret = Env::secret( 'MOMOS_TURNSTILE_SECRET', 'missing' );
		if ( 'missing' === $secret || '' === $secret ) {
			Logger::critical( 'captcha.secret_missing' );
			return self::OUTAGE;
		}
		$args = [
			'timeout' => 5,
			'body'    => [ 'secret' => $secret, 'response' => $token ],
		];
		$response = wp_remote_post( self::ENDPOINT, $args );
		if ( is_wp_error( $response ) ) {
			$response = wp_remote_post( self::ENDPOINT, $args ); // One retry on a network error.
		}
		if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) >= 500 ) {
			Logger::warning( 'captcha.outage', [ 'error' => is_wp_error( $response ) ? $response->get_error_code() : wp_remote_retrieve_response_code( $response ) ] );
			return self::OUTAGE;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return self::OUTAGE;
		}
		if ( empty( $body['success'] ) ) {
			return self::FAILED;
		}
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( strtolower( (string) ( $body['hostname'] ?? '' ) ) !== $host || $action !== ( $body['action'] ?? '' ) ) {
			return self::FAILED;
		}
		$ts = strtotime( (string) ( $body['challenge_ts'] ?? '' ) );
		if ( false === $ts || Clock::utc()->getTimestamp() - $ts > 300 ) {
			return self::FAILED;
		}
		return self::VERIFIED;
	}

	private static function set_outage(): void {
		set_transient( self::OUTAGE_FLAG, Clock::utc()->getTimestamp(), 10 * MINUTE_IN_SECONDS );
	}

	/** Was an outage seen in the last 10 minutes? (degraded rate limit, check 3) */
	public static function outage_active(): bool {
		$t = get_transient( self::OUTAGE_FLAG );
		return false !== $t && Clock::utc()->getTimestamp() - (int) $t < 10 * MINUTE_IN_SECONDS;
	}
}
