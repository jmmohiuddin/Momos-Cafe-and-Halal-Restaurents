<?php
declare(strict_types=1);

namespace Momos\Notification\Template;

/**
 * Escaping helpers for email layouts (SEC-021): catalog text is escaped first, then placeholders are
 * replaced by escaped, bidi-isolated values (FR-I18N-005) — translated fragments are never
 * concatenated.
 */
final class Html {
	/** @param array<string,string|int|float> $args */
	public static function fill( string $text, array $args = [] ): string {
		$out = esc_html( $text );
		if ( ! $args ) {
			return $out;
		}
		$map = [];
		foreach ( $args as $k => $v ) {
			$map[ '{' . $k . '}' ] = '<bdi>' . esc_html( (string) $v ) . '</bdi>';
		}
		return strtr( $out, $map );
	}

	/** @param array<string,string|int|float> $args */
	public static function fill_text( string $text, array $args = [] ): string {
		if ( ! $args ) {
			return $text;
		}
		$map = [];
		foreach ( $args as $k => $v ) {
			$map[ '{' . $k . '}' ] = (string) $v;
		}
		return strtr( $text, $map );
	}

	/** User-entered text (branch message): escaped, line breaks kept, direction auto. */
	public static function user_text( string $text ): string {
		return '<div dir="auto" style="white-space:normal">' . nl2br( esc_html( $text ) ) . '</div>';
	}

	/** Plain-text wrapping of a paragraph (soft wrap at 76 columns, multibyte safe). */
	public static function wrap( string $text, int $width = 76 ): string {
		$lines = [];
		foreach ( preg_split( '/\R/u', $text ) ?: [] as $line ) {
			if ( mb_strlen( $line ) <= $width || ! str_contains( $line, ' ' ) ) {
				$lines[] = $line;
				continue;
			}
			$cur = '';
			foreach ( explode( ' ', $line ) as $word ) {
				if ( '' !== $cur && mb_strlen( $cur . ' ' . $word ) > $width ) {
					$lines[] = $cur;
					$cur     = $word;
				} else {
					$cur = '' === $cur ? $word : $cur . ' ' . $word;
				}
			}
			$lines[] = $cur;
		}
		return implode( "\n", $lines );
	}
}
