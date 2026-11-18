<?php
declare(strict_types=1);

namespace Momos\Reservation\Domain;

/**
 * Reservation state machine (DEC-011, spec §3.8). Encodes which action an actor type may take from a
 * status and the resulting status. Time/reason guards live in Service\TransitionService and
 * Service\ActionPolicy; this class is the single source of the transition graph.
 *
 *   pending   → confirmed (T2 accept, staff) | rejected (T3 decline, staff)
 *             | cancelled (T4a customer / T4b staff) | expired (T5 system)
 *   confirmed → cancelled (T6a customer / T6b staff) | completed (T7 staff) | no_show (T8 staff)
 *   rejected, cancelled, expired, completed, no_show = terminal (BR-RES-05)
 */
final class StateMachine {
	public const EXPIRE = 'expire';

	/** status => action => target */
	private const GRAPH = [
		'pending'   => [
			'accept'  => 'confirmed',
			'decline' => 'rejected',
			'cancel'  => 'cancelled',
			'expire'  => 'expired',
		],
		'confirmed' => [
			'cancel'         => 'cancelled',
			'mark_completed' => 'completed',
			'mark_no_show'   => 'no_show',
		],
	];

	/** action => allowed actor types */
	private const ACTORS = [
		'accept'         => [ 'staff' ],
		'decline'        => [ 'staff' ],
		'cancel'         => [ 'staff', 'customer' ],
		'expire'         => [ 'system' ],
		'mark_completed' => [ 'staff' ],
		'mark_no_show'   => [ 'staff' ],
	];

	/** Lifecycle timestamp column written by each target status. */
	public const TIMESTAMP_COLUMN = [
		'confirmed' => 'confirmed_at',
		'rejected'  => 'rejected_at',
		'cancelled' => 'cancelled_at',
		'expired'   => 'expired_at',
		'completed' => 'attended_at',
		'no_show'   => 'attended_at',
	];

	/** Target status, or null when the transition is not allowed. */
	public static function target( ReservationStatus $from, string $action, ActorType $actor ): ?ReservationStatus {
		$to = self::GRAPH[ $from->value ][ $action ] ?? null;
		if ( null === $to || ! in_array( $actor->value, self::ACTORS[ $action ] ?? [], true ) ) {
			return null;
		}
		return ReservationStatus::from( $to );
	}

	/** @throws ReservationError invalid_transition (409) with the current status. */
	public static function assert( ReservationStatus $from, string $action, ActorType $actor ): ReservationStatus {
		$to = self::target( $from, $action, $actor );
		if ( null === $to ) {
			throw new ReservationError( 'invalid_transition', [], [ 'current' => [ 'status' => $from->value ] ] );
		}
		return $to;
	}

	/** @return list<string> every action known to the machine */
	public static function actions(): array {
		return array_keys( self::ACTORS );
	}
}
