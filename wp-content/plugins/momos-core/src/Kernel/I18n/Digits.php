<?php
declare(strict_types=1);

namespace Momos\Kernel\I18n;

/**
 * Western digits in every language (DEC-062, content-strings §1 rule 5, FR-I18N-006 "Western digits out,
 * multi-script digits in"). Converts Arabic-Indic (ar), Extended Arabic-Indic (ur/fa), Devanagari (hi) and
 * Bengali (bn) digits to 0–9, and the Arabic decimal/thousands separators between digits to "." and ",".
 * Applied to translated content on save (Translations, QA-050) and usable for any input normalisation.
 */
final class Digits {
	/** First code point (zero) of each supported non-Western digit block. */
	public const BLOCKS = [ 0x0660, 0x06F0, 0x0966, 0x09E6 ];

	public static function western( string $value ): string {
		static $map = null;
		if ( null === $map ) {
			$map = [];
			foreach ( self::BLOCKS as $zero ) {
				for ( $i = 0; $i < 10; $i++ ) {
					$map[ mb_chr( $zero + $i, 'UTF-8' ) ] = (string) $i;
				}
			}
		}
		$out = strtr( $value, $map );
		if ( $out === $value ) {
			return $value;
		}
		// ٫ (U+066B decimal) and ٬ (U+066C thousands) only between digits, e.g. ١٬٥٠٠ → 1,500.
		return (string) preg_replace( [ '/(?<=\d)\x{066B}(?=\d)/u', '/(?<=\d)\x{066C}(?=\d)/u' ], [ '.', ',' ], $out );
	}
}
