<?php
declare(strict_types=1);

namespace Momos\Dietary\Domain;

/** Halal claim type (Doc 11 §8.1, DEC-020). Statement rows additionally use `alcohol` and `neutral`. */
enum ClaimType: string {
	case Certified        = 'certified';
	case HalalIngredients = 'halal_ingredients';
	case NoClaim          = 'no_claim';

	/** @return array<string,string> */
	public static function choices(): array {
		return [
			'certified'         => __( 'Certified', 'momos-core' ),
			'halal_ingredients' => __( 'Halal ingredients', 'momos-core' ),
			'no_claim'          => __( 'No claim', 'momos-core' ),
		];
	}

	/** Statement row types (G2). @return array<string,string> */
	public static function statement_types(): array {
		return self::choices() + [
			'alcohol' => __( 'Alcohol', 'momos-core' ),
			'neutral' => __( 'Neutral / brand', 'momos-core' ),
		];
	}
}
