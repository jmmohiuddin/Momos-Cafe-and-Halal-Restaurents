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

	public static function id( string $role ): int {
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
	 * Language URL of a system page. Must-have roles always return the documented path (the nav never
	 * loses an item); optional roles return null unless the page is published and visible in $lang.
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
		$id = (int) View\Brand::option( $def[0] );
		if ( $id && 'page' === get_post_type( $id ) ) {
			return $id;
		}
		$page = get_page_by_path( $def[1], OBJECT, 'page' );
		return $page instanceof \WP_Post ? (int) $page->ID : 0;
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
