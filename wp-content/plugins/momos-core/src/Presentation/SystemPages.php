<?php
declare(strict_types=1);

namespace Momos\Presentation;

use Momos\Kernel\I18n\Lang;
use Momos\Kernel\I18n\Locale;
use Momos\Kernel\I18n\Translations;
use Momos\Kernel\I18n\Url;

/**
 * Role → page registry (screens.md §0.1). Option `momo_system_pages` = {role: page ID}; legal pages
 * come from the brand options (`momo_privacy_page`, …) with the documented slugs as fallback.
 */
final class SystemPages {
	public const OPTION = 'momo_system_pages';

	/** role => [default slug, parent role|null, JA title] — slugs are shared by every language (IMPL-002). */
	public const ROLES = [
		'home'        => [ 'home', null, 'ホーム' ],
		'about'       => [ 'about', null, '私たちについて' ],
		'halal'       => [ 'halal', null, 'ハラール情報' ],
		'branches'    => [ 'branches', null, '店舗一覧' ],
		'menu'        => [ 'menu', null, 'メニュー' ],
		'reservation' => [ 'reservation', null, '予約リクエスト' ],
		'submitted'   => [ 'submitted', 'reservation', 'リクエストを送信しました' ],
		'status'      => [ 'status', 'reservation', '予約リクエストの状況' ],
		'contact'     => [ 'contact', null, 'お問い合わせ' ],
		'faq'         => [ 'faq', null, 'よくある質問' ],
		'offers'      => [ 'offers', null, 'お得な情報' ],
		'gallery'     => [ 'gallery', null, 'ギャラリー' ],
		'news'        => [ 'news', null, 'ニュース' ],
	];

	/** legal key => [brand option, documented slug, JA title] (i18n-seo-analytics.md §8 rows 17–21). */
	public const LEGAL = [
		'privacy'       => [ 'momo_privacy_page', 'privacy-policy', 'プライバシーポリシー' ],
		'terms'         => [ 'momo_terms_page', 'terms', '利用規約' ],
		'res_policy'    => [ 'momo_reservation_policy_page', 'reservation-policy', '予約ポリシー' ],
		'ext_trans'     => [ 'momo_external_tx_page', 'external-transmission', '外部送信・Cookieについて' ],
		'accessibility' => [ 'momo_accessibility_page', 'accessibility', 'アクセシビリティ' ],
	];

	/** Roles that are never indexed and never carry analytics (C10/C11, i18n-seo-analytics.md §9.3, DEC-066). */
	public const NOINDEX = [ 'submitted', 'status' ];

	/** Roles whose nav/footer entry is shown only when the page exists and is visible (C/S pages). */
	public const OPTIONAL = [ 'contact', 'faq', 'gallery', 'news' ];

	/** @var array<string,int>|null */
	private static ?array $map = null;

	/** @return array<string,int> */
	public static function map(): array {
		if ( null === self::$map ) {
			$raw       = get_option( self::OPTION, [] );
			self::$map = is_array( $raw ) ? array_map( 'intval', $raw ) : [];
		}
		return self::$map;
	}

	public static function flush(): void {
		self::$map = null;
	}

	/**
	 * Loads every system and legal page (post + meta) with one query pair instead of one get_post() per nav item
	 * (NFR-PERF-02, TRD §5 ≤ 60 queries per uncached page). Already cached IDs cost nothing.
	 */
	public static function prime(): void {
		$ids = array_merge( array_values( self::map() ), array_values( self::legal_slug_ids() ) );
		foreach ( self::LEGAL as $def ) {
			$ids[] = (int) View\Brand::option( $def[0] );
		}
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( $ids ) {
			_prime_post_caches( $ids, false, true );
		}
	}

	/**
	 * Top-level pages with the documented legal slugs (fallback when the brand option is not set), resolved with
	 * one query and cached until any post changes (`last_changed` of the posts group).
	 *
	 * @return array<string,int> slug => page ID
	 */
	private static function legal_slug_ids(): array {
		$key    = 'legal_slugs:' . wp_cache_get_last_changed( 'posts' );
		$cached = wp_cache_get( $key, 'momos_system_pages' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		global $wpdb;
		$slugs = array_column( self::LEGAL, 1 );
		$in    = implode( ',', array_fill( 0, count( $slugs ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- cached above; one query for all legal slugs.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_name FROM {$wpdb->posts} WHERE post_type = 'page' AND post_parent = 0 AND post_name IN ({$in}) ORDER BY ID ASC", $slugs ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built from a constant list.
		$out  = [];
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row->post_name ] ??= (int) $row->ID;
		}
		wp_cache_set( $key, $out, 'momos_system_pages' );
		return $out;
	}

	public static function id( string $role ): int {
		self::prime();
		$id = self::map()[ $role ] ?? 0;
		if ( $id && 'page' === get_post_type( $id ) ) {
			return $id;
		}
		return 0;
	}

	/** Role of a page ID ('' when not a system page). Legal pages return "legal". */
	public static function role_of( int $page_id ): string {
		$role = array_search( $page_id, self::map(), true );
		if ( is_string( $role ) ) {
			return $role;
		}
		foreach ( array_keys( self::LEGAL ) as $key ) {
			if ( self::legal_id( $key ) === $page_id ) {
				return 'legal';
			}
		}
		return 'legal' === get_post_meta( $page_id, 'momo_page_type', true ) ? 'legal' : '';
	}

	/**
	 * Language URL of a system page. Must-have roles return the documented path while the page is missing (the
	 * nav never loses an item); a published content page (about, optional roles) returns null while it is not
	 * visible in $lang, so no navigation links to a 404.
	 */
	public static function url( string $role, ?string $lang = null ): ?string {
		$lang = $lang ?? Locale::current();
		if ( 'home' === $role ) {
			return Url::to( '/', $lang );
		}
		$id = self::id( $role );
		if ( in_array( $role, self::OPTIONAL, true ) ) {
			if ( ! $id || ! Translations::post_visible( $id, $lang ) ) {
				return null;
			}
		}
		if ( $id && 'publish' === get_post_status( $id ) ) {
			// A content page without an approved translation is 404 in $lang (TemplateRouter::guard_untranslated):
			// never link it there (TC-NAV-005, BR-I18N-02). String-driven pages render in every language.
			if ( ! in_array( $role, Templates\TemplateRouter::STRING_DRIVEN, true ) && ! Translations::post_visible( $id, $lang ) ) {
				return null;
			}
			return Url::localize( (string) get_permalink( $id ), $lang );
		}
		$def = self::ROLES[ $role ] ?? null;
		if ( null === $def ) {
			return null;
		}
		$path = null !== $def[1] ? '/' . self::ROLES[ $def[1] ][0] . '/' . $def[0] . '/' : '/' . $def[0] . '/';
		return Url::to( $path, $lang );
	}

	public static function legal_id( string $key ): int {
		$def = self::LEGAL[ $key ] ?? null;
		if ( null === $def ) {
			return 0;
		}
		self::prime();
		$id = (int) View\Brand::option( $def[0] );
		if ( $id && 'page' === get_post_type( $id ) ) {
			return $id;
		}
		return self::legal_slug_ids()[ $def[1] ] ?? 0;
	}

	/** Legal page URL in $lang, or null while the page is missing / not visible in $lang (no dead link). */
	public static function legal_url( string $key, ?string $lang = null ): ?string {
		$lang = $lang ?? Locale::current();
		$id   = self::legal_id( $key );
		if ( ! $id || ! Translations::post_visible( $id, $lang ) ) {
			return null;
		}
		return Url::localize( (string) get_permalink( $id ), $lang );
	}

	/** @internal */
	public static function is_valid_lang( string $lang ): bool {
		return Lang::is_valid( $lang );
	}
}
