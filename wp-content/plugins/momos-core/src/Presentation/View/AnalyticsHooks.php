<?php
declare(strict_types=1);

namespace Momos\Presentation\View;

use Momos\Kernel\I18n\Locale;
use Momos\Presentation\PageContext;

/**
 * dataLayer contract hook (i18n-seo-analytics.md §12.1/§12.2) — data only, no GTM/GA code: a
 * `<script type="application/json" id="momo-page-data">` with the `momo_page` parameters and the
 * server-known `view_*` event of the screen, for the future `datalayer.js` / TagLoader (SEO/analytics
 * module) to push. Never rendered on C10/C11, TEST branch pages or for logged-in users. Values follow
 * the parameter dictionary (codes, slugs, SKU) — never names, free text or PII.
 */
final class AnalyticsHooks {
	/** @return array<string,mixed>|null */
	public static function data(): ?array {
		$type = PageContext::page_type();
		if ( in_array( $type, PageContext::NO_ANALYTICS, true ) || is_user_logged_in() ) {
			return null;
		}
		$lang = Locale::current();
		$page = [ 'event' => 'momo_page', 'page_type' => $type, 'language' => $lang, 'branch_code' => null, 'menu_category' => null, 'dish_sku' => null ];
		$view = null;
		$ctx  = MenuView::branch_context( $lang );
		if ( 'branch' === $type ) {
			$id = (int) get_queried_object_id();
			if ( '1' === (string) get_post_meta( $id, 'momo_is_test_branch', true ) ) {
				return null; // TEST branch: no analytics at all.
			}
			$page['branch_code'] = (string) get_post_meta( $id, 'momo_branch_code', true );
			$view                = [ 'event' => 'view_branch', 'branch_code' => $page['branch_code'], 'language' => $lang ];
		} elseif ( 'menu_hub' === $type || 'menu_category' === $type ) {
			$cat                   = 'menu_hub' === $type ? 'hub' : (string) ( get_queried_object()->slug ?? '' );
			$page['menu_category'] = $cat;
			$page['branch_code']   = $ctx['code'] ?? null;
			$view                  = array_filter( [ 'event' => 'view_menu', 'menu_category' => $cat, 'branch_code' => $ctx['code'] ?? null, 'language' => $lang ] );
		} elseif ( 'dish' === $type ) {
			$id                    = (int) get_queried_object_id();
			$term                  = get_term( (int) get_post_meta( $id, 'momo_primary_category', true ), 'momo_menu_category' );
			$page['dish_sku']      = (string) get_post_meta( $id, 'momo_dish_sku', true );
			$page['menu_category'] = $term instanceof \WP_Term ? $term->slug : null;
			$view                  = [ 'event' => 'view_dish', 'dish_sku' => $page['dish_sku'], 'menu_category' => $page['menu_category'], 'language' => $lang ];
		} elseif ( 'halal' === $type ) {
			$view = [ 'event' => 'view_halal_information', 'language' => $lang, 'source_page' => 'halal' ];
		}
		return [ 'page' => $page, 'view' => $view ];
	}

	public static function page_data(): void {
		if ( is_admin() || ! \Momos\Presentation\Assets::active() ) {
			return;
		}
		$data = self::data();
		if ( null === $data ) {
			return;
		}
		echo '<script type="application/json" id="momo-page-data">' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP ) . "</script>\n";
	}
}

