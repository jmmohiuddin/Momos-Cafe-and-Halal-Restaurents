<?php
declare(strict_types=1);

namespace Momos\Presentation\Format;

/**
 * Branch phone display (Doc 07 §6.10): ja national format ("06-6xxx-xxxx"); other languages
 * international ("+81 6-6xxx-xxxx"). `tel:` always E.164. Output is isolated LTR by the caller.
 */
final class PhoneFormatter {
	public static function display( string $e164, string $national, ?string $lang = null ): string {
		$lang = $lang ?? momos_lang();
		if ( 'ja' === $lang && '' !== $national ) {
			return $national;
		}
		if ( '' !== $national && str_starts_with( $national, '0' ) && str_starts_with( $e164, '+81' ) ) {
			return "+81\u{00A0}" . substr( $national, 1 ); // NBSP: never wraps; keeps the gap inside RTL text.
		}
		return '' !== $e164 ? $e164 : $national;
	}

	public static function tel_href( string $e164 ): string {
		return 'tel:' . preg_replace( '/[^0-9+]/', '', $e164 );
	}
}
