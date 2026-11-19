<?php
declare(strict_types=1);

namespace Momos\Reservation\Service;

use Momos\Branch\Repository\BranchSettingsRepository;
use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Db\Transaction;
use Momos\Kernel\Events\AfterCommit;
use Momos\Kernel\Support\Json;
use Momos\Reservation\Domain\Actor;
use Momos\Reservation\Domain\DeclineReason;
use Momos\Reservation\Domain\EventType;
use Momos\Reservation\Domain\ReservationError;
use Momos\Reservation\Domain\ReservationStatus;
use Momos\Reservation\Domain\StaffAction;
use Momos\Reservation\Domain\StaffCancelReason;
use Momos\Reservation\Domain\StateMachine;
use Momos\Reservation\Repository\EventRepository;
use Momos\Reservation\Repository\ReservationRepository;
use Momos\Reservation\Validation\FieldValidator;

/**
 * Staff and customer transitions T2–T8 (DEC-011, FR-RES-010/011/012/018/020/025, spec §3.8) with
 * optimistic locking: a conditional UPDATE on (id, version, status); 0 rows → re-read →
 * invalid_transition (status changed) or version_conflict (same status, newer version). Repeating a
 * successful action therefore yields invalid_transition and no second hook/email [Ruling C-2].
 */
final class TransitionService {
	public const ATTENDANCE_WINDOW_HOURS = 72;

	/**
	 * Staff action (API-12 / SCR-A09). Capability and branch scope are checked by the caller.
	 *
	 * @param array{version?:mixed,reason_code?:mixed,message_to_customer?:mixed,suggested_times?:mixed,note?:mixed} $input
	 * @return array{row:array<string,mixed>,notifications_queued:list<string>}
	 * @throws ReservationError
	 */
	public static function staff( int $id, string $action, array $input, Actor $actor ): array {
		if ( ! StaffAction::is_valid( $action ) ) {
			throw ReservationError::invalid( [ 'action' => 'invalid_format' ] );
		}
		$expired_now = null !== ExpiryService::expire_if_due( $id );
		$row         = ReservationRepository::find( $id );
		if ( null === $row ) {
			throw new ReservationError( 'not_found' );
		}

		// Input validation (400).
		$fields  = [];
		$version = $input['version'] ?? null;
		if ( ! is_int( $version ) && ! ( is_string( $version ) && ctype_digit( $version ) ) ) {
			$fields['version'] = 'required';
		}
		[ $message, $merr ] = FieldValidator::message( $input['message_to_customer'] ?? null );
		if ( $merr ) {
			$fields['message_to_customer'] = $merr;
		}
		[ $note, $nerr ] = FieldValidator::note( $input['note'] ?? null, false );
		if ( $nerr ) {
			$fields['note'] = $nerr;
		}
		$reason    = isset( $input['reason_code'] ) && '' !== $input['reason_code'] ? (string) $input['reason_code'] : null;
		$suggested = null;
		if ( 'decline' === $action ) {
			[ $suggested, $serr ] = self::suggested_times( $input['suggested_times'] ?? [] );
			if ( $serr ) {
				$fields['suggested_times'] = $serr;
			}
		} elseif ( ! empty( $input['suggested_times'] ) ) {
			$fields['suggested_times'] = 'not_allowed';
		}
		if ( in_array( $action, [ 'accept', 'mark_completed', 'mark_no_show' ], true ) && null !== $reason ) {
			$fields['reason_code'] = 'not_allowed';
		}
		if ( in_array( $action, [ 'mark_completed', 'mark_no_show' ], true ) && null !== $message ) {
			$fields['message_to_customer'] = 'not_allowed';
		}
		if ( $fields ) {
			throw ReservationError::invalid( $fields );
		}
		if ( 'decline' === $action ) {
			if ( null === $reason ) {
				throw new ReservationError( 'reason_required', [ 'reason_code' => 'required' ] );
			}
			if ( ! DeclineReason::is_valid( $reason ) ) {
				throw ReservationError::invalid( [ 'reason_code' => 'invalid_format' ] );
			}
		}
		if ( 'cancel' === $action ) {
			if ( null === $reason ) {
				throw new ReservationError( 'reason_required', [ 'reason_code' => 'required' ] );
			}
			if ( ! StaffCancelReason::is_valid( $reason ) ) {
				throw ReservationError::invalid( [ 'reason_code' => 'invalid_format' ] );
			}
		}
		if ( 'other' === $reason && null === $message && null === $note ) {
			throw new ReservationError( 'reason_required', [ 'message_to_customer' => 'required' ] );
		}

		// State checks (409/422).
		$from = ReservationStatus::from( (string) $row['status'] );
		$to   = StateMachine::target( $from, $action, $actor->type );
		if ( null === $to ) {
			throw self::invalid_transition( $row, $expired_now );
		}
		$now   = Clock::utc();
		$start = Clock::from_mysql_utc( (string) $row['requested_start_utc'] );
		if ( in_array( $action, [ 'accept', 'decline' ], true ) ) {
			$cutoff = (int) BranchSettingsRepository::get( (int) $row['branch_id'] )['decision_cutoff_minutes'];
			if ( $now >= $start->modify( '-' . $cutoff . ' minutes' ) ) {
				throw self::invalid_transition( $row, true );
			}
		}
		if ( in_array( $action, [ 'mark_completed', 'mark_no_show' ], true ) && ! self::attendance_open( $row, (int) ( $actor->id ?? 0 ), $now ) ) {
			throw new ReservationError( 'attendance_window', [], [ 'current' => self::current( $row ) ] );
		}

		$set = [
			'status'                                     => $to->value,
			StateMachine::TIMESTAMP_COLUMN[ $to->value ] => Clock::mysql_utc( $now ),
			'handled_by'                                 => [ 'raw' => 'COALESCE(handled_by, %d)', 'args' => [ (int) $actor->id ] ],
		];
		if ( in_array( $action, [ 'accept', 'decline', 'cancel' ], true ) ) {
			$set['message_to_customer'] = $message;
		}
		if ( 'decline' === $action ) {
			$set['decision_reason_code'] = $reason;
			$set['suggested_times']      = $suggested ? Json::encode( $suggested ) : null;
		}
		if ( 'cancel' === $action ) {
			$set['decision_reason_code'] = $reason;
			$set['cancelled_by']         = 'staff';
			$set['needs_action_flag']    = 0;
		}

		$queued = self::commit( $row, (int) $version, $set, $actor, $reason, $message, $note, true );
		return [ 'row' => (array) ReservationRepository::find( $id ), 'notifications_queued' => $queued ];
	}

	/**
	 * Customer cancel T4a/T6a (API-04, FR-RES-020): lazy expiry → 409 invalid_transition; terminal →
	 * 409 cancel_not_allowed [Ruling C-1]; at/after start − customer cutoff → cancel_not_allowed.
	 *
	 * @param array<string,mixed> $row token-verified row
	 * @return array<string,mixed> updated row
	 * @throws ReservationError
	 */
	public static function customer_cancel( array $row ): array {
		$id = (int) $row['id'];
		if ( null !== ExpiryService::expire_if_due( $id ) ) {
			throw new ReservationError( 'invalid_transition', [], [ 'current' => [ 'status' => 'expired' ] ] );
		}
		$row  = (array) ReservationRepository::find( $id );
		$from = ReservationStatus::from( (string) $row['status'] );
		if ( $from->is_terminal() || ! self::customer_can_cancel( $row ) ) {
			throw new ReservationError( 'cancel_not_allowed' );
		}
		StateMachine::assert( $from, 'cancel', \Momos\Reservation\Domain\ActorType::Customer );
		$set = [
			'status'       => 'cancelled',
			'cancelled_at' => Clock::mysql_utc(),
			'cancelled_by' => 'customer',
		];
		try {
			self::commit( $row, (int) $row['version'], $set, Actor::customer(), null, null, null, false );
		} catch ( ReservationError ) {
			throw new ReservationError( 'cancel_not_allowed' );
		}
		return (array) ReservationRepository::find( $id );
	}

	/** Customer may cancel pending/confirmed until start − customer cutoff (BR-RES-07). @param array<string,mixed> $row */
	public static function customer_can_cancel( array $row, ?\DateTimeImmutable $now = null ): bool {
		if ( ! in_array( $row['status'], [ 'pending', 'confirmed' ], true ) ) {
			return false;
		}
		return ( $now ?? Clock::utc() ) < self::customer_cancel_deadline( $row );
	}

	/** @param array<string,mixed> $row */
	public static function customer_cancel_deadline( array $row ): \DateTimeImmutable {
		$cut = (int) BranchSettingsRepository::get( (int) $row['branch_id'] )['customer_cancel_cutoff_minutes'];
		return Clock::from_mysql_utc( (string) $row['requested_start_utc'] )->modify( '-' . $cut . ' minutes' );
	}

	/**
	 * Attendance window (BR-RES-09): branch staff start ≤ now ≤ start + 72 h; all-branch users any time
	 * after start.
	 *
	 * @param array<string,mixed> $row
	 */
	public static function attendance_open( array $row, int $user_id, ?\DateTimeImmutable $now = null ): bool {
		$now   = $now ?? Clock::utc();
		$start = Clock::from_mysql_utc( (string) $row['requested_start_utc'] );
		if ( $now < $start ) {
			return false;
		}
		if ( ReservationAccess::all_branches( $user_id ) ) {
			return true;
		}
		return $now <= $start->modify( '+' . self::ATTENDANCE_WINDOW_HOURS . ' hours' );
	}

	/**
	 * Conditional UPDATE + events + audit + notification queue rows in one transaction, then the
	 * after-commit hook. Returns the templates actually queued (never claims an e-mail that was not).
	 *
	 * @param array<string,mixed> $row
	 * @param array<string,mixed> $set
	 * @return list<string>
	 */
	private static function commit( array $row, int $version, array $set, Actor $actor, ?string $reason, ?string $message, ?string $note, bool $audit ): array {
		$id   = (int) $row['id'];
		$from = (string) $row['status'];
		$to   = (string) $set['status'];
		return Transaction::run(
			static function () use ( $id, $from, $to, $version, $set, $actor, $reason, $message, $note, $audit, $row ): array {
				$affected = ReservationRepository::update_conditional( $id, $version, $from, $set );
				if ( 1 !== $affected ) {
					$current = (array) ReservationRepository::find( $id );
					if ( ( $current['status'] ?? '' ) !== $from ) {
						throw self::invalid_transition( $current, 'expired' === ( $current['status'] ?? '' ) );
					}
					throw new ReservationError( 'version_conflict', [], [ 'current' => self::current( $current ) ] );
				}
				EventRepository::add( $id, EventType::StatusChanged, $actor, $from, $to, $reason, $message );
				if ( null !== $note ) {
					EventRepository::add( $id, EventType::Note, $actor, null, null, null, $note );
				}
				if ( $audit ) {
					momos_audit( 'reservation.status_changed', 'reservation', $id, (int) $row['branch_id'], [ 'from' => $from, 'to' => $to, 'reason' => $reason ] );
				}
				$queued = NotificationTrigger::status_changed( $id, (int) $row['branch_id'], $from, $to, $version + 1, $actor->type->value, $reason );
				AfterCommit::dispatch( 'momos/reservation/status_changed', $id, $from, $to, $actor->to_array() );
				return $queued;
			}
		);
	}

	/**
	 * Validate ≤ 2 future suggested times (decline only).
	 *
	 * @return array{0:list<array{date:string,time:string}>,1:?string}
	 */
	private static function suggested_times( mixed $value ): array {
		if ( null === $value || '' === $value ) {
			return [ [], null ];
		}
		if ( ! is_array( $value ) || count( $value ) > 2 ) {
			return [ [], is_array( $value ) ? 'out_of_range' : 'invalid_format' ];
		}
		$out = [];
		foreach ( array_values( $value ) as $item ) {
			$date = is_array( $item ) ? (string) ( $item['date'] ?? '' ) : '';
			$time = is_array( $item ) ? (string) ( $item['time'] ?? '' ) : '';
			$dt   = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date );
			if ( ! $dt || $dt->format( 'Y-m-d' ) !== $date || ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time ) ) {
				return [ [], 'invalid_format' ];
			}
			if ( Clock::jst_at( $date, $time ) <= Clock::utc() ) {
				return [ [], 'out_of_range' ];
			}
			$out[] = [ 'date' => $date, 'time' => $time ];
		}
		return [ $out, null ];
	}

	/** @param array<string,mixed> $row */
	public static function invalid_transition( array $row, bool $expired = false ): ReservationError {
		return new ReservationError(
			'invalid_transition',
			[],
			[
				'current'     => self::current( $row ),
				'message_key' => $expired && 'expired' === ( $row['status'] ?? '' ) ? 'staff.err.expired' : 'staff.err.invalid_transition',
			]
		);
	}

	/**
	 * Current summary for 409 bodies: {status, version, handled_by, updated_at, updated_by_display}.
	 *
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	public static function current( array $row ): array {
		$last = null;
		if ( ! empty( $row['id'] ) ) {
			$events = EventRepository::for_reservation( (int) $row['id'] );
			$last   = $events ? end( $events ) : null;
		}
		$handler = ! empty( $row['handled_by'] ) ? get_userdata( (int) $row['handled_by'] ) : null;
		return [
			'status'             => (string) ( $row['status'] ?? '' ),
			'version'            => (int) ( $row['version'] ?? 0 ),
			'handled_by'         => $handler ? [ 'id' => (int) $handler->ID, 'name' => (string) $handler->display_name ] : null,
			'updated_at'         => ! empty( $row['updated_at'] ) ? Clock::from_mysql_utc( (string) $row['updated_at'] )->setTimezone( Clock::tz() )->format( 'c' ) : null,
			'updated_by_display' => $last ? ( 'system' === $last['actor_type'] ? 'system' : $last['actor_display'] ) : null,
		];
	}
}
