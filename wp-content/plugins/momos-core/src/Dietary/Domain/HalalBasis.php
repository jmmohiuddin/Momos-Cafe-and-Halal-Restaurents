<?php
declare(strict_types=1);

namespace Momos\Dietary\Domain;

/** Dish halal basis (FR-DIET-003(e)); default branch_default. */
enum HalalBasis: string {
	case BranchDefault    = 'branch_default';
	case CertifiedItem    = 'certified_item';
	case HalalIngredients = 'halal_ingredients';
	case NoClaim          = 'no_claim';

	/** @return array<string,string> */
	public static function choices(): array {
		return [
			'branch_default'    => __( 'Branch default (show each branch\'s statement)', 'momos-core' ),
			'certified_item'    => __( 'Certified item (active specific-menu certificate)', 'momos-core' ),
			'halal_ingredients' => __( 'Halal ingredients (active halal-ingredients record)', 'momos-core' ),
			'no_claim'          => __( 'No claim', 'momos-core' ),
		];
	}
}
