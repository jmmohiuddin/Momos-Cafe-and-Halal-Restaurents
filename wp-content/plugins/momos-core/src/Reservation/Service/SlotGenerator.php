<?php
declare(strict_types=1);

namespace Momos\Reservation\Service;

use Momos\Branch\Repository\BranchRepository;
use Momos\Branch\Repository\BranchSettingsRepository;
use Momos\Branch\Service\ScheduleService;
use Momos\Kernel\Clock\Clock;
use Momos\Reservation\Support\Options;

/**
 * Slot generation (SDD §5.5, spec §3.3) — the single source of truth for API-01, API-02 and API-16.
 *
 * Business date b = JST date on which a period opens; resolved periods come from the Branch
 * ScheduleService (closures → special hours → dated weekly set → default weekly set). Slots are
 * aligned to each period's opens_at, step = slot interval, up to and including last order (or
 * close − interval when a special-hours period has no last order). A period closing after midnight
 * yields slots on the next calendar date.
 */
final class SlotGenerator {
	/**
	 * Is the branch accepting online requests (check 12)?
	 *
	 * @param array<string,mixed> $branch   BranchRepository::get()
	 * @param array<string,mixed> $settings BranchSettingsRepository::get()
	 * @return array{accepting:bool,reason:?string}
	 */
	public static function accepting( array $branch, array $settings ): array {
		if ( ! Options::bool( 'momo_res_global_enabled' ) ) {
			return [ 'accepting' => false, 'reason' => 'global_disabled' ];
		}
		if ( 'temporarily_closed' === $branch['operating_status'] ) {
			return [ 'accepting' => false, 'reason' => 'temporarily_closed' ];
		}
		if ( 'open' !== $branch['operating_status'] || 'publish' !== $branch['status'] || empty( $settings['enabled'] ) ) {
			return [ 'accepting' => false, 'reason' => 'reservations_disabled' ];
		}
		return [ 'accepting' => true, 'reason' => null ];
	}

	/**
	 * Slots of one business date as JST instants (no lead-time/window filtering).
	 *
	 * @return list<array{at:\DateTimeImmutable,label:string}>
	 */
	public static function business_day_slots( int $branch_id, string $business_date, int $interval ): array {
		$interval = in_array( $interval, [ 15, 30, 60 ], true ) ? $interval : 30;
		$out      = [];
		foreach ( ScheduleService::intervals( $branch_id, $business_date ) as $iv ) {
			$lo = $iv['lo'] ?? $iv['close']->modify( '-' . $interval . ' minutes' );
			for ( $t = $iv['open']; $t <= $lo; $t = $t->modify( '+' . $interval . ' minutes' ) ) {
				$out[] = [ 'at' => $t, 'label' => (string) $iv['period']['label'] ];
			}
		}
		return $out;
	}

	/**
	 * Slots on a calendar date (from business dates d−1 and d), optionally limited by lead time.
	 *
	 * @return array<string,string> 'H:i' => period label, sorted
	 */
	public static function slots_on( int $branch_id, string $date, int $interval, ?\DateTimeImmutable $earliest = null ): array {
		$prev = ( new \DateTimeImmutable( $date, Clock::tz() ) )->modify( '-1 day' )->format( 'Y-m-d' );
		$out  = [];
		foreach ( [ $prev, $date ] as $b ) {
			foreach ( self::business_day_slots( $branch_id, $b, $interval ) as $s ) {
				if ( $s['at']->format( 'Y-m-d' ) !== $date ) {
					continue;
				}
				if ( null !== $earliest && $s['at'] < $earliest ) {
					continue;
				}
				$out[ $s['at']->format( 'H:i' ) ] ??= $s['label'];
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Picker data for API-01: today … today + booking window (JST), lead time applied.
	 *
	 * @param array<string,mixed> $settings
	 * @return list<array{date:string,slots:list<string>,periods:array<string,string>,closed_reason:?string,closed_label:?string}>
	 */
	public static function days( int $branch_id, array $settings, ?\DateTimeImmutable $now = null, ?string $lang = null ): array {
		$now      = ( $now ?? Clock::utc() )->setTimezone( Clock::tz() );
		$today    = $now->setTime( 0, 0 );
		$last     = $today->modify( '+' . (int) $settings['booking_window_days'] . ' days' );
		$earliest = $now->modify( '+' . (int) $settings['lead_time_minutes'] . ' minutes' );
		$interval = (int) $settings['slot_interval_minutes'];
		$buckets  = [];
		for ( $d = $today; $d <= $last; $d = $d->modify( '+1 day' ) ) {
			$buckets[ $d->format( 'Y-m-d' ) ] = [];
		}
		for ( $b = $today->modify( '-1 day' ); $b <= $last; $b = $b->modify( '+1 day' ) ) {
			foreach ( self::business_day_slots( $branch_id, $b->format( 'Y-m-d' ), $interval ) as $s ) {
				$d = $s['at']->format( 'Y-m-d' );
				if ( isset( $buckets[ $d ] ) && $s['at'] >= $earliest ) {
					$buckets[ $d ][ $s['at']->format( 'H:i' ) ] ??= $s['label'];
				}
			}
		}
		$out = [];
		foreach ( $buckets as $date => $slots ) {
			ksort( $slots );
			$reason = null;
			$label  = null;
			if ( ! $slots ) {
				$reason = self::closed_reason( $branch_id, $date, $interval );
				if ( null !== $reason ) {
					$label = 'closed' === $reason ? momos_t( 'branch.closed_day', [], $lang ) : momos_t( 'closure_reason.' . $reason, [], $lang );
				}
			}
			$out[] = [
				'date'          => $date,
				'slots'         => array_keys( $slots ),
				'periods'       => (object) $slots,
				'closed_reason' => $reason,
				'closed_label'  => $label,
			];
		}
		return $out;
	}

	/**
	 * Why a calendar date has no slot at all (before lead time): closure public reason, 'closed' for a
	 * regular closed day, or null when slots exist and were only removed by lead time.
	 */
	public static function closed_reason( int $branch_id, string $date, int $interval ): ?string {
		if ( self::slots_on( $branch_id, $date, $interval ) ) {
			return null;
		}
		$closure = ScheduleService::closure_on( $branch_id, $date );
		if ( null !== $closure ) {
			return (string) $closure['public_reason'];
		}
		return 'closed';
	}

	/**
	 * Slot validity reason (check 16), ignoring lead time and window: null when (date, time) is a
	 * generated slot, else branch_closed_on_date | outside_opening_hours | after_last_order | not_a_slot.
	 */
	public static function slot_problem( int $branch_id, string $date, string $time, int $interval ): ?string {
		$slots = self::slots_on( $branch_id, $date, $interval );
		if ( isset( $slots[ $time ] ) ) {
			return null;
		}
		$t        = Clock::jst_at( $date, $time );
		$prev     = ( new \DateTimeImmutable( $date, Clock::tz() ) )->modify( '-1 day' )->format( 'Y-m-d' );
		$covering = null;
		$tail     = false;
		foreach ( [ $prev, $date ] as $b ) {
			foreach ( ScheduleService::intervals( $branch_id, $b ) as $iv ) {
				if ( $b === $prev && $iv['close']->format( 'Y-m-d' ) === $date ) {
					$tail = true;
				}
				if ( $t >= $iv['open'] && $t < $iv['close'] ) {
					$covering = $iv;
				}
			}
		}
		if ( null === $covering ) {
			if ( ! $tail && ! ScheduleService::periods_on( $branch_id, $date ) ) {
				return 'branch_closed_on_date';
			}
			return 'outside_opening_hours';
		}
		$lo = $covering['lo'] ?? $covering['close']->modify( '-' . $interval . ' minutes' );
		return $t > $lo ? 'after_last_order' : 'not_a_slot';
	}

	/** Convenience for the closure checks: slot still valid under the current schedule (lead/window ignored). */
	public static function is_valid_slot( int $branch_id, string $date, string $time ): bool {
		$s = BranchSettingsRepository::get( $branch_id );
		return null === self::slot_problem( $branch_id, $date, substr( $time, 0, 5 ), (int) $s['slot_interval_minutes'] );
	}

	/** @return array{e164:string,display:string} */
	public static function branch_phone( array $branch ): array {
		return [ 'e164' => (string) $branch['phone_e164'], 'display' => (string) $branch['phone_display'] ];
	}

	/** @return array<string,mixed>|null */
	public static function branch( int $branch_id ): ?array {
		return BranchRepository::get( $branch_id );
	}
}
