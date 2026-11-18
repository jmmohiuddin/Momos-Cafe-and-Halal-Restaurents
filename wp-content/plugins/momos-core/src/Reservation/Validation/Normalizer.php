<?php
declare(strict_types=1);

namespace Momos\Reservation\Validation;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use Momos\Reservation\Support\Options;

/**
 * Input normalisation (spec §3.2, §2.2). Pure functions; validation of the normalised values is in
 * FieldValidator. Unicode NFC everywhere, NFKC only for staff search.
 */
final class Normalizer {
	public static function nfc( string $value ): string {
		$value = trim( $value );
		if ( class_exists( \Normalizer::class ) ) {
			$n = \Normalizer::normalize( $value, \Normalizer::FORM_C );
			if ( false !== $n ) {
				$value = $n;
			}
		}
		return $value;
	}

	public static function nfkc( string $value ): string {
		$value = trim( $value );
		if ( class_exists( \Normalizer::class ) ) {
			$n = \Normalizer::normalize( $value, \Normalizer::FORM_KC );
			if ( false !== $n ) {
				$value = $n;
			}
		}
		return $value;
	}

	/** Trim, lower-case the domain (the local part is kept as entered). */
	public static function email( string $value ): string {
		$value = trim( $value );
		$at    = strrpos( $value, '@' );
		if ( false === $at ) {
			return $value;
		}
		return substr( $value, 0, $at ) . '@' . strtolower( substr( $value, $at + 1 ) );
	}

	/** Key used for rate limiting / locks: whole address lower-cased. */
	public static function email_key( string $value ): string {
		return strtolower( trim( $value ) );
	}

	public static function domain_of( string $email ): string {
		$at = strrpos( $email, '@' );
		return false === $at ? '' : strtolower( substr( $email, $at + 1 ) );
	}

	/** Japanese mobile-carrier address (FR-RES-029). Computed, never stored. */
	public static function is_carrier_email( string $email ): bool {
		return in_array( self::domain_of( $email ), Options::domains( 'momo_carrier_domains' ), true );
	}

	/**
	 * E.164 phone or null when empty; false when it cannot be parsed into a possible number
	 * (libphonenumber, default region JP; 8–15 digits).
	 */
	public static function phone( ?string $value ): string|false|null {
		if ( null === $value ) {
			return null;
		}
		$value = trim( Digits::normalize( $value ) );
		if ( '' === $value ) {
			return null;
		}
		if ( preg_match( '/[^0-9+\-\s().]/', $value ) ) {
			return false;
		}
		$util = PhoneNumberUtil::getInstance();
		try {
			$num = $util->parse( $value, 'JP' );
		} catch ( NumberParseException ) {
			return false;
		}
		if ( ! $util->isPossibleNumber( $num ) ) {
			return false;
		}
		$e164   = $util->format( $num, PhoneNumberFormat::E164 );
		$digits = strlen( $e164 ) - 1;
		if ( $digits < 8 || $digits > 15 || strlen( $e164 ) > 20 ) {
			return false;
		}
		return $e164;
	}

	/**
	 * Special request: NFC, CRLF → LF, tabs → space, tags stripped, more than two blank lines collapsed.
	 * Remaining control characters are left for FieldValidator to reject.
	 */
	public static function special_request( ?string $value ): ?string {
		if ( null === $value ) {
			return null;
		}
		$value = str_replace( [ "\r\n", "\r", "\t" ], [ "\n", "\n", ' ' ], $value );
		$value = self::nfc( wp_strip_all_tags( $value, false ) );
		$value = (string) preg_replace( "/\n[ ]*\n[ ]*\n([ ]*\n)+/", "\n\n\n", $value );
		return '' === $value ? null : $value;
	}

	/** Plain-text staff message / note: tags stripped, NFC, CRLF → LF. */
	public static function plain_text( ?string $value ): string {
		if ( null === $value ) {
			return '';
		}
		$value = str_replace( [ "\r\n", "\r" ], "\n", $value );
		return self::nfc( wp_strip_all_tags( $value, false ) );
	}

	/** Given name for staff lists/emails (Q-413): first token ≤ 20 chars, else first 20 chars. */
	public static function given_name( ?string $name ): string {
		$name = trim( (string) $name );
		if ( '' === $name ) {
			return '';
		}
		$first = preg_split( '/[\s\x{3000}]+/u', $name )[0] ?? $name;
		return mb_strlen( $first ) <= 20 ? $first : mb_substr( $name, 0, 20 );
	}

	public static function has_control_chars( string $value, bool $allow_newline = false ): bool {
		$pattern = $allow_newline ? '/[\x00-\x09\x0B-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u';
		return (bool) preg_match( $pattern, $value );
	}
}
