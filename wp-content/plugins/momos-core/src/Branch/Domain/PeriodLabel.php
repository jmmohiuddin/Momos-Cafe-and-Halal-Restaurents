<?php
declare(strict_types=1);

namespace Momos\Branch\Domain;

/** Opening period label; equals DB CHECK chk_momo_hours_label (spec §3.4/§3.7). */
enum PeriodLabel: string {
	case Lunch = 'lunch';
	case Dinner = 'dinner';
	case AllDay = 'all_day';
	case Other = 'other';

	/** @return list<string> */
	public static function values(): array {
		return array_map( static fn( self $c ): string => $c->value, self::cases() );
	}

	public static function is_valid( mixed $value ): bool {
		return is_string( $value ) && null !== self::tryFrom( $value );
	}
}
