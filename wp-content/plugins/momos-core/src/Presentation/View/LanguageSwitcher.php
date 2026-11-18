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
 * Only the query parameters `branch`, `diet`, `spice` survive.
 */
final class LanguageSwitcher {
	public const KEEP_QUERY = [ 'branch', 'diet', 'spice' ];

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
		foreach ( self::KEEP_QUERY as $key ) {
			if ( isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				$val = sanitize_text_field( wp_unslash( $_GET[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
				if ( '' !== $val && preg_match( '/^[A-Za-z0-9_,-]{1,60}$/', $val ) ) {
					$query[ $key ] = $val;
				}
			}
		}
		return $query ? add_query_arg( $query, $url ) : $url;
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
