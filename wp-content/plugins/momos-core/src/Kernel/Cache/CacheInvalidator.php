<?php
declare(strict_types=1);

namespace Momos\Kernel\Cache;

use Momos\Kernel\I18n\Lang;
use Momos\Kernel\Log\Logger;

/**
 * Page-cache headers, tags and purges without a cache plugin (NFR-PERF-02, gaps.md §4, IMPL-004).
 *
 * Adapter (constant MOMOS_CACHE_ADAPTER): litespeed | cloudflare | null (default).
 *  - litespeed: server cache via X-LiteSpeed-* headers (works without the LSCache plugin).
 *  - cloudflare: URL purges through the API (MOMOS_CF_ZONE_ID, MOMOS_CF_API_TOKEN).
 *  - null: no edge cache; public pages use s-maxage=60 so staleness is bounded.
 */
final class CacheInvalidator {
	/** @var list<string> */
	private static array $tags = [ 'momo-global' ];

	/** @var array<string,bool> */
	private static array $pending = [];

	public static function register(): void {
		add_action( 'template_redirect', [ self::class, 'tag_queried_object' ], 1 );
		add_action( 'send_headers', [ self::class, 'headers' ], 99 );
		add_action( 'shutdown', [ self::class, 'flush' ], 0 );
		add_action( 'momos_cache_purge_retry', [ self::class, 'retry' ] );

		// Generic purge request from any module: do_action( 'momos/cache/purge', [ 'momo-dish-12', … ] ).
		add_action( 'momos/cache/purge', static fn( $tags ) => self::purge( array_map( 'strval', (array) $tags ) ) );

		// Domain events → purge (SDD §2.5 consumers "cache purge").
		$branch = static fn( $id ) => self::purge( [ 'momo-branch-' . (int) $id, 'momo-home' ] );
		add_action( 'momos/branch/schedule_changed', $branch );
		add_action( 'momos/branch/settings_changed', $branch );
		add_action( 'momos/branch/identity_changed', static fn() => self::purge( [ 'momo-global' ] ) );
		add_action( 'momos/offer/visibility_changed', static fn( $id, $global = false ) => self::purge( $global ? [ 'momo-global' ] : [ 'momo-offers', 'momo-home' ] ), 10, 2 );
		add_action(
			'momos/branch_menu_item/updated',
			static function ( $item ): void {
				$item = (array) $item;
				self::purge( array_filter( [ isset( $item['dish_id'] ) ? 'momo-dish-' . (int) $item['dish_id'] : null, isset( $item['branch_id'] ) ? 'momo-branch-' . (int) $item['branch_id'] : null, 'momo-menu' ] ) );
			}
		);
		foreach ( [ 'momos/dish/published', 'momos/dish/retired', 'momos/dietary/review_required' ] as $h ) {
			add_action( $h, static fn( $id ) => self::purge( [ 'momo-dish-' . (int) $id, 'momo-menu', 'momo-home' ] ) );
		}
		add_action( 'momos/dietary/claims_changed', static fn() => self::purge( [ 'momo-halal', 'momo-menu', 'momo-home', 'momo-branches' ] ) );
		add_action( 'momos/certificate/status_changed', static fn() => self::purge( [ 'momo-halal', 'momo-menu', 'momo-home', 'momo-branches' ] ) );
		add_action( 'momos/i18n/translation_saved', static fn( $type, $id ) => 'post' === $type ? self::purge( [ 'momo-post-' . (int) $id ] ) : self::purge( [ 'momo-global' ] ), 10, 2 );
		add_action(
			'save_post',
			static function ( $id, $post ): void {
				if ( ! wp_is_post_revision( $id ) && 'publish' === ( $post->post_status ?? '' ) ) {
					$map = [ 'momo_branch' => 'momo-branch-', 'momo_dish' => 'momo-dish-' ];
					self::purge( [ ( $map[ $post->post_type ] ?? 'momo-post-' ) . (int) $id, 'momo-home', 'page' === $post->post_type ? 'momo-global' : 'momo-menu' ] );
				}
			},
			99,
			2
		);
	}

	public static function adapter(): string {
		$a = defined( 'MOMOS_CACHE_ADAPTER' ) ? (string) MOMOS_CACHE_ADAPTER : 'null';
		return in_array( $a, [ 'litespeed', 'cloudflare', 'null' ], true ) ? $a : 'null';
	}

	public static function tag( string ...$tags ): void {
		foreach ( $tags as $t ) {
			self::$tags[] = sanitize_key( $t );
		}
	}

	public static function tag_queried_object(): void {
		$o = get_queried_object();
		if ( $o instanceof \WP_Post ) {
			self::tag(
				match ( $o->post_type ) {
				'momo_branch' => 'momo-branch-' . $o->ID,
				'momo_dish' => 'momo-dish-' . $o->ID,
				default => 'momo-post-' . $o->ID,
				}
			);
		} elseif ( $o instanceof \WP_Term ) {
			self::tag( 'momo-cat-' . $o->term_id, 'momo-menu' );
		}
		if ( is_front_page() ) {
			self::tag( 'momo-home' );
		}
	}

	/** Whether the current response may be stored by a shared cache. */
	public static function cacheable(): bool {
		if ( is_admin() || is_user_logged_in() || 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) || is_404() || is_preview() || is_search() ) { // phpcs:ignore
			return false;
		}
		$uri = (string) ( $_SERVER['REQUEST_URI'] ?? '' ); // phpcs:ignore
		if ( str_contains( $uri, '/wp-json/' ) || str_contains( $uri, '/reservation/submitted' ) || str_contains( $uri, '/reservation/status' ) ) {
			return false;
		}
		return (bool) apply_filters( 'momos/cache/cacheable', true );
	}

	public static function headers(): void {
		if ( headers_sent() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		if ( 'litespeed' === self::adapter() ) {
			$queued = (array) get_option( 'momos_cache_purge_queue', [] );
			if ( $queued ) {
				delete_option( 'momos_cache_purge_queue' );
				header( 'X-LiteSpeed-Purge: ' . ( in_array( 'momo-global', $queued, true ) ? '*' : implode( ', ', array_map( static fn( $t ) => 'tag=' . $t, $queued ) ) ), false );
			}
		}
		if ( is_admin() ) {
			return;
		}
		if ( ! self::cacheable() ) {
			if ( 'litespeed' === self::adapter() ) {
				header( 'X-LiteSpeed-Cache-Control: no-cache' );
			}
			return;
		}
		$ttl = 'null' === self::adapter() ? 60 : 86400;
		header( 'Cache-Control: public, max-age=0, s-maxage=' . $ttl );
		if ( 'litespeed' === self::adapter() ) {
			header( 'X-LiteSpeed-Cache-Control: public,max-age=' . $ttl );
			header( 'X-LiteSpeed-Tag: ' . implode( ',', array_unique( self::$tags ) ) );
		}
	}

	/** @param list<string> $tags */
	public static function purge( array $tags ): void {
		foreach ( $tags as $t ) {
			self::$pending[ sanitize_key( (string) $t ) ] = true;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			self::flush();
		}
	}

	public static function purge_all(): void {
		self::purge( [ 'momo-global' ] );
		self::flush();
	}

	/** @return list<string> pending tags (tests). */
	public static function pending(): array {
		return array_keys( self::$pending );
	}

	public static function flush(): void {
		if ( ! self::$pending ) {
			return;
		}
		$tags          = array_keys( self::$pending );
		self::$pending = [];
		do_action( 'momos/cache/purged', $tags );
		try {
			match ( self::adapter() ) {
				'litespeed' => self::purge_litespeed( $tags ),
				'cloudflare' => self::purge_cloudflare( $tags ),
				default => null,
			};
		} catch ( \Throwable $e ) {
			Logger::warning( 'cache.purge_failed', [ 'adapter' => self::adapter(), 'tags' => count( $tags ) ] );
			\Momos\Kernel\Jobs\Schedule::at( new \DateTimeImmutable( '+60 seconds' ), 'momos_cache_purge_retry', [ $tags ] );
		}
	}

	/** @param list<string> $tags */
	public static function retry( array $tags ): void {
		self::purge( $tags );
		self::flush();
	}

	/** @param list<string> $tags */
	private static function purge_litespeed( array $tags ): void {
		$value = in_array( 'momo-global', $tags, true ) ? '*' : implode( ', ', array_map( static fn( $t ) => 'tag=' . $t, $tags ) );
		if ( ! headers_sent() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			header( 'X-LiteSpeed-Purge: ' . $value, false );
			return;
		}
		// CLI/jobs cannot send headers: queue for the next web request (handled on send_headers).
		$q = (array) get_option( 'momos_cache_purge_queue', [] );
		update_option( 'momos_cache_purge_queue', array_values( array_unique( array_merge( $q, $tags ) ) ), false );
	}

	/** @param list<string> $tags */
	private static function purge_cloudflare( array $tags ): void {
		$zone  = defined( 'MOMOS_CF_ZONE_ID' ) ? (string) MOMOS_CF_ZONE_ID : '';
		$token = defined( 'MOMOS_CF_API_TOKEN' ) ? (string) MOMOS_CF_API_TOKEN : '';
		if ( '' === $zone || '' === $token ) {
			return;
		}
		$endpoint = 'https://api.cloudflare.com/client/v4/zones/' . rawurlencode( $zone ) . '/purge_cache';
		if ( in_array( 'momo-global', $tags, true ) ) {
			$bodies = [ [ 'purge_everything' => true ] ];
		} else {
			$urls   = self::urls_for_tags( $tags );
			$bodies = array_map( static fn( $chunk ) => [ 'files' => $chunk ], array_chunk( $urls, 30 ) );
		}
		foreach ( $bodies as $body ) {
			$res = wp_remote_post(
				$endpoint,
				[
					'timeout' => 5,
					'headers' => [ 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ],
					'body'    => (string) wp_json_encode( $body ),
				]
			);
			if ( is_wp_error( $res ) || wp_remote_retrieve_response_code( $res ) >= 300 ) {
				throw new \RuntimeException( 'Cloudflare purge failed' );
			}
		}
	}

	/**
	 * Tag → URLs in every enabled language (for URL-based CDNs).
	 *
	 * @param list<string> $tags
	 * @return list<string>
	 */
	public static function urls_for_tags( array $tags ): array {
		$paths = [];
		foreach ( $tags as $t ) {
			if ( 'momo-home' === $t ) {
				$paths[] = '/';
			} elseif ( 'momo-menu' === $t ) {
				$paths[] = '/menu/';
			} elseif ( 'momo-offers' === $t ) {
				$paths[] = '/offers/';
			} elseif ( 'momo-halal' === $t ) {
				$paths[] = '/halal/';
			} elseif ( 'momo-branches' === $t ) {
				$paths[] = '/branches/';
			} elseif ( preg_match( '/^momo-(branch|dish|post)-(\d+)$/', $t, $m ) ) {
				$link = get_permalink( (int) $m[2] );
				if ( $link ) {
					$paths[] = (string) wp_parse_url( $link, PHP_URL_PATH );
				}
				if ( 'branch' === $m[1] ) {
					$paths[] = '/branches/';
				}
			} elseif ( preg_match( '/^momo-cat-(\d+)$/', $t, $m ) ) {
				$term = get_term( (int) $m[1] );
				if ( $term instanceof \WP_Term ) {
					$paths[] = '/menu/' . $term->slug . '/';
				}
			}
		}
		$urls = [];
		foreach ( array_unique( $paths ) as $path ) {
			$path = \Momos\Kernel\I18n\Url::strip_prefix( $path );
			foreach ( Lang::enabled() as $lang ) {
				$urls[] = \Momos\Kernel\I18n\Url::to( $path, $lang );
			}
		}
		return array_values( array_unique( $urls ) );
	}
}
