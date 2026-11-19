<?php
declare(strict_types=1);

namespace Momos\Seo;

use Momos\Kernel\I18n\Locale;
use Momos\Kernel\I18n\Translations;
use Momos\Kernel\I18n\Url;

/**
 * Breadcrumb trail (FR-SEO-007, i18n-seo-analytics.md §9.7) — single source for the visible
 * `template-parts/frame/breadcrumbs.php` and the BreadcrumbList JSON-LD. Names in the page language,
 * clean localised URLs (the current item carries its canonical URL; the theme renders it unlinked).
 * Never on Home, C09/C10/C11 or the 404 page.
 */
final class Breadcrumbs {
	/** @return list<array{name:string,url:string}> */
	public static function items( ?string $lang = null ): array {
		$lang = $lang ?? Locale::current();
		$c    = Context::current();
		if ( in_array( $c['kind'], [ Context::HOME, Context::NOTFOUND, Context::OTHER ], true ) ) {
			return [];
		}
		if ( Context::PAGE === $c['kind'] && in_array( $c['role'], [ 'reservation', 'submitted', 'status' ], true ) ) {
			return [];
		}
		$home  = [ 'name' => momos_t( 'bc.home', [], $lang ), 'url' => Url::to( '/', $lang ) ];
		$self  = (string) ( Context::url_in( $lang ) ?? '' );
		$trail = [ $home ];
		switch ( $c['kind'] ) {
			case Context::BRANCH:
				$trail[] = [ 'name' => momos_t( 'nav.branches', [], $lang ), 'url' => self::role_url( 'branches', '/branches/', $lang ) ];
				break;
			case Context::DISH:
				$trail[] = [ 'name' => momos_t( 'nav.menu', [], $lang ), 'url' => self::role_url( 'menu', '/menu/', $lang ) ];
				$cat     = (int) get_post_meta( $c['id'], 'momo_primary_category', true );
				$term    = $cat > 0 ? get_term( $cat, 'momo_menu_category' ) : null;
				$name    = $term instanceof \WP_Term ? trim( Translations::term( $term, 'name', $lang ) ) : '';
				if ( '' !== $name ) {
					$trail[] = [ 'name' => $name, 'url' => Url::to( '/menu/' . $term->slug . '/', $lang ) ];
				}
				break;
			case Context::CATEGORY:
				$trail[] = [ 'name' => momos_t( 'nav.menu', [], $lang ), 'url' => self::role_url( 'menu', '/menu/', $lang ) ];
				break;
			case Context::NEWS:
				$news = class_exists( \Momos\Presentation\SystemPages::class ) ? \Momos\Presentation\SystemPages::url( 'news', $lang ) : null;
				if ( null !== $news ) {
					$trail[] = [ 'name' => momos_t( 'nav.news', [], $lang ), 'url' => Context::clean( $news ) ];
				}
				break;
		}
		$name = Meta::entity_name( $c['kind'], $c['id'], $lang, $c['role'] );
		if ( '' === $name ) {
			return [];
		}
		$trail[] = [ 'name' => $name, 'url' => $self ];
		return (array) apply_filters( 'momos/seo/breadcrumbs', $trail, $c, $lang );
	}

	private static function role_url( string $role, string $path, string $lang ): string {
		$url = class_exists( \Momos\Presentation\SystemPages::class ) ? \Momos\Presentation\SystemPages::url( $role, $lang ) : null;
		return Context::clean( $url ?? Url::to( $path, $lang ) );
	}
}
