<?php
declare(strict_types=1);

namespace Momos\Branch\Query;

use Momos\Branch\Domain\OfferCta;
use Momos\Branch\Domain\OfferScope;
use Momos\Branch\Domain\OfferState;
use Momos\Branch\Domain\OperatingStatus;
use Momos\Branch\PostType\BranchScopeTaxonomy;
use Momos\Branch\Repository\BranchRepository;
use Momos\Branch\Service\ValidityMeta;
use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Env;
use Momos\Kernel\Events\AfterCommit;
use Momos\Kernel\I18n\Lang;
use Momos\Kernel\I18n\Translations;

/**
 * Active offers for the presentation layer (FR-CORP-011, spec §4.11, DEC-060).
 *
 * Active ⇔ publish ∧ start ≤ now < end ∧ (not sample in production) ∧ (lang = ja ∨ approved
 * translation) ∧ (scope all_branches ∨ ≥ 1 scoped branch that is published, non-test and open).
 * Order: momo_offer_order asc, then start asc. All comparisons in UTC.
 */
final class OfferQuery {
	/** Featured offers on the home page ⟦3 — Q-414⟧. */
	public const HOME_LIMIT = 3;

	/** Option holding the last known "has active offers" state per language (nav item, DEC-060). */
	public const STATE_OPTION = 'momos_offer_active_langs';

	/** @return list<int> */
	public static function all_active( ?string $lang = null, ?\DateTimeImmutable $now = null ): array {
		return self::query( $lang ?? Lang::SOURCE, $now );
	}

	/** @return list<int> */
	public static function home_featured( ?string $lang = null, int $limit = self::HOME_LIMIT ): array {
		$ids = array_values( array_filter( self::all_active( $lang ), static fn( $id ) => '1' === (string) get_post_meta( $id, 'momo_offer_homepage_featured', true ) ) );
		return array_slice( $ids, 0, max( 0, $limit ) );
	}

	/**
	 * Offers shown on a branch page; nothing when the branch is not open (spec §4.11).
	 *
	 * @return list<int>
	 */
	public static function for_branch( int $branch_id, ?string $lang = null ): array {
		if ( ! self::branch_eligible( $branch_id ) ) {
			return [];
		}
		return array_values(
			array_filter(
				self::all_active( $lang ),
				static fn( $id ) => OfferScope::AllBranches->value === self::scope( $id ) || in_array( $branch_id, BranchScopeTaxonomy::branch_ids_of_post( $id ), true )
			)
		);
	}

	public static function has_active( ?string $lang = null ): bool {
		return [] !== self::all_active( $lang );
	}

	/**
	 * Published offers starting within the next $hours (rendered hidden with data-start/end so a
	 * cached page can reveal them at the boundary).
	 *
	 * @return list<int>
	 */
	public static function upcoming( ?string $lang = null, int $hours = 24 ): array {
		$now = Clock::utc();
		return self::query( $lang ?? Lang::SOURCE, $now, $now->modify( '+' . $hours . ' hours' ) );
	}

	/** @return list<int> active featured offers (source language), for the featured-limit warning */
	public static function featured_active_ids(): array {
		return array_values( array_filter( self::all_active( Lang::SOURCE ), static fn( $id ) => '1' === (string) get_post_meta( $id, 'momo_offer_homepage_featured', true ) ) );
	}

	public static function is_active( int $offer_id, ?string $lang = null, ?\DateTimeImmutable $now = null ): bool {
		return in_array( $offer_id, self::query( $lang ?? Lang::SOURCE, $now, null, [ $offer_id ] ), true );
	}

	/** Computed state (Draft · Pending review · Scheduled · Active · Expired). */
	public static function state( int $offer_id, ?\DateTimeImmutable $now = null ): string {
		$status = get_post_status( $offer_id );
		if ( 'pending' === $status ) {
			return OfferState::Pending->value;
		}
		if ( 'publish' !== $status ) {
			return OfferState::Draft->value;
		}
		$now   = $now ?? Clock::utc();
		$start = ValidityMeta::instant( $offer_id, 'start' );
		$end   = ValidityMeta::instant( $offer_id, 'end' );
		if ( null !== $end && $now >= $end ) {
			return OfferState::Expired->value;
		}
		if ( null !== $start && $now < $start ) {
			return OfferState::Scheduled->value;
		}
		return OfferState::Active->value;
	}

	public static function scope( int $offer_id ): string {
		$s = (string) get_post_meta( $offer_id, 'momo_offer_scope', true );
		return OfferScope::is_valid( $s ) ? $s : OfferScope::Branches->value;
	}

	/**
	 * Branches named on the card: [] for all-branches (render `offers.scope.all`), else scoped
	 * branches that are published, non-test and open.
	 *
	 * @return list<int>
	 */
	public static function card_branch_ids( int $offer_id ): array {
		if ( OfferScope::AllBranches->value === self::scope( $offer_id ) ) {
			return [];
		}
		return array_values( array_filter( BranchScopeTaxonomy::branch_ids_of_post( $offer_id ), [ self::class, 'branch_eligible' ] ) );
	}

	/**
	 * CTA resolution (spec §4.11): ['type','url','label'] or null for `none`.
	 *
	 * @return array{type:string,url:string,label:string}|null
	 */
	public static function cta( int $offer_id, ?string $lang = null ): ?array {
		$lang     = $lang ?? Lang::SOURCE;
		$type     = (string) get_post_meta( $offer_id, 'momo_offer_cta_type', true ) ?: OfferCta::RequestTable->value;
		$branches = self::card_branch_ids( $offer_id );
		$single   = 1 === count( $branches ) ? (string) get_post_meta( $branches[0], 'momo_branch_code', true ) : '';
		$label    = Translations::post( $offer_id, 'momo_offer_cta_label', $lang );
		switch ( $type ) {
			case OfferCta::None->value:
				return null;
			case OfferCta::Dish->value:
				$dish = (int) get_post_meta( $offer_id, 'momo_offer_cta_dish', true );
				if ( $dish && 'publish' === get_post_status( $dish ) && '1' !== (string) get_post_meta( $dish, 'momo_dish_retired', true ) ) {
					return [ 'type' => 'dish', 'url' => \Momos\Kernel\I18n\Url::localize( (string) get_permalink( $dish ), $lang ), 'label' => '' !== $label ? $label : momos_t( 'cta.view_menu', [], $lang ) ];
				}
				// Dish no longer published → falls back to the menu (WF-CUS-15 A4).
				// no break.
			case OfferCta::Menu->value:
				$url  = momos_url( '/menu/', $lang );
				$term = (int) get_post_meta( $offer_id, 'momo_offer_cta_category', true );
				if ( OfferCta::Menu->value === $type && $term && taxonomy_exists( 'momo_menu_category' ) ) {
					$link = get_term_link( $term, 'momo_menu_category' );
					$url  = is_string( $link ) ? \Momos\Kernel\I18n\Url::localize( $link, $lang ) : $url;
				}
				if ( '' !== $single ) {
					$url = add_query_arg( 'branch', $single, $url );
				}
				return [ 'type' => 'menu', 'url' => $url, 'label' => '' !== $label ? $label : momos_t( 'cta.view_menu', [], $lang ) ];
			default:
				$url = momos_url( '/reservation/', $lang );
				if ( '' !== $single ) {
					$url = add_query_arg( 'branch', $single, $url );
				}
				$url = add_query_arg( 'offer', $offer_id, $url );
				return [ 'type' => 'request_table', 'url' => $url, 'label' => '' !== $label ? $label : momos_t( 'cta.request_table', [], $lang ) ];
		}
	}

	/**
	 * Detect 0 ↔ ≥1 transitions of active offers per enabled language and fire
	 * `momos/offer/visibility_changed(offer_id, global)` (header "Offers" item, DEC-060).
	 */
	public static function sync_visibility( int $offer_id = 0 ): bool {
		$prev   = (array) get_option( self::STATE_OPTION, [] );
		$now    = [];
		$global = false;
		foreach ( Lang::enabled() as $lang ) {
			$now[ $lang ] = self::has_active( $lang );
			if ( (bool) ( $prev[ $lang ] ?? false ) !== $now[ $lang ] ) {
				$global = true;
			}
		}
		if ( $now !== $prev ) {
			update_option( self::STATE_OPTION, $now, true );
		}
		AfterCommit::dispatch( 'momos/offer/visibility_changed', $offer_id, $global );
		$tags = [ 'momo-offers', 'momo-home' ];
		foreach ( $offer_id ? self::affected_branch_ids( $offer_id ) : [] as $b ) {
			$tags[] = 'momo-branch-' . $b;
		}
		if ( $global ) {
			$tags[] = 'momo-global';
		}
		do_action( 'momos/cache/purge', $tags );
		return $global;
	}

	/** @return list<int> branches whose page shows the offer */
	public static function affected_branch_ids( int $offer_id ): array {
		return OfferScope::AllBranches->value === self::scope( $offer_id ) ? BranchRepository::published_ids( false ) : BranchScopeTaxonomy::branch_ids_of_post( $offer_id );
	}

	/** Published, non-test and open (offers are hidden on closed branches). */
	public static function branch_eligible( int $branch_id ): bool {
		$b = BranchRepository::get( $branch_id );
		return $b && 'publish' === $b['status'] && ! $b['is_test'] && OperatingStatus::Open->value === $b['operating_status'];
	}

	/**
	 * Active at $now, or (with $window_end) published offers starting in ($now, $window_end].
	 *
	 * @param list<int>|null $only restrict to these IDs
	 * @return list<int>
	 */
	private static function query( string $lang, ?\DateTimeImmutable $now = null, ?\DateTimeImmutable $window_end = null, ?array $only = null ): array {
		if ( ! Lang::is_valid( $lang ) || ! Lang::is_enabled( $lang ) ) {
			return [];
		}
		$now  = $now ?? Clock::utc();
		$meta = [ 'relation' => 'AND' ];
		if ( null === $window_end ) {
			$meta[] = [ 'key' => '_momo_offer_start_utc', 'value' => Clock::mysql_utc( $now ), 'compare' => '<=', 'type' => 'DATETIME' ];
		} else {
			$meta[] = [ 'key' => '_momo_offer_start_utc', 'value' => Clock::mysql_utc( $now ), 'compare' => '>', 'type' => 'DATETIME' ];
			$meta[] = [ 'key' => '_momo_offer_start_utc', 'value' => Clock::mysql_utc( $window_end ), 'compare' => '<=', 'type' => 'DATETIME' ];
		}
		$meta[] = [ 'key' => '_momo_offer_end_utc', 'value' => Clock::mysql_utc( $now ), 'compare' => '>', 'type' => 'DATETIME' ];
		if ( Env::is_production() ) {
			$meta[] = [
				'relation' => 'OR',
				[ 'key' => 'momo_is_sample', 'compare' => 'NOT EXISTS' ],
				[ 'key' => 'momo_is_sample', 'value' => '1', 'compare' => '!=' ],
			];
		}
		foreach ( Translations::meta_query( $lang ) as $clause ) {
			$meta[] = $clause;
		}
		$args = [
			'post_type'        => 'momo_offer',
			'post_status'      => 'publish',
			'posts_per_page'   => 200,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => true,
			'meta_query'       => $meta, // phpcs:ignore
		];
		if ( null !== $only ) {
			$args['post__in'] = $only ?: [ 0 ];
		}
		$ids = array_map( 'intval', get_posts( $args ) );
		$ids = array_values(
			array_filter(
				$ids,
				static fn( $id ) => OfferScope::AllBranches->value === self::scope( $id ) || [] !== array_filter( BranchScopeTaxonomy::branch_ids_of_post( $id ), [ self::class, 'branch_eligible' ] )
			)
		);
		usort(
			$ids,
			static fn( $a, $b ) => [ (int) get_post_meta( $a, 'momo_offer_order', true ), (string) get_post_meta( $a, '_momo_offer_start_utc', true ), $a ]
				<=> [ (int) get_post_meta( $b, 'momo_offer_order', true ), (string) get_post_meta( $b, '_momo_offer_start_utc', true ), $b ]
		);
		return $ids;
	}
}
