<?php
declare(strict_types=1);

namespace Momos\Reservation\Domain;

/**
 * Fixed staff cancellation reasons (BR-RES-04, FR-RES-012). E04 is suppressed only for suspected_spam.
 */
enum StaffCancelReason: string {
	case CustomerRequestByPhone = 'customer_request_by_phone';
	case BranchUnableToServe    = 'branch_unable_to_serve';
	case Duplicate              = 'duplicate';
	case SuspectedSpam          = 'suspected_spam';
	case Other                  = 'other';

	/** @return list<string> */
	public static function values(): array {
		return array_map( static fn( self $c ): string => $c->value, self::cases() );
	}

	public static function is_valid( mixed $value ): bool {
		return is_string( $value ) && null !== self::tryFrom( $value );
	}
}
