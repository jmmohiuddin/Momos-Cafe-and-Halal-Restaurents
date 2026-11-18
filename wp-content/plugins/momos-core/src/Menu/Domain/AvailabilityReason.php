<?php
declare(strict_types=1);

namespace Momos\Menu\Domain;

/** Reason for sold_out/unavailable (spec §2.2, DB CHECK chk_momo_bmi_reason). */
enum AvailabilityReason: string {
	case SoldOutToday          = 'sold_out_today';
	case Seasonal              = 'seasonal';
	case IngredientUnavailable = 'ingredient_unavailable';
	case AdvanceOrderOnly      = 'advance_order_only';
	case Other                 = 'other';

	/** @return list<string> */
	public static function values(): array {
		return array_map( static fn( self $c ) => $c->value, self::cases() );
	}

	public function label(): string {
		return match ( $this ) {
			self::SoldOutToday          => __( 'Sold out today', 'momos-core' ),
			self::Seasonal              => __( 'Seasonal', 'momos-core' ),
			self::IngredientUnavailable => __( 'Ingredient unavailable', 'momos-core' ),
			self::AdvanceOrderOnly      => __( 'Advance order only', 'momos-core' ),
			self::Other                 => __( 'Other', 'momos-core' ),
		};
	}
}
