<?php
declare(strict_types=1);

namespace Momos\Seo;

use Momos\Kernel\I18n\Lang;
use Momos\Kernel\I18n\Translations;

/**
 * Title, description and Open Graph resolution in the page language L (FR-SEO-005,
 * i18n-seo-analytics.md §9.1): per-entity SEO field (ja meta / approved L translation) → page-type
 * template → never another language's text. Descriptions fall back to the entity summary in L and are
 * omitted rather than left empty or mixed.
 */
final class Meta {
	/** Description limits: characters (non-ja) / full-width characters (ja) — §9.1 step 2. */
	public const DESC_MAX    = 160;
	public const DESC_MAX_JA = 80;

	/** Role → H1 string key used when a system page has no (translated) title (same as the templates). */
	public const ROLE_TITLE_KEYS = [
		'halal'       => 'halal.title',
		'branches'    => 'nav.branches',
		'menu'        => 'nav.menu',
		'offers'      => 'offers.title',
		'reservation' => 'res.title',
		'submitted'   => 'res.submitted.heading',
		'status'      => 'status.title',
		'contact'     => 'nav.contact',
		'faq'         => 'nav.faq',
		'gallery'     => 'nav.gallery',
		'news'        => 'nav.news',
		'about'       => 'nav.about',
	];

	/** Role → lead string used as description fallback for string-driven pages. */
	public const ROLE_LEAD_KEYS = [
		'halal'       => 'halal.lead',
		'branches'    => 'branch.dir.intro',
		'menu'        => 'menu.intro_default',
		'offers'      => 'offers.intro',
		'reservation' => 'res.intro',
		'contact'     => 'contact.lead',
	];

	/** SEO translation keys (i18n-seo-analytics.md §3.1) ↔ JA source meta. */
	public const FIELDS = [
		'seo_title'       => 'momo_seo_title',
		'seo_description' => 'momo_seo_description',
		'og_title'        => 'momo_og_title',
		'og_description'  => 'momo_og_description',
	];

	/** og:locale per language (§9.1 step 3). */
	public const OG_LOCALE = [ 'ja' => 'ja_JP', 'en' => 'en_US', 'ar' => 'ar_AR', 'ur' => 'ur_PK', 'hi' => 'hi_IN', 'bn' => 'bn_BD' ];

	/** SEO field value of an entity in $lang ('' when unset or not approved). */
	public static function field( string $kind, int $id, string $key, string $lang ): string {
		if ( $id <= 0 || ! isset( self::FIELDS[ $key ] ) ) {
			return '';
		}
		$is_term = Context::CATEGORY === $kind;
		if ( Lang::SOURCE === $lang ) {
			$v = $is_term ? get_term_meta( $id, self::FIELDS[ $key ], true ) : get_post_meta( $id, self::FIELDS[ $key ], true );
			return trim( (string) $v );
		}
		return trim( $is_term ? Translations::term( $id, $key, $lang ) : Translations::post( $id, $key, $lang ) );
	}

	public static function brand( string $lang ): string {
		return momos_t( 'seo.brand_short', [], $lang );
	}

	/** Visible name of the current entity in $lang ('' when untranslated). */
	public static function entity_name( string $kind, int $id, string $lang, string $role = '' ): string {
		switch ( $kind ) {
			case Context::BRANCH:
			case Context::DISH:
			case Context::NEWS:
				return trim( Translations::post( $id, 'title', $lang ) );
			case Context::CATEGORY:
				return trim( Translations::term( $id, 'name', $lang ) );
			case Context::PAGE:
			case Context::NEWS_IDX:
				// Pages whose H1 is a fixed string in the template use that string (title = visible H1).
				if ( in_array( $role, [ 'halal', 'branches', 'menu', 'offers', 'reservation', 'submitted', 'status', 'contact' ], true ) ) {
					return momos_t( self::ROLE_TITLE_KEYS[ $role ], [], $lang );
				}
				$t = $id > 0 ? trim( Translations::post( $id, 'title', $lang ) ) : '';
				if ( '' === $t && isset( self::ROLE_TITLE_KEYS[ $role ] ) ) {
					$t = momos_t( self::ROLE_TITLE_KEYS[ $role ], [], $lang );
				}
				return $t;
		}
		return '';
	}

	/** Resolved `<title>` text for the current request. */
	public static function title( string $lang ): string {
		$c     = Context::current();
		$brand = self::brand( $lang );
		if ( Context::NOTFOUND === $c['kind'] ) {
			return momos_t( 'seo.title.default', [ 'title' => momos_t( '404.title', [], $lang ), 'brand' => $brand ], $lang );
		}
		$custom = self::field( $c['kind'], $c['id'], 'seo_title', $lang );
		if ( '' !== $custom ) {
			return $custom;
		}
		if ( Context::HOME === $c['kind'] ) {
			return momos_t( 'seo.title.home', [ 'brand' => $brand ], $lang );
		}
		$name = self::entity_name( $c['kind'], $c['id'], $lang, $c['role'] );
		if ( Context::BRANCH === $c['kind'] ) {
			$area = trim( Translations::post( $c['id'], 'momo_area_label', $lang ) );
			return '' !== $area
				? momos_t( 'seo.title.branch', [ 'branch' => $name, 'area' => $area, 'brand' => $brand ], $lang )
				: momos_t( 'seo.title.branch_no_area', [ 'branch' => $name, 'brand' => $brand ], $lang );
		}
		if ( Context::DISH === $c['kind'] ) {
			$cat  = (int) get_post_meta( $c['id'], 'momo_primary_category', true );
			$term = $cat > 0 ? trim( Translations::term( $cat, 'name', $lang ) ) : '';
			if ( '' !== $term ) {
				return momos_t( 'seo.title.dish', [ 'dish' => $name, 'category' => $term, 'brand' => $brand ], $lang );
			}
		}
		if ( '' === $name ) {
			return $brand;
		}
		$title = momos_t( 'seo.title.default', [ 'title' => $name, 'brand' => $brand ], $lang );
		$paged = (int) get_query_var( 'paged' );
		if ( Context::NEWS_IDX === $c['kind'] && $paged > 1 ) {
			$title = momos_t( 'seo.title.paged', [ 'title' => $title, 'n' => $paged ], $lang );
		}
		return $title;
	}

	/** Meta description in $lang, '' = omit the tag (§9.1 step 2). */
	public static function description( string $lang ): string {
		$c = Context::current();
		if ( in_array( $c['kind'], [ Context::NOTFOUND, Context::OTHER ], true ) ) {
			return '';
		}
		$custom = self::field( $c['kind'], $c['id'], 'seo_description', $lang );
		if ( '' !== $custom ) {
			return self::truncate( $custom, $lang );
		}
		$text = '';
		switch ( $c['kind'] ) {
			case Context::HOME:
				$text = class_exists( \Momos\Presentation\View\Brand::class ) ? \Momos\Presentation\View\Brand::text( 'momo_tagline', $lang ) : '';
				break;
			case Context::DISH:
				$text = Translations::post( $c['id'], 'momo_short_description', $lang );
				if ( '' === trim( $text ) ) {
					$text = Translations::post( $c['id'], 'content', $lang );
				}
				break;
			case Context::CATEGORY:
				$text = Translations::term( $c['id'], 'description', $lang );
				break;
			case Context::BRANCH:
				$text = Translations::post( $c['id'], 'content', $lang );
				break;
			case Context::PAGE:
			case Context::NEWS_IDX:
			case Context::NEWS:
				$text = $c['id'] > 0 ? Translations::post( $c['id'], 'excerpt', $lang ) : '';
				if ( '' === trim( $text ) && isset( self::ROLE_LEAD_KEYS[ $c['role'] ] ) ) {
					$text = momos_t( self::ROLE_LEAD_KEYS[ $c['role'] ], [], $lang );
				}
				if ( '' === trim( $text ) && Context::NEWS === $c['kind'] ) {
					$text = Translations::post( $c['id'], 'content', $lang );
				}
				break;
		}
		return self::truncate( $text, $lang );
	}

	/** Plain text, collapsed whitespace, cut at a word/sentence boundary within the limit. */
	public static function truncate( string $text, string $lang ): string {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( strip_shortcodes( $text ) ), ENT_QUOTES, 'UTF-8' ) ) );
		$max  = Lang::SOURCE === $lang ? self::DESC_MAX_JA : self::DESC_MAX;
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}
		$cut = mb_substr( $text, 0, $max );
		// Prefer a sentence end, then a space (Latin/Devanagari/Bengali/Arabic scripts), else hard cut (ja).
		$min = (int) ( $max * 0.6 );
		if ( preg_match( '/^(.*[。．！？.!?।؟])[^。．！？.!?।؟]*$/us', $cut, $m ) && mb_strlen( $m[1] ) >= $min ) {
			return rtrim( $m[1] );
		}
		if ( preg_match( '/^(.*\S)\s+\S*$/us', mb_substr( $text, 0, $max - 1 ), $m ) && mb_strlen( $m[1] ) >= $min ) {
			return rtrim( $m[1] ) . '…';
		}
		return rtrim( mb_substr( $cut, 0, $max - 1 ) ) . '…';
	}

	/**
	 * Open Graph image of the current entity: `momo_og_image` → featured image (not an illustrative image
	 * on branch/About pages, DEC-047) → brand logo.
	 *
	 * @return array{url:string,width:int,height:int,alt:string}|null
	 */
	public static function og_image( string $lang ): ?array {
		$c   = Context::current();
		$ids = [];
		if ( $c['id'] > 0 ) {
			$ids[] = (int) ( Context::CATEGORY === $c['kind'] ? get_term_meta( $c['id'], 'momo_og_image', true ) : get_post_meta( $c['id'], 'momo_og_image', true ) );
			if ( Context::CATEGORY !== $c['kind'] ) {
				$thumb  = (int) get_post_thumbnail_id( $c['id'] );
				$strict = Context::BRANCH === $c['kind'] || 'about' === $c['role'];
				if ( $thumb && ! ( $strict && '1' === (string) get_post_meta( $thumb, 'momo_image_illustrative', true ) ) ) {
					$ids[] = $thumb;
				}
			}
		}
		if ( class_exists( \Momos\Presentation\View\Brand::class ) ) {
			$ids[] = (int) \Momos\Presentation\View\Brand::option( 'momo_logo_primary' );
		}
		foreach ( array_filter( $ids ) as $aid ) {
			$src = wp_get_attachment_image_src( $aid, 'full' );
			if ( is_array( $src ) && ! empty( $src[0] ) ) {
				return [ 'url' => (string) $src[0], 'width' => (int) $src[1], 'height' => (int) $src[2], 'alt' => self::alt( $aid, $lang ) ];
			}
		}
		return null;
	}

	public static function alt( int $attachment_id, string $lang ): string {
		return Lang::SOURCE === $lang ? (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) : Translations::post( $attachment_id, 'alt', $lang );
	}
}
