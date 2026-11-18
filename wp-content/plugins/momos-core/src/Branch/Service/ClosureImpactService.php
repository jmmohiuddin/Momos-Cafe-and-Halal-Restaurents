<?php
declare(strict_types=1);

namespace Momos\Branch\Service;

use Momos\Branch\Domain\ClosureType;
use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Db\Tables;

/**
 * Closures vs reservations (FR-BR-010, BR-RES-13, spec §4.7, SDD §5.16).
 *
 * 1. Preview (before save): pending/confirmed future reservations of the branch that the proposed
 *    schedule would no longer allow (lead time and booking window ignored). The proposed schedule is
 *    evaluated through a ScheduleService overlay, so nothing is written.
 * 2. Apply (after commit): Branch fires `momos/branch/schedule_changed(branch, dates)` (repositories)
 *    and `momos/branch/accepting_changed(branch, accepting, reason)` (status / kill switch); the
 *    Reservation module flags the affected reservations (`needs_action_flag`, event
 *    `closure_conflict`). Never a status change, never a customer email; flags are not auto-cleared.
 *
 * Slot validity defaults to "inside an opening period of its business date (≤ last order)"; the
 * Reservation module can substitute its SlotGenerator via filter `momos/branch/slot_valid`.
 * The preview list never contains guest name/email/phone.
 */
final class ClosureImpactService {
	public const STATUSES = [ 'pending', 'confirmed' ];

	/**
	 * Future pending/confirmed reservations (no PII), optionally limited to requested dates.
	 *
	 * @param list<string>|null $dates requested dates (Y-m-d); null = all future
	 * @return list<array{id:int,public_ref:string,requested_date:string,requested_time:string,party_size:int,status:string}>
	 */
	public static function future_active_reservations( int $branch_id, ?array $dates = null ): array {
		global $wpdb;
		$t = Tables::reservations();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) {
			return [];
		}
		$sql = $wpdb->prepare(
			"SELECT id, public_ref, requested_date, requested_time, party_size, status FROM {$t} WHERE branch_id = %d AND status IN ('pending','confirmed') AND requested_start_utc >= %s", // phpcs:ignore
			$branch_id,
			Clock::mysql_utc()
		);
		if ( null !== $dates ) {
			if ( ! $dates ) {
				return [];
			}
			$sql .= ' AND requested_date IN (' . implode( ',', array_map( static fn( $d ) => $wpdb->prepare( '%s', $d ), $dates ) ) . ')';
		}
		$sql .= ' ORDER BY requested_start_utc ASC LIMIT 500';
		$rows = (array) $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore -- prepared above.
		return array_map(
			static fn( $r ) => [
				'id'             => (int) $r['id'],
				'public_ref'     => (string) $r['public_ref'],
				'requested_date' => (string) $r['requested_date'],
				'requested_time' => substr( (string) $r['requested_time'], 0, 5 ),
				'party_size'     => (int) $r['party_size'],
				'status'         => (string) $r['status'],
			],
			$rows
		);
	}

	/**
	 * Impact of replacing a weekly set (all future reservations are re-checked).
	 *
	 * @param list<array{weekday:int,period_no:int,label:string,opens_at:string,closes_at:string,last_order_at:?string}> $periods
	 * @return list<array<string,mixed>>
	 */
	public static function preview_week( int $branch_id, ?string $valid_from, ?string $valid_until, array $periods ): array {
		$rows = array_map(
			static fn( $p ) => [
				'weekday'         => (int) $p['weekday'],
				'period_no'       => (int) $p['period_no'],
				'label'           => (string) $p['label'],
				'opens_at'        => (string) $p['opens_at'],
				'closes_at'       => (string) $p['closes_at'],
				'closes_next_day' => $p['closes_at'] < $p['opens_at'],
				'last_order_at'   => $p['last_order_at'] ?: null,
				'valid_from'      => $valid_from,
				'valid_until'     => $valid_until,
			],
			$periods
		);
		ScheduleService::flush();
		ScheduleService::overlay_week( $branch_id, $valid_from, $valid_until, $rows );
		try {
			return self::invalid( $branch_id, self::future_active_reservations( $branch_id ) );
		} finally {
			ScheduleService::clear_overlay();
			ScheduleService::flush();
		}
	}

	/**
	 * Impact of setting (or deleting, $type = null) the closure record of each date. The range
	 * checked is each date and the following calendar date (after-midnight slots).
	 *
	 * @param list<string> $dates
	 * @param list<array{label:string,opens_at:string,closes_at:string,last_order_at:?string}> $periods
	 * @return list<array<string,mixed>>
	 */
	public static function preview_closure( int $branch_id, array $dates, ?string $type, array $periods = [] ): array {
		$record = null;
		if ( null !== $type ) {
			$record = [
				'closure_type'  => $type,
				'public_reason' => 'other',
				'periods'       => ClosureType::SpecialHours->value === $type ? array_map(
					static fn( $p, $i ) => [
						'period_no'       => $i + 1,
						'label'           => $p['label'],
						'opens_at'        => $p['opens_at'],
						'closes_at'       => $p['closes_at'],
						'closes_next_day' => $p['closes_at'] < $p['opens_at'],
						'last_order_at'   => $p['last_order_at'] ?: null,
					],
					array_values( $periods ),
					array_keys( array_values( $periods ) )
				) : [],
			];
		}
		$check = [];
		foreach ( $dates as $d ) {
			$check[] = $d;
			$check[] = ( new \DateTimeImmutable( $d, Clock::tz() ) )->modify( '+1 day' )->format( 'Y-m-d' );
		}
		ScheduleService::flush();
		foreach ( $dates as $d ) {
			ScheduleService::overlay_closure( $branch_id, $d, null === $record ? null : $record + [ 'closure_date' => $d ] );
		}
		try {
			return self::invalid( $branch_id, self::future_active_reservations( $branch_id, array_values( array_unique( $check ) ) ) );
		} finally {
			ScheduleService::clear_overlay();
			ScheduleService::flush();
		}
	}

	/**
	 * Status → temporarily_closed / kill switch: every future pending/confirmed reservation.
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function preview_all( int $branch_id ): array {
		return self::future_active_reservations( $branch_id );
	}

	/** Branch stopped/started accepting (status or `momo_res_enabled`). Fired after commit. */
	public static function accepting_changed( int $branch_id, bool $accepting, string $reason ): void {
		\Momos\Kernel\Events\AfterCommit::dispatch( 'momos/branch/accepting_changed', $branch_id, $accepting, $reason );
	}

	/**
	 * Default slot validity under the (possibly overlaid) schedule: the requested time lies in a
	 * period of its business date (open ≤ t ≤ last order, or < close without LO), including the
	 * after-midnight part of the previous date's period.
	 */
	public static function slot_valid( int $branch_id, string $date_ymd, string $time_hm ): bool {
		$t = Clock::jst_at( $date_ymd, $time_hm );
		$prev = ( new \DateTimeImmutable( $date_ymd, Clock::tz() ) )->modify( '-1 day' )->format( 'Y-m-d' );
		foreach ( [ $prev, $date_ymd ] as $d ) {
			foreach ( ScheduleService::intervals( $branch_id, $d ) as $iv ) {
				$end_ok = null !== $iv['lo'] ? $t <= $iv['lo'] : $t < $iv['close'];
				if ( $t >= $iv['open'] && $end_ok ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * @param list<array<string,mixed>> $reservations
	 * @return list<array<string,mixed>>
	 */
	private static function invalid( int $branch_id, array $reservations ): array {
		$out = [];
		foreach ( $reservations as $r ) {
			$valid = self::slot_valid( $branch_id, $r['requested_date'], $r['requested_time'] );
			/**
			 * Let the Reservation module decide with its SlotGenerator (lead time/window ignored).
			 *
			 * @param bool                $valid
			 * @param array<string,mixed> $reservation id, public_ref, requested_date, requested_time, party_size, status
			 * @param int                 $branch_id
			 */
			$valid = (bool) apply_filters( 'momos/branch/slot_valid', $valid, $r, $branch_id );
			if ( ! $valid ) {
				$out[] = $r;
			}
		}
		return $out;
	}
}
