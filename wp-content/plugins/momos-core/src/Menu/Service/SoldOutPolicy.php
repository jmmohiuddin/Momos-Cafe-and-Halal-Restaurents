<?php
declare(strict_types=1);

namespace Momos\Menu\Service;

use Momos\Kernel\Clock\Clock;

/**
 * Sold-out defaults and limits (BR-MENU-04, FR-MENU-007, spec §3.5).
 *
 * Default `sold_out_until` = close of the branch's last opening period of the current JST business day
 * (including `closes_next_day`). If now is inside a period that began yesterday and closes after
 * midnight, that period's close is used. No period / computed value ≤ now → 23:59 JST today.
 */
final class SoldOutPolicy {
	public const MAX_DAYS = 7;

	public static function default_until( int $branch_id, ?\DateTimeImmutable $now = null ): \DateTimeImmutable {
		$now       = ( $now ?? Clock::utc() )->setTimezone( Clock::tz() );
		$today     = $now->format( 'Y-m-d' );
		$yesterday = $now->modify( '-1 day' )->format( 'Y-m-d' );
		$fallback  = Clock::jst_at( $today, '23:59:00' );

		// Overnight period that started yesterday and is still running.
		foreach ( self::periods( $branch_id, $yesterday ) as $p ) {
			if ( ! empty( $p['closes_next_day'] ) ) {
				$close = Clock::jst_at( $today, (string) $p['closes_at'] );
				if ( $close > $now ) {
					return $close->setTimezone( new \DateTimeZone( 'UTC' ) );
				}
			}
		}
		$last = null;
		foreach ( self::periods( $branch_id, $today ) as $p ) {
			$close = Clock::jst_at( $today, (string) $p['closes_at'] );
			if ( ! empty( $p['closes_next_day'] ) ) {
				$close = $close->modify( '+1 day' );
			}
			if ( null === $last || $close > $last ) {
				$last = $close;
			}
		}
		$result = ( null !== $last && $last > $now ) ? $last : $fallback;
		if ( $result <= $now ) {
			$result = $now->modify( '+1 minute' ); // 23:59–24:00 edge: still a future value.
		}
		return $result->setTimezone( new \DateTimeZone( 'UTC' ) );
	}

	/** Supplied value must be > now and ≤ now + 7 days. */
	public static function is_valid_until( \DateTimeImmutable $until, ?\DateTimeImmutable $now = null ): bool {
		$now = $now ?? Clock::utc();
		return $until > $now && $until <= $now->modify( '+' . self::MAX_DAYS . ' days' );
	}

	/** @return list<array<string,mixed>> */
	private static function periods( int $branch_id, string $date ): array {
		$periods = [];
		if ( class_exists( \Momos\Branch\Service\ScheduleService::class ) ) {
			try {
				$periods = (array) \Momos\Branch\Service\ScheduleService::periods_on( $branch_id, $date );
			} catch ( \Throwable $e ) {
				\Momos\Kernel\Log\Logger::warning( 'menu.soldout_default_hours_failed', [ 'branch_id' => $branch_id, 'error' => $e->getMessage() ] );
			}
		}
		/** Effective opening periods used for the sold-out default (extension/test seam). */
		return (array) apply_filters( 'momos/menu/branch_periods', $periods, $branch_id, $date );
	}
}
