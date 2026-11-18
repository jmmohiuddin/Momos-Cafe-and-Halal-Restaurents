<?php
declare(strict_types=1);

namespace Momos\Reservation\Validation;

use Momos\Kernel\I18n\Lang;
use Momos\Reservation\Support\Options;

/**
 * Field rules of API-02 check 10 (spec §2.2/§3.1). Takes schema-valid input, returns the normalised
 * values and a field => reason map (reasons: required, invalid_format, too_long, out_of_range,
 * not_allowed).
 */
final class FieldValidator {
	/**
	 * @param array<string,mixed> $in
	 * @return array{values:array<string,mixed>,errors:array<string,string>}
	 */
	public static function submission( array $in, bool $staff_user = false ): array {
		$errors = [];
		$v      = [];

		// Name: 1–100 after trim + NFC; CR/LF, controls and < > rejected [Ruling C-9].
		$name = Normalizer::nfc( (string) ( $in['name'] ?? '' ) );
		if ( '' === $name ) {
			$errors['name'] = 'required';
		} elseif ( mb_strlen( $name ) > 100 ) {
			$errors['name'] = 'too_long';
		} elseif ( Normalizer::has_control_chars( $name ) || preg_match( '/[<>]/', $name ) ) {
			$errors['name'] = 'invalid_format';
		}
		$v['guest_name'] = $name;

		// Email.
		$raw_email = (string) ( $in['email'] ?? '' );
		$email     = Normalizer::email( $raw_email );
		if ( '' === $email ) {
			$errors['email'] = 'required';
		} elseif ( strlen( $email ) > 254 ) {
			$errors['email'] = 'too_long';
		} elseif ( preg_match( '/[\r\n]/', $raw_email ) || ! is_email( $email ) ) {
			$errors['email'] = 'invalid_format';
		}
		$v['email'] = $email;

		// Phone (optional).
		$phone = Normalizer::phone( isset( $in['phone'] ) ? (string) $in['phone'] : null );
		if ( false === $phone ) {
			$errors['phone'] = 'invalid_format';
			$phone           = null;
		}
		$v['phone_e164'] = $phone;

		// Special request (optional, ≤ 1,000).
		$sr = Normalizer::special_request( isset( $in['special_request'] ) ? (string) $in['special_request'] : null );
		if ( null !== $sr ) {
			if ( mb_strlen( $sr ) > 1000 ) {
				$errors['special_request'] = 'too_long';
			} elseif ( Normalizer::has_control_chars( $sr, true ) ) {
				$errors['special_request'] = 'invalid_format';
			}
		}
		$v['special_request'] = $sr;
		if ( Options::bool( 'momo_special_request_separate_consent' ) && null !== $sr && true !== ( $in['special_request_consent'] ?? null ) ) {
			$errors['special_request_consent'] = 'required';
		}

		// Languages: must be enabled (a disabled language only from logged-in staff).
		foreach ( [ 'lang', 'preferred_lang' ] as $key ) {
			$code = (string) ( $in[ $key ] ?? '' );
			if ( ! Lang::is_valid( $code ) ) {
				$errors[ $key ] = 'invalid_format';
			} elseif ( ! Lang::is_enabled( $code ) && ! $staff_user ) {
				$errors[ $key ] = 'not_allowed';
			}
		}
		$v['source_lang']    = (string) ( $in['lang'] ?? 'ja' );
		$v['preferred_lang'] = (string) ( $in['preferred_lang'] ?? $v['source_lang'] );

		if ( true !== ( $in['consent'] ?? null ) ) {
			$errors['consent'] = 'required';
		}

		// Date must be a real calendar date (pattern checked by the schema).
		$date = (string) ( $in['date'] ?? '' );
		$dt   = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date );
		if ( ! $dt || $dt->format( 'Y-m-d' ) !== $date ) {
			$errors['date'] = 'invalid_format';
		}
		$v['date']       = $date;
		$v['time']       = (string) ( $in['time'] ?? '' );
		$v['party_size'] = (int) Digits::normalize( (string) ( $in['party_size'] ?? '0' ) );

		return [ 'values' => $v, 'errors' => $errors ];
	}

	/** Staff message to customer: ≤ 500 plain text. Returns [value, error|null]. @return array{0:?string,1:?string} */
	public static function message( mixed $value ): array {
		if ( null === $value || '' === $value ) {
			return [ null, null ];
		}
		if ( ! is_string( $value ) ) {
			return [ null, 'invalid_format' ];
		}
		$m = Normalizer::plain_text( $value );
		if ( '' === $m ) {
			return [ null, null ];
		}
		if ( mb_strlen( $m ) > 500 ) {
			return [ null, 'too_long' ];
		}
		if ( Normalizer::has_control_chars( $m, true ) ) {
			return [ null, 'invalid_format' ];
		}
		return [ $m, null ];
	}

	/** Internal note: 1–1,000 plain text. @return array{0:?string,1:?string} */
	public static function note( mixed $value, bool $required = true ): array {
		$n = is_string( $value ) ? Normalizer::plain_text( $value ) : '';
		if ( '' === $n ) {
			return [ null, $required ? 'required' : null ];
		}
		if ( mb_strlen( $n ) > 1000 ) {
			return [ null, 'too_long' ];
		}
		if ( Normalizer::has_control_chars( $n, true ) ) {
			return [ null, 'invalid_format' ];
		}
		return [ $n, null ];
	}
}
