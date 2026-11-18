<?php
declare(strict_types=1);

namespace Momos\Kernel\Log;

final class Redactor {
	private const SENSITIVE_KEYS = [ 'email', 'phone', 'phone_e164', 'guest_name', 'name', 'token', 'password', 'special_request', 'recipient_email', 'authorization', 'x-momo-token', 'secret' ];

	/**
	 * @param array<mixed> $context
	 * @return array<mixed>
	 */
	public static function redact( array $context ): array {
		foreach ( $context as $key => $value ) {
			if ( is_string( $key ) && in_array( strtolower( $key ), self::SENSITIVE_KEYS, true ) ) {
				$context[ $key ] = '[redacted]';
			} elseif ( is_array( $value ) ) {
				$context[ $key ] = self::redact( $value );
			} elseif ( is_string( $value ) ) {
				$context[ $key ] = self::scrub( $value );
			}
		}
		return $context;
	}

	public static function scrub( string $value ): string {
		$value = (string) preg_replace( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email]', $value );
		return (string) preg_replace( '/\+?\d[\d\s-]{8,}\d/', '[phone]', $value );
	}
}
