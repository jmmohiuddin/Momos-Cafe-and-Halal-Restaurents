<?php
declare(strict_types=1);

namespace Momos\Seo\Schema;

use Momos\Kernel\Clock\Clock;

/**
 * `openingHoursSpecification` of a branch (FR-SEO-006(b), AC-SEO-02-02/04, i18n-seo-analytics.md §10)
 * built only from ScheduleService (JST), so it always equals the visible hours:
 *  1. regular entries = the weekly set effective today (cross-midnight periods keep the next-day close);
 *  2. every date in the next 60 days whose resolved periods differ from the regular week (closure,
 *     special hours, a dated set starting) gets date-bound entries (validFrom = validThrough = date);
 *     closed dates use opens = closes = "00:00" (C-15);
 *  3. `temporarily_closed`: each date up to `momo_reopen_date` (or the whole window) is emitted closed.
 */
final class OpeningHours {
	public const WINDOW_DAYS = 60;

	public const DAYS = [ 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday' ];

	/** @return list<array<string,string>> */
	public static function for_branch( int $branch_id, ?\DateTimeImmutable $now = null ): array {
		if ( ! class_exists( \Momos\Branch\Service\ScheduleService::class ) ) {
			return [];
		}
		$svc   = \Momos\Branch\Service\ScheduleService::class;
		$today = ( $now ?? Clock::utc() )->setTimezone( Clock::tz() );
		$week  = $svc::week( $branch_id );
		$out   = [];
		foreach ( $week as $weekday => $periods ) {
			foreach ( $periods as $p ) {
				$out[] = self::entry( (int) $weekday, (string) $p['opens_at'], (string) $p['closes_at'] );
			}
		}

		$status = (string) get_post_meta( $branch_id, 'momo_operating_status', true );
		$reopen = (string) get_post_meta( $branch_id, 'momo_reopen_date', true );
		$end    = $today->modify( '+' . ( self::WINDOW_DAYS - 1 ) . ' days' )->format( 'Y-m-d' );
		$dates  = self::dates_to_check( $branch_id, $today->format( 'Y-m-d' ), $end );

		for ( $i = 0; $i < self::WINDOW_DAYS; $i++ ) {
			$day     = $today->modify( '+' . $i . ' days' );
			$date    = $day->format( 'Y-m-d' );
			$weekday = (int) $day->format( 'N' );
			if ( 'temporarily_closed' === $status && ( '' === $reopen || $date < $reopen ) ) {
				$out[] = self::entry( $weekday, '00:00', '00:00', $date );
				continue;
			}
			if ( null !== $dates && ! isset( $dates[ $date ] ) ) {
				continue;
			}
			$periods = $svc::periods_on( $branch_id, $date );
			if ( self::signature( $periods ) === self::signature( $week[ $weekday ] ?? [] ) ) {
				continue;
			}
			if ( [] === $periods ) {
				$out[] = self::entry( $weekday, '00:00', '00:00', $date );
				continue;
			}
			foreach ( $periods as $p ) {
				$out[] = self::entry( $weekday, (string) $p['opens_at'], (string) $p['closes_at'], $date );
			}
		}
		return $out;
	}

	/**
	 * Dates that can differ from the regular week: closure dates, or every date when a dated hours set
	 * starts/ends inside the window. null = check every date.
	 *
	 * @return array<string,bool>|null
	 */
	private static function dates_to_check( int $branch_id, string $from, string $to ): ?array {
		if ( ! class_exists( \Momos\Branch\Repository\ClosureRepository::class ) || ! class_exists( \Momos\Branch\Repository\HoursRepository::class ) ) {
			return null;
		}
		foreach ( \Momos\Branch\Repository\HoursRepository::sets( $branch_id ) as $set ) {
			$vf = $set['valid_from'];
			$vu = $set['valid_until'];
			if ( ( null !== $vf && $vf > $from && $vf <= $to ) || ( null !== $vu && $vu >= $from && $vu < $to ) ) {
				return null;
			}
		}
		$out = [];
		foreach ( \Momos\Branch\Repository\ClosureRepository::rows_between( $branch_id, $from, $to ) as $r ) {
			$out[ (string) $r['closure_date'] ] = true;
		}
		return $out;
	}

	/** @param list<array<string,mixed>> $periods */
	private static function signature( array $periods ): string {
		return implode( ';', array_map( static fn( $p ) => $p['opens_at'] . '-' . $p['closes_at'], $periods ) );
	}

	/** @return array<string,string> */
	private static function entry( int $weekday, string $opens, string $closes, ?string $date = null ): array {
		$e = [
			'@type'     => 'OpeningHoursSpecification',
			'dayOfWeek' => 'https://schema.org/' . self::DAYS[ $weekday ],
			'opens'     => substr( $opens, 0, 5 ),
			'closes'    => substr( $closes, 0, 5 ),
		];
		if ( null !== $date ) {
			$e['validFrom']    = $date;
			$e['validThrough'] = $date;
		}
		return $e;
	}
}
