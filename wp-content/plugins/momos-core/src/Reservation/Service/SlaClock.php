<?php
declare(strict_types=1);

namespace Momos\Reservation\Service;

use Momos\Branch\Repository\BranchSettingsRepository;
use Momos\Branch\Service\ScheduleService;
use Momos\Kernel\Clock\Clock;

/**
 * Response-time clock (DEC-013, FR-RES-017, spec §3.10). wall_clock counts elapsed minutes;
 * business_hours counts only minutes inside resolved opening periods (special hours and
 * after-midnight tails included), so a request received while closed starts counting at the next
 * opening. The same clock drives the SLA job and the inbox sla_state.
 */
final class SlaClock {
	/** Longest span evaluated in business-hours mode (days). */
	private const MAX_DAYS = 31;

	public static function minutes( int $branch_id, \DateTimeImmutable $from, \DateTimeImmutable $to, ?string $mode = null ): int {
		if ( $to <= $from ) {
			return 0;
		}
		$mode = $mode ?? BranchSettingsRepository::get( $branch_id )['sla_clock'];
		if ( 'business_hours' !== $mode ) {
			return intdiv( $to->getTimestamp() - $from->getTimestamp(), 60 );
		}
		$from_jst = $from->setTimezone( Clock::tz() );
		$to_jst   = $to->setTimezone( Clock::tz() );
		$day      = $from_jst->setTime( 0, 0 )->modify( '-1 day' );
		$last     = $to_jst->setTime( 0, 0 );
		$seconds  = 0;
		for ( $i = 0; $day <= $last && $i <= self::MAX_DAYS + 1; $i++, $day = $day->modify( '+1 day' ) ) {
			foreach ( ScheduleService::intervals( $branch_id, $day->format( 'Y-m-d' ) ) as $iv ) {
				$start = max( $iv['open']->getTimestamp(), $from->getTimestamp() );
				$end   = min( $iv['close']->getTimestamp(), $to->getTimestamp() );
				if ( $end > $start ) {
					$seconds += $end - $start;
				}
			}
		}
		return intdiv( $seconds, 60 );
	}

	/**
	 * Inbox SLA state: none (SLA off / not pending), ok (< 75 %), due (75–99 %), overdue (≥ SLA).
	 *
	 * @param array<string,mixed> $row
	 */
	public static function state( array $row, ?\DateTimeImmutable $now = null ): string {
		if ( 'pending' !== ( $row['status'] ?? 'pending' ) ) {
			return 'none';
		}
		$settings = BranchSettingsRepository::get( (int) $row['branch_id'] );
		$sla      = (int) $settings['response_sla_minutes'];
		if ( $sla <= 0 ) {
			return 'none';
		}
		$elapsed = self::minutes( (int) $row['branch_id'], Clock::from_mysql_utc( (string) $row['created_at'] ), $now ?? Clock::utc(), $settings['sla_clock'] );
		if ( $elapsed >= $sla ) {
			return 'overdue';
		}
		return $elapsed * 100 >= $sla * 75 ? 'due' : 'ok';
	}
}
