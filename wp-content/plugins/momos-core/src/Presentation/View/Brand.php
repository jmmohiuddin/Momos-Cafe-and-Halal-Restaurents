<?php
declare(strict_types=1);

namespace Momos\Presentation\View;

use Momos\Kernel\I18n\Lang;
use Momos\Kernel\I18n\Locale;
use Momos\Kernel\I18n\Translations;
use Momos\Kernel\Settings;

/**
 * Brand settings (G1 options page `momo-brand`, ui-design-system.md §11) read for the frame. Any element
 * depending on an empty brand field is not rendered (FR-FND-005(b)). Works before the settings module
 * registers its fields (reads the raw option).
 */
final class Brand {
	public const PAGE = 'momo-brand';

	/** Provisional trading name (DEC-046/067) used only for accessible names when the option is empty. */
	public const PROVISIONAL_NAME = "MOMO's Cafe & Halal Restaurant";

	public static function option( string $name ): mixed {
		$v = Settings::get( $name );
		if ( null !== $v && '' !== $v ) {
			return $v;
		}
		$raw = get_option( 'momos_opt_' . self::PAGE, [] );
		return is_array( $raw ) ? ( $raw[ $name ] ?? null ) : null;
	}

	/**
	 * Translatable brand text in $lang ('' when not approved in $lang — never mixed language). An empty source field
	 * hides the element in every language (FR-FND-005(b), AC-FND-01-10), even if an older translation is still stored
	 * (clearing a source value does not flag translations, IMPL-009).
	 */
	public static function text( string $name, ?string $lang = null ): string {
		$lang   = $lang ?? Locale::current();
		$source = trim( (string) self::option( $name ) );
		if ( Lang::SOURCE === $lang || '' === $source ) {
			return $source;
		}
		return trim( Translations::option( self::PAGE, $name, $lang ) );
	}

	public static function trading_name( ?string $lang = null ): string {
		$name = self::text( 'momo_trading_name', $lang );
		return '' !== $name ? $name : self::PROVISIONAL_NAME;
	}

	/** Legal operator for the © line; '' hides the line (⟦TBD — Owner — FR-FND-005⟧). */
	public static function operator( ?string $lang = null ): string {
		return self::text( 'momo_legal_operator_name', $lang );
	}

	/**
	 * Social links (https only).
	 *
	 * @return list<array{network:string,url:string,label:string}>
	 */
	public static function social_links(): array {
		$rows   = self::option( 'momo_social_links' );
		$labels = [ 'instagram' => 'Instagram', 'facebook' => 'Facebook', 'x' => 'X', 'youtube' => 'YouTube', 'tiktok' => 'TikTok', 'line' => 'LINE' ];
		$out    = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$url = is_array( $row ) ? (string) ( $row['url'] ?? '' ) : '';
			$net = is_array( $row ) ? (string) ( $row['network'] ?? 'other' ) : 'other';
			if ( ! str_starts_with( $url, 'https://' ) ) {
				continue;
			}
			$out[] = [ 'network' => $net, 'url' => $url, 'label' => $labels[ $net ] ?? (string) wp_parse_url( $url, PHP_URL_HOST ) ];
		}
		return $out;
	}

	/** Attachment URL of an uploaded official logo (DEC-046) or '' to use the provisional wordmark. */
	public static function logo_url( bool $inverse = false ): string {
		$id = (int) self::option( $inverse ? 'momo_logo_inverse' : 'momo_logo_primary' );
		if ( ! $id ) {
			return '';
		}
		$url = wp_get_attachment_image_url( $id, 'full' );
		return is_string( $url ) ? $url : '';
	}
}
