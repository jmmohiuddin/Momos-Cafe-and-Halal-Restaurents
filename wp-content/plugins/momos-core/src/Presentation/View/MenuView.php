<?php
declare(strict_types=1);

namespace Momos\Presentation\View;

use Momos\Kernel\I18n\Locale;
use Momos\Kernel\I18n\Translations;
use Momos\Kernel\I18n\Url;
use Momos\Presentation\SystemPages;

/**
 * View model of SCR-C06 Menu hub, C07 Menu category and C08 Dish detail (screens.md §3.6–3.8,
 * menu-dietary.md §3). Reads only through Menu/Dietary services (DEC-008); every method degrades to an
 * empty result while the Menu module is absent.
 */
final class MenuView {
	public static function ready(): bool {
		return class_exists( \Momos\Menu\Service\MenuQueryService::class );
	}

	/**
	 * `?branch={code}` context (published, public, non-test); unknown codes are ignored (AC-MENU-01-05).
	 *
	 * @return array{id:int,code:string,name:string}|null
	 */
	public static function branch_context( ?string $lang = null ): ?array {
		$lang = $lang ?? Locale::current();
		if ( ! self::ready() || ! isset( $_GET['branch'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- read-only context.
			return null;
		}
		$code = strtoupper( sanitize_text_field( wp_unslash( (string) $_GET['branch'] ) ) ); // phpcs:ignore
		$id   = \Momos\Menu\Service\MenuQueryService::branch_context( $code );
		if ( null === $id ) {
			return null;
		}
		$name = \Momos\Menu\Support\Branches::name( $id, $lang );
		return '' === $name ? null : [ 'id' => $id, 'code' => \Momos\Menu\Support\Branches::code( $id ), 'name' => $name ];
	}

	/** @return list<array{code:string,name:string,selected:bool}> branch selector options (open, public). */
	public static function selector( ?array $context, ?string $lang = null ): array {
		$lang = $lang ?? Locale::current();
		if ( ! self::ready() ) {
			return [];
		}
		$out = [];
		foreach ( \Momos\Menu\Service\MenuQueryService::selector_branches( $lang ) as $b ) {
			$out[] = [ 'code' => $b['code'], 'name' => $b['name'], 'selected' => null !== $context && $context['code'] === $b['code'] ];
		}
		return $out;
	}

	/** Same URL with/without `?branch=` (other variant params dropped). */
	public static function with_branch( string $url, ?array $context ): string {
		return null !== $context ? add_query_arg( 'branch', rawurlencode( $context['code'] ), $url ) : $url;
	}

	/**
	 * CMP-CategoryTile list: categories with ≥ 1 displayable dish (offered at the branch with context).
	 * No dish counts (screens.md C-3).
	 *
	 * @return list<array{id:int,slug:string,name:string,intro:string,image_id:int,url:string,current:bool}>
	 */
	public static function categories( ?array $context, ?string $lang = null, int $current = 0 ): array {
		$lang = $lang ?? Locale::current();
		if ( ! self::ready() ) {
			return [];
		}
		try {
			$terms = \Momos\Menu\Service\MenuQueryService::categories( $lang, $context['id'] ?? null );
		} catch ( \Throwable $e ) {
			return [];
		}
		$out = [];
		foreach ( $terms as $t ) {
			$out[] = [
				'id'       => (int) $t->term_id,
				'slug'     => (string) $t->slug,
				'name'     => Translations::term( $t, 'name', $lang ),
				'intro'    => Translations::term( $t, 'description', $lang ),
				'image_id' => (int) get_term_meta( $t->term_id, 'momo_cat_image', true ),
				'url'      => self::with_branch( self::term_url( $t, $lang ), $context ),
				'current'  => (int) $t->term_id === $current,
			];
		}
		return $out;
	}

	public static function term_url( \WP_Term $term, string $lang ): string {
		return Url::localize( (string) get_term_link( $term ), $lang );
	}

	/**
	 * Cards of a category page. With branch context only dishes offered there.
	 *
	 * @return list<array<string,mixed>> MenuQueryService::card() rows
	 */
	public static function category_cards( int $term_id, ?array $context, ?string $lang = null ): array {
		$lang = $lang ?? Locale::current();
		if ( ! self::ready() ) {
			return [];
		}
		try {
			$ids = \Momos\Menu\Service\MenuQueryService::dishes_in_category( $term_id, $lang, $context['id'] ?? null );
			return array_map( static fn( int $id ) => \Momos\Menu\Service\MenuQueryService::card( $id, $lang, $context['id'] ?? null ), $ids );
		} catch ( \Throwable $e ) {
			\Momos\Kernel\Log\Logger::error( 'presentation.menu_cards_failed', [ 'term' => $term_id, 'error' => $e->getMessage() ] );
			return [];
		}
	}

	/**
	 * Filter options for the category page (tags in use; allergen exclusion list) — empty while no tag is used.
	 *
	 * @param list<array<string,mixed>> $cards
	 * @return array{tags:list<array{code:string,label:string}>,allergens:list<array{code:string,label:string}>}
	 */
	public static function filters( array $cards, ?string $lang = null ): array {
		$lang = $lang ?? Locale::current();
		if ( ! self::ready() ) {
			return [ 'tags' => [], 'allergens' => [] ];
		}
		return \Momos\Menu\Service\MenuQueryService::filter_options( array_map( static fn( $c ) => (int) $c['id'], $cards ), $lang );
	}

	/**
	 * SCR-C08 data. Null when the module is absent.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function dish( int $dish_id, ?array $context, ?string $lang = null ): ?array {
		$lang = $lang ?? Locale::current();
		if ( ! self::ready() ) {
			return null;
		}
		$card    = \Momos\Menu\Service\MenuQueryService::card( $dish_id, $lang, $context['id'] ?? null );
		$primary = (int) get_post_meta( $dish_id, 'momo_primary_category', true );
		$term    = $primary ? get_term( $primary, 'momo_menu_category' ) : null;
		$illus   = '1' === (string) get_post_meta( $dish_id, 'momo_image_illustrative', true );
		$images  = array_values( array_unique( array_filter( array_merge( [ (int) get_post_thumbnail_id( $dish_id ) ], array_map( 'intval', (array) get_post_meta( $dish_id, 'momo_gallery', true ) ) ) ) ) );
		$gallery = [];
		foreach ( array_slice( $images, 0, 6 ) as $aid ) {
			$gallery[] = [ 'id' => $aid, 'illustrative' => $illus || '1' === (string) get_post_meta( $aid, 'momo_image_illustrative', true ), 'alt' => self::alt( $aid, $lang ) ];
		}

		// Branch rows (Available at) and price block.
		$rows = [];
		foreach ( \Momos\Menu\Service\MenuQueryService::branches_offering( $dish_id ) as $bid ) {
			$name = \Momos\Menu\Support\Branches::name( $bid, $lang );
			if ( '' === $name ) {
				continue;
			}
			$row    = \Momos\Menu\Service\MenuQueryService::branch_offers_dish( $bid, $dish_id );
			$price  = \Momos\Menu\Service\PriceResolver::price( $dish_id, $bid );
			$avail  = \Momos\Menu\Service\AvailabilityLabel::for_row( $row, $lang );
			$rows[] = [
				'id'         => $bid,
				'code'       => \Momos\Menu\Support\Branches::code( $bid ),
				'name'       => $name,
				'url'        => \Momos\Menu\Support\Branches::url( $bid, $lang ),
				'price'      => $price,
				'price_html' => null !== $price ? \Momos\Menu\Service\PriceFormatter::html( $price, $lang ) : '',
				'avail'      => $avail,
				'accepting'  => self::accepting( $bid ),
			];
		}
		$summary = \Momos\Menu\Service\PriceResolver::summary( $dish_id );
		$mode    = $summary['mode'];
		if ( null !== $context ) {
			$mode = null !== $card['price'] ? 'branch' : 'not_offered_here';
		}

		// CTA ?branch=: branch context, else the single accepting branch offering the dish.
		$accepting = array_values( array_filter( $rows, static fn( $r ) => $r['accepting'] ) );
		$cta_code  = null !== $context ? ( self::accepting( (int) $context['id'] ) ? $context['code'] : '' ) : ( 1 === count( $accepting ) ? $accepting[0]['code'] : '' );
		$res_url   = (string) SystemPages::url( 'reservation', $lang );

		$ingredients = [];
		foreach ( (array) wp_get_object_terms( $dish_id, 'momo_ingredient' ) as $t ) {
			if ( $t instanceof \WP_Term ) {
				$n = Translations::term( $t, 'name', $lang );
				if ( '' !== $n ) {
					$ingredients[] = $n;
				}
			}
		}
		return [
			'card'         => $card,
			'title'        => (string) $card['name'],
			'content'      => Translations::post( $dish_id, 'content', $lang ),
			'sku'          => (string) get_post_meta( $dish_id, 'momo_dish_sku', true ),
			'term'         => $term instanceof \WP_Term ? [ 'slug' => $term->slug, 'name' => Translations::term( $term, 'name', $lang ), 'url' => self::with_branch( self::term_url( $term, $lang ), $context ) ] : null,
			'gallery'      => $gallery,
			'price_mode'   => $mode,               // single | table | none | branch | not_offered_here
			'price_html'   => null !== $summary['price'] ? \Momos\Menu\Service\PriceFormatter::html( (int) $summary['price'], $lang ) : '',
			'rows'         => $rows,
			'offered'      => [] !== $rows,
			'ingredients'  => $ingredients,
			'request_url'  => [] === $rows || ( null !== $context && null === $card['price'] ) ? '' : ( '' !== $cta_code ? add_query_arg( 'branch', $cta_code, $res_url ) : $res_url ),
			'claim'        => class_exists( \Momos\Dietary\Service\ClaimResolver::class ) ? \Momos\Dietary\Service\ClaimResolver::dish_claim( $dish_id, $context['id'] ?? null, $lang ) : null,
			'allergens'    => class_exists( \Momos\Dietary\Service\AllergenPresenter::class ) ? \Momos\Dietary\Service\AllergenPresenter::for_dish( $dish_id, $lang ) : null,
			'related'      => \Momos\Menu\Service\MenuQueryService::related( $dish_id, $lang, 4 ),
		];
	}

	/** Alt text of an attachment in the language ('' = decorative). */
	public static function alt( int $attachment_id, string $lang ): string {
		if ( 'ja' === $lang ) {
			return (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		}
		return Translations::post( $attachment_id, 'alt', $lang );
	}

	private static function accepting( int $branch_id ): bool {
		if ( ! class_exists( \Momos\Branch\Repository\BranchRepository::class ) ) {
			return false;
		}
		$b = \Momos\Branch\Repository\BranchRepository::get( $branch_id );
		return null !== $b && ActionBar::accepting( $branch_id, $b );
	}
}
