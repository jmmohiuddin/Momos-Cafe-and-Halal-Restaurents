<?php
declare(strict_types=1);

namespace Momos\Presentation\View;

use Momos\Kernel\I18n\Lang;
use Momos\Kernel\I18n\Locale;
use Momos\Kernel\I18n\Translations;
use Momos\Kernel\I18n\Url;

/**
 * Language switcher targets (FR-I18N-002, i18n-seo-analytics.md §5.1). Server-side and identical for
 * every anonymous visitor (cache-safe):
 *  1) current singular object visible in the target → same URL with the target prefix;
 *  2) else target home + `?notice=untranslated`;
 *  3) on 404 → target home without notice.
 * Only the selection/filter parameters survive: `branch` and the menu filters as the Menu module names them
 * (`diet[]`, `max_spice`, `exclude[]`; legacy `spice`) — every tracking or unknown parameter is dropped.
 */
final class LanguageSwitcher {
	public const KEEP_QUERY = [ 'branch', 'diet', 'spice', 'max_spice', 'exclude' ];

	/**
	 * @return list<array{code:string,native:string,dir:string,url:string,current:bool,translated:bool}>
	 */
	public static function options( ?string $current = null ): array {
		$current = $current ?? Locale::current();
		$out     = [];
		foreach ( Lang::enabled() as $code ) {
			$target = self::target( $code );
			$out[]  = [
				'code'       => $code,
				'native'     => Lang::native( $code ),
				'dir'        => Lang::is_rtl( $code ) ? 'rtl' : 'ltr',
				'url'        => $target['url'],
				'current'    => $code === $current,
				'translated' => $target['translated'],
			];
		}
		return $out;
	}

	/** @return array{url:string,translated:bool} */
	public static function target( string $lang ): array {
		$home = Url::to( '/', $lang );
		if ( is_404() ) {
			return [ 'url' => $home, 'translated' => false ];
		}
		if ( is_front_page() ) {
			return [ 'url' => $home, 'translated' => true ];
		}
		$visible = true;
		$object  = get_queried_object();
		if ( $object instanceof \WP_Post ) {
			$visible = Translations::post_visible( $object, $lang );
		} elseif ( $object instanceof \WP_Term ) {
			$visible = Translations::term_visible( $object, $lang );
		}
		// AC-CORP-03-03: the halal page 404s in a language without reviewed statement templates.
		if ( $visible && $object instanceof \WP_Post && 'halal' === \Momos\Presentation\SystemPages::role_of( (int) $object->ID ) && ! HalalPage::published( $lang ) ) {
			$visible = false;
		}
		/** Lets modules veto a target (e.g. a hidden branch or a not-yet-translated custom route). */
		$visible = (bool) apply_filters( 'momos/presentation/switcher_visible', $visible, $lang, $object );
		if ( ! $visible ) {
			return [ 'url' => add_query_arg( 'notice', 'untranslated', $home ), 'translated' => false ];
		}
		return [ 'url' => self::current_url( $lang ), 'translated' => true ];
	}

	/** Current clean URL in $lang with only the meaningful query parameters. */
	public static function current_url( string $lang ): string {
		$url   = Url::to( Url::current_path(), $lang );
		$query = [];
		$ok    = static fn( $v ): bool => is_string( $v ) && '' !== $v && (bool) preg_match( '/^[A-Za-z0-9_,-]{1,60}$/', $v );
		foreach ( self::KEEP_QUERY as $key ) {
			if ( ! isset( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				continue;
			}
			$raw = wp_unslash( $_GET[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
			if ( is_string( $raw ) ) {
				$val = sanitize_text_field( $raw );
				if ( $ok( $val ) ) {
					$query[ $key ] = $val;
				}
			} elseif ( is_array( $raw ) ) {
				// `diet[]=vegetarian&diet[]=…` (multi-select filters), max 20 values.
				$vals = array_values( array_filter( array_map( static fn( $v ) => is_string( $v ) ? sanitize_text_field( $v ) : '', array_slice( $raw, 0, 20 ) ), $ok ) );
				if ( $vals ) {
					$query[ $key ] = $vals;
				}
			}
		}
		if ( ! $query ) {
			return $url;
		}
		// http_build_query keeps arrays as `diet[0]=…`; normalise to the `diet[]=…` form the filter form submits.
		$qs = (string) preg_replace( '/%5B\d+%5D=/', '%5B%5D=', http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ) );
		return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . $qs;
	}

	/**
	 * Data for the client-side suggestion bar (FR-I18N-003): only languages where this page exists.
	 *
	 * @return array<string, array{url:string,suggest:string,switch:string,dismiss:string,native:string,dir:string}>
	 */
	public static function suggestions( ?string $current = null ): array {
		$current = $current ?? Locale::current();
		$out     = [];
		foreach ( self::options( $current ) as $o ) {
			if ( $o['current'] || ! $o['translated'] ) {
				continue;
			}
			$out[ $o['code'] ] = [
				'url'     => $o['url'],
				'suggest' => momos_t( 'lang.suggest', [ 'language' => $o['native'] ], $o['code'] ),
				'switch'  => momos_t( 'lang.switch', [], $o['code'] ),
				'dismiss' => momos_t( 'lang.dismiss', [], $o['code'] ),
				'native'  => $o['native'],
				'dir'     => $o['dir'],
			];
		}
		return $out;
	}

	/** Is the "not yet available in {language}" notice requested on this (home) page? */
	public static function untranslated_notice(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification
		$n = isset( $_GET['notice'] ) ? (string) $_GET['notice'] : ( isset( $_GET['momo_notice'] ) ? (string) $_GET['momo_notice'] : '' );
		return in_array( $n, [ 'untranslated', 'missing_translation' ], true );
	}
}
