<?php
declare(strict_types=1);

namespace Momos\Branch\Service;

use Momos\Branch\Domain\PeriodLabel;
use Momos\Branch\Repository\HoursRepository;

/**
 * Weekly-hours and special-hours rules (FR-BR-006/007, spec §4.4/§4.6).
 *
 * Input periods use 24 h `HH:MM` text (full-width / Eastern Arabic digits accepted). A period
 * whose close < open crosses midnight (`closes_next_day`), its length is < 24 h.
 */
final class HoursValidator {
	public const MAX_PERIODS = 6;

	/** Weekday names for error labels (admin language). */
	public static function weekday_name( int $w ): string {
		$names = [
			1 => __( 'Monday', 'momos-core' ),
			2 => __( 'Tuesday', 'momos-core' ),
			3 => __( 'Wednesday', 'momos-core' ),
			4 => __( 'Thursday', 'momos-core' ),
			5 => __( 'Friday', 'momos-core' ),
			6 => __( 'Saturday', 'momos-core' ),
			7 => __( 'Sunday', 'momos-core' ),
		];
		return $names[ $w ] ?? (string) $w;
	}

	/** ASCII digits + `HH:MM`, or null when not a valid time. "24:00" is read as "00:00". */
	public static function normalize_time( mixed $raw ): ?string {
		if ( ! is_scalar( $raw ) ) {
			return null;
		}
		$s = trim( self::ascii_digits( (string) $raw ) );
		$s = str_replace( [ '：', '.', ' ' ], [ ':', ':', '' ], $s );
		if ( '' === $s ) {
			return null;
		}
		if ( preg_match( '/^(\d{1,2}):?(\d{2})$/', $s, $m ) ) {
			$h = (int) $m[1];
			$i = (int) $m[2];
			if ( 24 === $h && 0 === $i ) {
				$h = 0;
			}
			if ( $h <= 23 && $i <= 59 ) {
				return sprintf( '%02d:%02d', $h, $i );
			}
		}
		return null;
	}

	/** Full-width and Eastern Arabic / Devanagari / Bengali digits → ASCII (Kernel Digits equivalent). */
	public static function ascii_digits( string $s ): string {
		static $map = null;
		if ( null === $map ) {
			$map = [];
			foreach ( [ 0xFF10, 0x0660, 0x06F0, 0x0966, 0x09E6 ] as $base ) {
				for ( $d = 0; $d <= 9; $d++ ) {
					$map[ mb_chr( $base + $d, 'UTF-8' ) ] = (string) $d;
				}
			}
		}
		return strtr( $s, $map );
	}

	/**
	 * Validate a weekly set. Returns [errors, periods] where errors maps a field key (e.g. "w1p2")
	 * to a message and periods is the normalised list ready for HoursRepository::replace_week().
	 *
	 * @param array<int|string, mixed> $input weekday => list of {label, opens, closes, last_order}
	 * @return array{0: array<string,string>, 1: list<array{weekday:int,period_no:int,label:string,opens_at:string,closes_at:string,last_order_at:?string}>}
	 */
	public static function validate_week( array $input, bool $lo_required ): array {
		$errors  = [];
		$periods = [];
		$by_day  = [];
		for ( $w = 1; $w <= 7; $w++ ) {
			$rows                         = isset( $input[ $w ] ) && is_array( $input[ $w ] ) ? $input[ $w ] : [];
			[ $day_errors, $day_periods ] = self::validate_day( $rows, $lo_required, 'w' . $w, self::weekday_name( $w ) );
			$errors                      += $day_errors;
			$by_day[ $w ]                 = $day_periods;
		}
		// Overlap with the after-midnight tail of the previous weekday (weekday 1's previous = 7).
		for ( $w = 1; $w <= 7; $w++ ) {
			$prev = 1 === $w ? 7 : $w - 1;
			foreach ( $by_day[ $prev ] as $pp ) {
				[ , $pc ] = self::minutes( $pp );
				if ( $pc <= 1440 ) {
					continue;
				}
				$tail_end = $pc - 1440;
				foreach ( $by_day[ $w ] as $i => $p ) {
					[ $o ] = self::minutes( $p );
					if ( $o < $tail_end ) {
						$errors[ 'w' . $w . 'p' . ( $i + 1 ) ] ??= self::label_for( self::weekday_name( $w ), $i + 1 ) . __( 'Periods on the same day must not overlap.', 'momos-core' );
					}
				}
			}
		}
		foreach ( $by_day as $w => $list ) {
			foreach ( $list as $i => $p ) {
				$periods[] = [ 'weekday' => $w, 'period_no' => $i + 1 ] + $p;
			}
		}
		return [ $errors, $periods ];
	}

	/**
	 * Validate special-hours periods of a closure record.
	 *
	 * @param array<int|string,mixed> $rows
	 * @return array{0: array<string,string>, 1: list<array{label:string,opens_at:string,closes_at:string,last_order_at:?string}>}
	 */
	public static function validate_special( array $rows, bool $lo_required ): array {
		[ $errors, $periods ] = self::validate_day( $rows, $lo_required, 's', __( 'Special hours', 'momos-core' ) );
		if ( ! $periods && ! $errors ) {
			$errors['s'] = __( "Add at least one period or choose 'Closed all day'.", 'momos-core' );
		}
		return [ $errors, $periods ];
	}

	/**
	 * Dated-set rules: valid_until ≥ valid_from; at most one dated set valid on any date.
	 *
	 * @return array<string,string>
	 */
	public static function validate_set_dates( int $branch_id, ?string $valid_from, ?string $valid_until, ?string $editing_from = null, ?string $editing_until = null ): array {
		if ( null === $valid_from && null === $valid_until ) {
			return [];
		}
		$msg = __( 'Schedules with dates must not overlap.', 'momos-core' );
		if ( null === $valid_from || ( null !== $valid_until && $valid_until < $valid_from ) ) {
			return [ 'validity' => $msg ];
		}
		foreach ( HoursRepository::sets( $branch_id ) as $set ) {
			if ( null === $set['valid_from'] ) {
				continue;
			}
			if ( $set['valid_from'] === $editing_from && $set['valid_until'] === $editing_until ) {
				continue;
			}
			$a_end = $valid_until ?? '9999-12-31';
			$b_end = $set['valid_until'] ?? '9999-12-31';
			if ( $valid_from <= $b_end && $set['valid_from'] <= $a_end ) {
				return [ 'validity' => $msg ];
			}
		}
		return [];
	}

	/**
	 * @param array<int|string,mixed> $rows
	 * @return array{0: array<string,string>, 1: list<array{label:string,opens_at:string,closes_at:string,last_order_at:?string}>}
	 */
	private static function validate_day( array $rows, bool $lo_required, string $key_prefix, string $day_name ): array {
		$errors  = [];
		$periods = [];
		$n       = 0;
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$raw_open  = trim( (string) ( $row['opens'] ?? $row['opens_at'] ?? '' ) );
			$raw_close = trim( (string) ( $row['closes'] ?? $row['closes_at'] ?? '' ) );
			$raw_lo    = trim( (string) ( $row['last_order'] ?? $row['last_order_at'] ?? '' ) );
			if ( '' === $raw_open && '' === $raw_close && '' === $raw_lo ) {
				continue; // Empty row.
			}
			++$n;
			$key   = $key_prefix . 'p' . $n;
			$label = self::label_for( $day_name, $n );
			if ( $n > self::MAX_PERIODS ) {
				$errors[ $key ] = $label . sprintf( /* translators: %d: maximum */ __( 'A day can have at most %d periods.', 'momos-core' ), self::MAX_PERIODS );
				continue;
			}
			$open  = self::normalize_time( $raw_open );
			$close = self::normalize_time( $raw_close );
			$lo    = '' === $raw_lo ? null : self::normalize_time( $raw_lo );
			$plab  = (string) ( $row['label'] ?? PeriodLabel::AllDay->value );
			if ( null === $open || null === $close || ( '' !== $raw_lo && null === $lo ) ) {
				$errors[ $key ] = $label . __( 'Enter times as HH:MM (24-hour).', 'momos-core' );
				continue;
			}
			if ( ! PeriodLabel::is_valid( $plab ) ) {
				$errors[ $key ] = $label . __( 'Choose a valid period type.', 'momos-core' );
				continue;
			}
			if ( $open === $close ) {
				$errors[ $key ] = $label . __( 'Opening and closing time cannot be the same.', 'momos-core' );
				continue;
			}
			$p = [ 'label' => $plab, 'opens_at' => $open, 'closes_at' => $close, 'last_order_at' => $lo ];
			if ( null !== $lo ) {
				[ $o, $c, $l ] = self::minutes( $p );
				if ( $l < $o || $l >= $c ) {
					$errors[ $key ] = $label . __( 'Last order must be between opening and closing time.', 'momos-core' );
					continue;
				}
			} elseif ( $lo_required ) {
				$errors[ $key ] = $label . __( 'Add a last-order time to every period while online requests are on.', 'momos-core' );
				continue;
			}
			$periods[] = $p;
		}
		// Overlaps within the day.
		$sorted = $periods;
		usort( $sorted, static fn( $a, $b ) => self::minutes( $a )[0] <=> self::minutes( $b )[0] );
		for ( $i = 1, $c = count( $sorted ); $i < $c; $i++ ) {
			if ( self::minutes( $sorted[ $i ] )[0] < self::minutes( $sorted[ $i - 1 ] )[1] ) {
				$idx = array_search( $sorted[ $i ], $periods, true );
				$errors[ $key_prefix . 'p' . ( (int) $idx + 1 ) ] ??= self::label_for( $day_name, (int) $idx + 1 ) . __( 'Periods on the same day must not overlap.', 'momos-core' );
			}
		}
		// Keep chronological order so period_no follows the day.
		return [ $errors, $errors ? $periods : $sorted ];
	}

	/**
	 * Open, close and last-order minutes from the business-day start (close/LO + 1440 after midnight).
	 *
	 * @param array{opens_at:string,closes_at:string,last_order_at:?string} $p
	 * @return array{0:int,1:int,2:?int}
	 */
	public static function minutes( array $p ): array {
		$o = self::to_min( $p['opens_at'] );
		$c = self::to_min( $p['closes_at'] );
		if ( $c <= $o ) {
			$c += 1440;
		}
		$l = null;
		if ( null !== ( $p['last_order_at'] ?? null ) ) {
			$l = self::to_min( (string) $p['last_order_at'] );
			if ( $l < $o ) {
				$l += 1440;
			}
		}
		return [ $o, $c, $l ];
	}

	private static function to_min( string $hm ): int {
		[ $h, $m ] = array_map( 'intval', explode( ':', $hm ) );
		return $h * 60 + $m;
	}

	private static function label_for( string $day, int $n ): string {
		/* translators: 1: weekday or "Special hours" 2: period number */
		return sprintf( __( '%1$s, period %2$d: ', 'momos-core' ), $day, $n );
	}
}
