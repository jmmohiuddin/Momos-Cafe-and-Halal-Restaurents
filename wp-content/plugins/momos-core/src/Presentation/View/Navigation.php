<?php
declare(strict_types=1);

namespace Momos\Presentation\View;

use Momos\Kernel\I18n\Locale;
use Momos\Presentation\PageContext;
use Momos\Presentation\SystemPages;

/**
 * Code-defined navigation (ui-design-system.md §8.2, Doc 06 §3, FR-CORP-008/009, DEC-060). There is
 * no WP menu: items, string keys and URLs are fixed here, every link stays in the current language.
 */
final class Navigation {
	/** "Offers" header/mobile item only while ≥ 1 active offer is visible in $lang (DEC-060). */
	public static function offers_active( ?string $lang = null ): bool {
		$lang = $lang ?? Locale::current();
		if ( ! class_exists( \Momos\Branch\Query\OfferQuery::class ) ) {
			return false;
		}
		try {
			return (bool) apply_filters( 'momos/presentation/offers_active', \Momos\Branch\Query\OfferQuery::has_active( $lang ), $lang );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Desktop header items: Menu · Branches ▾ · [Offers] · Halal · About · Reservation.
	 *
	 * @return list<array{key:string,label:string,url:string,current:bool,children?:list<array{label:string,sub:string,url:string}>}>
	 */
	public static function primary( ?string $lang = null ): array {
		$lang  = $lang ?? Locale::current();
		$items = [
			self::item( 'menu', 'nav.menu', SystemPages::url( 'menu', $lang ), $lang ),
			self::item( 'branches', 'nav.branches', SystemPages::url( 'branches', $lang ), $lang ) + [ 'children' => self::branch_children( $lang ) ],
		];
		if ( self::offers_active( $lang ) ) {
			$items[] = self::item( 'offers', 'nav.offers', SystemPages::url( 'offers', $lang ), $lang );
		}
		$items[] = self::item( 'halal', 'nav.halal', SystemPages::url( 'halal', $lang ), $lang );
		$items[] = self::item( 'about', 'nav.about', SystemPages::url( 'about', $lang ), $lang );
		$items[] = self::item( 'reservation', 'nav.reservation', SystemPages::url( 'reservation', $lang ), $lang );
		return self::linked( $items );
	}

	/**
	 * Mobile menu rows: Menu · Branches (expandable) · Halal information · [Offers] · About · FAQ · Contact · [Gallery] · [News].
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function mobile( ?string $lang = null ): array {
		$lang  = $lang ?? Locale::current();
		$items = [
			self::item( 'menu', 'nav.menu', SystemPages::url( 'menu', $lang ), $lang ),
			self::item( 'branches', 'nav.branches', SystemPages::url( 'branches', $lang ), $lang ) + [ 'children' => self::branch_children( $lang ) ],
			self::item( 'halal', 'cta.halal_info', SystemPages::url( 'halal', $lang ), $lang ),
		];
		if ( self::offers_active( $lang ) ) {
			$items[] = self::item( 'offers', 'nav.offers', SystemPages::url( 'offers', $lang ), $lang );
		}
		$items[] = self::item( 'about', 'nav.about', SystemPages::url( 'about', $lang ), $lang );
		foreach ( [ 'faq', 'contact', 'gallery', 'news' ] as $role ) {
			$url = SystemPages::url( $role, $lang );
			if ( null !== $url ) {
				$items[] = self::item( $role, 'nav.' . $role, $url, $lang );
			}
		}
		return self::linked( $items );
	}

	/**
	 * Footer "Explore": Menu · Offers (always) · Halal information · About · FAQ · Contact · Gallery (C) · News (C).
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function explore( ?string $lang = null ): array {
		$lang  = $lang ?? Locale::current();
		$items = [
			self::item( 'menu', 'nav.menu', SystemPages::url( 'menu', $lang ), $lang ),
			self::item( 'offers', 'nav.offers', SystemPages::url( 'offers', $lang ), $lang ),
			self::item( 'halal', 'cta.halal_info', SystemPages::url( 'halal', $lang ), $lang ),
			self::item( 'about', 'nav.about', SystemPages::url( 'about', $lang ), $lang ),
		];
		foreach ( [ 'faq', 'contact', 'gallery', 'news' ] as $role ) {
			$url = SystemPages::url( $role, $lang );
			if ( null !== $url ) {
				$items[] = self::item( $role, 'nav.' . $role, $url, $lang );
			}
		}
		return self::linked( $items );
	}

	/**
	 * Footer legal column (5 legal pages + Analytics settings anchor on C17). Missing pages are omitted.
	 *
	 * @return list<array{key:string,label:string,url:string}>
	 */
	public static function legal( ?string $lang = null ): array {
		$lang = $lang ?? Locale::current();
		$out  = [];
		foreach ( array_keys( SystemPages::LEGAL ) as $key ) {
			$url = SystemPages::legal_url( $key, $lang );
			if ( null !== $url ) {
				$out[] = [ 'key' => $key, 'label' => momos_t( 'ftr.' . $key, [], $lang ), 'url' => $url ];
			}
		}
		$ext = SystemPages::legal_url( 'ext_trans', $lang );
		if ( null !== $ext ) {
			$out[] = [ 'key' => 'analytics', 'label' => momos_t( 'ftr.analytics', [], $lang ), 'url' => $ext . '#analytics-settings' ];
		}
		return $out;
	}

	/**
	 * Drops items without a URL in this language (page hidden there: no link to a 404, TC-NAV-005).
	 *
	 * @param list<array<string,mixed>> $items
	 * @return list<array<string,mixed>>
	 */
	private static function linked( array $items ): array {
		return array_values( array_filter( $items, static fn( $i ) => '' !== (string) ( $i['url'] ?? '' ) ) );
	}

	/** @return list<array{label:string,sub:string,url:string}> */
	private static function branch_children( string $lang ): array {
		$out = [];
		foreach ( BranchLinks::all( $lang ) as $b ) {
			$out[] = [ 'label' => $b['name'], 'sub' => $b['area'], 'url' => $b['url'] ];
		}
		return $out;
	}

	/** @return array{key:string,label:string,url:string,current:bool} */
	private static function item( string $key, string $label_key, ?string $url, string $lang ): array {
		return [
			'key'     => $key,
			'label'   => momos_t( $label_key, [], $lang ),
			'url'     => (string) $url,
			'current' => PageContext::nav_section() === $key,
		];
	}
}
