<?php
declare(strict_types=1);

namespace Momos\Presentation\Format;

/**
 * Price display (Doc 07 §6.10, DEC-022/062): `<bdi dir="ltr">¥1,500</bdi>` + `price.tax_incl`.
 * Delegates to the Menu module formatter when present so both modules print identical prices.
 */
final class PriceFormatter {
	public static function amount( int $yen ): string {
		return '¥' . number_format( $yen, 0, '.', ',' );
	}

	/** Escaped HTML: isolated amount + tax label (ja: no space before full-width parenthesis). */
	public static function html( int $yen, ?string $lang = null ): string {
		$lang = $lang ?? momos_lang();
		if ( class_exists( \Momos\Menu\Service\PriceFormatter::class ) ) {
			return \Momos\Menu\Service\PriceFormatter::html( $yen, $lang );
		}
		$label = momos_t( 'price.tax_incl', [], $lang );
		$sep   = ( '' === $label || str_starts_with( $label, '（' ) ) ? '' : ' ';
		return '<bdi dir="ltr">' . esc_html( self::amount( $yen ) ) . '</bdi>' . esc_html( $sep . $label );
	}
}
