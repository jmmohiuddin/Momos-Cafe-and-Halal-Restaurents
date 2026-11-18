<?php
declare(strict_types=1);

namespace Momos\Kernel\Http;

final class RequestContext {
	private static ?string $id = null;

	public static function id(): string {
		return self::$id ??= wp_generate_uuid4();
	}

	public static function ip(): ?string {
		// Behind a trusted proxy/CDN the real IP header is configured explicitly (MOMOS_TRUSTED_IP_HEADER).
		if ( defined( 'MOMOS_TRUSTED_IP_HEADER' ) && MOMOS_TRUSTED_IP_HEADER ) {
			$h = 'HTTP_' . strtoupper( str_replace( '-', '_', (string) MOMOS_TRUSTED_IP_HEADER ) );
			if ( ! empty( $_SERVER[ $h ] ) ) {
				$ip = trim( explode( ',', (string) wp_unslash( $_SERVER[ $h ] ) )[0] );
				return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : null;
			}
		}
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : null;
	}

	public static function user_agent(): string {
		return isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 400 ) : '';
	}
}
