<?php
declare(strict_types=1);

namespace Momos\Reservation\Service;

use Momos\Kernel\Clock\Clock;
use Momos\Reservation\Domain\ReservationError;

/**
 * Booking rule checks 12–16 of SDD §5.2 (spec §3.1), shared by API-02 (submission) and API-16
 * (modification, context staff_modify). Branch settings are read fresh by the caller (FR-RES-024).
 * The first failing check throws.
 */
final class BookingRules {
	/**
	 * @param array<string,mixed> $branch   BranchRepository::get()
	 * @param array<string,mixed> $settings BranchSettingsRepository::get()
	 * @param bool                $override HO override (API-16): waives party range, window and lead time only
	 * @return \DateTimeImmutable requested start (JST)
	 * @throws ReservationError
	 */
	public static function check( array $branch, array $settings, string $date, string $time, int $party, \DateTimeImmutable $now, bool $override = false ): \DateTimeImmutable {
		$phone = SlotGenerator::branch_phone( $branch );

		// 12. Accepting.
		$acc = SlotGenerator::accepting( $branch, $settings );
		if ( ! $acc['accepting'] ) {
			throw new ReservationError( 'branch_not_accepting', [], [ 'branch_phone' => $phone, 'reason' => $acc['reason'], 'reopen_date' => $branch['reopen_date'] ?? null ] );
		}

		// 13. Party size.
		if ( ! $override && ( $party < (int) $settings['party_min'] || $party > (int) $settings['party_max'] ) ) {
			throw new ReservationError(
				'party_size_out_of_range',
				[ 'party_size' => 'out_of_range' ],
				[ 'branch_phone' => $phone, 'args' => [ 'min' => (int) $settings['party_min'], 'max' => (int) $settings['party_max'] ], 'message_key' => $party < (int) $settings['party_min'] ? 'res.err.party.min' : 'res.err.party.max_call' ]
			);
		}
		if ( $party < 1 || $party > 50 ) {
			throw ReservationError::invalid( [ 'party_size' => 'out_of_range' ] );
		}

		// 14. Booking window (JST dates).
		$jst   = $now->setTimezone( Clock::tz() );
		$today = $jst->format( 'Y-m-d' );
		$last  = $jst->modify( '+' . (int) $settings['booking_window_days'] . ' days' )->format( 'Y-m-d' );
		if ( ! $override && ( $date < $today || $date > $last ) ) {
			throw new ReservationError( 'outside_booking_window', [ 'date' => 'out_of_range' ], [ 'args' => [ 'from' => $today, 'to' => $last, 'days' => (int) $settings['booking_window_days'] ] ] );
		}

		// 15. Lead time.
		$start = Clock::jst_at( $date, $time );
		if ( ! $override && $start < $now->modify( '+' . (int) $settings['lead_time_minutes'] . ' minutes' ) ) {
			throw new ReservationError( 'lead_time_not_met', [ 'time' => 'out_of_range' ], [ 'branch_phone' => $phone ] );
		}

		// 16. Slot (never waived).
		$problem = SlotGenerator::slot_problem( (int) $branch['id'], $date, $time, (int) $settings['slot_interval_minutes'] );
		if ( null !== $problem ) {
			throw new ReservationError( 'slot_unavailable', [ 'time' => $problem ], [ 'message_key' => 'branch_closed_on_date' === $problem ? 'res.err.date.closed' : 'res.err.time.unavailable' ] );
		}
		return $start;
	}
}
