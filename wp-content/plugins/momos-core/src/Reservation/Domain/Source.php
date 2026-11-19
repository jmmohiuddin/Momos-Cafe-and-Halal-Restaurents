<?php
declare(strict_types=1);

namespace Momos\Reservation\Domain;

/**
 * Origin channel of a reservation row (CHECK chk_momo_res_source).
 */
enum Source: string {
	case Web   = 'web';
	case Staff = 'staff';
	case Api   = 'api';

	/** @return list<string> */
	public static function values(): array {
		return array_map( static fn( self $c ): string => $c->value, self::cases() );
	}

	public static function is_valid( mixed $value ): bool {
		return is_string( $value ) && null !== self::tryFrom( $value );
	}
}
