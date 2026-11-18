<?php
declare(strict_types=1);

namespace Momos\Reservation\Validation;

/**
 * Native digit normalisation (spec §3.2): Arabic-Indic U+0660–0669, Extended Arabic-Indic
 * U+06F0–06F9, Devanagari U+0966–096F, Bengali U+09E6–09EF and full-width U+FF10–FF19 → ASCII.
 * Applies to phone, party size and admin time inputs ("৪" → "4").
 */
final class Digits {
	private const BLOCKS = [ 0x0660, 0x06F0, 0x0966, 0x09E6, 0xFF10 ];

	public static function normalize( string $value ): string {
		static $map = null;
		if ( null === $map ) {
			$map = [];
			foreach ( self::BLOCKS as $base ) {
				for ( $i = 0; $i <= 9; $i++ ) {
					$map[ mb_chr( $base + $i, 'UTF-8' ) ] = (string) $i;
				}
			}
			$map['－'] = '-';
			$map['＋'] = '+';
			$map['（'] = '(';
			$map['）'] = ')';
			$map['：'] = ':';
			$map["\u{3000}"] = ' ';
		}
		return strtr( $value, $map );
	}
}
