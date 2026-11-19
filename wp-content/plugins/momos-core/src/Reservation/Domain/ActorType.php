<?php
declare(strict_types=1);

namespace Momos\Reservation\Domain;

/**
 * Actors of reservation events and cancellations (spec §2.3; CHECK chk_momo_evt_actor / chk_momo_res_cancelled_by).
 */
enum ActorType: string {
	case Customer = 'customer';
	case Staff    = 'staff';
	case System   = 'system';

	/** @return list<string> */
	public static function values(): array {
		return array_map( static fn( self $c ): string => $c->value, self::cases() );
	}

	public static function is_valid( mixed $value ): bool {
		return is_string( $value ) && null !== self::tryFrom( $value );
	}
}
