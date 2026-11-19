<?php
declare(strict_types=1);

namespace Momos\Reservation\Domain;

/**
 * Staff transition actions of API-12 (spec §2.3).
 */
enum StaffAction: string {
	case Accept        = 'accept';
	case Decline       = 'decline';
	case Cancel        = 'cancel';
	case MarkCompleted = 'mark_completed';
	case MarkNoShow    = 'mark_no_show';

	/** @return list<string> */
	public static function values(): array {
		return array_map( static fn( self $c ): string => $c->value, self::cases() );
	}

	public static function is_valid( mixed $value ): bool {
		return is_string( $value ) && null !== self::tryFrom( $value );
	}
}
