<?php
declare(strict_types=1);

namespace Momos\Api\Http;

use Momos\Reservation\Domain\ReservationError;
use Momos\Reservation\Service\TokenService;

/**
 * Transport guards that run before route parsing (rest_pre_dispatch), in SDD §5.2 order:
 *  - JsonBodyGuard: POST/PATCH need Content-Type application/json, a body ≤ limit and a JSON object.
 *  - OriginGuard: Origin host (else Referer host) must equal the site host (public POST routes).
 *  - TokenHeaderGuard (DEC-058): `t` in query or body → 400 before any lookup; the token travels only
 *    in the X-Momo-Token header.
 */
final class Guards {
	public const PUBLIC_BODY_LIMIT = 8192;
	public const STAFF_BODY_LIMIT  = 16384;

	/** @throws ReservationError */
	public static function json_body( \WP_REST_Request $request, int $limit ): void {
		$type = strtolower( (string) $request->get_header( 'content-type' ) );
		if ( ! str_starts_with( trim( explode( ';', $type )[0] ), 'application/json' ) ) {
			throw new ReservationError( 'unsupported_media_type' );
		}
		$body = (string) $request->get_body();
		if ( strlen( $body ) > $limit ) {
			throw new ReservationError( 'payload_too_large' );
		}
		$decoded = json_decode( '' === trim( $body ) ? '{}' : $body, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) || ( $decoded && array_is_list( $decoded ) ) ) {
			throw new ReservationError( 'invalid_json' );
		}
	}

	/** @throws ReservationError */
	public static function origin( \WP_REST_Request $request ): void {
		$site   = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$origin = (string) $request->get_header( 'origin' );
		if ( '' === $origin || 'null' === $origin ) {
			$origin = (string) $request->get_header( 'referer' );
		}
		$host = strtolower( (string) wp_parse_url( $origin, PHP_URL_HOST ) );
		if ( '' === $host || $host !== $site ) {
			throw new ReservationError( 'origin_rejected' );
		}
	}

	/** Reject `t` in the query string or body (before any lookup). @throws ReservationError */
	public static function no_token_param( \WP_REST_Request $request ): void {
		$body = json_decode( (string) $request->get_body(), true );
		if ( array_key_exists( 't', (array) $request->get_query_params() ) || ( is_array( $body ) && array_key_exists( 't', $body ) ) || array_key_exists( 't', (array) $request->get_body_params() ) ) {
			throw new ReservationError( 'invalid_param', [ 't' => 'not_allowed' ] );
		}
	}

	/** Header token (format only). @throws ReservationError */
	public static function header_token( \WP_REST_Request $request ): string {
		$token = trim( (string) $request->get_header( 'x_momo_token' ) );
		if ( '' === $token ) {
			throw new ReservationError( 'invalid_param', [ 'X-Momo-Token' => 'required' ] );
		}
		if ( ! preg_match( TokenService::PATTERN, $token ) ) {
			throw new ReservationError( 'invalid_param', [ 'X-Momo-Token' => 'invalid_format' ] );
		}
		return $token;
	}
}
