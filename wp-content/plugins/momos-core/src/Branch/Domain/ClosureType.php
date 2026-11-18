<?php
declare(strict_types=1);

namespace Momos\Branch\Domain;

/** Closure record type; equals DB CHECK chk_momo_clos_type (FR-BR-007). */
enum ClosureType: string {
	case ClosedAllDay = 'closed_all_day';
	case SpecialHours = 'special_hours';

	/** @return list<string> */
	public static function values(): array {
		return array_map( static fn( self $c ): string => $c->value, self::cases() );
	}

	public static function is_valid( mixed $value ): bool {
		return is_string( $value ) && null !== self::tryFrom( $value );
	}
}
