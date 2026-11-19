<?php
declare(strict_types=1);

namespace Momos\Presentation\View;

use Momos\Kernel\Clock\Clock;
use Momos\Kernel\I18n\Lang;
use Momos\Kernel\I18n\Locale;
use Momos\Kernel\I18n\Translations;
use Momos\Presentation\Format\DateFormatter;
use Momos\Presentation\Helpers\Bdi;

/**
 * View model of SCR-C05 Branch detail (screens.md §3.5; branch.md §4.5): hours table, closures in the next
 * 30 days, address + access lines, facilities (unknown omitted, FR-BR-003), map links (DEC-029: links
 * only, no Maps JS) and photos. Every reader returns an empty value when the Branch module is absent or
 * the data is missing — the template then omits the section (documented empty state).
 */
final class BranchView {
	public const CLOSURE_DAYS = 30;

	/**
	 * CMP-HoursTable rows Mon..Sun: day label, opening ranges and last orders as isolated LTR HTML.
	 *
	 * @return list<array{weekday:int,day:string,hours_html:string,lo_html:string,today:bool,closed:bool}>
	 */
	public static function hours( int $branch_id, ?string $lang = null ): array {
		$lang = $lang ?? Locale::current();
		if ( ! class_exists( \Momos\Branch\Service\ScheduleService::class ) ) {
			return [];
		}
		try {
			$week = \Momos\Branch\Service\ScheduleService::week( $branch_id );
		} catch ( \Throwable $e ) {
			return [];
		}
		$has_any = false;
		foreach ( $week as $periods ) {
			$has_any = $has_any || [] !== $periods;
		}
		if ( ! $has_any ) {
			return []; // No hours recorded yet: section omitted (never "please inquire").
		}
		$today = (int) Clock::jst()->format( 'N' );
		$rows  = [];
		foreach ( $week as $wd => $periods ) {
			$ranges = [];
			$los    = [];
			foreach ( $periods as $p ) {
				$ranges[] = DateFormatter::time( (string) $p['opens_at'] ) . '–' . DateFormatter::time( (string) $p['closes_at'] );
				if ( ! empty( $p['last_order_at'] ) ) {
					$los[] = DateFormatter::time( (string) $p['last_order_at'] );
				}
			}
			$rows[] = [
				'weekday'    => (int) $wd,
				'day'        => momos_t( 'branch.weekday_long.' . (int) $wd, [], $lang ),
				'hours_html' => $ranges ? self::joined( $ranges ) : esc_html( momos_t( 'branch.closed_day', [], $lang ) ),
				'lo_html'    => $los ? self::joined( $los ) : '',
				'today'      => (int) $wd === $today,
				'closed'     => ! $ranges,
			];
		}
		return $rows;
	}

	/**
	 * CMP-ClosureList: closures and special hours in the next 30 days (public reason only).
	 *
	 * @return list<array{date_html:string,text_html:string}>
	 */
	public static function closures( int $branch_id, ?string $lang = null ): array {
		$lang = $lang ?? Locale::current();
		if ( ! class_exists( \Momos\Branch\Repository\ClosureRepository::class ) || ! class_exists( \Momos\Branch\Service\ScheduleService::class ) ) {
			return [];
		}
		try {
			$from  = Clock::jst()->format( 'Y-m-d' );
			$to    = Clock::jst()->modify( '+' . self::CLOSURE_DAYS . ' day' )->format( 'Y-m-d' );
			$dates = array_keys( \Momos\Branch\Repository\ClosureRepository::group_by_date( \Momos\Branch\Repository\ClosureRepository::rows_between( $branch_id, $from, $to ) ) );
		} catch ( \Throwable $e ) {
			return [];
		}
		sort( $dates );
		$out = [];
		foreach ( $dates as $date ) {
			$c = \Momos\Branch\Service\ScheduleService::closure_on( $branch_id, (string) $date );
			if ( null === $c ) {
				continue;
			}
			$reason = '' !== (string) $c['public_reason'] ? momos_t( 'closure_reason.' . $c['public_reason'], [], $lang ) : '';
			if ( 'closed_all_day' === $c['closure_type'] ) {
				$text = esc_html( momos_t( 'branch.closed_day', [], $lang ) );
			} else {
				$ranges = array_map( static fn( $p ) => DateFormatter::time( (string) $p['opens_at'] ) . '–' . DateFormatter::time( (string) $p['closes_at'] ), $c['periods'] );
				$text   = esc_html( momos_t( 'branch.special_hours', [], $lang ) ) . ' ' . self::joined( $ranges );
			}
			if ( '' !== $reason ) {
				$text .= ' — ' . esc_html( $reason );
			}
			$out[] = [ 'date_html' => esc_html( DateFormatter::long( (string) $date, $lang ) ), 'text_html' => $text ];
		}
		return $out;
	}

	/**
	 * Localised address lines + the Japanese address (always shown, copyable).
	 *
	 * @return array{local:string,ja:string,postal:string}
	 */
	public static function address( int $branch_id, ?string $lang = null ): array {
		$lang  = $lang ?? Locale::current();
		$ja    = (string) get_post_meta( $branch_id, 'momo_address_ja', true );
		$local = '';
		if ( Lang::SOURCE !== $lang ) {
			$parts = [];
			foreach ( [ 'momo_address_line2', 'momo_address_line1', 'momo_city', 'momo_prefecture' ] as $k ) {
				$v = trim( Translations::post( $branch_id, $k, $lang ) );
				if ( '' !== $v ) {
					$parts[] = $v;
				}
			}
			$local = implode( momos_t( 'diet.list.separator', [], $lang ), $parts );
		}
		return [ 'local' => $local, 'ja' => $ja, 'postal' => (string) get_post_meta( $branch_id, 'momo_postal_code', true ) ];
	}

	/**
	 * Access lines "{station} · Exit {exit} · {n} min walk" (+ translated notes). Untranslated rows are
	 * omitted in that language (no mixed language).
	 *
	 * @return array{lines:list<string>,notes:string}
	 */
	public static function access( int $branch_id, ?string $lang = null ): array {
		$lang  = $lang ?? Locale::current();
		$rows  = get_post_meta( $branch_id, 'momo_access_directions', true );
		$lines = [];
		foreach ( is_array( $rows ) ? array_values( $rows ) : [] as $i => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$station = Lang::SOURCE === $lang ? (string) ( $row['station'] ?? '' ) : Translations::post( $branch_id, 'access_station_' . ( $i + 1 ), $lang );
			if ( '' === trim( $station ) ) {
				continue;
			}
			$parts = [ esc_html( $station ) ];
			$exit  = Lang::SOURCE === $lang ? (string) ( $row['exit'] ?? '' ) : Translations::post( $branch_id, 'access_exit_' . ( $i + 1 ), $lang );
			if ( '' !== trim( $exit ) ) {
				$parts[] = Bdi::t( 'branch.access_exit', [ 'exit' => $exit ], [ 'exit' ], $lang );
			}
			$min = (int) ( $row['walking_minutes'] ?? 0 );
			if ( $min > 0 ) {
				$parts[] = Bdi::t( 'branch.access_walk', [ 'minutes' => $min ], [ 'minutes' ], $lang );
			}
			$lines[] = implode( ' · ', $parts );
		}
		return [ 'lines' => $lines, 'notes' => trim( Translations::post( $branch_id, 'momo_access_notes', $lang ) ) ];
	}

	/**
	 * CMP-FacilityList rows (confirmed values only; `unknown`/empty omitted).
	 *
	 * @return list<array{label:string,value:string,icon:string}>
	 */
	public static function facilities( int $branch_id, ?string $lang = null ): array {
		$lang   = $lang ?? Locale::current();
		$m      = static fn( string $k ) => get_post_meta( $branch_id, $k, true );
		$out    = [];
		$single = [
			'prayer'    => [ 'momo_prayer_space', [ 'yes', 'no', 'nearby' ], 'compass' ],
			'smoking'   => [ 'momo_smoking_policy', [ 'non_smoking', 'smoking_area' ], 'ban' ],
			'step_free' => [ 'momo_step_free_access', [ 'yes', 'partial', 'no' ], 'accessibility' ],
			'wifi'      => [ 'momo_wifi', [ 'yes', 'no' ], 'wifi' ],
		];
		foreach ( $single as $key => [ $meta, $allowed, $icon ] ) {
			$v = (string) $m( $meta );
			if ( ! in_array( $v, $allowed, true ) ) {
				continue;
			}
			$value = momos_t( 'facility.' . $key . '.' . $v, [], $lang );
			if ( 'prayer' === $key ) {
				$note   = trim( Translations::post( $branch_id, 'momo_prayer_note', $lang ) );
				$value .= '' !== $note ? ' — ' . $note : '';
			}
			$out[ $key ] = [ 'label' => momos_t( 'facility.' . $key . '.label', [], $lang ), 'value' => $value, 'icon' => $icon ];
		}
		foreach ( [ 'seating' => [ 'momo_seating_types', 'utensils' ], 'payment' => [ 'momo_payment_methods', 'credit-card' ] ] as $key => [ $meta, $icon ] ) {
			$vals = array_values( array_filter( (array) $m( $meta ), static fn( $v ) => is_string( $v ) && '' !== $v ) );
			if ( ! $vals ) {
				continue;
			}
			$labels      = array_map( static fn( $v ) => momos_t( 'facility.' . $key . '.' . $v, [], $lang ), $vals );
			$out[ $key ] = [ 'label' => momos_t( 'facility.' . $key . '.label', [], $lang ), 'value' => implode( momos_t( 'facility.list_separator', [], $lang ), $labels ), 'icon' => $icon ];
		}
		// Display order of the spec table (CMP-FacilityList).
		$order = [ 'prayer', 'smoking', 'step_free', 'seating', 'payment', 'wifi' ];
		return array_values( array_filter( array_map( static fn( $k ) => $out[ $k ] ?? null, $order ) ) );
	}

	/** "Open in Google Maps" link (search URL; no Maps JS, DEC-029). '' without coordinates/address. */
	public static function maps_url( array $branch ): string {
		if ( null !== ( $branch['lat'] ?? null ) && null !== ( $branch['lng'] ?? null ) ) {
			$args = [ 'api' => '1', 'query' => $branch['lat'] . ',' . $branch['lng'] ];
			if ( '' !== (string) ( $branch['place_id'] ?? '' ) ) {
				$args['query_place_id'] = (string) $branch['place_id'];
			}
			return add_query_arg( array_map( 'rawurlencode', $args ), 'https://www.google.com/maps/search/' );
		}
		if ( '' !== (string) ( $branch['address_ja'] ?? '' ) ) {
			return add_query_arg( [ 'api' => '1', 'query' => rawurlencode( (string) $branch['address_ja'] ) ], 'https://www.google.com/maps/search/' );
		}
		return '';
	}

	/** @return list<int> real branch photos (featured + `momo_gallery`; illustrative images are refused on save). */
	public static function photos( int $branch_id ): array {
		$ids = array_filter( array_merge( [ (int) get_post_thumbnail_id( $branch_id ) ], array_map( 'intval', (array) get_post_meta( $branch_id, 'momo_gallery', true ) ) ) );
		return array_values( array_unique( $ids ) );
	}

	/** @param list<string> $parts */
	private static function joined( array $parts ): string {
		return implode( ' · ', array_map( static fn( $p ) => Bdi::ltr( $p ), $parts ) );
	}
}
