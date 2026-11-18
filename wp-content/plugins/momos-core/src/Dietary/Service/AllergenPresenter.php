<?php
declare(strict_types=1);

namespace Momos\Dietary\Service;

use Momos\Dietary\Allergens;
use Momos\Dietary\Domain\AllergenState;
use Momos\Kernel\Clock\Clock;
use Momos\Kernel\I18n\Lang;
use Momos\Kernel\I18n\Translations;

/**
 * Allergen block data (FR-DIET-005/006, Doc 11 §9.2/§9.4, spec §3.13, TC-DIET-011/012/027).
 * Order: summary → banner → legend → grouped table (mandatory 9 → recommended 20 → other) →
 * last verified → voluntary disclaimer → cross-contact (only when confirmed) → tell staff.
 * Never summarises as "free from"; missing data reads `not_verified`. Variants use their own data.
 * Contract: docs/CONTRACTS.md (`for_dish`).
 */
final class AllergenPresenter {
	/**
	 * States of a dish: code => state for the 29 reference items and every custom item (class other).
	 *
	 * @return array<string,string>
	 */
	public static function states( int $dish_id ): array {
		$out = [];
		foreach ( Allergens::codes() as $code ) {
			$v            = (string) get_post_meta( $dish_id, Allergens::meta_key( $code ), true );
			$out[ $code ] = in_array( $v, AllergenState::values(), true ) ? $v : AllergenState::NotVerified->value;
		}
		$custom = [];
		foreach ( (array) get_post_meta( $dish_id, 'momo_alg_custom', true ) as $row ) {
			if ( is_array( $row ) && isset( $row['term_id'] ) ) {
				$custom[ (int) $row['term_id'] ] = in_array( (string) ( $row['state'] ?? '' ), AllergenState::values(), true ) ? (string) $row['state'] : AllergenState::NotVerified->value;
			}
		}
		foreach ( Allergens::custom_terms() as $t ) {
			$code         = (string) get_term_meta( $t->term_id, 'momo_alg_code', true );
			$code         = '' !== $code ? $code : 'term_' . $t->term_id;
			$out[ $code ] = $custom[ (int) $t->term_id ] ?? AllergenState::NotVerified->value;
		}
		return $out;
	}

	/** @return list<string> */
	public static function not_verified_codes( int $dish_id ): array {
		return array_keys( array_filter( self::states( $dish_id ), static fn( $s ) => AllergenState::NotVerified->value === $s ) );
	}

	/** Conservative filter tokens: codes in contains/may_contain/not_verified (+ `review`). @return list<string> */
	public static function filter_codes( int $dish_id ): array {
		$codes = array_keys( array_filter( self::states( $dish_id ), static fn( $s ) => AllergenState::NotContained->value !== $s ) );
		if ( '1' === (string) get_post_meta( $dish_id, 'momo_allergen_review_required', true ) ) {
			$codes[] = 'review';
		}
		return $codes;
	}

	/** @return list<array{code:string,label:string}> allergens available in the exclusion filter (named in $lang) */
	public static function filter_allergens( string $lang ): array {
		$out = [];
		foreach ( self::catalogue( $lang ) as $code => $a ) {
			if ( '' !== $a['name'] ) {
				$out[] = [ 'code' => $code, 'label' => $a['name'] ];
			}
		}
		return $out;
	}

	/** @param list<string> $codes @return list<string> names in $lang (code when unnamed) */
	public static function names( array $codes, string $lang ): array {
		$cat = self::catalogue( $lang );
		return array_map( static fn( $c ) => ( $cat[ $c ]['name'] ?? '' ) !== '' ? $cat[ $c ]['name'] : $c, $codes );
	}

	/**
	 * @return array<string,mixed> see class doc; strings are plain text in $lang
	 */
	public static function for_dish( int $dish_id, string $lang ): array {
		$states  = self::states( $dish_id );
		$cat     = self::catalogue( $lang );
		$review  = '1' === (string) get_post_meta( $dish_id, 'momo_allergen_review_required', true );
		$by      = [ 'contains' => [], 'may_contain' => [], 'not_verified' => [] ];
		$groups  = [
			'mandatory'   => [ 'key' => 'mandatory', 'label' => momos_t( 'allergen.group.mandatory', [], $lang ), 'rows' => [] ],
			'recommended' => [ 'key' => 'recommended', 'label' => momos_t( 'allergen.group.recommended', [], $lang ), 'rows' => [] ],
			'other'       => [ 'key' => 'other', 'label' => momos_t( 'allergen.group.other', [], $lang ), 'rows' => [] ],
		];
		$complete_names = true;
		foreach ( $cat as $code => $a ) {
			$state = $states[ $code ] ?? AllergenState::NotVerified->value;
			if ( '' === $a['name'] ) {
				$complete_names = false;
			}
			if ( isset( $by[ $state ] ) ) {
				$by[ $state ][] = $a['name'];
			}
			$groups[ $a['class'] ]['rows'][] = [
				'code'        => $code,
				'term_id'     => $a['term_id'],
				'name'        => $a['name'],
				'state'       => $state,
				'state_label' => momos_t( 'allergen.state.' . $state, [], $lang ),
				'icon_id'     => $a['icon_id'],
			];
		}
		if ( ! $groups['other']['rows'] ) {
			unset( $groups['other'] );
		}
		$sep      = momos_t( 'diet.list.separator', [], $lang );
		$verified = Clock::from_mysql_utc( (string) get_post_meta( $dish_id, 'momo_claims_verified_at', true ) );
		$cross    = StatementRepository::cross_contact()['confirmed'];
		return [
			'complete'        => ! $by['not_verified'] && ! $review,
			'review_required' => $review,
			'names_complete'  => $complete_names,
			'contains'        => $by['contains'],
			'may_contain'     => $by['may_contain'],
			'not_verified'    => $by['not_verified'],
			'summary'         => [
				'contains'    => $by['contains'] ? momos_t( 'allergen.summary.contains', [ 'list' => implode( $sep, $by['contains'] ) ], $lang ) : null,
				'may_contain' => $by['may_contain'] ? momos_t( 'allergen.summary.may_contain', [ 'list' => implode( $sep, $by['may_contain'] ) ], $lang ) : null,
			],
			'banner'          => ( $by['not_verified'] || $review ) ? momos_t( 'allergen.banner.not_verified', [], $lang ) : null,
			'legend'          => array_combine( AllergenState::values(), array_map( static fn( $s ) => momos_t( 'allergen.state.' . $s, [], $lang ), AllergenState::values() ) ),
			'groups'          => array_values( $groups ),
			'last_verified'   => $verified ? momos_t( 'allergen.last_verified', [ 'date' => ClaimResolver::format_date( $verified->setTimezone( Clock::tz() )->format( 'Y-m-d' ), $lang ) ], $lang ) : null,
			'disclaimers'     => array_filter(
				[
					'voluntary'     => momos_t( 'allergen.disclaimer.voluntary', [], $lang ),
					'cross_contact' => $cross ? momos_t( 'allergen.cross_contact', [], $lang ) : null,
					'tell_staff'    => momos_t( 'allergen.tell_staff', [], $lang ),
				]
			),
			'labels'          => [
				'caption'     => momos_t( 'allergen.table.caption', [], $lang ),
				'allergen'    => momos_t( 'allergen.table.allergen', [], $lang ),
				'status'      => momos_t( 'allergen.table.status', [], $lang ),
				'toggle'      => momos_t( 'allergen.toggle.relevant', [], $lang ),
			],
		];
	}

	/**
	 * Reference + custom allergens in display order with names in $lang ('' when not approved).
	 *
	 * @return array<string,array{name:string,class:string,term_id:int,icon_id:int}>
	 */
	public static function catalogue( string $lang ): array {
		$terms = Allergens::terms_by_code();
		$out   = [];
		foreach ( Allergens::config() as $a ) {
			$t    = $terms[ $a['code'] ] ?? null;
			$name = $t ? Translations::term( $t, 'name', $lang ) : ( Lang::SOURCE === $lang ? $a['ja'] : ( 'en' === $lang ? $a['en'] : '' ) );
			$out[ $a['code'] ] = [ 'name' => $name, 'class' => $a['class'], 'term_id' => $t ? (int) $t->term_id : 0, 'icon_id' => $t ? (int) get_term_meta( $t->term_id, 'momo_alg_icon', true ) : 0 ];
		}
		foreach ( Allergens::custom_terms() as $t ) {
			$code         = (string) get_term_meta( $t->term_id, 'momo_alg_code', true );
			$code         = '' !== $code ? $code : 'term_' . $t->term_id;
			$out[ $code ] = [ 'name' => Translations::term( $t, 'name', $lang ), 'class' => 'other', 'term_id' => (int) $t->term_id, 'icon_id' => (int) get_term_meta( $t->term_id, 'momo_alg_icon', true ) ];
		}
		return $out;
	}
}
