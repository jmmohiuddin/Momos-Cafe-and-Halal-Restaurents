<?php
declare(strict_types=1);

namespace Momos\Kernel\Support;

use Momos\Kernel\Env;

/**
 * Keyed hashes for abuse correlation and audit integrity (DEC-059). Keys come from constants.
 */
final class Hash {
	public static function hmac( string $value, string $key_name = 'MOMOS_HMAC_KEY' ): string {
		return hash_hmac( 'sha256', $value, Env::secret( $key_name ) );
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
