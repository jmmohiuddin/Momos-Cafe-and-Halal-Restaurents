<?php
declare(strict_types=1);

namespace Momos\Seo\Schema;

use Momos\Kernel\I18n\Lang;
use Momos\Kernel\I18n\Locale;
use Momos\Kernel\I18n\Translations;
use Momos\Kernel\I18n\Url;
use Momos\Seo\Breadcrumbs;
use Momos\Seo\Context;
use Momos\Seo\Meta;
use Momos\Seo\SeoSettings;

/**
 * The page's single JSON-LD block (FR-SEO-006, BR-SEO-01, i18n-seo-analytics.md §10):
 * `{"@context":"https://schema.org","@graph":[…]}` built from module data in the page language.
 * Never: aggregateRating/review, offer/promotion markup for `momo_offer`, anything for TEST/hidden/
 * closed branches, placeholders for unknown values (the property is omitted). Filter
 * `momos/schema/graph (array $graph, string $context)`.
 */
final class JsonLd {
	public static function print(): void {
		$graph = self::graph();
		if ( ! $graph ) {
			return;
		}
		echo '<script type="application/ld+json">' . self::encode( $graph ) . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON encoded with JSON_HEX_TAG.
	}

	/** @param list<array<string,mixed>> $graph */
	public static function encode( array $graph ): string {
		return (string) wp_json_encode( [ '@context' => 'https://schema.org', '@graph' => $graph ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP );
	}

	/** @return list<array<string,mixed>> */
	public static function graph( ?string $lang = null ): array {
		$lang   = $lang ?? Locale::current();
		$reason = Context::noindex_reason();
		// Private, hidden, preview, TEST and error pages carry no structured data.
		if ( '' !== $reason && ! in_array( $reason, [ 'query', 'offers_empty' ], true ) ) {
			return [];
		}
		$c     = Context::current();
		$graph = [];
		switch ( $c['kind'] ) {
			case Context::HOME:
				$graph[] = self::organization( $lang );
				$graph[] = self::website( $lang );
				break;
			case Context::BRANCH:
				$node = self::restaurant( $c['id'], $lang );
				if ( null !== $node ) {
					$graph[] = $node;
				}
				break;
			case Context::CATEGORY:
				$graph[] = self::menu_section( $c['id'], $lang );
				break;
			case Context::DISH:
				$node = self::menu_item( $c['id'], $lang );
				if ( null !== $node ) {
					$graph[] = $node;
				}
				break;
			case Context::PAGE:
				if ( 'menu' === $c['role'] ) {
					$graph[] = self::menu( $lang );
				} elseif ( in_array( $c['role'], [ 'faq', 'halal' ], true ) ) {
					$faq = self::faq_page( $c['role'], $c['id'], $lang );
					if ( null !== $faq ) {
						$graph[] = $faq;
					}
				}
				break;
		}
		$bc = self::breadcrumb_list( $lang );
		if ( null !== $bc ) {
			$graph[] = $bc;
		}
		/** @var list<array<string,mixed>> $graph */
		$graph = (array) apply_filters( 'momos/schema/graph', $graph, $c['kind'] . ( '' !== $c['role'] ? ':' . $c['role'] : '' ) );
		return array_values( array_filter( $graph, 'is_array' ) );
	}

	public static function org_id(): string {
		return Url::raw_home() . '/#organization';
	}

	/** @return array<string,mixed> */
	public static function organization( string $lang ): array {
		$node = [
			'@type' => 'Organization',
			'@id'   => self::org_id(),
			'name'  => self::trading_name( $lang ),
			'url'   => Url::to( '/', $lang ),
		];
		if ( class_exists( \Momos\Presentation\View\Brand::class ) ) {
			$legal = \Momos\Presentation\View\Brand::operator( $lang );
			if ( '' !== $legal ) {
				$node['legalName'] = $legal;
			}
			$logo = (int) \Momos\Presentation\View\Brand::option( 'momo_logo_primary' );
			$src  = $logo ? wp_get_attachment_image_src( $logo, 'full' ) : false;
			if ( is_array( $src ) && ! empty( $src[0] ) ) {
				$node['logo'] = array_filter( [ '@type' => 'ImageObject', 'url' => (string) $src[0], 'width' => (int) $src[1] ?: null, 'height' => (int) $src[2] ?: null ] );
			}
			$same = array_column( \Momos\Presentation\View\Brand::social_links(), 'url' );
			if ( $same ) {
				$node['sameAs'] = array_values( $same );
			}
		}
		return $node;
	}

	/** @return array<string,mixed> */
	public static function website( string $lang ): array {
		return [
			'@type'      => 'WebSite',
			'@id'        => Url::raw_home() . '/#website',
			'url'        => Url::to( '/', $lang ),
			'name'       => self::trading_name( $lang ),
			'inLanguage' => Lang::ALL[ $lang ]['html'],
			'publisher'  => [ '@id' => self::org_id() ],
		];
	}

	/** @return array<string,mixed>|null */
	public static function restaurant( int $branch_id, string $lang ): ?array {
		if ( ! class_exists( \Momos\Branch\Repository\BranchRepository::class ) || ! \Momos\Branch\Repository\BranchRepository::is_public( $branch_id, $lang ) ) {
			return null; // TEST, permanently closed, unpublished or untranslated: nothing.
		}
		$b   = \Momos\Branch\Repository\BranchRepository::get( $branch_id );
		$url = (string) Context::entity_url( Context::BRANCH, $branch_id, $lang );
		$tr  = static fn( string $k ): string => trim( Lang::SOURCE === $lang ? (string) get_post_meta( $branch_id, $k, true ) : Translations::post( $branch_id, $k, $lang ) );

		$street   = trim( $tr( 'momo_address_line1' ) . ' ' . $tr( 'momo_address_line2' ) );
		$addr     = array_filter(
			[
				'@type'           => 'PostalAddress',
				'streetAddress'   => $street,
				'addressLocality' => $tr( 'momo_city' ),
				'addressRegion'   => $tr( 'momo_prefecture' ),
				'postalCode'      => (string) get_post_meta( $branch_id, 'momo_postal_code', true ),
				'addressCountry'  => 'JP',
			],
			static fn( $v ) => '' !== $v
		);
			$node = [
				'@type' => 'Restaurant',
				'@id'   => $url . '#restaurant',
				'name'  => \Momos\Branch\Repository\BranchRepository::name( $branch_id, $lang ),
				'url'   => $url,
			];
			if ( count( $addr ) > 2 ) {
				$node['address'] = $addr;
			}
			if ( null !== $b['lat'] && null !== $b['lng'] ) {
				$node['geo'] = [ '@type' => 'GeoCoordinates', 'latitude' => $b['lat'], 'longitude' => $b['lng'] ];
			}
			if ( '' !== $b['phone_e164'] ) {
				$node['telephone'] = $b['phone_e164'];
			}
			$images = [];
			foreach ( array_merge( [ (int) get_post_thumbnail_id( $branch_id ) ], array_map( 'intval', (array) get_post_meta( $branch_id, 'momo_gallery', true ) ) ) as $aid ) {
				$src = $aid ? wp_get_attachment_image_url( $aid, 'full' ) : false;
				if ( is_string( $src ) && '' !== $src ) {
					$images[] = $src;
				}
			}
			if ( $images ) {
				$node['image'] = array_values( array_unique( $images ) );
			}
			$menu = class_exists( \Momos\Presentation\SystemPages::class ) ? \Momos\Presentation\SystemPages::url( 'menu', $lang ) : Url::to( '/menu/', $lang );
			if ( null !== $menu ) {
				$node['menu'] = Context::clean( $menu );
			}
			// FR-SEO-006(b) / Q-415: only when Head Office has supplied the values (SEO settings).
			$cuisine = SeoSettings::serves_cuisine( $lang );
			if ( '' !== $cuisine ) {
				$node['servesCuisine'] = $cuisine;
			}
			$price = SeoSettings::price_range();
			if ( '' !== $price ) {
				$node['priceRange'] = $price;
			}
			if ( 'open' === $b['operating_status'] && '1' === (string) get_post_meta( $branch_id, 'momo_res_enabled', true ) ) {
				$node['acceptsReservations'] = true;
			}
			$node['parentOrganization'] = [ '@id' => self::org_id() ];
			$hours                      = OpeningHours::for_branch( $branch_id );
			if ( $hours ) {
				$node['openingHoursSpecification'] = $hours;
			}
			return $node;
	}

	/** @return array<string,mixed> */
	public static function menu( string $lang ): array {
		$url      = (string) ( Context::url_in( $lang ) ?? Url::to( '/menu/', $lang ) );
		$sections = [];
		if ( class_exists( \Momos\Menu\Service\MenuQueryService::class ) ) {
			foreach ( \Momos\Menu\Service\MenuQueryService::categories( $lang ) as $t ) {
				$sections[] = [ '@type' => 'MenuSection', 'name' => Translations::term( $t, 'name', $lang ), 'url' => Url::to( '/menu/' . $t->slug . '/', $lang ) ];
			}
		}
		$node = [
			'@type'      => 'Menu',
			'@id'        => $url . '#menu',
			'name'       => momos_t( 'nav.menu', [], $lang ),
			'url'        => $url,
			'inLanguage' => Lang::ALL[ $lang ]['html'],
		];
		if ( $sections ) {
			$node['hasMenuSection'] = $sections;
		}
		return $node;
	}

	/** @return array<string,mixed> */
	public static function menu_section( int $term_id, string $lang ): array {
		$term = get_term( $term_id, 'momo_menu_category' );
		$url  = (string) Context::entity_url( Context::CATEGORY, $term_id, $lang );
		$node = [
			'@type' => 'MenuSection',
			'@id'   => $url . '#section',
			'name'  => $term instanceof \WP_Term ? Translations::term( $term, 'name', $lang ) : '',
			'url'   => $url,
		];
		$desc = $term instanceof \WP_Term ? trim( wp_strip_all_tags( Translations::term( $term, 'description', $lang ) ) ) : '';
		if ( '' !== $desc ) {
			$node['description'] = $desc;
		}
		$items = [];
		if ( class_exists( \Momos\Menu\Service\MenuQueryService::class ) ) {
			foreach ( \Momos\Menu\Service\MenuQueryService::dishes_in_category( $term_id, $lang ) as $dish ) {
				$dish_url = Context::entity_url( Context::DISH, $dish, $lang );
				if ( null !== $dish_url ) {
					$items[] = [ '@type' => 'MenuItem', 'name' => Translations::post( $dish, 'title', $lang ), 'url' => $dish_url ];
				}
			}
		}
		if ( $items ) {
			$node['hasMenuItem'] = $items;
		}
		return $node;
	}

	/** @return array<string,mixed>|null */
	public static function menu_item( int $dish_id, string $lang ): ?array {
		$url = Context::entity_url( Context::DISH, $dish_id, $lang );
		if ( null === $url ) {
			return null;
		}
		$node = [
			'@type' => 'MenuItem',
			'@id'   => $url . '#dish',
			'name'  => Translations::post( $dish_id, 'title', $lang ),
			'url'   => $url,
		];
		$desc = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( Translations::post( $dish_id, 'content', $lang ) ) ) );
		if ( '' !== $desc ) {
			$node['description'] = $desc;
		}
		$img = wp_get_attachment_image_url( (int) get_post_thumbnail_id( $dish_id ), 'full' );
		if ( is_string( $img ) && '' !== $img ) {
			$node['image'] = $img;
		}
		$price = class_exists( \Momos\Menu\Service\PriceResolver::class ) ? \Momos\Menu\Service\PriceResolver::price( $dish_id ) : null;
		if ( null !== $price && $price > 0 ) {
			$node['offers'] = [ '@type' => 'Offer', 'price' => (string) (int) $price, 'priceCurrency' => 'JPY' ];
		}
		// HalalDiet only with an active, displayable certified_item claim (vegetarian/vegan never in Phase 1).
		if ( class_exists( \Momos\Dietary\Service\ClaimResolver::class ) ) {
			$claim = \Momos\Dietary\Service\ClaimResolver::dish_claim( $dish_id, null, $lang );
			if ( ! empty( $claim['suitable_for_halal_diet'] ) ) {
				$node['suitableForDiet'] = 'https://schema.org/HalalDiet';
			}
		}
		return $node;
	}

	/**
	 * FAQPage from the same Q&A the page shows (FR-CORP-004(c), AC-CORP-04-02): the FAQ page lists all
	 * visible items; the Halal page lists the "Halal & dietary" excerpt it renders (first 3).
	 *
	 * @return array<string,mixed>|null
	 */
	public static function faq_page( string $role, int $page_id, string $lang ): ?array {
		if ( ! SeoSettings::faq_schema() || ! class_exists( \Momos\Presentation\View\Content::class ) ) {
			return null;
		}
		$items = [];
		if ( 'faq' === $role ) {
			foreach ( \Momos\Presentation\View\Content::faq( $page_id, $lang ) as $group ) {
				$items = array_merge( $items, $group );
			}
		} else {
			$faq_id = \Momos\Presentation\SystemPages::id( 'faq' );
			if ( $faq_id && Translations::post_visible( $faq_id, $lang ) ) {
				$items = array_slice( \Momos\Presentation\View\Content::faq( $faq_id, $lang )['halal_dietary'] ?? [], 0, 3 );
			}
		}
		$entities = [];
		foreach ( $items as $it ) {
			$q = trim( (string) $it['question'] );
			$a = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $it['answer'] ) ) );
			if ( '' !== $q && '' !== $a ) {
				$entities[] = [ '@type' => 'Question', 'name' => $q, 'acceptedAnswer' => [ '@type' => 'Answer', 'text' => $a ] ];
			}
		}
		if ( ! $entities ) {
			return null;
		}
		$url = (string) Context::url_in( $lang );
		return [ '@type' => 'FAQPage', '@id' => $url . '#faq', 'url' => $url, 'inLanguage' => Lang::ALL[ $lang ]['html'], 'mainEntity' => $entities ];
	}

	/** @return array<string,mixed>|null */
	public static function breadcrumb_list( string $lang ): ?array {
		$items = Breadcrumbs::items( $lang );
		if ( count( $items ) < 2 ) {
			return null;
		}
		$list = [];
		foreach ( array_values( $items ) as $i => $it ) {
			$entry = [ '@type' => 'ListItem', 'position' => $i + 1, 'name' => (string) $it['name'] ];
			if ( '' !== (string) ( $it['url'] ?? '' ) ) {
				$entry['item'] = (string) $it['url'];
			}
			$list[] = $entry;
		}
		return [ '@type' => 'BreadcrumbList', 'itemListElement' => $list ];
	}

	private static function trading_name( string $lang ): string {
		return class_exists( \Momos\Presentation\View\Brand::class ) ? \Momos\Presentation\View\Brand::trading_name( $lang ) : (string) get_bloginfo( 'name' );
	}
}
