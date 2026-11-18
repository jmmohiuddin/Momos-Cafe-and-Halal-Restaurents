<?php
declare(strict_types=1);

namespace Momos\Dietary\Domain;

/** Four-state allergen model (Doc 11 §9.2, spec §2.2). Default not_verified. */
enum AllergenState: string {
	case Contains     = 'contains';
	case MayContain   = 'may_contain';
	case NotContained = 'not_contained';
	case NotVerified  = 'not_verified';

	/** @return list<string> */
	public static function values(): array {
		return array_map( static fn( self $c ) => $c->value, self::cases() );
	}

	/** @return array<string,string> admin choices (ja/en admin via gettext) */
	public static function choices(): array {
		return [
			'contains'      => __( 'Contains', 'momos-core' ),
			'may_contain'   => __( 'May contain', 'momos-core' ),
			'not_contained' => __( 'Not contained', 'momos-core' ),
			'not_verified'  => __( 'Not verified', 'momos-core' ),
		];
	}

	/** States that exclude a dish in the conservative allergen filter (FR-MENU-008(c)). */
	public function excludes_in_filter(): bool {
		return self::NotContained !== $this;
	}
}
