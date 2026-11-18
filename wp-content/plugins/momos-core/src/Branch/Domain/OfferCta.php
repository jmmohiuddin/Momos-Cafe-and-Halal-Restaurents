<?php
declare(strict_types=1);

namespace Momos\Branch\Domain;

/** Offer call-to-action type (G17, FR-CORP-010). */
enum OfferCta: string {
	case None = 'none';
	case RequestTable = 'request_table';
	case Menu = 'menu';
	case Dish = 'dish';

	/** @return list<string> */
	public static function values(): array {
		return array_map( static fn( self $c ): string => $c->value, self::cases() );
	}

	public static function is_valid( mixed $value ): bool {
		return is_string( $value ) && null !== self::tryFrom( $value );
	}
}
