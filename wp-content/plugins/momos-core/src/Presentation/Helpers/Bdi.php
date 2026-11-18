<?php
declare(strict_types=1);

namespace Momos\Presentation\Helpers;

/**
 * Bidi isolation helpers (FR-I18N-005, Doc 08 §6.2, ui-design-system.md §9). Numbers, prices, phones,
 * times, references, emails and Latin names are wrapped by code — never by translators.
 */
final class Bdi {
	/** `<bdi dir="ltr">…</bdi>` with escaped text. */
	public static function ltr( string $text, string $class = '' ): string {
		$class = '' !== $class ? ' class="' . esc_attr( $class ) . '"' : '';
		return '<bdi dir="ltr"' . $class . '>' . esc_html( $text ) . '</bdi>';
	}

	/** Isolate without forcing a direction (Latin brand names inside RTL text). */
	public static function auto( string $text ): string {
		return '<bdi>' . esc_html( $text ) . '</bdi>';
	}

	/** Japanese fragment (address) on any page: `<span lang="ja" dir="ltr">`. */
	public static function ja( string $text ): string {
		return '<span lang="ja" dir="ltr">' . esc_html( $text ) . '</span>';
	}

	/**
	 * Translate a catalog string and substitute placeholders whose values are numbers/Latin as
	 * isolated HTML. $iso lists the placeholder names to wrap in `<bdi dir="ltr">`; all other values and
	 * the template are escaped. Returns safe HTML.
	 *
	 * @param array<string, string|int|float> $args
	 * @param list<string>                    $iso
	 */
	public static function t( string $key, array $args = [], array $iso = [], ?string $lang = null ): string {
		$template = esc_html( momos_t( $key, [], $lang ) );
		$replace  = [];
		foreach ( $args as $k => $v ) {
			$replace[ '{' . $k . '}' ] = in_array( $k, $iso, true ) ? self::ltr( (string) $v ) : esc_html( (string) $v );
		}
		return strtr( $template, $replace );
	}
}
