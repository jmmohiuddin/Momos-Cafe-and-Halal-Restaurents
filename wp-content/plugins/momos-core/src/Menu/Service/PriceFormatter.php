<?php
declare(strict_types=1);

namespace Momos\Menu\Service;

/**
 * Price display (spec §3.3, CMP-PriceBlock): `<bdi dir="ltr">¥1,500</bdi>` + translated
 * `price.tax_incl` — ja `¥1,500（税込）`, en `¥1,500 (tax incl.)`. Western digits, thousands
 * separator, integers only, no tax-rate text, no strike-through.
 */
final class PriceFormatter {
	public static function amount( int $yen ): string {
		return '¥' . number_format( $yen, 0, '.', ',' );
	}

	/** Plain text ("¥1,500 (tax incl.)"). */
	public static function text( int $yen, string $lang ): string {
		$label = momos_t( 'price.tax_incl', [], $lang );
		return self::amount( $yen ) . self::separator( $label ) . $label;
	}

	/** Escaped HTML with bidi isolation of the amount. */
	public static function html( int $yen, string $lang ): string {
		$label = momos_t( 'price.tax_incl', [], $lang );
		return '<bdi dir="ltr">' . esc_html( self::amount( $yen ) ) . '</bdi>' . esc_html( self::separator( $label ) . $label );
	}

	private static function separator( string $label ): string {
		return ( str_starts_with( $label, '（' ) || '' === $label ) ? '' : ' ';
	}
}
