<?php
declare(strict_types=1);

namespace Momos\Reservation\Service;

use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Db\Transaction;
use Momos\Reservation\Domain\Actor;
use Momos\Reservation\Domain\EventType;
use Momos\Reservation\Domain\ReservationError;
use Momos\Reservation\Repository\EventRepository;
use Momos\Reservation\Repository\ReservationRepository;
use Momos\Reservation\Validation\FieldValidator;

/**
 * Claim, release, internal notes and flags (API-13, FR-RES-013/014, spec §3.11). Notes are
 * append-only and never reach customers (BR-NOT-02); claiming never changes the status or sends email.
 */
final class NoteService {
	public const TYPES = [ 'note', 'claim', 'release', 'customer_contacted', 'flag_resolved' ];

	/**
	 * @param array{type?:mixed,text?:mixed,version?:mixed} $input
	 * @return array{event_id:int,type:string,at:string,actor:?string,version:int}
	 * @throws ReservationError
	 */
	public static function apply( int $id, array $input, Actor $actor ): array {
		$type = (string) ( $input['type'] ?? '' );
		if ( ! in_array( $type, self::TYPES, true ) ) {
			throw ReservationError::invalid( [ 'type' => 'required' === $type ? 'required' : 'invalid_format' ] );
		}
		$row = ReservationRepository::find( $id );
		if ( null === $row ) {
			throw new ReservationError( 'not_found' );
		}
		$fields  = [];
		$version = $input['version'] ?? null;
		$has_ver = is_int( $version ) || ( is_string( $version ) && ctype_digit( $version ) );
		if ( 'note' !== $type && ! $has_ver ) {
			$fields['version'] = 'required';
		}
		[ $text, $terr ] = FieldValidator::note( $input['text'] ?? null, in_array( $type, [ 'note', 'flag_resolved' ], true ) );
		if ( $terr ) {
			$fields['text'] = $terr;
		}
		if ( $fields ) {
			throw ReservationError::invalid( $fields );
		}
		if ( in_array( $type, [ 'claim', 'release' ], true ) && ! in_array( $row['status'], [ 'pending', 'confirmed' ], true ) ) {
			throw TransitionService::invalid_transition( $row );
		}

		$branch_id = (int) $row['branch_id'];
		$event_id  = Transaction::run(
			static function () use ( $type, $row, $id, $version, $text, $actor, $branch_id ): int {
				$set = match ( $type ) {
					'claim'              => [ 'handled_by' => (int) $actor->id ],
					'release'            => [ 'handled_by' => null ],
					'customer_contacted' => [ 'customer_notified_flag' => 1 ],
					'flag_resolved'      => [ 'needs_action_flag' => 0 ],
					default              => null,
				};
				if ( null !== $set ) {
					if ( 1 !== ReservationRepository::update_conditional( $id, (int) $version, null, $set ) ) {
						throw new ReservationError( 'version_conflict', [], [ 'current' => TransitionService::current( (array) ReservationRepository::find( $id ) ) ] );
					}
				}
				switch ( $type ) {
					case 'claim':
						$prev    = ! empty( $row['handled_by'] ) ? get_userdata( (int) $row['handled_by'] ) : null;
						$eid     = EventRepository::add( $id, EventType::Assigned, $actor, null, null, null, null, [ 'previous_handler_id' => $prev ? (int) $prev->ID : null, 'previous_handler_display' => $prev ? (string) $prev->display_name : null ] );
						momos_audit( 'reservation.claimed', 'reservation', $id, $branch_id, [ 'previous_handler_id' => $prev ? (int) $prev->ID : null ] );
						return $eid;
					case 'release':
						return EventRepository::add( $id, EventType::Released, $actor );
					case 'customer_contacted':
						return EventRepository::add( $id, EventType::CustomerContacted, $actor, null, null, null, $text );
					case 'flag_resolved':
						return EventRepository::add( $id, EventType::Note, $actor, null, null, 'flag_resolved', $text );
					default:
						$eid = EventRepository::add( $id, EventType::Note, $actor, null, null, null, $text );
						momos_audit( 'reservation.note_added', 'reservation', $id, $branch_id, [ 'event_id' => $eid ] );
						return $eid;
				}
			}
		);
		$event = (array) EventRepository::find( $event_id );
		$fresh = (array) ReservationRepository::find( $id );
		return [
			'event_id' => $event_id,
			'type'     => $type,
			'at'       => Clock::from_mysql_utc( (string) $event['created_at'] )->setTimezone( Clock::tz() )->format( 'c' ),
			'actor'    => $actor->display,
			'version'  => (int) $fresh['version'],
		];
	}
}
