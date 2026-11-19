<?php
declare(strict_types=1);

namespace Momos\Branch\Domain;

/** Computed offer state (spec §3.7, Doc 07 labels). */
enum OfferState: string {
	case Draft     = 'draft';
	case Pending   = 'pending';
	case Scheduled = 'scheduled';
	case Active    = 'active';
	case Expired   = 'expired';

	/** @return list<string> */
	public static function values(): array {
		return array_map( static fn( self $c ): string => $c->value, self::cases() );
	}

	public static function is_valid( mixed $value ): bool {
		return is_string( $value ) && null !== self::tryFrom( $value );
	}
}
