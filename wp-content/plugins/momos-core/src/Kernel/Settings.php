<?php
declare(strict_types=1);

namespace Momos\Kernel;

/**
 * Options-page values (brand G1, dietary statements G2, settings G3). Each options page is one
 * option `momos_opt_{page}` holding {field name => value}. Field definitions (defaults, page)
 * come from Fields\FieldRegistry.
 */
final class Settings {
	/** @var array<string, array<string,mixed>> */
	private static array $cache = [];

	public static function get( string $name, mixed $default = null ): mixed {
		$page = Fields\FieldRegistry::option_page_of( $name );
		if ( null === $page ) {
			return $default;
		}
		$values = self::page( $page );
		if ( array_key_exists( $name, $values ) ) {
			return $values[ $name ];
		}
		$def = Fields\FieldRegistry::field_def( 'option:' . $page, $name );
		return $def['default'] ?? $default;
	}

	/** @return array<string,mixed> */
	public static function page( string $page ): array {
		if ( ! isset( self::$cache[ $page ] ) ) {
			$v                    = get_option( 'momos_opt_' . $page, [] );
			self::$cache[ $page ] = is_array( $v ) ? $v : [];
		}
		return self::$cache[ $page ];
	}

	public static function set( string $name, mixed $value ): void {
		$page = Fields\FieldRegistry::option_page_of( $name );
		if ( null === $page ) {
			throw new \InvalidArgumentException( "Unknown setting {$name}" );
		}
		$values          = self::page( $page );
		$values[ $name ] = $value;
		update_option( 'momos_opt_' . $page, $values, true );
		self::$cache[ $page ] = $values;
	}

	/** @param array<string,mixed> $values */
	public static function save_page( string $page, array $values ): void {
		update_option( 'momos_opt_' . $page, $values, true );
		self::$cache[ $page ] = $values;
	}

	public static function flush(): void {
		self::$cache = [];
	}
}
