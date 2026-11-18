<?php
declare(strict_types=1);

namespace Momos\Kernel\I18n;

/**
 * Key-based UI string catalog for customer-facing text and emails (Doc 07 §6 key register).
 *
 * Defaults: languages/strings/{lang}.php (array key => string). Reviewed overrides edited by
 * translators in SCR-A11 are stored in option `momos_strings_overrides` [lang][key].
 * Placeholders use {name}. A key missing in a target language falls back to English and is
 * reported in the translation gaps view (UI chrome only; content never falls back — DEC-027).
 */
final class Strings {
	/** @var array<string, array<string,string>> */
	private static array $catalog = [];

	/** @var array<string, array<string,string>>|null */
	private static ?array $overrides = null;

	/** @param array<string, string|int|float> $args */
	public static function t( string $key, array $args = [], ?string $lang = null ): string {
		$lang  = $lang ?? Locale::current();
		$value = self::raw( $key, $lang );
		if ( null === $value && 'en' !== $lang ) {
			$value = self::raw( $key, 'en' );
		}
		if ( null === $value ) {
			$value = $key;
		}
		if ( $args ) {
			$replace = [];
			foreach ( $args as $k => $v ) {
				$replace[ '{' . $k . '}' ] = (string) $v;
			}
			$value = strtr( $value, $replace );
		}
		return $value;
	}

	public static function raw( string $key, string $lang ): ?string {
		$over = self::overrides()[ $lang ][ $key ] ?? null;
		if ( null !== $over && '' !== $over ) {
			return $over;
		}
		$cat = self::catalog( $lang );
		return $cat[ $key ] ?? null;
	}

	public static function has( string $key, string $lang ): bool {
		return null !== self::raw( $key, $lang );
	}

	/** @return array<string,string> */
	public static function catalog( string $lang ): array {
		if ( ! isset( self::$catalog[ $lang ] ) ) {
			$file                   = MOMOS_CORE_DIR . '/languages/strings/' . $lang . '.php';
			$data                   = is_readable( $file ) ? include $file : [];
			self::$catalog[ $lang ] = is_array( $data ) ? $data : [];
			/** Allow theme/modules to add keys. */
			self::$catalog[ $lang ] = (array) apply_filters( 'momos/strings/catalog', self::$catalog[ $lang ], $lang );
		}
		return self::$catalog[ $lang ];
	}

	/** @return array<string, array<string,string>> */
	public static function overrides(): array {
		if ( null === self::$overrides ) {
			$o               = get_option( 'momos_strings_overrides', [] );
			self::$overrides = is_array( $o ) ? $o : [];
		}
		return self::$overrides;
	}

	public static function save_override( string $lang, string $key, string $value ): void {
		$o                  = self::overrides();
		$o[ $lang ][ $key ] = sanitize_textarea_field( $value );
		if ( '' === $o[ $lang ][ $key ] ) {
			unset( $o[ $lang ][ $key ] );
		}
		update_option( 'momos_strings_overrides', $o, true );
		self::$overrides = $o;
	}

	/** Keys present in the English catalog but missing in $lang. @return list<string> */
	public static function missing_keys( string $lang ): array {
		$out = [];
		foreach ( array_keys( self::catalog( 'en' ) ) as $key ) {
			if ( null === self::raw( $key, $lang ) ) {
				$out[] = $key;
			}
		}
		return $out;
	}

	public static function flush(): void {
		self::$catalog   = [];
		self::$overrides = null;
	}
}
