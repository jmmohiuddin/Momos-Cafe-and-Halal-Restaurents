<?php
declare(strict_types=1);

namespace Momos\Reservation\Service;

use Momos\Branch\Repository\BranchRepository;
use Momos\Branch\Repository\BranchSettingsRepository;
use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Db\Transaction;
use Momos\Kernel\Events\AfterCommit;
use Momos\Reservation\Domain\Actor;
use Momos\Reservation\Domain\EventType;
use Momos\Reservation\Domain\ReservationError;
use Momos\Reservation\Repository\EventRepository;
use Momos\Reservation\Repository\ReservationRepository;
use Momos\Reservation\Validation\Digits;
use Momos\Reservation\Validation\FieldValidator;

/**
 * Staff modification of date, time and party size (FR-RES-015, BR-RES-11, API-16, spec §3.12).
 * Re-validates checks 12–16 (context staff_modify); HO (`momo_access_all_branches`) may waive lead
 * time, booking window and party range with a recorded reason; closures, periods, last order, slots
 * and a non-accepting branch are never waived. The branch can never change.
 */
final class ModificationService {
	/**
	 * @param array<string,mixed> $input API-16 body
	 * @return array{row:array<string,mixed>,notifications_queued:list<string>}
	 * @throws ReservationError
	 */
	public static function modify( int $id, array $input, Actor $actor ): array {
		$expired_now = null !== ExpiryService::expire_if_due( $id );
		$row         = ReservationRepository::find( $id );
		if ( null === $row ) {
			throw new ReservationError( 'not_found' );
		}

		$allowed = [ 'version', 'requested_date', 'requested_time', 'party_size', 'reason', 'note', 'override_rules', 'override_reason', 'lang' ];
		$fields  = [];
		foreach ( array_keys( $input ) as $k ) {
			if ( ! in_array( $k, $allowed, true ) ) {
				$fields[ (string) $k ] = 'not_allowed';
			}
		}
		$version = $input['version'] ?? null;
		if ( ! is_int( $version ) && ! ( is_string( $version ) && ctype_digit( $version ) ) ) {
			$fields['version'] = 'required';
		}
		$date  = isset( $input['requested_date'] ) && null !== $input['requested_date'] ? (string) $input['requested_date'] : (string) $row['requested_date'];
		$time  = isset( $input['requested_time'] ) && null !== $input['requested_time'] ? Digits::normalize( (string) $input['requested_time'] ) : substr( (string) $row['requested_time'], 0, 5 );
		$party = isset( $input['party_size'] ) && null !== $input['party_size'] ? Digits::normalize( (string) $input['party_size'] ) : (string) $row['party_size'];
		$dt    = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date );
		if ( ! $dt || $dt->format( 'Y-m-d' ) !== $date ) {
			$fields['requested_date'] = 'invalid_format';
		}
		if ( ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time ) ) {
			$fields['requested_time'] = 'invalid_format';
		}
		if ( ! ctype_digit( $party ) || (int) $party < 1 || (int) $party > 50 ) {
			$fields['party_size'] = ctype_digit( $party ) ? 'out_of_range' : 'invalid_format';
		}
		[ $note, $nerr ] = FieldValidator::note( $input['note'] ?? null, false );
		if ( $nerr ) {
			$fields['note'] = $nerr;
		}
		$override = true === ( $input['override_rules'] ?? false );
		$o_reason = null;
		if ( $override ) {
			$o_reason = trim( wp_strip_all_tags( (string) ( $input['override_reason'] ?? '' ) ) );
			if ( '' === $o_reason ) {
				$fields['override_reason'] = 'required';
			} elseif ( mb_strlen( $o_reason ) > 200 ) {
				$fields['override_reason'] = 'too_long';
			}
		}
		if ( $fields ) {
			throw ReservationError::invalid( $fields );
		}
		if ( 'agreed_with_guest' !== ( $input['reason'] ?? null ) ) {
			throw new ReservationError( 'reason_required', [ 'reason' => 'required' ] );
		}
		if ( $override && ! ReservationAccess::all_branches( (int) $actor->id ) ) {
			throw new ReservationError( 'forbidden_field', [ 'override_rules' => 'not_allowed' ], [ 'args' => [ 'field' => 'override_rules' ] ] );
		}

		$now   = Clock::utc();
		$start = Clock::from_mysql_utc( (string) $row['requested_start_utc'] );
		if ( ! in_array( $row['status'], [ 'pending', 'confirmed' ], true ) || $now >= $start ) {
			throw TransitionService::invalid_transition( $row, $expired_now );
		}
		// E06 / event payload shape (notifications.md E06, CONTRACTS `momos/reservation/modified`):
		// {before:{requested_date,requested_time,party_size}, after:{…}} — no personal data.
		$before = [ 'requested_date' => (string) $row['requested_date'], 'requested_time' => substr( (string) $row['requested_time'], 0, 5 ), 'party_size' => (int) $row['party_size'] ];
		$after  = [ 'requested_date' => $date, 'requested_time' => $time, 'party_size' => (int) $party ];
		if ( $before === $after ) {
			throw ReservationError::invalid( [ 'requested_date' => 'not_allowed' ] );
		}

		$branch = BranchRepository::get( (int) $row['branch_id'] );
		if ( null === $branch ) {
			throw new ReservationError( 'branch_not_accepting' );
		}
		$settings  = BranchSettingsRepository::get( (int) $row['branch_id'] );
		$new_start = BookingRules::check( $branch, $settings, $date, $time, (int) $party, $now, $override );
		if ( $new_start <= $now ) {
			throw new ReservationError( 'lead_time_not_met', [ 'requested_time' => 'out_of_range' ], [ 'branch_phone' => SlotGenerator::branch_phone( $branch ) ] );
		}

		$status = (string) $row['status'];
		$queued = Transaction::run(
			static function () use ( $id, $version, $status, $date, $time, $party, $new_start, $before, $after, $override, $o_reason, $note, $actor, $row ): array {
				$affected = ReservationRepository::update_conditional(
					$id,
					(int) $version,
					$status,
					[
						'requested_date'      => $date,
						'requested_time'      => $time . ':00',
						'requested_start_utc' => Clock::mysql_utc( $new_start ),
						'party_size'          => (int) $party,
						'token_expires_utc'   => Clock::mysql_utc( $new_start->modify( '+24 hours' ) ),
						'needs_action_flag'   => 0,
					]
				);
				if ( 1 !== $affected ) {
					$current = (array) ReservationRepository::find( $id );
					if ( ( $current['status'] ?? '' ) !== $status ) {
						throw TransitionService::invalid_transition( $current, 'expired' === ( $current['status'] ?? '' ) );
					}
					throw new ReservationError( 'version_conflict', [], [ 'current' => TransitionService::current( $current ) ] );
				}
				$payload = [ 'before' => $before, 'after' => $after ];
				if ( $override ) {
					$payload['override_reason'] = $o_reason;
				}
				EventRepository::add( $id, EventType::Modified, $actor, null, null, 'agreed_with_guest', null, $payload );
				if ( null !== $note ) {
					EventRepository::add( $id, EventType::Note, $actor, null, null, null, $note );
				}
				momos_audit( 'reservation.modified', 'reservation', $id, (int) $row['branch_id'], [ 'before' => $before, 'after' => $after, 'override' => $override, 'override_reason' => $o_reason ] );
				$queued = in_array( $status, [ 'pending', 'confirmed' ], true ) ? [ 'E06' ] : [];
				NotificationTrigger::modified( $id, (int) $row['branch_id'], $status, (int) $version + 1 ); // E06, same transaction.
				AfterCommit::dispatch( 'momos/reservation/modified', $id, $before, $after, $actor->to_array() );
				return $queued;
			}
		);
		return [ 'row' => (array) ReservationRepository::find( $id ), 'notifications_queued' => $queued ];
	}
}
