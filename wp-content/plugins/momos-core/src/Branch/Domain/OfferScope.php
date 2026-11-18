<?php
declare(strict_types=1);

namespace Momos\Branch\Domain;

/** Offer scope (G17, FR-CORP-010). */
enum OfferScope: string {
	case AllBranches = 'all_branches';
	case Branches = 'branches';

	/** @return list<string> */
	public static function values(): array {
		return array_map( static fn( self $c ): string => $c->value, self::cases() );
	}

	public static function is_valid( mixed $value ): bool {
		return is_string( $value ) && null !== self::tryFrom( $value );
	}
}
