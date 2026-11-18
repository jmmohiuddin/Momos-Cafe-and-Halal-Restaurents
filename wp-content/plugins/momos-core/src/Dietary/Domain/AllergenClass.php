<?php
declare(strict_types=1);

namespace Momos\Dietary\Domain;

/** Allergen reference class (spec §2.2). */
enum AllergenClass: string {
	case Mandatory   = 'mandatory';
	case Recommended = 'recommended';
	case Other       = 'other';

	/** @return array<string,string> */
	public static function choices(): array {
		return [
			'mandatory'   => __( 'Mandatory (specified ingredients, 9)', 'momos-core' ),
			'recommended' => __( 'Recommended (20)', 'momos-core' ),
			'other'       => __( 'Other (custom)', 'momos-core' ),
		];
	}
}
