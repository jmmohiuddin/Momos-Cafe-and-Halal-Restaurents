<?php
declare(strict_types=1);

namespace Momos\Branch\Domain;

/** Public closure reason; equals DB CHECK chk_momo_clos_reason (FR-BR-007). */
enum ClosureReason: string {
	case PublicHoliday = 'public_holiday';
	case PrivateEvent = 'private_event';
	case Maintenance = 'maintenance';
	case StaffShortage = 'staff_shortage';
	case Other = 'other';

	/** @return list<string> */
	public static function values(): array {
		return array_map( static fn( self $c ): string => $c->value, self::cases() );
	}

	public static function is_valid( mixed $value ): bool {
		return is_string( $value ) && null !== self::tryFrom( $value );
	}
}
