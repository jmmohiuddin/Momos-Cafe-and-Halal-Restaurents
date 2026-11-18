<?php
declare(strict_types=1);

namespace Momos\Access\TwoFactor;

use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Env;
use Momos\Kernel\Support\Crockford;

/**
 * TOTP second factor for staff accounts (SEC-001, FR-SEC-001, BR-ADM-02; Access spec §5.2).
 *
 * Storage (user meta, exact keys): `momo_2fa_secret` = "v1:" + base64(nonce‖secretbox), key =
 * HKDF-SHA256(MOMOS_2FA_KEY, "momos-2fa") (fallback AUTH_KEY.SECURE_AUTH_KEY); `momo_2fa_enrolled_at`;
 * `momo_2fa_last_step` (replay guard); `momo_2fa_recovery` = JSON list of wp_hash_password() hashes
 * (10 codes XXXX-XXXX, 40 bits each, single use).
 *
 * Enforcement: every staff account when MOMOS_2FA_ENFORCE is true, the setting `momo_2fa_enforce`
 * is on, or the environment is production; otherwise for users who enrolled or opted in.
 */
final class TwoFactor {
	public const META_SECRET   = 'momo_2fa_secret';
	public const META_ENROLLED = 'momo_2fa_enrolled_at';
	public const META_LAST     = 'momo_2fa_last_step';
	public const META_RECOVERY = 'momo_2fa_recovery';
	public const META_OPTIN    = 'momo_2fa_optin';
	public const RECOVERY_COUNT = 10;

	public static function enforced(): bool {
		if ( defined( 'MOMOS_2FA_ENFORCE' ) && MOMOS_2FA_ENFORCE ) {
			return true;
		}
		if ( Env::is_production() ) {
			return true;
		}
		$setting = class_exists( \Momos\Kernel\Settings::class ) ? \Momos\Kernel\Settings::get( 'momo_2fa_enforce', '0' ) : '0';
		return '1' === (string) $setting;
	}

	public static function is_enrolled( int $user_id ): bool {
		return '' !== (string) get_user_meta( $user_id, self::META_SECRET, true ) && '' !== (string) get_user_meta( $user_id, self::META_ENROLLED, true );
	}

	/** Is a second factor required for this user at login and for every session? */
	public static function required( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		return self::enforced() || self::is_enrolled( $user_id ) || '1' === (string) get_user_meta( $user_id, self::META_OPTIN, true );
	}

	// ----------------------------------------------------------------- secrets

	private static function key(): string {
		$material = self::key_is_fallback() ? '' : Env::secret( 'MOMOS_2FA_KEY' );
		if ( '' === trim( $material ) ) {
			$material = ( defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_KEY' ) ? (string) SECURE_AUTH_KEY : '' );
		}
		if ( '' === trim( $material ) ) {
			$material = wp_salt( 'secure_auth' ); // Last resort when no salts are configured (SA notice shown).
		}
		return hash_hkdf( 'sha256', $material, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'momos-2fa' );
	}

	/** Is the dedicated key constant missing (SA notice)? */
	public static function key_is_fallback(): bool {
		return ! ( defined( 'MOMOS_2FA_KEY' ) && '' !== (string) MOMOS_2FA_KEY ) && ! getenv( 'MOMOS_2FA_KEY' );
	}

	public static function encrypt( string $secret ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return 'v1:' . base64_encode( $nonce . sodium_crypto_secretbox( $secret, $nonce, self::key() ) );
	}

	public static function decrypt( string $stored ): ?string {
		if ( ! str_starts_with( $stored, 'v1:' ) ) {
			return null;
		}
		$raw = base64_decode( substr( $stored, 3 ), true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::key() );
		return false === $plain ? null : $plain;
	}

	// ----------------------------------------------------------------- enrolment

	/**
	 * Complete enrolment with a verified code; returns the 10 plain recovery codes (shown once),
	 * or null when the code is wrong.
	 *
	 * @return list<string>|null
	 */
	public static function enrol( int $user_id, string $secret, string $code ): ?array {
		$step = Totp::verify( $secret, $code, Clock::utc()->getTimestamp() );
		if ( null === $step ) {
			return null;
		}
		update_user_meta( $user_id, self::META_SECRET, self::encrypt( $secret ) );
		update_user_meta( $user_id, self::META_LAST, $step );
		update_user_meta( $user_id, self::META_ENROLLED, Clock::mysql_utc() );
		delete_user_meta( $user_id, self::META_OPTIN );
		$codes = self::new_recovery_codes( $user_id );
		\Momos\Access\AuditTrail::for_user( $user_id, 'user.2fa_enrolled', 'user', $user_id );
		return $codes;
	}

	/** @return list<string> plain codes (stored hashed) */
	public static function new_recovery_codes( int $user_id ): array {
		$codes  = [];
		$hashes = [];
		for ( $i = 0; $i < self::RECOVERY_COUNT; $i++ ) {
			$c        = Crockford::random( 8 );
			$codes[]  = Crockford::display( $c );
			$hashes[] = wp_hash_password( $c );
		}
		update_user_meta( $user_id, self::META_RECOVERY, (string) wp_json_encode( $hashes ) );
		return $codes;
	}

	public static function recovery_remaining( int $user_id ): int {
		return count( self::recovery_hashes( $user_id ) );
	}

	/** @return list<string> */
	private static function recovery_hashes( int $user_id ): array {
		$list = json_decode( (string) get_user_meta( $user_id, self::META_RECOVERY, true ), true );
		return is_array( $list ) ? array_values( array_map( 'strval', $list ) ) : [];
	}

	// ----------------------------------------------------------------- verification

	/** Verify a TOTP code for an enrolled user (replay-protected). */
	public static function verify_code( int $user_id, string $code ): bool {
		$secret = self::decrypt( (string) get_user_meta( $user_id, self::META_SECRET, true ) );
		if ( null === $secret ) {
			return false;
		}
		$last = (int) get_user_meta( $user_id, self::META_LAST, true );
		$step = Totp::verify( $secret, $code, Clock::utc()->getTimestamp(), $last );
		if ( null === $step ) {
			return false;
		}
		update_user_meta( $user_id, self::META_LAST, $step );
		return true;
	}

	/** Use a single-use recovery code (hash removed on success). */
	public static function use_recovery_code( int $user_id, string $input ): bool {
		$code = Crockford::normalize( $input );
		if ( null === $code ) {
			return false;
		}
		$hashes = self::recovery_hashes( $user_id );
		foreach ( $hashes as $i => $hash ) {
			if ( wp_check_password( $code, $hash, $user_id ) ) {
				unset( $hashes[ $i ] );
				update_user_meta( $user_id, self::META_RECOVERY, (string) wp_json_encode( array_values( $hashes ) ) );
				\Momos\Access\AuditTrail::for_user( $user_id, 'user.2fa_recovery_used', 'user', $user_id, null, [ 'remaining' => count( $hashes ) ] );
				return true;
			}
		}
		return false;
	}

	/** Remove the second factor (lost device, §5.2); the caller audits and destroys sessions. */
	public static function reset( int $user_id ): void {
		foreach ( [ self::META_SECRET, self::META_ENROLLED, self::META_LAST, self::META_RECOVERY, Challenge::META ] as $k ) {
			delete_user_meta( $user_id, $k );
		}
	}
}
