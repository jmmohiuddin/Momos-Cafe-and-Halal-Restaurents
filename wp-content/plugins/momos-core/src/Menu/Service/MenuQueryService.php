<?php
declare(strict_types=1);

namespace Momos\Menu\Service;

use Momos\Kernel\Env;
use Momos\Kernel\I18n\Lang;
use Momos\Kernel\I18n\Translations;
use Momos\Menu\ContentTypes;
use Momos\Menu\Repository\BranchMenuItemRepository;
use Momos\Menu\Support\Branches;

/**
 * Public menu queries (FR-MENU-001/002/003/009, BR-MENU-05, spec §3.2) used by theme templates in
 * place of Elementor loop queries (`momo_category_dishes`, `momo_featured_dishes`, `momo_related_dishes`).
 * Contract: docs/CONTRACTS.md. All lists respect the language gate (no mixed-language fallback, DEC-027).
 */
final class MenuQueryService {
	public const FEATURED_DEFAULT = 6;
	public const CATEGORY_LIMIT   = 200;

	/** displayable = published ∧ not retired ∧ ¬(production ∧ sample) ∧ approved in lang. */
	public static function is_displayable( int|\WP_Post $dish, string $lang ): bool {
		$post = get_post( $dish );
		if ( ! $post || ContentTypes::DISH !== $post->post_type || 'publish' !== $post->post_status ) {
			return false;
		}
		if ( '1' === (string) get_post_meta( $post->ID, 'momo_retired', true ) ) {
			return false;
		}
		if ( Env::is_production() && '1' === (string) get_post_meta( $post->ID, 'momo_is_sample', true ) ) {
			return false;
		}
		return Translations::post_visible( $post, $lang );
	}

	/**
	 * Visible categories ordered by `momo_cat_order`, then name in the language. A category is listed only
	 * when approved in the language and it has ≥ 1 displayable dish (offered at the branch, with context).
	 *
	 * @return list<\WP_Term>
	 */
	public static function categories( string $lang, ?int $branch_id = null ): array {
		$terms = get_terms( [ 'taxonomy' => ContentTypes::CATEGORY, 'hide_empty' => false, 'parent' => 0 ] );
		if ( ! is_array( $terms ) ) {
			return [];
		}
		$visible = array_values( array_filter( $terms, static fn( \WP_Term $t ): bool => Translations::term_visible( $t, $lang ) ) );
		// One dish query for all categories instead of one per category (NFR-PERF-02).
		$by_cat = self::dishes_by_category( array_map( static fn( \WP_Term $t ): int => (int) $t->term_id, $visible ), $lang, $branch_id );
		$out    = array_values( array_filter( $visible, static fn( \WP_Term $t ): bool => ! empty( $by_cat[ (int) $t->term_id ] ) ) );
		// Category tiles show `momo_cat_image`: load those attachments in one go instead of one per tile.
		$images = array_filter( array_map( static fn( \WP_Term $t ): int => (int) get_term_meta( $t->term_id, 'momo_cat_image', true ), $out ) );
		if ( $images ) {
			_prime_post_caches( array_values( array_unique( $images ) ), false, true );
		}
		usort(
			$out,
			static function ( \WP_Term $a, \WP_Term $b ) use ( $lang ): int {
				$oa = (int) get_term_meta( $a->term_id, 'momo_cat_order', true );
				$ob = (int) get_term_meta( $b->term_id, 'momo_cat_order', true );
				return $oa <=> $ob ?: strcmp( Translations::term( $a, 'name', $lang ), Translations::term( $b, 'name', $lang ) );
			}
		);
		return $out;
	}

	/**
	 * Displayable dish IDs in a category ordered by menu_order, then title in the language.
	 * With a branch: only dishes with an offered row there.
	 *
	 * @return list<int>
	 */
	public static function dishes_in_category( int $term_id, string $lang, ?int $branch_id = null ): array {
		$ids = self::dishes_by_category( [ $term_id ], $lang, $branch_id, true )[ $term_id ] ?? [];
		// The listed dishes are rendered as cards next: load their branch rows with one query.
		if ( $ids && null === $branch_id ) {
			BranchMenuItemRepository::prime_offered( $ids );
			self::prime_offered_branches( $ids );
		} elseif ( $ids ) {
			BranchMenuItemRepository::prime_rows( $branch_id, $ids );
		}
		return $ids;
	}

	/**
	 * Displayable dish IDs per category (direct assignment, as dishes_in_category) with one dish query
	 * and batched post/meta/term caches — no per-dish queries (NFR-PERF-02, TRD §5).
	 *
	 * @param list<int> $term_ids
	 * @param bool      $cards    the dishes are rendered as cards next (also load tag meta and images)
	 * @return array<int,list<int>> term_id => dish IDs ordered by menu_order, then title in the language
	 */
	private static function dishes_by_category( array $term_ids, string $lang, ?int $branch_id, bool $cards = false ): array {
		$term_ids = array_values( array_unique( array_filter( array_map( 'intval', $term_ids ) ) ) );
		if ( ! $term_ids ) {
			return [];
		}
		$q   = new \WP_Query(
			[
				'post_type'              => ContentTypes::DISH,
				'post_status'            => 'publish',
				'posts_per_page'         => self::CATEGORY_LIMIT * count( $term_ids ),
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'orderby'                => [ 'menu_order' => 'ASC', 'title' => 'ASC' ],
				'tax_query'              => [ [ 'taxonomy' => ContentTypes::CATEGORY, 'field' => 'term_id', 'terms' => $term_ids, 'include_children' => false ] ], // phpcs:ignore
				'meta_query'             => Translations::meta_query( $lang ), // phpcs:ignore
			]
		);
		$ids = array_map( 'intval', $q->posts );
		if ( null !== $branch_id ) {
			$ids = array_values( array_intersect( $ids, BranchMenuItemRepository::offered_dish_ids( $branch_id ) ) );
		}
		if ( ! $ids ) {
			return [];
		}
		self::prime( $ids, $cards );
		$ids    = array_values( array_filter( $ids, static fn( int $id ) => self::is_displayable( $id, $lang ) ) );
		$wanted = array_flip( $term_ids );
		$out    = [];
		foreach ( $ids as $id ) {
			$terms = get_the_terms( $id, ContentTypes::CATEGORY ); // From the primed relationship cache.
			foreach ( is_array( $terms ) ? $terms : [] as $t ) {
				$tid = (int) $t->term_id;
				if ( isset( $wanted[ $tid ] ) && count( $out[ $tid ] ?? [] ) < self::CATEGORY_LIMIT ) {
					$out[ $tid ][] = $id;
				}
			}
		}
		foreach ( $out as $tid => $list ) {
			$out[ $tid ] = self::sort_by_order_and_title( $list, $lang );
		}
		return $out;
	}

	/**
	 * Load posts, meta, taxonomy relationships, dietary-tag meta and featured images of many dishes with a
	 * fixed number of queries, so card rendering does not query per dish.
	 *
	 * @param list<int> $ids
	 * @param bool      $cards also dietary-tag term meta and featured images (card rendering)
	 */
	public static function prime( array $ids, bool $cards = true ): void {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids ) {
			return;
		}
		_prime_post_caches( $ids, false, true );
		update_object_term_cache( $ids, ContentTypes::DISH );
		if ( ! $cards ) {
			return;
		}
		$term_ids = [];
		$thumbs   = [];
		foreach ( $ids as $id ) {
			$terms = get_object_term_cache( $id, ContentTypes::TAG );
			foreach ( is_array( $terms ) ? $terms : [] as $t ) {
				$term_ids[] = (int) ( $t instanceof \WP_Term ? $t->term_id : $t );
			}
			$thumb = (int) get_post_meta( $id, '_thumbnail_id', true );
			if ( $thumb > 0 ) {
				$thumbs[] = $thumb;
			}
		}
		if ( $term_ids ) {
			update_termmeta_cache( array_values( array_unique( $term_ids ) ) );
		}
		if ( $thumbs ) {
			_prime_post_caches( array_values( array_unique( $thumbs ) ), false, true );
		}
	}

	/** @param list<int> $dish_ids load the branch posts behind already primed offered rows */
	private static function prime_offered_branches( array $dish_ids ): void {
		$branches = [];
		foreach ( $dish_ids as $id ) {
			foreach ( BranchMenuItemRepository::offered_for_dish( $id ) as $row ) {
				$branches[] = (int) $row['branch_id'];
			}
		}
		Branches::prime( $branches );
	}

	/**
	 * Home featured dishes: displayable ∧ featured ∧ offered at ≥ 1 public branch.
	 *
	 * @return list<int>
	 */
	public static function featured( string $lang, int $limit = self::FEATURED_DEFAULT ): array {
		$q   = new \WP_Query(
			[
				'post_type'      => ContentTypes::DISH,
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => [ 'menu_order' => 'ASC', 'title' => 'ASC' ],
				'meta_query'     => array_merge( [ [ 'key' => 'momo_featured', 'value' => '1' ] ], Translations::meta_query( $lang ) ), // phpcs:ignore
			]
		);
		$ids = array_map( 'intval', $q->posts );
		if ( ! $ids ) {
			return [];
		}
		$public  = array_values( array_filter( Branches::published_ids(), static fn( int $b ) => Branches::is_public( $b ) ) );
		$offered = BranchMenuItemRepository::dish_ids_offered_anywhere( $public );
		$ids     = array_values( array_intersect( $ids, $offered ) );
		self::prime( $ids );
		$ids = array_slice( array_values( array_filter( $ids, static fn( int $id ) => self::is_displayable( $id, $lang ) ) ), 0, max( 0, $limit ) );
		if ( $ids ) {
			BranchMenuItemRepository::prime_offered( $ids ); // Cards read prices/availability next.
		}
		return $ids;
	}

	/** @return list<int> up to $limit displayable dishes of the same primary category */
	public static function related( int $dish_id, string $lang, int $limit = 4 ): array {
		$primary = (int) get_post_meta( $dish_id, 'momo_primary_category', true );
		if ( $primary <= 0 ) {
			return [];
		}
		$ids = array_values( array_filter( self::dishes_in_category( $primary, $lang ), static fn( int $id ) => $id !== $dish_id && (int) get_post_meta( $id, 'momo_primary_category', true ) === $primary ) );
		return array_slice( $ids, 0, $limit );
	}

	/** Branch menu row when the dish is offered there, else null. */
	public static function branch_offers_dish( int $branch_id, int $dish_id ): ?array {
		$row = BranchMenuItemRepository::find( $branch_id, $dish_id );
		return ( null !== $row && 1 === (int) $row['is_offered'] ) ? $row : null;
	}

	/** @return list<int> public branches offering the dish (dish page "Available at"). */
	public static function branches_offering( int $dish_id ): array {
		$out = [];
		foreach ( BranchMenuItemRepository::offered_for_dish( $dish_id ) as $row ) {
			if ( Branches::is_public( (int) $row['branch_id'] ) ) {
				$out[] = (int) $row['branch_id'];
			}
		}
		return $out;
	}

	/**
	 * `?branch={code}` → branch ID when valid (published, not TEST, not permanently closed); else null
	 * (treated as "All branches", no error — AC-MENU-01-05).
	 */
	public static function branch_context( ?string $code ): ?int {
		if ( null === $code || '' === $code ) {
			return null;
		}
		$id = Branches::id_by_code( sanitize_text_field( $code ) );
		return ( null !== $id && Branches::is_public( $id ) ) ? $id : null;
	}

	/**
	 * Branch selector options: published, non-TEST, open (not temporarily/permanently closed), named in lang.
	 *
	 * @return list<array{id:int,code:string,name:string}>
	 */
	public static function selector_branches( string $lang ): array {
		$out = [];
		foreach ( Branches::published_ids() as $id ) {
			if ( ! Branches::is_public( $id, true ) ) {
				continue;
			}
			$name = Branches::name( $id, $lang );
			if ( '' !== $name ) {
				$out[] = [ 'id' => $id, 'code' => Branches::code( $id ), 'name' => $name ];
			}
		}
		return $out;
	}

	/**
	 * Data for a dish card / client-side filters (FR-MENU-002/008). Strings are plain text in $lang.
	 *
	 * @return array<string,mixed>
	 */
	public static function card( int $dish_id, string $lang, ?int $branch_id = null ): array {
		$post  = get_post( $dish_id );
		$spice = (int) get_post_meta( $dish_id, 'momo_spice_level', true );
		$price = PriceResolver::price( $dish_id, $branch_id );
		$row   = null !== $branch_id ? BranchMenuItemRepository::find( $branch_id, $dish_id ) : null;
		$lead  = '1' === (string) get_post_meta( $dish_id, 'momo_advance_order_required', true ) ? (int) get_post_meta( $dish_id, 'momo_advance_order_lead_hours', true ) : 0;
		$tags  = self::tags( $dish_id, $lang );
		$alg   = class_exists( \Momos\Dietary\Service\AllergenPresenter::class ) ? \Momos\Dietary\Service\AllergenPresenter::filter_codes( $dish_id ) : [];
		$url   = (string) get_permalink( $dish_id );
		return [
			'id'                  => $dish_id,
			'url'                 => \Momos\Kernel\I18n\Url::localize( $url, $lang ) . ( null !== $branch_id ? '?branch=' . rawurlencode( Branches::code( $branch_id ) ) : '' ),
			'name'                => Translations::post( $post, 'title', $lang ),
			'original_name'       => (string) get_post_meta( $dish_id, 'momo_original_name', true ),
			'short_description'   => Translations::post( $post, 'momo_short_description', $lang ),
			'image_id'            => (int) get_post_thumbnail_id( $dish_id ),
			'image_illustrative'  => '1' === (string) get_post_meta( $dish_id, 'momo_image_illustrative', true ),
			'price'               => $price,
			'price_html'          => null !== $price ? PriceFormatter::html( $price, $lang ) : '',
			'prices_may_differ'   => null === $branch_id && PriceResolver::prices_may_differ( $dish_id ),
			'spice'               => $spice,
			'spice_label'         => momos_t( 'spice.' . $spice, [], $lang ),
			'tags'                => $tags,
			'availability'        => null !== $branch_id ? AvailabilityLabel::for_row( $row, $lang ) : AvailabilityLabel::without_branch( $dish_id, $lang ),
			'advance_order_hours' => $lead > 0 ? $lead : null,
			'advance_order_label' => $lead > 0 ? momos_t( 'menu.advance_order', [ 'hours' => $lead ], $lang ) : null,
			'data'                => [
				'data-tags'  => implode( ' ', array_column( $tags, 'code' ) ),
				'data-spice' => (string) $spice,
				'data-alg'   => implode( ' ', $alg ),
			],
		];
	}

	/**
	 * Dietary tags of a dish approved in $lang (claim tags included; they carry `is_claim`).
	 *
	 * @return list<array{id:int,code:string,label:string,is_claim:bool,icon:int}>
	 */
	public static function tags( int $dish_id, string $lang ): array {
		$terms = get_the_terms( $dish_id, ContentTypes::TAG ); // Object term cache (primed for lists), name order.
		$out   = [];
		foreach ( is_array( $terms ) ? $terms : [] as $t ) {
			$label = Translations::term( $t, 'name', $lang );
			if ( '' === $label ) {
				continue; // Not approved in this language: hidden, never mixed.
			}
			$code  = (string) get_term_meta( $t->term_id, 'momo_tag_code', true );
			$out[] = [
				'id'       => (int) $t->term_id,
				'code'     => '' !== $code ? $code : $t->slug,
				'label'    => $label,
				'is_claim' => '1' === (string) get_term_meta( $t->term_id, 'momo_tag_is_claim', true ),
				'icon'     => (int) get_term_meta( $t->term_id, 'momo_tag_icon', true ),
			];
		}
		return $out;
	}

	/**
	 * Filter options for a category page: tags in use on the listed dishes (filters hidden if none) and
	 * allergens for the (C) exclusion filter.
	 *
	 * @param list<int> $dish_ids
	 * @return array{tags:list<array{code:string,label:string}>, allergens:list<array{code:string,label:string}>}
	 */
	public static function filter_options( array $dish_ids, string $lang ): array {
		$tags = [];
		foreach ( $dish_ids as $id ) {
			foreach ( self::tags( $id, $lang ) as $t ) {
				$tags[ $t['code'] ] = [ 'code' => $t['code'], 'label' => $t['label'] ];
			}
		}
		$allergens = class_exists( \Momos\Dietary\Service\AllergenPresenter::class ) ? \Momos\Dietary\Service\AllergenPresenter::filter_allergens( $lang ) : [];
		return [ 'tags' => array_values( $tags ), 'allergens' => $allergens ];
	}

	/**
	 * Parse filter query (`?diet[]=…&max_spice=1&exclude[]=peanut`, spec §5.1 / §11 C12).
	 *
	 * @param array<string,mixed> $query
	 * @return array{diet:list<string>,max_spice:?int,exclude:list<string>}
	 */
	public static function parse_filters( array $query ): array {
		$list = static fn( $v ) => array_values( array_unique( array_filter( array_map( static fn( $x ) => preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $x ) ), (array) $v ) ) ) );
		$max  = isset( $query['max_spice'] ) && is_scalar( $query['max_spice'] ) && preg_match( '/^[0-3]$/', (string) $query['max_spice'] ) ? (int) $query['max_spice'] : null;
		return [ 'diet' => $list( $query['diet'] ?? [] ), 'max_spice' => $max, 'exclude' => $list( $query['exclude'] ?? [] ) ];
	}

	/**
	 * Server-side equivalent of the client filter (AND tags, spice ≤ max, conservative allergen exclusion).
	 *
	 * @param list<int>                                                   $dish_ids
	 * @param array{diet:list<string>,max_spice:?int,exclude:list<string>} $filters
	 * @return list<int>
	 */
	public static function apply_filters( array $dish_ids, array $filters, string $lang ): array {
		return array_values(
			array_filter(
				$dish_ids,
				static function ( int $id ) use ( $filters, $lang ): bool {
					$card = self::card( $id, $lang );
					$tags = '' === $card['data']['data-tags'] ? [] : explode( ' ', $card['data']['data-tags'] );
					if ( array_diff( $filters['diet'], $tags ) ) {
						return false;
					}
					if ( null !== $filters['max_spice'] && $card['spice'] > $filters['max_spice'] ) {
						return false;
					}
					$alg = '' === $card['data']['data-alg'] ? [] : explode( ' ', $card['data']['data-alg'] );
					if ( $filters['exclude'] && ( in_array( 'review', $alg, true ) || array_intersect( $filters['exclude'], $alg ) ) ) {
						return false;
					}
					return true;
				}
			)
		);
	}

	/** @param list<int> $ids @return list<int> */
	private static function sort_by_order_and_title( array $ids, string $lang ): array {
		if ( Lang::SOURCE === $lang ) {
			return $ids; // Already ordered by SQL.
		}
		$keyed = [];
		foreach ( $ids as $id ) {
			$p            = get_post( $id );
			$keyed[ $id ] = [ (int) $p->menu_order, Translations::post( $p, 'title', $lang ) ];
		}
		usort( $ids, static fn( $a, $b ) => $keyed[ $a ][0] <=> $keyed[ $b ][0] ?: strcmp( $keyed[ $a ][1], $keyed[ $b ][1] ) );
		return $ids;
	}
}
