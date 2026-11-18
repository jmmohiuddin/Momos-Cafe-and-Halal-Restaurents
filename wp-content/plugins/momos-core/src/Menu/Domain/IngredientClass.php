<?php
declare(strict_types=1);

namespace Momos\Menu\Domain;

/** Ingredient class (TRD values, spec §2.2 / §11 C9). */
enum IngredientClass: string {
	case Meat           = 'meat';
	case Poultry        = 'poultry';
	case Seafood        = 'seafood';
	case Dairy          = 'dairy';
	case Egg            = 'egg';
	case Plant          = 'plant';
	case AlcoholDerived = 'alcohol_derived';
	case Other          = 'other';

	/** @return array<string,string> */
	public static function choices(): array {
		return [
			'meat'            => __( 'Meat', 'momos-core' ),
			'poultry'         => __( 'Poultry', 'momos-core' ),
			'seafood'         => __( 'Seafood', 'momos-core' ),
			'dairy'           => __( 'Dairy', 'momos-core' ),
			'egg'             => __( 'Egg', 'momos-core' ),
			'plant'           => __( 'Plant', 'momos-core' ),
			'alcohol_derived' => __( 'Alcohol-derived', 'momos-core' ),
			'other'           => __( 'Other', 'momos-core' ),
		];
	}
}
