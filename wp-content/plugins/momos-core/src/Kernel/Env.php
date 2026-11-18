<?php
declare(strict_types=1);

namespace Momos\Kernel;

/**
 * Environment helpers. MOMOS_ENV is one of development, staging, production (TRD §6.4).
 */
final class Env {
	public static function name(): string {
		$env = defined( 'MOMOS_ENV' ) ? (string) MOMOS_ENV : ( function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production' );
		return in_array( $env, [ 'development', 'local', 'staging', 'production' ], true ) ? ( 'local' === $env ? 'development' : $env ) : 'production';
	}

	public static function is_production(): bool {
		return 'production' === self::name();
	}

	/** Secret from a constant (wp-config / environment), never from the database. */
	public static function secret( string $name, string $fallback = '' ): string {
		if ( defined( $name ) && '' !== (string) constant( $name ) ) {
			return (string) constant( $name );
		}
		$env = getenv( $name );
		if ( false !== $env && '' !== $env ) {
			return $env;
		}
		if ( '' === $fallback && ! self::is_production() ) {
			// Deterministic development key derived from WordPress salts (never used in production).
			return hash( 'sha256', $name . ( defined( 'AUTH_SALT' ) ? AUTH_SALT : 'momos-dev' ) );
		}
		return $fallback;
	}
}
