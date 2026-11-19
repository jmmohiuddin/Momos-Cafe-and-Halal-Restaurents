<?php
declare(strict_types=1);

namespace Momos\Seo;

use Momos\Kernel\ModuleInterface;

/**
 * SEO & analytics module (IMPL-001 — no SEO/analytics plugin; docs/specs/i18n-seo-analytics.md §8–§12):
 *  - Head: per-language title/description/OG/Twitter, canonical, hreflang (+ x-default), robots (FR-SEO-002/003/005);
 *  - Sitemap: `/sitemap.xml` + per-language/type children, core sitemaps off (FR-SEO-004);
 *  - Routing: robots.txt, search/attachment 301, archive/feed 404 (FR-SEO-009, DEC-045);
 *  - Schema\JsonLd: Organization/WebSite/Restaurant/Menu/MenuSection/MenuItem/BreadcrumbList/FAQPage (FR-SEO-006);
 *  - Breadcrumbs::items() for the theme and BreadcrumbList (FR-SEO-007);
 *  - Redirects (map, normalisation, 410), 404 log + weekly review, admin screen, WP-CLI (FR-SEO-008);
 *  - Analytics\TagLoader + theme `datalayer.js`: GTM only where allowed, opt-out/GPC (FR-ANL-001/002, DEC-066).
 */
final class SeoModule implements ModuleInterface {
	public function register(): void {
		SeoSettings::register();
		SeoFields::register();
		Head::register();
		Routing::register();
		Sitemap::register();
		Redirects\RedirectMatcher::register();
		Redirects\NotFoundLog::register();
		Analytics\TagLoader::register();
		Admin\SeoAdmin::register();
		add_action( 'momos/cli', [ Cli::class, 'register' ] );
		add_action( 'momos/sanitize', [ self::class, 'sanitize' ] );
	}

	/** Production copy → staging: drop the 404 log (environment-specific data). */
	public static function sanitize(): void {
		Redirects\NotFoundLog::clear();
	}
}
