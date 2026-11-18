<?php
declare(strict_types=1);

namespace Momos\Reservation\Domain;

/**
 * Fixed decline reasons (BR-RES-04, FR-RES-011).
 */
enum DeclineReason: string {
	case FullyBooked = 'fully_booked';
	case ClosedOrPrivateEvent = 'closed_or_private_event';
	case PartySizeNotSupported = 'party_size_not_supported';
	case IncompleteInformation = 'incomplete_information';
	case Other = 'other';

	/** @return list<string> */
	public static function values(): array {
		return array_map( static fn( self $c ): string => $c->value, self::cases() );
	}

	public static function is_valid( mixed $value ): bool {
		return is_string( $value ) && null !== self::tryFrom( $value );
	}
}
