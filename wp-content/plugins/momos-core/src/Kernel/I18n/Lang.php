<?php
declare(strict_types=1);

namespace Momos\Kernel\I18n;

/**
 * The six customer languages (DEC-002/003). Japanese is the source language at the URL root.
 */
final class Lang {
	public const SOURCE = 'ja';

	/** code => [locale, native name, direction, url prefix, html lang] */
	public const ALL = [
		'ja' => [ 'locale' => 'ja', 'native' => '日本語', 'english' => 'Japanese', 'dir' => 'ltr', 'prefix' => '', 'html' => 'ja' ],
		'en' => [ 'locale' => 'en_US', 'native' => 'English', 'english' => 'English', 'dir' => 'ltr', 'prefix' => 'en', 'html' => 'en' ],
		'ar' => [ 'locale' => 'ar', 'native' => 'العربية', 'english' => 'Arabic', 'dir' => 'rtl', 'prefix' => 'ar', 'html' => 'ar' ],
		'ur' => [ 'locale' => 'ur', 'native' => 'اردو', 'english' => 'Urdu', 'dir' => 'rtl', 'prefix' => 'ur', 'html' => 'ur' ],
		'hi' => [ 'locale' => 'hi_IN', 'native' => 'हिन्दी', 'english' => 'Hindi', 'dir' => 'ltr', 'prefix' => 'hi', 'html' => 'hi' ],
		'bn' => [ 'locale' => 'bn_BD', 'native' => 'বাংলা', 'english' => 'Bengali', 'dir' => 'ltr', 'prefix' => 'bn', 'html' => 'bn' ],
	];

	/** Admin languages (DEC-037). */
	public const ADMIN = [ 'ja', 'en' ];

	/** @return list<string> */
	public static function codes(): array {
		return array_keys( self::ALL );
	}

	/** @return list<string> non-source codes */
	public static function targets(): array {
		return array_values( array_diff( self::codes(), [ self::SOURCE ] ) );
	}

	public static function is_valid( ?string $code ): bool {
		return null !== $code && isset( self::ALL[ $code ] );
	}

	public static function is_rtl( string $code ): bool {
		return 'rtl' === ( self::ALL[ $code ]['dir'] ?? 'ltr' );
	}

	public static function locale( string $code ): string {
		return self::ALL[ $code ]['locale'] ?? 'ja';
	}

	public static function native( string $code ): string {
		return self::ALL[ $code ]['native'] ?? $code;
	}

	public static function from_locale( string $locale ): string {
		foreach ( self::ALL as $code => $def ) {
			if ( $def['locale'] === $locale || str_starts_with( $locale, $code ) ) {
				return $code;
			}
		}
		return 'en';
	}

	/**
	 * Publicly enabled languages (language launch gate, DEC-002, IMPL-003). The source language
	 * is always enabled.
	 *
	 * @return list<string>
	 */
	public static function enabled(): array {
		$default = \Momos\Kernel\Env::is_production() ? [ 'ja', 'en' ] : self::codes();
		$enabled = get_option( 'momos_languages_enabled', $default );
		$enabled = array_values( array_intersect( self::codes(), array_merge( [ self::SOURCE ], (array) $enabled ) ) );
		return $enabled;
	}

	public static function is_enabled( string $code ): bool {
		return in_array( $code, self::enabled(), true );
	}
}
