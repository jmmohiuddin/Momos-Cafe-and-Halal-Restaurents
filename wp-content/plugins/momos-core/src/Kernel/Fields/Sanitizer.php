<?php
declare(strict_types=1);

namespace Momos\Kernel\Fields;

/**
 * Server-side sanitising and type validation of field values (SDD §6.4 — validate on input,
 * escape on output).
 */
final class Sanitizer {
	/** @param array<string,mixed> $f */
	public static function sanitize( array $f, mixed $raw ): mixed {
		$type = (string) $f['type'];
		switch ( $type ) {
			case 'text':
			case 'tel':
			case 'hidden':
				$v = sanitize_text_field( (string) ( is_scalar( $raw ) ? $raw : '' ) );
				return isset( $f['maxlength'] ) ? mb_substr( $v, 0, (int) $f['maxlength'] ) : $v;
			case 'textarea':
				$v = sanitize_textarea_field( (string) ( is_scalar( $raw ) ? $raw : '' ) );
				return isset( $f['maxlength'] ) ? mb_substr( $v, 0, (int) $f['maxlength'] ) : $v;
			case 'wysiwyg':
				return wp_kses_post( (string) ( is_scalar( $raw ) ? $raw : '' ) );
			case 'email':
				return strtolower( sanitize_email( (string) ( is_scalar( $raw ) ? $raw : '' ) ) );
			case 'url':
				return esc_url_raw( trim( (string) ( is_scalar( $raw ) ? $raw : '' ) ), [ 'https', 'http' ] );
			case 'number':
				if ( null === $raw || '' === $raw || ! is_scalar( $raw ) ) {
					return '';
				}
				$num = str_replace( [ ',', ' ' ], '', (string) $raw );
				if ( ! is_numeric( $num ) ) {
					return '';
				}
				return ( isset( $f['step'] ) && (float) $f['step'] < 1 ) || str_contains( $num, '.' ) ? (string) (float) $num : (string) (int) $num;
			case 'true_false':
				return ( ! empty( $raw ) && '0' !== $raw ) ? '1' : '0';
			case 'select':
			case 'radio':
				if ( ! empty( $f['multiple'] ) ) {
					return self::choice_list( $f, $raw );
				}
				$v = sanitize_text_field( (string) ( is_scalar( $raw ) ? $raw : '' ) );
				return ( '' === $v || isset( $f['choices'][ $v ] ) ) ? $v : '';
			case 'checkbox':
				return self::choice_list( $f, $raw );
			case 'date':
				$v = sanitize_text_field( (string) $raw );
				return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '';
			case 'time':
				$v = sanitize_text_field( (string) $raw );
				return preg_match( '/^\d{2}:\d{2}$/', $v ) ? $v : '';
			case 'datetime':
				$v = str_replace( 'T', ' ', sanitize_text_field( (string) $raw ) );
				return preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $v ) ? $v : '';
			case 'image':
			case 'post_object':
			case 'user':
				if ( ! empty( $f['multiple'] ) ) {
					return self::id_list( $raw );
				}
				$id = absint( is_scalar( $raw ) ? $raw : 0 );
				return $id > 0 ? (string) $id : '';
			case 'gallery':
			case 'relationship':
				return self::id_list( $raw );
			case 'taxonomy':
				$ids = self::id_list( $raw );
				return empty( $f['multiple'] ) ? ( $ids ? (string) $ids[0] : '' ) : $ids;
			case 'repeater':
				$rows = [];
				if ( is_array( $raw ) ) {
					foreach ( $raw as $key => $row ) {
						if ( '__index__' === (string) $key || ! is_array( $row ) ) {
							continue;
						}
						$clean = [];
						$empty = true;
						foreach ( $f['sub_fields'] as $sf ) {
							$clean[ $sf['name'] ] = self::sanitize( $sf, $row[ $sf['name'] ] ?? null );
							if ( ! FieldRegistry::is_empty( $clean[ $sf['name'] ] ) && 'true_false' !== $sf['type'] ) {
								$empty = false;
							}
						}
						if ( ! $empty ) {
							$rows[] = $clean;
						}
					}
				}
				if ( isset( $f['max_rows'] ) ) {
					$rows = array_slice( $rows, 0, (int) $f['max_rows'] );
				}
				return $rows;
			default:
				return is_scalar( $raw ) ? sanitize_text_field( (string) $raw ) : '';
		}
	}

	/**
	 * Type-level validation of a sanitised value. Returns an error message or null.
	 *
	 * @param array<string,mixed> $f
	 */
	public static function validate( array $f, mixed $v ): ?string {
		$label = wp_strip_all_tags( (string) $f['label'] );
		if ( FieldRegistry::is_empty( $v ) ) {
			return null; // Required-ness is checked separately.
		}
		switch ( $f['type'] ) {
			case 'number':
				if ( isset( $f['min'] ) && (float) $v < (float) $f['min'] ) {
					/* translators: 1: field label 2: minimum */
					return sprintf( __( '"%1$s" must be at least %2$s.', 'momos-core' ), $label, (string) $f['min'] );
				}
				if ( isset( $f['max'] ) && (float) $v > (float) $f['max'] ) {
					/* translators: 1: field label 2: maximum */
					return sprintf( __( '"%1$s" must be at most %2$s.', 'momos-core' ), $label, (string) $f['max'] );
				}
				break;
			case 'email':
				if ( ! is_email( (string) $v ) ) {
					/* translators: %s: field label */
					return sprintf( __( '"%s" must be a valid email address.', 'momos-core' ), $label );
				}
				break;
			case 'url':
				if ( ! empty( $f['https_only'] ) && ! str_starts_with( (string) $v, 'https://' ) ) {
					/* translators: %s: field label */
					return sprintf( __( '"%s" must start with https://', 'momos-core' ), $label );
				}
				break;
			case 'post_object':
			case 'relationship':
				foreach ( (array) $v as $id ) {
					$p = get_post( (int) $id );
					if ( ! $p || ( isset( $f['post_type'] ) && ! in_array( $p->post_type, (array) $f['post_type'], true ) ) ) {
						/* translators: %s: field label */
						return sprintf( __( '"%s" contains an invalid selection.', 'momos-core' ), $label );
					}
				}
				break;
			case 'repeater':
				foreach ( (array) $v as $i => $row ) {
					foreach ( $f['sub_fields'] as $sf ) {
						$cell = $row[ $sf['name'] ] ?? null;
						if ( ! empty( $sf['required'] ) && FieldRegistry::is_empty( $cell ) ) {
							/* translators: 1: field label 2: row number 3: sub-field label */
							return sprintf( __( '"%1$s" row %2$d: "%3$s" is required.', 'momos-core' ), $label, $i + 1, wp_strip_all_tags( (string) $sf['label'] ) );
						}
						$err = self::validate( $sf, $cell );
						if ( null !== $err ) {
							return $err;
						}
					}
				}
				if ( isset( $f['min_rows'] ) && count( (array) $v ) < (int) $f['min_rows'] ) {
					/* translators: 1: field label 2: minimum rows */
					return sprintf( __( '"%1$s" needs at least %2$d rows.', 'momos-core' ), $label, (int) $f['min_rows'] );
				}
				break;
		}
		if ( isset( $f['pattern'] ) && is_string( $v ) && ! preg_match( '/' . str_replace( '/', '\/', (string) $f['pattern'] ) . '/u', $v ) ) {
			/* translators: %s: field label */
			return (string) ( $f['pattern_message'] ?? sprintf( __( '"%s" has an invalid format.', 'momos-core' ), $label ) );
		}
		return null;
	}

	/**
	 * @param array<string,mixed> $f
	 * @return list<string>
	 */
	private static function choice_list( array $f, mixed $raw ): array {
		$out = [];
		foreach ( (array) $raw as $v ) {
			$v = sanitize_text_field( (string) $v );
			if ( isset( $f['choices'][ $v ] ) ) {
				$out[] = $v;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** @return list<int> */
	private static function id_list( mixed $raw ): array {
		if ( is_string( $raw ) ) {
			$raw = '' === $raw ? [] : explode( ',', $raw );
		}
		return array_values( array_unique( array_filter( array_map( 'absint', (array) $raw ) ) ) );
	}
}
