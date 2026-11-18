<?php
declare(strict_types=1);

namespace Momos\Kernel\Support;

final class Json {
	public static function encode( mixed $value ): string {
		return (string) wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/** @return array<mixed> */
	public static function decode_array( ?string $json ): array {
		if ( null === $json || '' === $json ) {
			return [];
		}
		$value = json_decode( $json, true );
		return is_array( $value ) ? $value : [];
	}
}
