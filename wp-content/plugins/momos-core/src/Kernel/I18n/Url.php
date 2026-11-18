<?php
declare(strict_types=1);

namespace Momos\Kernel\I18n;

/**
 * Language-aware URLs (DEC-003): ja at root, /en/ /ar/ /ur/ /hi/ /bn/ prefixes.
 */
final class Url {
	/** Absolute URL for a site path in a language. $path like "/branches/shinsaibashi/". */
	public static function to( string $path = '/', ?string $lang = null ): string {
		$lang   = $lang ?? Locale::current();
		$prefix = Lang::ALL[ $lang ]['prefix'] ?? '';
		$path   = '/' . ltrim( $path, '/' );
		if ( '' !== $prefix ) {
			$path = '/' . $prefix . $path;
		}
		return self::raw_home() . $path;
	}

	/** Insert the language prefix into an absolute URL of this site (idempotent). */
	public static function localize( string $url, ?string $lang = null ): string {
		$lang = $lang ?? Locale::current();
		$home = self::raw_home();
		if ( ! str_starts_with( $url, $home ) ) {
			return $url;
		}
		$path = substr( $url, strlen( $home ) );
		$path = self::strip_prefix( '' === $path ? '/' : $path );
		return self::to( $path, $lang );
	}

	/** Remove a language prefix from a path ("/en/menu/" → "/menu/"). */
	public static function strip_prefix( string $path ): string {
		foreach ( Lang::targets() as $code ) {
			$prefix = '/' . Lang::ALL[ $code ]['prefix'];
			if ( $path === $prefix ) {
				return '/';
			}
			if ( str_starts_with( $path, $prefix . '/' ) || str_starts_with( $path, $prefix . '?' ) ) {
				return substr( $path, strlen( $prefix ) );
			}
		}
		return $path;
	}

	/** Detect the language of a path. */
	public static function lang_of_path( string $path ): string {
		foreach ( Lang::targets() as $code ) {
			$prefix = '/' . Lang::ALL[ $code ]['prefix'];
			if ( $path === $prefix || str_starts_with( $path, $prefix . '/' ) || str_starts_with( $path, $prefix . '?' ) ) {
				return $code;
			}
		}
		return Lang::SOURCE;
	}

	/** home URL without any filter-added language prefix, no trailing slash. */
	public static function raw_home(): string {
		return untrailingslashit( (string) get_option( 'home' ) );
	}

	/** Path of the current request relative to home, without language prefix. */
	public static function current_path(): string {
		$uri  = Router::original_uri();
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$base = (string) wp_parse_url( self::raw_home(), PHP_URL_PATH );
		if ( '' !== $base && str_starts_with( $path, $base ) ) {
			$path = substr( $path, strlen( $base ) );
		}
		return self::strip_prefix( '' === $path ? '/' : $path );
	}
}
