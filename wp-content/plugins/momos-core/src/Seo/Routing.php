<?php
declare(strict_types=1);

namespace Momos\Seo;

use Momos\Kernel\Env;
use Momos\Kernel\I18n\Locale;
use Momos\Kernel\I18n\Translations;
use Momos\Kernel\I18n\Url;

/**
 * robots.txt and the URLs that must not exist (FR-SEO-009, i18n-seo-analytics.md §8 "System URLs",
 * §9.6): search `?s=` → 301 language home (DEC-045), attachment pages → 301 parent or home, date/tag
 * archives, core feeds and taxonomy archives of ingredients/tags/allergens → 404. Author archives
 * are handled by Access\Hardening.
 */
final class Routing {
	public const NOT_FOUND_TAXONOMIES = [ 'momo_ingredient', 'momo_dietary_tag', 'momo_allergen' ];

	public static function register(): void {
		add_filter( 'robots_txt', [ self::class, 'robots_txt' ], 99, 2 );
		add_action( 'template_redirect', [ self::class, 'template_redirect' ], 2 );
		add_filter( 'feed_links_show_posts_feed', '__return_false' );
		add_filter( 'feed_links_show_comments_feed', '__return_false' );
	}

	/** §9.6: production allows everything but wp-admin and lists the sitemap index; other environments block all. */
	public static function robots_txt( string $output, $public = null ): string {
		if ( ! Env::is_production() ) {
			return "User-agent: *\nDisallow: /\n";
		}
		return "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n\nSitemap: " . Url::raw_home() . "/sitemap.xml\n";
	}

	public static function template_redirect(): void {
		if ( is_admin() ) {
			return;
		}
		$lang = Locale::current();
		if ( is_search() ) {
			self::redirect( Url::to( '/', $lang ) );
		}
		if ( is_attachment() ) {
			$parent = (int) wp_get_post_parent_id( (int) get_queried_object_id() );
			$target = Url::to( '/', $lang );
			if ( $parent > 0 && Translations::post_visible( $parent, $lang ) ) {
				$link   = (string) get_permalink( $parent );
				$target = '' !== $link ? Url::localize( $link, $lang ) : $target;
			}
			self::redirect( $target );
		}
		if ( is_date() || is_tag() || is_feed() || is_tax( self::NOT_FOUND_TAXONOMIES ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}
	}

	private static function redirect( string $url ): void {
		wp_safe_redirect( $url, 301, 'momos' );
		exit;
	}
}
