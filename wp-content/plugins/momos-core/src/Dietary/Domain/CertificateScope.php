<?php
declare(strict_types=1);

namespace Momos\Dietary\Domain;

/** Certificate scope (Doc 11 §8.1). */
enum CertificateScope: string {
	case WholeKitchen        = 'whole_kitchen';
	case SpecificMenu        = 'specific_menu';
	case SpecificIngredients = 'specific_ingredients';

	/** @return array<string,string> */
	public static function choices(): array {
		return [
			'whole_kitchen'        => __( 'Whole kitchen', 'momos-core' ),
			'specific_menu'        => __( 'Specific menu items', 'momos-core' ),
			'specific_ingredients' => __( 'Specific ingredients', 'momos-core' ),
		];
	}
}
