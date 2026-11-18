<?php
declare(strict_types=1);

namespace Momos\Notification\Support;

use Momos\Kernel\Fields\FieldRegistry;
use Momos\Kernel\I18n\Lang;
use Momos\Kernel\I18n\Translations;
use Momos\Kernel\Settings;

/**
 * Brand values used in emails: trading name (G1 `momo_trading_name`), sender display name
 * (`momo_sender_name`, translatable — IMPL-002 stores per-language values in the option translation
 * store), legal operator name (footer, hidden while empty ⟦Q-409⟧).
 */
final class Brand {
	public static function trading_name( string $lang ): string {
		$v = self::translated( 'momo_trading_name', $lang );
		if ( '' === $v ) {
			$v = self::translated( 'momo_trading_name', Lang::SOURCE );
		}
		if ( '' === $v ) {
			$v = (string) get_option( 'blogname', "Momo's" );
		}
		return self::clean( $v );
	}

	/** From display name in a language: momo_sender_name[L] → trading name[L] → ja value (spec §3.3). */
	public static function sender_name( string $lang ): string {
		$v = self::translated( 'momo_sender_name', $lang );
		if ( '' === $v ) {
			$v = self::translated( 'momo_trading_name', $lang );
		}
		if ( '' === $v ) {
			$v = self::translated( 'momo_sender_name', Lang::SOURCE );
		}
		return mb_substr( self::clean( '' === $v ? self::trading_name( $lang ) : $v ), 0, 60 );
	}

	public static function operator_name(): string {
		return self::clean( self::translated( 'momo_legal_operator_name', Lang::SOURCE ) );
	}

	private static function translated( string $field, string $lang ): string {
		$page = FieldRegistry::option_page_of( $field );
		if ( null !== $page ) {
			$v = Translations::option( $page, $field, $lang );
			if ( is_string( $v ) && str_starts_with( trim( $v ), '{' ) ) {
				$map = json_decode( $v, true ); // Legacy JSON map {ja,en,…} (spec §0).
				$v   = is_array( $map ) ? (string) ( $map[ $lang ] ?? '' ) : $v;
			}
			return (string) $v;
		}
		if ( Lang::SOURCE !== $lang ) {
			$raw = get_option( $field . '_' . $lang, '' );
			return is_string( $raw ) ? $raw : '';
		}
		$raw = get_option( $field, '' );
		if ( is_array( $raw ) ) {
			return (string) ( $raw[ $lang ] ?? '' );
		}
		return is_string( $raw ) ? $raw : '';
	}

	private static function clean( string $v ): string {
		$v = html_entity_decode( wp_strip_all_tags( $v ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ); // blogname is stored entity-encoded.
		return trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $v ) );
	}
}
