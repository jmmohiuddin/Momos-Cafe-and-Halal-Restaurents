<?php
declare(strict_types=1);

namespace Momos\Api\Http;

use Momos\Reservation\Validation\Digits;

/**
 * Minimal JSON-schema validator for request bodies (types, required, enum, pattern, lengths,
 * minimum/maximum, additionalProperties:false). Unlike core's validator it reports every field with
 * the catalogue reasons: required, invalid_format, too_long, out_of_range, not_allowed.
 * `x-digits: true` accepts an integer or a string of (native) digits.
 */
final class SchemaValidator {
	/**
	 * @param array<string,mixed> $body
	 * @param array<string,mixed> $schema
	 * @return array<string,string> field => reason (empty when valid)
	 */
	public static function validate( array $body, array $schema ): array {
		$errors = [];
		$props  = (array) ( $schema['properties'] ?? [] );
		if ( false === ( $schema['additionalProperties'] ?? true ) ) {
			foreach ( array_keys( $body ) as $k ) {
				if ( ! isset( $props[ $k ] ) ) {
					$errors[ (string) $k ] = 'not_allowed';
				}
			}
		}
		foreach ( (array) ( $schema['required'] ?? [] ) as $req ) {
			if ( ! array_key_exists( $req, $body ) || null === $body[ $req ] || '' === $body[ $req ] ) {
				$errors[ $req ] = 'required';
			}
		}
		foreach ( $props as $name => $def ) {
			if ( isset( $errors[ $name ] ) || ! array_key_exists( $name, $body ) ) {
				continue;
			}
			$reason = self::check( $body[ $name ], (array) $def );
			if ( null !== $reason ) {
				$errors[ $name ] = $reason;
			}
		}
		return $errors;
	}

	/** @param array<string,mixed> $def */
	private static function check( mixed $v, array $def ): ?string {
		$types = (array) ( $def['type'] ?? [] );
		if ( null === $v ) {
			return in_array( 'null', $types, true ) ? null : 'invalid_format';
		}
		if ( ! empty( $def['x-digits'] ) ) {
			if ( is_string( $v ) ) {
				$v = trim( Digits::normalize( $v ) );
				if ( ! preg_match( '/^\d{1,3}$/', $v ) ) {
					return '' === $v ? 'required' : 'invalid_format';
				}
				$v = (int) $v;
			}
			if ( ! is_int( $v ) ) {
				return 'invalid_format';
			}
		} elseif ( ! self::type_ok( $v, $types ) ) {
			return 'invalid_format';
		}
		if ( isset( $def['enum'] ) && ! in_array( $v, (array) $def['enum'], true ) ) {
			return 'invalid_format';
		}
		if ( is_string( $v ) ) {
			if ( isset( $def['maxLength'] ) && mb_strlen( $v ) > (int) $def['maxLength'] ) {
				return 'too_long';
			}
			if ( isset( $def['minLength'] ) && mb_strlen( trim( $v ) ) < (int) $def['minLength'] ) {
				return 'required';
			}
			if ( isset( $def['pattern'] ) && ! preg_match( '/' . str_replace( '/', '\/', (string) $def['pattern'] ) . '/u', $v ) ) {
				return 'invalid_format';
			}
		}
		if ( is_int( $v ) || is_float( $v ) ) {
			if ( ( isset( $def['minimum'] ) && $v < $def['minimum'] ) || ( isset( $def['maximum'] ) && $v > $def['maximum'] ) ) {
				return 'out_of_range';
			}
		}
		if ( is_array( $v ) && isset( $def['maxItems'] ) && count( $v ) > (int) $def['maxItems'] ) {
			return 'out_of_range';
		}
		return null;
	}

	/** @param list<string> $types */
	private static function type_ok( mixed $v, array $types ): bool {
		if ( ! $types ) {
			return true;
		}
		foreach ( $types as $t ) {
			$ok = match ( $t ) {
				'string'  => is_string( $v ),
				'integer' => is_int( $v ),
				'number'  => is_int( $v ) || is_float( $v ),
				'boolean' => is_bool( $v ),
				'array'   => is_array( $v ) && array_is_list( $v ),
				'object'  => is_array( $v ) && ( ! $v || ! array_is_list( $v ) ),
				'null'    => null === $v,
				default   => false,
			};
			if ( $ok ) {
				return true;
			}
		}
		return false;
	}
}
