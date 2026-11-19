<?php
declare(strict_types=1);

namespace Momos\Kernel\Support;

use Momos\Kernel\Env;

/**
 * Keyed hashes for abuse correlation and audit integrity (DEC-059). Keys come from constants.
 */
final class Hash {
	/** Purposes that separate the HMAC domains of one pepper (access-security-privacy.md §9.1/§17.16, DEC-059). */
	public const PURPOSES = [ 'ip', 'ua', 'email', 'rl', 'audit' ];

	/**
	 * Keyed hash. Default key: MOMOS_HASH_PEPPER (spec C-15), alias MOMOS_HMAC_KEY.
	 *
	 * Fails closed: a missing key throws instead of hashing with an empty key (an unkeyed HMAC of an IP or
	 * email is reversible by enumeration, and an unkeyed audit chain is forgeable). Outside production
	 * Env::secret() derives a deterministic development key, so this only throws on a misconfigured production.
	 *
	 * @throws \RuntimeException when the key is not configured.
	 */
	public static function hmac( string $value, string $key_name = 'MOMOS_HASH_PEPPER' ): string {
		return hash_hmac( 'sha256', $value, self::key( $key_name ) );
	}

	/**
	 * Purpose-separated pepper HMAC: HMAC-SHA256(pepper, "{purpose}|{value}") — `ip` (reservation ip_hash),
	 * `ua` (user_agent_hash), `email` (recipient hashes), `rl` (rate-limit subjects), `audit` (row chain).
	 */
	public static function purpose( string $purpose, string $value ): string {
		if ( ! in_array( $purpose, self::PURPOSES, true ) ) {
			throw new \InvalidArgumentException( 'Unknown HMAC purpose: ' . $purpose ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		return self::hmac( $purpose . '|' . $value );
	}

	/**
	 * The configured key for $key_name (pepper alias resolved).
	 *
	 * @throws \RuntimeException when the key is not configured.
	 */
	public static function key( string $key_name = 'MOMOS_HASH_PEPPER' ): string {
		if ( 'MOMOS_HASH_PEPPER' === $key_name && ! Env::has( 'MOMOS_HASH_PEPPER' ) && Env::has( 'MOMOS_HMAC_KEY' ) ) {
			$key_name = 'MOMOS_HMAC_KEY';
		}
		$key = Env::secret( $key_name );
		if ( '' === $key ) {
			\Momos\Kernel\Log\Logger::critical( 'secret.missing', [ 'name' => $key_name ] );
			throw new \RuntimeException( 'Secret ' . $key_name . ' is not configured' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		return $key;
	}

	public static function sha256( string $value ): string {
		return hash( 'sha256', $value );
	}

	/** Truncate an IP for the audit log: IPv4 /24, IPv6 /48 (DEC-059). */
	public static function truncate_ip( ?string $ip ): ?string {
		if ( null === $ip || '' === $ip ) {
			return null;
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$parts    = explode( '.', $ip );
			$parts[3] = '0';
			return implode( '.', $parts ) . '/24';
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$bin = inet_pton( $ip );
			if ( false === $bin ) {
				return null;
			}
			$bin = substr( $bin, 0, 6 ) . str_repeat( "\0", 10 );
			return inet_ntop( $bin ) . '/48';
		}
		return null;
	}

	/** Mask an email for logs: a***@d***.com */
	public static function mask_email( ?string $email ): string {
		if ( null === $email || ! str_contains( $email, '@' ) ) {
			return '';
		}
		[ $local, $domain ] = explode( '@', $email, 2 );
		$dot                = strrpos( $domain, '.' );
		$tld                = false === $dot ? '' : substr( $domain, $dot );
		return mb_substr( $local, 0, 1 ) . '***@' . mb_substr( $domain, 0, 1 ) . '***' . $tld;
	}
}
