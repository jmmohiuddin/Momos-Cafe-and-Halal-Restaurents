<?php
declare(strict_types=1);

namespace Momos\Access\TwoFactor;

use Momos\Kernel\Clock\Clock;

/**
 * Pre-authentication state between the password step and the second factor (Access spec §5.1):
 * user meta `momo_2fa_challenge` = {hash (SHA-256 of a 32-byte token), expires_utc, redirect_to,
 * attempts, enrol, stage, pending}. The token travels only in a POST hidden field, never in a URL.
 */
final class Challenge {
	public const META         = 'momo_2fa_challenge';
	public const TTL          = 300;
	public const MAX_ATTEMPTS = 5;

	/** @return string the plain token */
	public static function create( int $user_id, string $redirect_to, bool $enrol ): string {
		$token = bin2hex( random_bytes( 32 ) );
		$data  = [
			'hash'        => hash( 'sha256', $token ),
			'expires_utc' => Clock::utc()->getTimestamp() + self::TTL,
			'redirect_to' => $redirect_to,
			'attempts'    => 0,
			'enrol'       => $enrol,
			'stage'       => $enrol ? 'enrol' : 'code',
			'pending'     => $enrol ? TwoFactor::encrypt( Totp::new_secret() ) : '',
		];
		update_user_meta( $user_id, self::META, (string) wp_json_encode( $data ) );
		return $token;
	}

	/** @return array<string,mixed>|null valid, unexpired challenge for the token */
	public static function get( int $user_id, string $token ): ?array {
		if ( $user_id <= 0 || ! preg_match( '/^[0-9a-f]{64}$/', $token ) ) {
			return null;
		}
		$data = json_decode( (string) get_user_meta( $user_id, self::META, true ), true );
		if ( ! is_array( $data ) || ! hash_equals( (string) ( $data['hash'] ?? '' ), hash( 'sha256', $token ) ) ) {
			return null;
		}
		if ( (int) ( $data['expires_utc'] ?? 0 ) < Clock::utc()->getTimestamp() ) {
			self::delete( $user_id );
			return null;
		}
		return $data;
	}

	/** Persist changes and extend the lifetime by another TTL (each completed step). @param array<string,mixed> $data */
	public static function save( int $user_id, array $data ): void {
		$data['expires_utc'] = Clock::utc()->getTimestamp() + self::TTL;
		update_user_meta( $user_id, self::META, (string) wp_json_encode( $data ) );
	}

	public static function delete( int $user_id ): void {
		delete_user_meta( $user_id, self::META );
	}
}
