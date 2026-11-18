<?php
declare(strict_types=1);

namespace Momos\Reservation\Service;

use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Env;
use Momos\Kernel\I18n\Url;
use Momos\Reservation\Domain\ReservationError;
use Momos\Reservation\Repository\ReservationRepository;

/**
 * Status-link token (DEC-058/DEC-064, spec §3.6).
 *
 * token = base64url_nopad( HMAC-SHA256( MOMOS_TOKEN_KEY_V{n}, "momos-res-token|v{n}|{id}|{public_ref}|{created_at_utc}" ) )
 * Only sha256(token) is stored; the token is re-derived for every email and for the idempotent replay.
 * Key versions: MOMOS_TOKEN_KEY_V1..V9 constants/env; the current version is MOMOS_TOKEN_KEY_CURRENT
 * (default: highest defined version, else 1).
 */
final class TokenService {
	public const PATTERN = '/^[A-Za-z0-9_-]{43}$/';

	public static function current_key_version(): int {
		if ( defined( 'MOMOS_TOKEN_KEY_CURRENT' ) && (int) MOMOS_TOKEN_KEY_CURRENT > 0 ) {
			return (int) MOMOS_TOKEN_KEY_CURRENT;
		}
		$env = getenv( 'MOMOS_TOKEN_KEY_CURRENT' );
		if ( false !== $env && (int) $env > 0 ) {
			return (int) $env;
		}
		for ( $n = 9; $n >= 1; $n-- ) {
			if ( defined( 'MOMOS_TOKEN_KEY_V' . $n ) || false !== getenv( 'MOMOS_TOKEN_KEY_V' . $n ) ) {
				return $n;
			}
		}
		return 1;
	}

	private static function key( int $version ): string {
		$key = Env::secret( 'MOMOS_TOKEN_KEY_V' . $version );
		if ( '' === $key ) {
			throw new \RuntimeException( 'Token key version ' . $version . ' is not configured' );
		}
		return $key;
	}

	public static function derive( int $id, string $public_ref, string $created_at_utc, int $version ): string {
		$msg = 'momos-res-token|v' . $version . '|' . $id . '|' . $public_ref . '|' . $created_at_utc;
		return rtrim( strtr( base64_encode( hash_hmac( 'sha256', $msg, self::key( $version ), true ) ), '+/', '-_' ), '=' );
	}

	public static function hash( string $token ): string {
		return hash( 'sha256', $token );
	}

	/** @param array<string,mixed> $row */
	public static function token_for( array $row ): string {
		return self::derive( (int) $row['id'], (string) $row['public_ref'], (string) $row['created_at'], (int) $row['token_key_version'] );
	}

	/**
	 * Customer status link (docs/CONTRACTS.md): {home}/{lang-prefix}reservation/status/{REF}/#t={token}.
	 *
	 * @param array<string,mixed> $reservation
	 */
	public static function status_url( array $reservation ): string {
		$lang = (string) ( $reservation['preferred_lang'] ?? 'ja' );
		return Url::to( '/reservation/status/' . $reservation['public_ref'] . '/', $lang ) . '#t=' . self::token_for( $reservation );
	}

	/**
	 * Verify a header token for a reference (API-03/04 steps 3–5). Returns the row.
	 *
	 * @return array<string,mixed>
	 * @throws ReservationError not_found | link_expired
	 */
	public static function verify( string $ref, string $token ): array {
		$row = ReservationRepository::find_by_ref( $ref );
		if ( null === $row || null !== $row['pii_purged_at'] || empty( $row['token_hash'] ) ) {
			throw new ReservationError( 'not_found' );
		}
		if ( ! hash_equals( (string) $row['token_hash'], self::hash( $token ) ) ) {
			throw new ReservationError( 'not_found' );
		}
		$expires = Clock::from_mysql_utc( (string) $row['token_expires_utc'] );
		if ( null !== $expires && Clock::utc() >= $expires ) {
			throw new ReservationError( 'link_expired' );
		}
		return $row;
	}

	/**
	 * Re-issue tokens of unexpired reservations with a new key version (`wp momos token reissue`).
	 * Fires `momos/reservation/token_reissued (int $id)` per row so Notification can send E06 `new_link`.
	 */
	public static function reissue( int $version, int $limit = 5000 ): int {
		global $wpdb;
		self::key( $version );
		$t    = \Momos\Kernel\Db\Tables::reservations();
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE pii_purged_at IS NULL AND token_hash IS NOT NULL AND token_expires_utc > %s AND token_key_version <> %d LIMIT %d", Clock::mysql_utc(), $version, $limit ), ARRAY_A ); // phpcs:ignore
		$n    = 0;
		foreach ( $rows as $row ) {
			$token = self::derive( (int) $row['id'], (string) $row['public_ref'], (string) $row['created_at'], $version );
			$done  = ReservationRepository::update_conditional( (int) $row['id'], (int) $row['version'], null, [ 'token_hash' => self::hash( $token ), 'token_key_version' => $version ] );
			if ( $done ) {
				++$n;
				\Momos\Kernel\Events\AfterCommit::dispatch( 'momos/reservation/token_reissued', (int) $row['id'] );
			}
		}
		return $n;
	}
}
