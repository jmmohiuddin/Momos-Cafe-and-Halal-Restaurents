<?php
declare(strict_types=1);

namespace Momos\Kernel\Support;

/**
 * Crockford base32 public references (DEC-051): 8 characters, no I/L/O/U, displayed XXXX-XXXX.
 */
final class Crockford {
	public const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

	public static function random( int $length = 8 ): string {
		$out = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$out .= self::ALPHABET[ random_int( 0, 31 ) ];
		}
		return $out;
	}

	public static function display( string $ref ): string {
		return strlen( $ref ) === 8 ? substr( $ref, 0, 4 ) . '-' . substr( $ref, 4 ) : $ref;
	}

	/** Normalise user input (lower case, hyphen, confusable letters) to the stored form, or null. */
	public static function normalize( string $input ): ?string {
		$v = strtoupper( preg_replace( '/[\s-]/', '', $input ) ?? '' );
		$v = strtr( $v, [ 'I' => '1', 'L' => '1', 'O' => '0' ] );
		return preg_match( '/^[0-9A-HJKMNP-TV-Z]{8}$/', $v ) ? $v : null;
	}
}
