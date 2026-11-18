<?php
declare(strict_types=1);

namespace Momos\Kernel\Support;

final class Uuid {
	public static function v4(): string {
		return wp_generate_uuid4();
	}

	public static function is_valid( string $value ): bool {
		return (bool) preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value );
	}
}
