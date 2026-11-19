<?php
declare(strict_types=1);

namespace Momos\Menu\Rewrite;

use Momos\Kernel\I18n\Locale;
use Momos\Kernel\I18n\Url;
use Momos\Menu\ContentTypes;
use Momos\Menu\Service\CategoryGuard;
use Momos\Menu\Service\MenuQueryService;

/**
 * Menu URLs (spec §3.1): `/menu/{category}/` and `/menu/{category}/{dish}/` after the language prefix.
 * A dish requested under a non-primary category → 301 to the canonical URL; retired dish → 301 to its
 * primary category in the same language; unpublished / untranslated / sample in production → 404; old slugs → 301.
 * Rules are flushed only by migrations (Kernel Runner).
 */
final class MenuRewrite {
	public const QV_CATEGORY_REQ = 'momo_req_cat';
	/** Query args kept on redirects (branch context and shareable filters). */
	private const KEEP_ARGS = [ 'branch', 'diet', 'max_spice', 'exclude' ];

	public static function register(): void {
		add_action( 'init', [ self::class, 'rules' ], 20 );
		add_filter( 'query_vars', [ self::class, 'query_vars' ] );
		add_filter( 'post_type_link', [ self::class, 'dish_link' ], 10, 2 );
		add_filter( 'term_link', [ self::class, 'term_link' ], 10, 3 );
		add_action( 'template_redirect', [ self::class, 'template_redirect' ], 5 );
		add_filter( 'redirect_canonical', [ self::class, 'no_core_canonical' ], 5, 2 );
	}

	public static function rules(): void {
		// Dish rule first (spec §3.1).
		add_rewrite_rule( '^menu/([^/]+)/([^/]+)/?$', 'index.php?' . ContentTypes::DISH . '=$matches[2]&' . self::QV_CATEGORY_REQ . '=$matches[1]', 'top' );
		add_rewrite_rule( '^menu/([^/]+)/?$', 'index.php?' . ContentTypes::CATEGORY . '=$matches[1]', 'top' );
	}

	/** @param list<string> $vars @return list<string> */
	public static function query_vars( array $vars ): array {
		$vars[] = self::QV_CATEGORY_REQ;
		return $vars;
	}

	/** Canonical path of a dish (no language prefix): /menu/{primary}/{slug}/ or null. */
	public static function dish_path( \WP_Post $post ): ?string {
		$primary = (int) get_post_meta( $post->ID, 'momo_primary_category', true );
		$term    = $primary > 0 ? get_term( $primary, ContentTypes::CATEGORY ) : null;
		if ( ! $term instanceof \WP_Term || '' === $post->post_name ) {
			return null;
		}
		return '/menu/' . $term->slug . '/' . $post->post_name . '/';
	}

	public static function dish_link( string $link, \WP_Post $post ): string {
		if ( ContentTypes::DISH !== $post->post_type ) {
			return $link;
		}
		$path = self::dish_path( $post );
		return null === $path ? add_query_arg( [ 'post_type' => ContentTypes::DISH, 'p' => $post->ID ], Url::raw_home() . '/' ) : Url::raw_home() . $path;
	}

	public static function term_link( string $link, \WP_Term $term, string $taxonomy ): string {
		return ContentTypes::CATEGORY === $taxonomy ? Url::raw_home() . '/menu/' . $term->slug . '/' : $link;
	}

	/** @param string|false $redirect */
	public static function no_core_canonical( $redirect, string $requested ) {
		return ( get_query_var( ContentTypes::DISH ) || get_query_var( ContentTypes::CATEGORY ) ) ? false : $redirect;
	}

	public static function template_redirect(): void {
		$lang      = Locale::current();
		$dish_slug = (string) get_query_var( ContentTypes::DISH );
		$cat_slug  = (string) get_query_var( ContentTypes::CATEGORY );
		if ( '' !== $dish_slug ) {
			self::handle_dish( $dish_slug, (string) get_query_var( self::QV_CATEGORY_REQ ), $lang );
		} elseif ( '' !== $cat_slug ) {
			self::handle_category( $cat_slug, $lang );
		}
	}

	private static function handle_dish( string $slug, string $req_cat, string $lang ): void {
		$post = is_singular( ContentTypes::DISH ) ? get_queried_object() : null;
		if ( $post instanceof \WP_Post ) {
			if ( ! MenuQueryService::is_displayable( $post, $lang ) && ! current_user_can( 'edit_post', $post->ID ) ) {
				self::not_found();
				return;
			}
			$path = self::dish_path( $post );
			if ( null !== $path && '' !== $req_cat && '/menu/' . $req_cat . '/' . $post->post_name . '/' !== $path ) {
				self::redirect( Url::to( $path, $lang ) );
			}
			return;
		}
		// Not resolvable as published: retired → 301 to its primary category; old slug → 301 to current URL.
		$found = get_posts( [ 'post_type' => ContentTypes::DISH, 'name' => $slug, 'post_status' => [ 'draft', 'pending', 'private', 'publish' ], 'posts_per_page' => 1 ] );
		$dish  = $found[0] ?? null;
		if ( ! $dish ) {
			$ids  = get_posts( [ 'post_type' => ContentTypes::DISH, 'post_status' => 'publish', 'meta_key' => '_wp_old_slug', 'meta_value' => $slug, 'posts_per_page' => 1, 'fields' => 'ids' ] ); // phpcs:ignore
			$dish = $ids ? get_post( (int) $ids[0] ) : null;
			$path = $dish && MenuQueryService::is_displayable( $dish, $lang ) ? self::dish_path( $dish ) : null;
			if ( null !== $path ) {
				self::redirect( Url::to( $path, $lang ) );
			}
			self::not_found();
			return;
		}
		if ( '1' === (string) get_post_meta( $dish->ID, 'momo_retired', true ) ) {
			$primary = get_term( (int) get_post_meta( $dish->ID, 'momo_primary_category', true ), ContentTypes::CATEGORY );
			$target  = $primary instanceof \WP_Term ? '/menu/' . $primary->slug . '/' : '/menu/';
			self::redirect( Url::to( $target, $lang ) );
		}
		self::not_found(); // Draft/pending: never public (FR-MENU-009).
	}

	private static function handle_category( string $slug, string $lang ): void {
		$term = get_term_by( 'slug', $slug, ContentTypes::CATEGORY );
		if ( ! $term instanceof \WP_Term ) {
			$old = CategoryGuard::category_by_any_slug( $slug );
			if ( $old ) {
				self::redirect( Url::to( '/menu/' . $old->slug . '/', $lang ) );
			}
			self::not_found();
			return;
		}
		if ( ! MenuQueryService::is_category_public( $term, $lang ) ) {
			self::not_found(); // Untranslated, or a sample category on the live site (BR-CORP-03).
			return;
		}
		$branch = MenuQueryService::branch_context( isset( $_GET['branch'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['branch'] ) ) : null ); // phpcs:ignore
		if ( null === $branch && ! MenuQueryService::dishes_in_category( $term->term_id, $lang ) ) {
			self::not_found(); // No translated dish and no branch context (spec §5.1).
		}
	}

	private static function redirect( string $url ): void {
		$keep = [];
		foreach ( self::KEEP_ARGS as $k ) {
			if ( isset( $_GET[ $k ] ) ) { // phpcs:ignore
				$keep[ $k ] = map_deep( wp_unslash( $_GET[ $k ] ), 'sanitize_text_field' ); // phpcs:ignore
			}
		}
		wp_safe_redirect( $keep ? add_query_arg( $keep, $url ) : $url, 301 );
		exit;
	}

	private static function not_found(): void {
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}
}
