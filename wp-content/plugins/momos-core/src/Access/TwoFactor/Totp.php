<?php
declare(strict_types=1);

namespace Momos\Access\TwoFactor;

/**
 * RFC 6238 TOTP (HMAC-SHA1, 6 digits, 30 s) with RFC 4648 base32 secrets (Access spec §5.2).
 */
final class Totp {
	public const PERIOD = 30;
	public const DIGITS = 6;
	public const WINDOW = 1; // Accept T−1, T, T+1.

	private const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

	/** 20 random bytes (160 bits, RFC 4226 recommendation). */
	public static function new_secret(): string {
		return random_bytes( 20 );
	}

	public static function step( int $unix ): int {
		return intdiv( $unix, self::PERIOD );
	}

	/** RFC 4226 HOTP value for a counter. */
	public static function code( string $secret, int $counter ): string {
		$msg  = pack( 'N2', ( $counter >> 32 ) & 0xFFFFFFFF, $counter & 0xFFFFFFFF );
		$hash = hash_hmac( 'sha1', $msg, $secret, true );
		$off  = ord( $hash[19] ) & 0x0F;
		$bin  = ( ( ord( $hash[ $off ] ) & 0x7F ) << 24 ) | ( ord( $hash[ $off + 1 ] ) << 16 ) | ( ord( $hash[ $off + 2 ] ) << 8 ) | ord( $hash[ $off + 3 ] );
		return str_pad( (string) ( $bin % ( 10 ** self::DIGITS ) ), self::DIGITS, '0', STR_PAD_LEFT );
	}

	/**
	 * Verify a code; returns the matching time-step, or null. Steps ≤ $last_step are rejected
	 * (replay guard, `momo_2fa_last_step`).
	 */
	public static function verify( string $secret, string $input, int $unix, int $last_step = -1 ): ?int {
		$input = preg_replace( '/\D/', '', $input ) ?? '';
		if ( self::DIGITS !== strlen( $input ) ) {
			return null;
		}
		$now = self::step( $unix );
		for ( $d = -self::WINDOW; $d <= self::WINDOW; $d++ ) {
			$s = $now + $d;
			if ( $s > $last_step && hash_equals( self::code( $secret, $s ), $input ) ) {
				return $s;
			}
		}
		return null;
	}

	public static function base32_encode( string $bin ): string {
		$bits = '';
		foreach ( str_split( $bin ) as $c ) {
			$bits .= str_pad( decbin( ord( $c ) ), 8, '0', STR_PAD_LEFT );
		}
		$out = '';
		foreach ( str_split( $bits, 5 ) as $chunk ) {
			$out .= self::B32[ bindec( str_pad( $chunk, 5, '0' ) ) ];
		}
		return $out;
	}

	public static function base32_decode( string $b32 ): string {
		$b32  = strtoupper( (string) preg_replace( '/[\s=-]/', '', $b32 ) );
		$bits = '';
		foreach ( str_split( $b32 ) as $c ) {
			$v = strpos( self::B32, $c );
			if ( false === $v ) {
				throw new \InvalidArgumentException( 'Invalid base32' );
			}
			$bits .= str_pad( decbin( $v ), 5, '0', STR_PAD_LEFT );
		}
		$out = '';
		foreach ( str_split( $bits, 8 ) as $byte ) {
			if ( 8 === strlen( $byte ) ) {
				$out .= chr( bindec( $byte ) );
			}
		}
		return $out;
	}

	/** Secret for display: base32 in groups of 4. */
	public static function display( string $secret ): string {
		return trim( chunk_split( self::base32_encode( $secret ), 4, ' ' ) );
	}

	/** otpauth:// provisioning URI (Key URI Format). */
	public static function uri( string $secret, string $account, string $issuer ): string {
		$label = rawurlencode( $issuer ) . ':' . rawurlencode( $account );
		return 'otpauth://totp/' . $label . '?secret=' . self::base32_encode( $secret ) . '&issuer=' . rawurlencode( $issuer ) . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
	}
}
