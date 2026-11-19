<?php
declare(strict_types=1);

namespace Momos\Seo;

use Momos\Kernel\I18n\Lang;
use Momos\Kernel\I18n\Router;
use Momos\Kernel\I18n\Translations;
use Momos\Kernel\I18n\Url;

/**
 * XML sitemaps (FR-SEO-004, AC-SEO-01-03, i18n-seo-analytics.md §9.5) replacing the WordPress core
 * sitemaps: `/sitemap.xml` = index of `/sitemaps/{lang}-{type}[-n].xml` for each **enabled** language
 * and each non-empty type; children list only indexable, published URLs visible in that language
 * (≤ 1,000 URLs per file). Hidden/unknown language or type → 404. `/wp-sitemap*.xml` → 301 index.
 * Lists are cached and invalidated by a version bump on any content/translation/language change.
 */
final class Sitemap {
	public const TYPES      = [ 'pages', 'branches', 'menu-categories', 'dishes', 'news' ];
	public const PER_FILE   = 1000;
	public const VERSION    = 'momos_seo_sitemap_ver';
	private const CACHE_TTL = 6 * HOUR_IN_SECONDS;
	/** Bumped when the inclusion rules change, so lists cached under the old rules are not served. */
	private const RULES_REV = 2;

	public static function register(): void {
		add_filter( 'wp_sitemaps_enabled', '__return_false' );
		add_action( 'parse_request', [ self::class, 'route' ], 2 );
		foreach ( [ 'save_post', 'deleted_post', 'trashed_post', 'created_term', 'edited_term', 'delete_term', 'momos/i18n/translation_saved', 'momos/offer/visibility_changed', 'momos/dish/published', 'momos/dish/retired', 'momos/branch/identity_changed', 'update_option_momos_languages_enabled', 'update_option_page_on_front' ] as $hook ) {
			add_action( $hook, [ self::class, 'bump' ] );
		}
	}

	public static function bump(): void {
		update_option( self::VERSION, (int) get_option( self::VERSION, 0 ) + 1, true );
	}

	/** Request path relative to home, without query. */
	public static function request_path(): string {
		$path = (string) wp_parse_url( Router::original_uri(), PHP_URL_PATH );
		$base = (string) wp_parse_url( Url::raw_home(), PHP_URL_PATH );
		if ( '' !== $base && str_starts_with( $path, $base ) ) {
			$path = substr( $path, strlen( $base ) );
		}
		return '' === $path ? '/' : $path;
	}

	public static function route(): void {
		$path = self::request_path();
		if ( preg_match( '#^/wp-sitemap[a-z0-9-]*\.xml$#', $path ) || '/wp-sitemap.xsl' === $path ) {
			wp_safe_redirect( Url::raw_home() . '/sitemap.xml', 301, 'momos' );
			exit;
		}
		if ( '/sitemap.xml' === $path ) {
			self::send( self::render_index() );
		}
		if ( preg_match( '#^/sitemaps/([a-z]{2})-([a-z-]+?)(?:-(\d{1,3}))?\.xml$#', $path, $m ) ) {
			$xml = self::render_file( $m[1], $m[2], isset( $m[3] ) ? (int) $m[3] : 1 );
			if ( null === $xml ) {
				status_header( 404 );
				nocache_headers();
				header( 'Content-Type: text/plain; charset=UTF-8' );
				header( 'X-Robots-Tag: noindex' );
				echo 'Not found';
				exit;
			}
			self::send( $xml );
		}
	}

	private static function send( string $xml ): void {
		status_header( 200 );
		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex' );
		header( 'Cache-Control: public, max-age=300, s-maxage=3600' );
		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput -- values escaped with esc_xml.
		exit;
	}

	public static function render_index(): string {
		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
		foreach ( self::files() as $f ) {
			$xml .= "\t<sitemap><loc>" . esc_xml( $f['loc'] ) . '</loc>' . ( '' !== $f['lastmod'] ? '<lastmod>' . esc_xml( $f['lastmod'] ) . '</lastmod>' : '' ) . "</sitemap>\n";
		}
		return $xml . '</sitemapindex>' . "\n";
	}

	/**
	 * Child files of the index.
	 *
	 * @return list<array{loc:string,lastmod:string,lang:string,type:string,n:int}>
	 */
	public static function files(): array {
		$out = [];
		foreach ( Lang::enabled() as $lang ) {
			foreach ( self::TYPES as $type ) {
				$urls = self::urls( $type, $lang );
				foreach ( array_chunk( $urls, self::PER_FILE ) as $i => $chunk ) {
					$n     = $i + 1;
					$out[] = [
						'loc'     => Url::raw_home() . '/sitemaps/' . $lang . '-' . $type . ( $n > 1 ? '-' . $n : '' ) . '.xml',
						'lastmod' => (string) max( array_column( $chunk, 'lastmod' ) ?: [ '' ] ),
						'lang'    => $lang,
						'type'    => $type,
						'n'       => $n,
					];
				}
			}
		}
		return $out;
	}

	public static function render_file( string $lang, string $type, int $n = 1 ): ?string {
		if ( ! Lang::is_valid( $lang ) || ! Lang::is_enabled( $lang ) || ! in_array( $type, self::TYPES, true ) || $n < 1 ) {
			return null;
		}
		$chunk = array_slice( self::urls( $type, $lang ), ( $n - 1 ) * self::PER_FILE, self::PER_FILE );
		if ( ! $chunk ) {
			return null;
		}
		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
		foreach ( $chunk as $u ) {
			$xml .= "\t<url><loc>" . esc_xml( $u['loc'] ) . '</loc>' . ( '' !== $u['lastmod'] ? '<lastmod>' . esc_xml( $u['lastmod'] ) . '</lastmod>' : '' ) . "</url>\n";
		}
		return $xml . '</urlset>' . "\n";
	}

	/**
	 * Indexable URLs of a type in a language (cached).
	 *
	 * @return list<array{loc:string,lastmod:string}>
	 */
	public static function urls( string $type, string $lang ): array {
		if ( ! Lang::is_enabled( $lang ) || ! in_array( $type, self::TYPES, true ) ) {
			return [];
		}
		$key    = 'momos_sm_' . md5( self::RULES_REV . '|' . get_option( self::VERSION, 0 ) . '|' . $lang . '|' . $type . '|' . implode( ',', Lang::enabled() ) );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$urls = match ( $type ) {
			'pages' => self::pages( $lang ),
			'branches' => self::branches( $lang ),
			'menu-categories' => self::categories( $lang ),
			'dishes' => self::dishes( $lang ),
			'news' => self::news( $lang ),
		};
		$urls = array_values( array_unique( $urls, SORT_REGULAR ) );
		set_transient( $key, $urls, self::CACHE_TTL );
		return $urls;
	}

	/** @return list<array{loc:string,lastmod:string}> */
	private static function pages( string $lang ): array {
		$front = (int) get_option( 'page_on_front' );
		$out   = [ [ 'loc' => Url::to( '/', $lang ), 'lastmod' => $front ? self::post_lastmod( $front, $lang ) : '' ] ];
		$ids   = get_posts(
			[
				'post_type'        => 'page',
				'post_status'      => 'publish',
				'posts_per_page'   => 1000,
				'fields'           => 'ids',
				'orderby'          => [ 'menu_order' => 'ASC', 'ID' => 'ASC' ],
				'has_password'     => false,
				'no_found_rows'    => true,
				'suppress_filters' => true,
			]
		);
		$staff = class_exists( \Momos\Presentation\Templates\TemplateRouter::class ) ? \Momos\Presentation\Templates\TemplateRouter::STAFF_ONLY : [];
		update_postmeta_cache( array_map( 'intval', $ids ) );
		foreach ( array_map( 'intval', $ids ) as $id ) {
			if ( $id === $front ) {
				continue;
			}
			$role = Context::role_of( $id );
			if ( in_array( $role, Context::NOINDEX_ROLES, true ) || '1' === (string) get_post_meta( $id, 'momo_noindex', true )
				|| in_array( (string) get_page_template_slug( $id ), $staff, true ) ) {
				continue;
			}
			if ( ! Context::entity_visible( Context::PAGE, $id, $lang, $role ) ) {
				continue;
			}
			$loc = Context::entity_url( Context::PAGE, $id, $lang );
			if ( null !== $loc ) {
				$out[] = [ 'loc' => $loc, 'lastmod' => self::post_lastmod( $id, $lang ) ];
			}
		}
		return $out;
	}

	/** @return list<array{loc:string,lastmod:string}> */
	private static function branches( string $lang ): array {
		if ( ! class_exists( \Momos\Branch\Repository\BranchRepository::class ) ) {
			return [];
		}
		$out = [];
		foreach ( \Momos\Branch\Repository\BranchRepository::public_ids( $lang ) as $id ) {
			$loc = Context::entity_url( Context::BRANCH, $id, $lang );
			if ( null !== $loc ) {
				$out[] = [ 'loc' => $loc, 'lastmod' => self::post_lastmod( $id, $lang ) ];
			}
		}
		return $out;
	}

	/** @return list<array{loc:string,lastmod:string}> */
	private static function categories( string $lang ): array {
		if ( ! class_exists( \Momos\Menu\Service\MenuQueryService::class ) ) {
			return [];
		}
		$out = [];
		foreach ( \Momos\Menu\Service\MenuQueryService::categories( $lang ) as $t ) {
			$upd   = Lang::SOURCE === $lang ? '' : (string) get_term_meta( $t->term_id, '_momos_i18n_updated_' . $lang, true );
			$out[] = [ 'loc' => Url::to( '/menu/' . $t->slug . '/', $lang ), 'lastmod' => self::w3c( $upd ) ];
		}
		return $out;
	}

	/** @return list<array{loc:string,lastmod:string}> */
	private static function dishes( string $lang ): array {
		if ( ! class_exists( \Momos\Menu\Service\MenuQueryService::class ) ) {
			return [];
		}
		$ids = get_posts(
			[
				'post_type'        => 'momo_dish',
				'post_status'      => 'publish',
				'posts_per_page'   => 5000,
				'fields'           => 'ids',
				'orderby'          => [ 'menu_order' => 'ASC', 'ID' => 'ASC' ],
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_query'       => Translations::meta_query( $lang ), // phpcs:ignore WordPress.DB.SlowDBQuery
			]
		);
		update_postmeta_cache( array_map( 'intval', $ids ) );
		$out = [];
		foreach ( array_map( 'intval', $ids ) as $id ) {
			// §9.5: published, not retired, `momo_is_sample = 0` — demo dishes never reach a sitemap, in any
			// environment (they stay viewable outside production, where the SAMPLE badge marks them).
			if ( '1' === (string) get_post_meta( $id, 'momo_is_sample', true ) ) {
				continue;
			}
			if ( ! \Momos\Menu\Service\MenuQueryService::is_displayable( $id, $lang ) ) {
				continue;
			}
			$loc = Context::entity_url( Context::DISH, $id, $lang );
			if ( null !== $loc ) {
				$out[] = [ 'loc' => $loc, 'lastmod' => self::post_lastmod( $id, $lang ) ];
			}
		}
		return $out;
	}

	/** @return list<array{loc:string,lastmod:string}> */
	private static function news( string $lang ): array {
		$ids = get_posts(
			[
				'post_type'        => 'post',
				'post_status'      => 'publish',
				'posts_per_page'   => 1000,
				'fields'           => 'ids',
				'has_password'     => false,
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_query'       => Translations::meta_query( $lang ), // phpcs:ignore WordPress.DB.SlowDBQuery
			]
		);
		$out = [];
		foreach ( array_map( 'intval', $ids ) as $id ) {
			if ( Translations::post_visible( $id, $lang ) ) {
				$loc = Context::entity_url( Context::NEWS, $id, $lang );
				if ( null !== $loc ) {
					$out[] = [ 'loc' => $loc, 'lastmod' => self::post_lastmod( $id, $lang ) ];
				}
			}
		}
		return $out;
	}

	/** max(JA modified, translation updated in $lang) as W3C UTC. */
	private static function post_lastmod( int $id, string $lang ): string {
		$mod = (string) get_post_field( 'post_modified_gmt', $id );
		if ( Lang::SOURCE !== $lang ) {
			$tr  = (string) get_post_meta( $id, '_momos_i18n_updated_' . $lang, true );
			$mod = $tr > $mod ? $tr : $mod;
		}
		return self::w3c( $mod );
	}

	private static function w3c( string $mysql_utc ): string {
		if ( '' === $mysql_utc || str_starts_with( $mysql_utc, '0000' ) ) {
			return '';
		}
		$t = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $mysql_utc, new \DateTimeZone( 'UTC' ) );
		return $t ? $t->format( 'Y-m-d\TH:i:s\Z' ) : '';
	}
}
