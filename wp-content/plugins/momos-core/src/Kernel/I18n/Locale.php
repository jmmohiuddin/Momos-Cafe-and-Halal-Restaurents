<?php
declare(strict_types=1);

namespace Momos\Kernel\I18n;

/**
 * Current request language. Front end: from the URL prefix (Router). REST: from `lang`
 * parameter. Admin: from the user's profile language (ja/en, DEC-037).
 */
final class Locale {
	private static ?string $current = null;

	public static function set( string $code ): void {
		if ( Lang::is_valid( $code ) ) {
			self::$current = $code;
		}
	}

	public static function reset(): void {
		self::$current = null;
	}

	public static function current(): string {
		if ( null !== self::$current ) {
			return self::$current;
		}
		if ( is_admin() && ! wp_doing_ajax() ) {
			return self::admin();
		}
		return Lang::SOURCE;
	}

	/** Admin language of a user (ja or en). */
	public static function admin( ?int $user_id = null ): string {
		$user_id = $user_id ?? get_current_user_id();
		$locale  = $user_id ? get_user_locale( $user_id ) : get_locale();
		return str_starts_with( (string) $locale, 'ja' ) ? 'ja' : 'en';
	}

	public static function is_rtl(): bool {
		return Lang::is_rtl( self::current() );
	}

	/**
	 * Run a callback with a temporary language (emails in the recipient's language).
	 *
	 * @template T
	 * @param callable():T $fn
	 * @return T
	 */
	public static function with( string $code, callable $fn ) {
		$prev          = self::$current;
		self::$current = Lang::is_valid( $code ) ? $code : $prev;
		try {
			return $fn();
		} finally {
			self::$current = $prev;
		}
	}
}
