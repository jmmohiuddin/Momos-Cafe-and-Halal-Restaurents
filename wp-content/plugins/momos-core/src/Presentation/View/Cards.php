<?php
declare(strict_types=1);

namespace Momos\Presentation\View;

use Momos\Kernel\Clock\Clock;
use Momos\Kernel\I18n\Translations;
use Momos\Kernel\I18n\Url;
use Momos\Presentation\Format\DateFormatter;
use Momos\Presentation\Format\PhoneFormatter;
use Momos\Presentation\Format\PriceFormatter;
use Momos\Presentation\Helpers\Bdi;
use Momos\Presentation\SystemPages;

/**
 * View models for card components (CMP-BranchCard, CMP-OfferCard, CMP-OpenStatus). Pure reads through
 * module services; every method returns null when the owning module or the translation is missing
 * (the component then renders nothing — documented empty state).
 */
final class Cards {
	/** @return array<string,mixed>|null */
	public static function branch( int $branch_id, ?string $lang = null ): ?array {
		$lang = $lang ?? momos_lang();
		if ( ! class_exists( \Momos\Branch\Repository\BranchRepository::class ) ) {
			return null;
		}
		$b = \Momos\Branch\Repository\BranchRepository::get( $branch_id );
		if ( null === $b ) {
			return null;
		}
		$name = \Momos\Branch\Repository\BranchRepository::name( $branch_id, $lang );
		if ( '' === $name ) {
			return null;
		}
		$accepting = ActionBar::accepting( $branch_id, $b );
		return [
			'id'          => $branch_id,
			'code'        => $b['code'],
			'name'        => $name,
			'concept'     => Translations::post( $branch_id, 'momo_concept_label', $lang ),
			'area'        => Translations::post( $branch_id, 'momo_area_label', $lang ),
			'address'     => trim( Translations::post( $branch_id, 'momo_address_line1', $lang ) . ' ' . Translations::post( $branch_id, 'momo_address_line2', $lang ) ),
			'address_ja'  => (string) $b['address_ja'],
			'phone'       => '' !== $b['phone_e164'] ? PhoneFormatter::display( $b['phone_e164'], $b['phone_display'], $lang ) : '',
			'tel'         => '' !== $b['phone_e164'] ? PhoneFormatter::tel_href( $b['phone_e164'] ) : '',
			'url'         => Url::localize( (string) get_permalink( $branch_id ), $lang ),
			'request_url' => $accepting ? add_query_arg( 'branch', $b['code'], (string) SystemPages::url( 'reservation', $lang ) ) : '',
			'directions'  => ActionBar::directions_url( $b ),
			'temp_closed' => 'temporarily_closed' === $b['operating_status'],
			'open'        => self::open_status( $branch_id, $lang ),
		];
	}

	/**
	 * CMP-OpenStatus text (times isolated). Null when the schedule service is absent or fails (hidden;
	 * the hours table stays).
	 *
	 * @return array{state:string,html:string}|null
	 */
	public static function open_status( int $branch_id, ?string $lang = null ): ?array {
		$lang = $lang ?? momos_lang();
		if ( ! class_exists( \Momos\Branch\Service\ScheduleService::class ) ) {
			return null;
		}
		try {
			$s = \Momos\Branch\Service\ScheduleService::open_status( $branch_id );
		} catch ( \Throwable $e ) {
			return null;
		}
		if ( 'temporarily_closed' === ( $s['closed_reason'] ?? '' ) ) {
			$reopen = (string) get_post_meta( $branch_id, 'momo_reopen_date', true );
			$html   = '' !== $reopen
				? Bdi::t( 'branch.temp_closed', [ 'date' => DateFormatter::short( $reopen, $lang ) ], [ 'date' ], $lang )
				: esc_html( momos_t( 'branch.temp_closed_nodate', [], $lang ) );
			return [ 'state' => 'temp_closed', 'html' => $html ];
		}
		if ( 'closed_permanently' === ( $s['closed_reason'] ?? '' ) ) {
			return null;
		}
		$until = isset( $s['until'] ) ? DateFormatter::time( (string) $s['until'] ) : '';
		switch ( $s['state'] ) {
			case 'open':
				return [
					'state' => 'open',
					'html'  => ! empty( $s['last_order'] )
						? Bdi::t( 'branch.open_now', [ 'time' => $until, 'lo' => DateFormatter::time( (string) $s['last_order'] ) ], [ 'time', 'lo' ], $lang )
						: Bdi::t( 'branch.open_now_nolo', [ 'time' => $until ], [ 'time' ], $lang ),
				];
			case 'closing_soon':
				return [ 'state' => 'closing', 'html' => Bdi::t( 'branch.closing_soon', [ 'time' => $until ], [ 'time' ], $lang ) ];
			default:
				$next = $s['next_open'] ?? null;
				if ( ! is_array( $next ) ) {
					return null;
				}
				$today = Clock::jst()->format( 'Y-m-d' );
				$day   = $next['date'] === $today ? momos_t( 'branch.day.today', [], $lang )
					: ( $next['date'] === Clock::jst()->modify( '+1 day' )->format( 'Y-m-d' ) ? momos_t( 'branch.day.tomorrow', [], $lang ) : DateFormatter::short( $next['date'], $lang ) );
				return [ 'state' => 'closed', 'html' => Bdi::t( 'branch.closed_now', [ 'day' => $day, 'time' => DateFormatter::time( (string) $next['time'] ) ], [ 'time' ], $lang ) ];
		}
	}

	/** @return array<string,mixed>|null */
	public static function offer( int $offer_id, ?string $lang = null ): ?array {
		$lang = $lang ?? momos_lang();
		if ( ! class_exists( \Momos\Branch\Query\OfferQuery::class ) ) {
			return null;
		}
		$title = Translations::post( $offer_id, 'title', $lang );
		if ( '' === $title ) {
			return null;
		}
		$start = Clock::from_mysql_utc( (string) get_post_meta( $offer_id, '_momo_offer_start_utc', true ) ?: null );
		$end   = Clock::from_mysql_utc( (string) get_post_meta( $offer_id, '_momo_offer_end_utc', true ) ?: null );
		$scope = [];
		if ( 'all_branches' === \Momos\Branch\Query\OfferQuery::scope( $offer_id ) ) {
			$scope[] = momos_t( 'offers.scope.all', [], $lang );
		} else {
			foreach ( \Momos\Branch\Query\OfferQuery::card_branch_ids( $offer_id ) as $bid ) {
				$n = \Momos\Branch\Repository\BranchRepository::name( (int) $bid, $lang );
				if ( '' !== $n ) {
					$scope[] = $n;
				}
			}
		}
		$price = get_post_meta( $offer_id, 'momo_offer_price_jpy', true );
		$valid = '';
		if ( $start && $end ) {
			$valid = Bdi::t( 'offers.valid', [ 'start' => DateFormatter::long( $start, $lang ), 'end' => DateFormatter::long( $end, $lang ) ], [ 'start', 'end' ], $lang );
		}
		return [
			'id'           => $offer_id,
			'title'        => $title,
			'summary'      => Translations::post( $offer_id, 'momo_offer_summary', $lang ),
			'conditions'   => Translations::post( $offer_id, 'momo_offer_conditions', $lang ),
			'valid_html'   => $valid,
			'scope'        => $scope,
			'price_html'   => '' !== (string) $price ? PriceFormatter::html( (int) $price, $lang ) : '',
			'image_id'     => (int) get_post_thumbnail_id( $offer_id ),
			'illustrative' => '1' === (string) get_post_meta( (int) get_post_thumbnail_id( $offer_id ), 'momo_image_illustrative', true ) || '1' === (string) get_post_meta( $offer_id, 'momo_image_illustrative', true ),
			'cta'          => \Momos\Branch\Query\OfferQuery::cta( $offer_id, $lang ),
		];
	}
}
