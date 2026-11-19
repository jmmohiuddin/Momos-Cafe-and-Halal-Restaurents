<?php
declare(strict_types=1);

namespace Momos\Branch\Service;

use Momos\Branch\Domain\ClosureType;
use Momos\Branch\Domain\OperatingStatus;
use Momos\Branch\Repository\ClosureRepository;
use Momos\Branch\Repository\HoursRepository;
use Momos\Kernel\Clock\Clock;

/**
 * Schedule resolution and open-now (BR-BR-05, spec §4.5, SDD §5.5). All dates/times are JST
 * business-local (DEC-050); the device time zone is never used.
 *
 * Business date b = JST calendar date on which a period opens. Resolution for b:
 *  1. closure record for b → closed_all_day ⇒ no periods; special_hours ⇒ only special periods;
 *  2. else weekly rows for weekday(b) from the dated set covering b (if it has rows for that weekday);
 *  3. else default-set rows for weekday(b); 4. else closed.
 * A closure on X removes business day X incl. its after-midnight tail on X+1, not the tail of X−1.
 *
 * Reads are request-cached and flushed on every write; ClosureImpactService uses an overlay to
 * evaluate a proposed (unsaved) schedule.
 *
 * Request cache (NFR-PERF-02, QA-052): the first closure lookup inside the open-now window (JST
 * yesterday … today + 8) loads the closure rows of every branch for the whole window in one query, and
 * the first weekly lookup loads the weekly rows of every branch in one query, so a page that lists N
 * branches costs two schedule queries instead of ~10 × N. A lookup outside the window loads that
 * branch for 31 days at once. prime() loads an explicit set of branches and dates up front.
 */
final class ScheduleService {
	/** Minutes before closing at which the state turns `closing_soon`. */
	public const CLOSING_SOON_MINUTES = 30;

	/** @var array<int, list<array<string,mixed>>> */
	private static array $weekly = [];

	/** @var array<string, array<string,mixed>|null> "branch|date" => grouped closure record */
	private static array $closures = [];

	/** @var array<int, list<array{0:string,1:string}>> branch => date ranges whose closures are loaded */
	private static array $covered = [];

	/** @var list<array{0:string,1:string}> date ranges whose closures are loaded for every branch */
	private static array $covered_all = [];

	/** Weekly rows of every branch are loaded (branches absent from $weekly have none). */
	private static bool $weekly_all = false;

	/** @var array<int, array{valid_from:?string,valid_until:?string,rows:list<array<string,mixed>>}> */
	private static array $overlay_week = [];

	/** @var array<int, array<string, array<string,mixed>|null>> */
	private static array $overlay_closures = [];

	public static function flush(): void {
		self::$weekly      = [];
		self::$closures    = [];
		self::$covered     = [];
		self::$covered_all = [];
		self::$weekly_all  = false;
	}

	/**
	 * Load the closures/special hours of $branch_ids for the JST dates $from_ymd … $to_ymd (inclusive) and
	 * their weekly rows into the request cache with one query each (NFR-PERF-02). Later per-day lookups
	 * (periods_on, closure_on, open_status, schedule_json, intervals …) are served from memory; writes
	 * through HoursRepository/ClosureRepository flush the cache. Results are identical to unprimed reads.
	 *
	 * @param list<int> $branch_ids
	 */
	public static function prime( array $branch_ids, string $from_ymd, string $to_ymd ): void {
		if ( $to_ymd < $from_ymd ) {
			[ $from_ymd, $to_ymd ] = [ $to_ymd, $from_ymd ];
		}
		$ids  = array_values( array_unique( array_filter( array_map( 'intval', $branch_ids ) ) ) );
		$load = array_values( array_filter( $ids, static fn( $id ) => ! self::range_covered( $id, $from_ymd, $to_ymd ) ) );
		if ( $load ) {
			self::store_closures( ClosureRepository::rows_between_many( $load, $from_ymd, $to_ymd ) );
			foreach ( $load as $id ) {
				self::$covered[ $id ][] = [ $from_ymd, $to_ymd ];
			}
		}
		$weekly = self::$weekly_all ? [] : array_values( array_filter( $ids, static fn( $id ) => ! isset( self::$weekly[ $id ] ) ) );
		if ( $weekly ) {
			$rows = HoursRepository::all_rows_many( $weekly );
			foreach ( $weekly as $id ) {
				self::$weekly[ $id ] = $rows[ $id ] ?? [];
			}
		}
	}

	// ------------------------------------------------------------------ contract (docs/CONTRACTS.md)

	/**
	 * Effective opening periods for a JST business date.
	 *
	 * @return list<array{period_no:int,label:string,opens_at:string,closes_at:string,closes_next_day:bool,last_order_at:?string}>
	 */
	public static function periods_on( int $branch_id, string $date_ymd ): array {
		$closure = self::closure_record( $branch_id, $date_ymd );
		if ( null !== $closure ) {
			if ( ClosureType::ClosedAllDay->value === $closure['closure_type'] ) {
				return [];
			}
			return array_map( [ self::class, 'period' ], $closure['periods'] );
		}
		$weekday = (int) ( new \DateTimeImmutable( $date_ymd, Clock::tz() ) )->format( 'N' );
		return array_map( [ self::class, 'period' ], self::weekly_rows_for( $branch_id, $weekday, $date_ymd ) );
	}

	public static function is_closed_on( int $branch_id, string $date_ymd ): bool {
		return [] === self::periods_on( $branch_id, $date_ymd );
	}

	/**
	 * Public closure record of a date (internal note never included), or null.
	 *
	 * @return array{closure_date:string,closure_type:string,public_reason:string,periods:list<array<string,mixed>>}|null
	 */
	public static function closure_on( int $branch_id, string $date_ymd ): ?array {
		$rec = self::closure_record( $branch_id, $date_ymd );
		if ( null === $rec ) {
			return null;
		}
		return [
			'closure_date'  => $rec['closure_date'],
			'closure_type'  => $rec['closure_type'],
			'public_reason' => $rec['public_reason'],
			'periods'       => array_map( [ self::class, 'period' ], $rec['periods'] ),
		];
	}

	/**
	 * Open-now state in JST.
	 *
	 * @return array{state:string,until:?string,last_order:?string,next_open:?array{date:string,time:string},closed_reason:?string}
	 */
	public static function open_status( int $branch_id, ?\DateTimeImmutable $now = null ): array {
		$now    = ( $now ?? Clock::utc() )->setTimezone( Clock::tz() );
		$today  = $now->format( 'Y-m-d' );
		$result = [ 'state' => 'closed', 'until' => null, 'last_order' => null, 'next_open' => null, 'closed_reason' => null ];
		$status = (string) get_post_meta( $branch_id, 'momo_operating_status', true );
		if ( in_array( $status, [ OperatingStatus::TemporarilyClosed->value, OperatingStatus::ClosedPermanently->value ], true ) ) {
			$result['closed_reason'] = $status;
			return $result;
		}
		$current = self::interval_at( $branch_id, $now );
		if ( null !== $current ) {
			$minutes_left         = (int) floor( ( $current['close']->getTimestamp() - $now->getTimestamp() ) / 60 );
			$after_lo             = null !== $current['lo'] && $now >= $current['lo'];
			$result['state']      = ( $minutes_left <= self::CLOSING_SOON_MINUTES || $after_lo ) ? 'closing_soon' : 'open';
			$result['until']      = $current['close']->format( 'H:i' );
			$result['last_order'] = $current['lo'] ? $current['lo']->format( 'H:i' ) : null;
			return $result;
		}
		$closure = self::closure_on( $branch_id, $today );
		if ( $closure && ClosureType::ClosedAllDay->value === $closure['closure_type'] ) {
			$result['closed_reason'] = $closure['public_reason'];
		}
		for ( $i = 0; $i <= 7; $i++ ) {
			$date = $now->modify( '+' . $i . ' day' )->format( 'Y-m-d' );
			foreach ( self::intervals( $branch_id, $date ) as $iv ) {
				if ( $iv['open'] > $now ) {
					$result['next_open'] = [ 'date' => $iv['open']->format( 'Y-m-d' ), 'time' => $iv['open']->format( 'H:i' ) ];
					$result['state']     = $iv['open']->format( 'Y-m-d' ) === $today ? 'opens_later' : 'closed';
					return $result;
				}
			}
		}
		return $result;
	}

	/**
	 * Weekly table for display: weekday (1=Mon..7=Sun) => periods of the set effective today.
	 *
	 * @return array<int, list<array<string,mixed>>>
	 */
	public static function week( int $branch_id ): array {
		$today = Clock::jst()->format( 'Y-m-d' );
		$out   = [];
		for ( $w = 1; $w <= 7; $w++ ) {
			$out[ $w ] = array_map( [ self::class, 'period' ], self::weekly_rows_for( $branch_id, $w, $today ) );
		}
		return $out;
	}

	// ------------------------------------------------------------------ additional public API

	/** Is the branch open at an instant (period of business date d or d−1 covers it)? */
	public static function is_open_at( int $branch_id, \DateTimeImmutable $instant ): bool {
		return null !== self::interval_at( $branch_id, $instant->setTimezone( Clock::tz() ) );
	}

	/**
	 * Resolved periods of a business date as absolute JST instants.
	 *
	 * @return list<array{open:\DateTimeImmutable,close:\DateTimeImmutable,lo:?\DateTimeImmutable,period:array<string,mixed>}>
	 */
	public static function intervals( int $branch_id, string $date_ymd ): array {
		$out = [];
		foreach ( self::periods_on( $branch_id, $date_ymd ) as $p ) {
			$open  = Clock::jst_at( $date_ymd, $p['opens_at'] );
			$close = Clock::jst_at( $date_ymd, $p['closes_at'] );
			if ( $close <= $open ) {
				$close = $close->modify( '+1 day' );
			}
			$lo = null;
			if ( null !== $p['last_order_at'] ) {
				$lo = Clock::jst_at( $date_ymd, $p['last_order_at'] );
				if ( $lo < $open ) {
					$lo = $lo->modify( '+1 day' );
				}
			}
			$out[] = [ 'open' => $open, 'close' => $close, 'lo' => $lo, 'period' => $p ];
		}
		usort( $out, static fn( $a, $b ) => $a['open'] <=> $b['open'] );
		return $out;
	}

	/**
	 * End of the current business period/day (used by Menu for default sold_out_until):
	 * close of the period covering $now, else close of the last period of today's business date.
	 */
	public static function end_of_business_day( int $branch_id, ?\DateTimeImmutable $now = null ): ?\DateTimeImmutable {
		$now     = ( $now ?? Clock::utc() )->setTimezone( Clock::tz() );
		$current = self::interval_at( $branch_id, $now );
		if ( null !== $current ) {
			return $current['close'];
		}
		$ivs = self::intervals( $branch_id, $now->format( 'Y-m-d' ) );
		return $ivs ? end( $ivs )['close'] : null;
	}

	/**
	 * Cache-safe open-now embed (spec §4.5): business dates yesterday … today + 7.
	 *
	 * @return array<string,mixed>
	 */
	public static function schedule_json( int $branch_id, ?\DateTimeImmutable $now = null ): array {
		$now    = ( $now ?? Clock::utc() )->setTimezone( Clock::tz() );
		$status = (string) get_post_meta( $branch_id, 'momo_operating_status', true );
		$reopen = (string) get_post_meta( $branch_id, 'momo_reopen_date', true );
		$days   = [];
		for ( $i = -1; $i <= 7; $i++ ) {
			$date    = $now->modify( ( $i >= 0 ? '+' : '' ) . $i . ' day' )->format( 'Y-m-d' );
			$closure = self::closure_on( $branch_id, $date );
			$days[]  = [
				'date'          => $date,
				'closed_reason' => $closure && ClosureType::ClosedAllDay->value === $closure['closure_type'] ? $closure['public_reason'] : null,
				'periods'       => array_map(
					static fn( $iv ) => [
						'open'  => $iv['open']->format( 'c' ),
						'close' => $iv['close']->format( 'c' ),
						'lo'    => $iv['lo'] ? $iv['lo']->format( 'c' ) : null,
						'label' => $iv['period']['label'],
					],
					self::intervals( $branch_id, $date )
				),
			];
		}
		return [
			'tz'           => 'Asia/Tokyo',
			'status'       => OperatingStatus::TemporarilyClosed->value === $status ? 'temporarily_closed' : 'open',
			'reopen_date'  => OperatingStatus::TemporarilyClosed->value === $status && '' !== $reopen && $reopen >= $now->format( 'Y-m-d' ) ? $reopen : null,
			'days'         => $days,
			'generated_at' => $now->format( 'c' ),
		];
	}

	// ------------------------------------------------------------------ overlay (impact preview)

	/** @param list<array<string,mixed>> $rows proposed rows of one set (HoursRepository::cast shape) */
	public static function overlay_week( int $branch_id, ?string $valid_from, ?string $valid_until, array $rows ): void {
		self::$overlay_week[ $branch_id ] = [ 'valid_from' => $valid_from, 'valid_until' => $valid_until, 'rows' => $rows ];
	}

	/** @param array<string,mixed>|null $record proposed grouped closure record (null = no closure) */
	public static function overlay_closure( int $branch_id, string $date_ymd, ?array $record ): void {
		self::$overlay_closures[ $branch_id ][ $date_ymd ] = $record;
	}

	public static function clear_overlay(): void {
		self::$overlay_week     = [];
		self::$overlay_closures = [];
	}

	// ------------------------------------------------------------------ internals

	/** @return array{open:\DateTimeImmutable,close:\DateTimeImmutable,lo:?\DateTimeImmutable,period:array<string,mixed>}|null */
	private static function interval_at( int $branch_id, \DateTimeImmutable $jst ): ?array {
		foreach ( [ $jst->modify( '-1 day' )->format( 'Y-m-d' ), $jst->format( 'Y-m-d' ) ] as $date ) {
			foreach ( self::intervals( $branch_id, $date ) as $iv ) {
				if ( $jst >= $iv['open'] && $jst < $iv['close'] ) {
					return $iv;
				}
			}
		}
		return null;
	}

	/** @return array<string,mixed>|null */
	private static function closure_record( int $branch_id, string $date_ymd ): ?array {
		if ( isset( self::$overlay_closures[ $branch_id ] ) && array_key_exists( $date_ymd, self::$overlay_closures[ $branch_id ] ) ) {
			return self::$overlay_closures[ $branch_id ][ $date_ymd ];
		}
		$key = $branch_id . '|' . $date_ymd;
		if ( ! array_key_exists( $key, self::$closures ) ) {
			if ( ! self::range_covered( $branch_id, $date_ymd, $date_ymd ) ) {
				$today = Clock::jst();
				$from  = $today->modify( '-1 day' )->format( 'Y-m-d' );
				$to    = $today->modify( '+8 days' )->format( 'Y-m-d' );
				if ( $date_ymd >= $from && $date_ymd <= $to ) {
					// Open-now window of every branch (directory, home, cards): one query per request.
					self::store_closures( ClosureRepository::rows_between_many( null, $from, $to ) );
					self::$covered_all[] = [ $from, $to ];
				} else {
					self::prime( [ $branch_id ], $date_ymd, ( new \DateTimeImmutable( $date_ymd, Clock::tz() ) )->modify( '+30 days' )->format( 'Y-m-d' ) );
				}
			}
			if ( ! array_key_exists( $key, self::$closures ) ) {
				self::$closures[ $key ] = null;
			}
		}
		return self::$closures[ $key ];
	}

	/** Are the closures of $branch_id for every date in $from … $to already loaded? */
	private static function range_covered( int $branch_id, string $from_ymd, string $to_ymd ): bool {
		foreach ( array_merge( self::$covered_all, self::$covered[ $branch_id ] ?? [] ) as [ $from, $to ] ) {
			if ( $from <= $from_ymd && $to_ymd <= $to ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Group loaded closure rows per branch and date into the request cache.
	 *
	 * @param list<array<string,mixed>> $rows
	 */
	private static function store_closures( array $rows ): void {
		$by_branch = [];
		foreach ( $rows as $r ) {
			$by_branch[ (int) $r['branch_id'] ][] = $r;
		}
		foreach ( $by_branch as $branch_id => $list ) {
			foreach ( ClosureRepository::group_by_date( $list ) as $date => $record ) {
				$key = $branch_id . '|' . $date;
				if ( ! array_key_exists( $key, self::$closures ) ) {
					self::$closures[ $key ] = $record;
				}
			}
		}
	}

	/** @return list<array<string,mixed>> */
	private static function weekly_rows( int $branch_id ): array {
		if ( ! isset( self::$weekly[ $branch_id ] ) && ! self::$weekly_all ) {
			// Every branch's weekly rows in one query (bounded by the branch count, NFR-SCL-01).
			self::$weekly     = HoursRepository::all_rows_many( null ) + self::$weekly;
			self::$weekly_all = true;
		}
		$rows = self::$weekly[ $branch_id ] ?? [];
		if ( isset( self::$overlay_week[ $branch_id ] ) ) {
			$o    = self::$overlay_week[ $branch_id ];
			$rows = array_values( array_filter( $rows, static fn( $r ) => ! ( $r['valid_from'] === $o['valid_from'] && $r['valid_until'] === $o['valid_until'] ) ) );
			$rows = array_merge( $rows, $o['rows'] );
		}
		return $rows;
	}

	/** @return list<array<string,mixed>> rows for a weekday under the set precedence for $date */
	private static function weekly_rows_for( int $branch_id, int $weekday, string $date_ymd ): array {
		$rows   = self::weekly_rows( $branch_id );
		$dated  = array_filter( $rows, static fn( $r ) => null !== $r['valid_from'] && $r['valid_from'] <= $date_ymd && ( null === $r['valid_until'] || $r['valid_until'] >= $date_ymd ) );
		$starts = array_unique( array_map( static fn( $r ) => $r['valid_from'], $dated ) );
		rsort( $starts );
		foreach ( $starts as $start ) {
			$set = array_values( array_filter( $dated, static fn( $r ) => $r['valid_from'] === $start && $r['weekday'] === $weekday ) );
			if ( $set ) {
				return self::sort_periods( $set );
			}
		}
		$default = array_values( array_filter( $rows, static fn( $r ) => null === $r['valid_from'] && null === $r['valid_until'] && $r['weekday'] === $weekday ) );
		return self::sort_periods( $default );
	}

	/**
	 * @param list<array<string,mixed>> $rows
	 * @return list<array<string,mixed>>
	 */
	private static function sort_periods( array $rows ): array {
		usort( $rows, static fn( $a, $b ) => [ $a['opens_at'], $a['period_no'] ] <=> [ $b['opens_at'], $b['period_no'] ] );
		return $rows;
	}

	/**
	 * @param array<string,mixed> $r
	 * @return array{period_no:int,label:string,opens_at:string,closes_at:string,closes_next_day:bool,last_order_at:?string}
	 */
	private static function period( array $r ): array {
		return [
			'period_no'       => (int) $r['period_no'],
			'label'           => (string) ( $r['label'] ?? 'other' ),
			'opens_at'        => (string) $r['opens_at'],
			'closes_at'       => (string) $r['closes_at'],
			'closes_next_day' => (bool) $r['closes_next_day'],
			'last_order_at'   => $r['last_order_at'] ?? null,
		];
	}
}
