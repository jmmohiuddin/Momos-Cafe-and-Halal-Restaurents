<?php
declare(strict_types=1);

namespace Momos\Branch\Domain;

/** Branch operating status (spec §3.7, FR-BR-004, BR-BR-04). */
enum OperatingStatus: string {
	case Open              = 'open';
	case TemporarilyClosed = 'temporarily_closed';
	case ClosedPermanently = 'closed_permanently';

	/** @return list<string> */
	public static function values(): array {
		return array_map( static fn( self $c ): string => $c->value, self::cases() );
	}

	public static function is_valid( mixed $value ): bool {
		return is_string( $value ) && null !== self::tryFrom( $value );
	}
}
