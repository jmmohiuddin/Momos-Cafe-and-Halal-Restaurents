<?php
declare(strict_types=1);

namespace Momos\Reservation\Domain;

/**
 * Reservation event types (spec §2.3; mirrors CHECK chk_momo_evt_type of M0002).
 */
enum EventType: string {
	case Created            = 'created';
	case StatusChanged      = 'status_changed';
	case Assigned           = 'assigned';
	case Released           = 'released';
	case Note               = 'note';
	case Modified           = 'modified';
	case NotificationFailed = 'notification_failed';
	case CustomerContacted  = 'customer_contacted';
	case SlaReminder        = 'sla_reminder';
	case SlaEscalated       = 'sla_escalated';
	case ClosureConflict    = 'closure_conflict';
	case PiiPurged          = 'pii_purged';

	/** @return list<string> */
	public static function values(): array {
		return array_map( static fn( self $c ): string => $c->value, self::cases() );
	}

	public static function is_valid( mixed $value ): bool {
		return is_string( $value ) && null !== self::tryFrom( $value );
	}
}
