<?php
declare(strict_types=1);

namespace Momos\Dietary\Domain;

/** Alcohol policy value (FR-DIET-004); `unknown` is never displayed. */
enum AlcoholValue: string {
	case Yes     = 'yes';
	case No      = 'no';
	case Unknown = 'unknown';

	/** @return array<string,string> */
	public static function choices(): array {
		return [
			'unknown' => __( 'Unknown (not displayed)', 'momos-core' ),
			'yes'     => __( 'Yes', 'momos-core' ),
			'no'      => __( 'No', 'momos-core' ),
		];
	}
}
