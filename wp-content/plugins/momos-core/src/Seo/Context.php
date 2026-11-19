<?php
declare(strict_types=1);

namespace Momos\Seo;

use Momos\Kernel\I18n\Lang;
use Momos\Kernel\I18n\Locale;
use Momos\Kernel\I18n\Translations;
use Momos\Kernel\I18n\Url;

/**
 * What the current front-end request is, in SEO terms (i18n-seo-analytics.md §8, §9.2–§9.4):
 * the entity, its clean URL and visibility in every language, and whether the page is indexable.
 * Single source for canonical, hreflang, robots, OG, breadcrumbs and JSON-LD. Request-cached;
 * `reset()` after the main query changes (tests).
 */
final class Context {
	public const HOME     = 'home';
	public const PAGE     = 'page';
	public const BRANCH   = 'branch';
	public const DISH     = 'dish';
	public const CATEGORY = 'category';
	public const NEWS     = 'news';
	public const NEWS_IDX = 'news_index';
	public const NOTFOUND = '404';
	public const OTHER    = 'other';

	/** System page roles that are never indexed (C10/C11). */
	public const NOINDEX_ROLES = [ 'submitted', 'status' ];

	private static ?array $cache = null;

	public static function reset(): void {
		self::$cache = null;
	}

	/**
	 * @return array{kind:string,id:int,role:string,object:\WP_Post|\WP_Term|null}
	 */
	public static function current(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$kind = self::OTHER;
		$id   = 0;
		$role = '';
		$obj  = get_queried_object();
		if ( is_404() ) {
			$kind = self::NOTFOUND;
		} elseif ( is_front_page() ) {
			$kind = self::HOME;
			$id   = (int) get_option( 'page_on_front' );
			$role = 'home';
		} elseif ( is_home() ) {
			$kind = self::NEWS_IDX;
			$id   = (int) get_option( 'page_for_posts' );
			$role = 'news';
		} elseif ( is_singular( 'momo_branch' ) && $obj instanceof \WP_Post ) {
			$kind = self::BRANCH;
			$id   = (int) $obj->ID;
		} elseif ( is_singular( 'momo_dish' ) && $obj instanceof \WP_Post ) {
			$kind = self::DISH;
			$id   = (int) $obj->ID;
		} elseif ( is_tax( 'momo_menu_category' ) && $obj instanceof \WP_Term ) {
			$kind = self::CATEGORY;
			$id   = (int) $obj->term_id;
		} elseif ( is_singular( 'post' ) && $obj instanceof \WP_Post ) {
			$kind = self::NEWS;
			$id   = (int) $obj->ID;
		} elseif ( is_page() && $obj instanceof \WP_Post ) {
			$kind = self::PAGE;
			$id   = (int) $obj->ID;
			$role = self::role_of( $id );
		}
		self::$cache = [ 'kind' => $kind, 'id' => $id, 'role' => $role, 'object' => $obj instanceof \WP_Post || $obj instanceof \WP_Term ? $obj : null ];
		return self::$cache;
	}

	public static function role_of( int $page_id ): string {
		return class_exists( \Momos\Presentation\SystemPages::class ) ? \Momos\Presentation\SystemPages::role_of( $page_id ) : '';
	}

	/** Pages whose content is catalog strings + module data: visible in every enabled language. */
	public static function is_string_driven( string $role ): bool {
		$roles = class_exists( \Momos\Presentation\Templates\TemplateRouter::class ) ? \Momos\Presentation\Templates\TemplateRouter::STRING_DRIVEN : [ 'home', 'halal', 'branches', 'menu', 'offers', 'reservation', 'submitted', 'status' ];
		return in_array( $role, $roles, true );
	}

	// ------------------------------------------------------------------ visibility / URLs

	/** Is the current entity publicly visible in $lang (enabled language + approved translation + module rules)? */
	public static function visible_in( string $lang ): bool {
		$c = self::current();
		return self::entity_visible( $c['kind'], $c['id'], $lang, $c['role'] );
	}

	public static function entity_visible( string $kind, int $id, string $lang, string $role = '' ): bool {
		if ( ! Lang::is_valid( $lang ) || ! Lang::is_enabled( $lang ) ) {
			return false;
		}
		switch ( $kind ) {
			case self::HOME:
				return true;
			case self::PAGE:
			case self::NEWS_IDX:
				if ( 'offers' === $role && ! self::offers_active( $lang ) ) {
					return false;
				}
				// AC-CORP-03-03: the halal page is not published in a language without reviewed statement templates.
				if ( 'halal' === $role && class_exists( \Momos\Presentation\View\HalalPage::class ) && ! \Momos\Presentation\View\HalalPage::published( $lang ) ) {
					return false;
				}
				if ( self::is_string_driven( $role ) ) {
					return $id > 0 && 'publish' === get_post_status( $id );
				}
				return $id > 0 && Translations::post_visible( $id, $lang );
			case self::NEWS:
				return Translations::post_visible( $id, $lang );
			case self::BRANCH:
				return class_exists( \Momos\Branch\Repository\BranchRepository::class )
					? \Momos\Branch\Repository\BranchRepository::is_public( $id, $lang )
					: Translations::post_visible( $id, $lang );
			case self::DISH:
				return class_exists( \Momos\Menu\Service\MenuQueryService::class )
					? \Momos\Menu\Service\MenuQueryService::is_displayable( $id, $lang )
					: Translations::post_visible( $id, $lang );
			case self::CATEGORY:
				$term = get_term( $id, 'momo_menu_category' );
				if ( ! $term instanceof \WP_Term || ! Translations::term_visible( $term, $lang ) ) {
					return false;
				}
				return ! class_exists( \Momos\Menu\Service\MenuQueryService::class ) || [] !== \Momos\Menu\Service\MenuQueryService::dishes_in_category( $term->term_id, $lang );
		}
		return false;
	}

	/** Clean absolute URL of the current entity in $lang (no query, trailing slash), or null. */
	public static function url_in( string $lang ): ?string {
		$c   = self::current();
		$url = self::entity_url( $c['kind'], $c['id'], $lang );
		if ( null !== $url && in_array( $c['kind'], [ self::NEWS_IDX ], true ) ) {
			$paged = max( 1, (int) get_query_var( 'paged' ) );
			if ( $paged > 1 ) {
				$url = trailingslashit( $url ) . 'page/' . $paged . '/';
			}
		}
		return $url;
	}

	public static function entity_url( string $kind, int $id, string $lang ): ?string {
		switch ( $kind ) {
			case self::HOME:
				return Url::to( '/', $lang );
			case self::PAGE:
			case self::NEWS_IDX:
			case self::NEWS:
			case self::BRANCH:
				if ( $id <= 0 ) {
					return self::NEWS_IDX === $kind ? Url::to( '/news/', $lang ) : null;
				}
				if ( (int) get_option( 'page_on_front' ) === $id ) {
					return Url::to( '/', $lang );
				}
				$link = (string) get_permalink( $id );
				return '' === $link ? null : self::clean( Url::localize( $link, $lang ) );
			case self::DISH:
				$post = get_post( $id );
				if ( ! $post ) {
					return null;
				}
				$path = class_exists( \Momos\Menu\Rewrite\MenuRewrite::class ) ? \Momos\Menu\Rewrite\MenuRewrite::dish_path( $post ) : null;
				return null === $path ? null : Url::to( $path, $lang );
			case self::CATEGORY:
				$term = get_term( $id, 'momo_menu_category' );
				return $term instanceof \WP_Term ? Url::to( '/menu/' . $term->slug . '/', $lang ) : null;
		}
		return null;
	}

	/** Lowercase path, trailing slash, no query/fragment (FR-SEO-001). */
	public static function clean( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return $url;
		}
		$path = strtolower( (string) ( $parts['path'] ?? '/' ) );
		if ( ! str_ends_with( $path, '/' ) ) {
			$path .= '/';
		}
		return ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . $path;
	}

	// ------------------------------------------------------------------ indexability

	/** Does the request carry a query string (filters, `?branch=`, `?notice=`, UTM …)? §9.3 row 2. */
	public static function has_query(): bool {
		return ! empty( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification -- read-only flag.
	}

	/**
	 * Why the current page must not be indexed ('' = indexable). Values: 404, private (C10/C11),
	 * test_branch, preview (not visible in the URL language), hidden_language, query, flagged,
	 * offers_empty, staff_only, other (search, archives, attachments).
	 */
	public static function noindex_reason(): string {
		$c    = self::current();
		$lang = Locale::current();
		if ( self::NOTFOUND === $c['kind'] ) {
			return '404';
		}
		if ( self::OTHER === $c['kind'] ) {
			return 'other';
		}
		if ( self::PAGE === $c['kind'] && in_array( $c['role'], self::NOINDEX_ROLES, true ) ) {
			return 'private';
		}
		if ( self::BRANCH === $c['kind'] && '1' === (string) get_post_meta( $c['id'], 'momo_is_test_branch', true ) ) {
			return 'test_branch';
		}
		if ( Lang::SOURCE !== $lang && ! Lang::is_enabled( $lang ) ) {
			return 'hidden_language';
		}
		if ( self::PAGE === $c['kind'] && 'offers' === $c['role'] && ! self::offers_active( $lang ) ) {
			return 'offers_empty';
		}
		if ( ! self::visible_in( $lang ) ) {
			return 'preview';
		}
		if ( in_array( $c['kind'], [ self::PAGE, self::NEWS, self::NEWS_IDX ], true ) && $c['id'] > 0 ) {
			if ( '1' === (string) get_post_meta( $c['id'], 'momo_noindex', true ) ) {
				return 'flagged';
			}
			if ( class_exists( \Momos\Presentation\Templates\TemplateRouter::class ) && \Momos\Presentation\Templates\TemplateRouter::is_staff_only() ) {
				return 'staff_only';
			}
		}
		if ( is_preview() ) {
			return 'preview';
		}
		if ( self::has_query() ) {
			return 'query';
		}
		return '';
	}

	public static function indexable(): bool {
		return '' === self::noindex_reason();
	}

	/** Canonical URL of the current page (§9.2): only on indexable pages and query variants of them. */
	public static function canonical(): ?string {
		$reason = self::noindex_reason();
		if ( '' !== $reason && ! in_array( $reason, [ 'query', 'offers_empty' ], true ) ) {
			return null;
		}
		return self::url_in( Locale::current() );
	}

	/**
	 * hreflang alternates (§9.4): enabled languages (fixed order) where the entity is visible, only on
	 * indexable pages and only when ≥ 2 versions exist.
	 *
	 * @return array<string,string> code => absolute URL
	 */
	public static function alternates(): array {
		if ( ! self::indexable() ) {
			return [];
		}
		$out = [];
		foreach ( Lang::codes() as $code ) {
			if ( ! Lang::is_enabled( $code ) || ! self::visible_in( $code ) ) {
				continue;
			}
			$url = self::url_in( $code );
			if ( null !== $url ) {
				$out[ $code ] = $url;
			}
		}
		return count( $out ) >= 2 ? $out : [];
	}

	public static function offers_active( string $lang ): bool {
		if ( class_exists( \Momos\Presentation\View\Navigation::class ) ) {
			return \Momos\Presentation\View\Navigation::offers_active( $lang );
		}
		return class_exists( \Momos\Branch\Query\OfferQuery::class ) && \Momos\Branch\Query\OfferQuery::has_active( $lang );
	}

	/** Customer analytics/SEO page type (PageContext, §3.5). */
	public static function page_type(): string {
		return class_exists( \Momos\Presentation\PageContext::class ) ? \Momos\Presentation\PageContext::page_type() : 'page';
	}
}
