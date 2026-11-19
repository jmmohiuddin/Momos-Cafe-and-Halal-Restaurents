<?php
declare(strict_types=1);

namespace Momos\Kernel\Http;

final class RequestContext {
	private static ?string $id = null;

	public static function id(): string {
		return self::$id ??= wp_generate_uuid4();
	}

	/**
	 * Client IP (SDD §5.14, access-security-privacy.md §7.6): `REMOTE_ADDR`, or the CDN client-IP header
	 * (MOMOS_TRUSTED_IP_HEADER) only when the peer is inside the configured CDN/proxy ranges
	 * (MOMOS_TRUSTED_PROXIES, CIDR list). Without a configured range the header is ignored (fail closed), so a
	 * client talking to the origin directly cannot choose its own rate-limit / audit IP.
	 */
	public static function ip(): ?string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$remote = filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : null;
		if ( null !== $remote && defined( 'MOMOS_TRUSTED_IP_HEADER' ) && MOMOS_TRUSTED_IP_HEADER && self::is_trusted_proxy( $remote ) ) {
			$h = 'HTTP_' . strtoupper( str_replace( '-', '_', (string) MOMOS_TRUSTED_IP_HEADER ) );
			if ( ! empty( $_SERVER[ $h ] ) ) {
				$ip = trim( explode( ',', (string) wp_unslash( $_SERVER[ $h ] ) )[0] );
				return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : $remote;
			}
		}
		return $remote;
	}

	/** @return list<string> configured proxy/CDN ranges (CIDR or single addresses). */
	public static function trusted_proxies(): array {
		$raw = defined( 'MOMOS_TRUSTED_PROXIES' ) ? MOMOS_TRUSTED_PROXIES : '';
		$raw = is_array( $raw ) ? implode( ',', array_map( 'strval', $raw ) ) : (string) $raw;
		return array_values( array_filter( array_map( 'trim', preg_split( '/[\s,]+/', $raw ) ?: [] ) ) );
	}

	public static function is_trusted_proxy( string $ip ): bool {
		foreach ( self::trusted_proxies() as $range ) {
			if ( self::in_range( $ip, $range ) ) {
				return true;
			}
		}
		return false;
	}

	/** IPv4/IPv6 CIDR match ("203.0.113.0/24", "2400:cb00::/32", or a single address). */
	public static function in_range( string $ip, string $range ): bool {
		[ $net, $bits ] = array_pad( explode( '/', $range, 2 ), 2, null );
		$ip_bin         = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$net_bin        = @inet_pton( (string) $net ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $ip_bin || false === $net_bin || strlen( $ip_bin ) !== strlen( $net_bin ) ) {
			return false;
		}
		$max  = strlen( $ip_bin ) * 8;
		$bits = null === $bits ? $max : ( ctype_digit( $bits ) ? (int) $bits : -1 );
		if ( $bits < 0 || $bits > $max ) {
			return false;
		}
		$bytes = intdiv( $bits, 8 );
		if ( substr( $ip_bin, 0, $bytes ) !== substr( $net_bin, 0, $bytes ) ) {
			return false;
		}
		$rest = $bits % 8;
		if ( 0 === $rest ) {
			return true;
		}
		$mask = ( 0xff << ( 8 - $rest ) ) & 0xff;
		return ( ord( $ip_bin[ $bytes ] ) & $mask ) === ( ord( $net_bin[ $bytes ] ) & $mask );
	}

	public static function user_agent(): string {
		return isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 400 ) : '';
	}
}
