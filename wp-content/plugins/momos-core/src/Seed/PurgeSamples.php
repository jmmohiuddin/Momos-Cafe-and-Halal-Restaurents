<?php
declare(strict_types=1);

namespace Momos\Seed;

use Momos\Kernel\Db\Tables;
use Momos\Menu\ContentTypes;

/**
 * `wp momos purge-samples` (content-strings.md §10.1 D8, DEF-012): deletes every item flagged
 * `momo_is_sample = 1` — the demo seed's objects through Resetter (which also restores the pages and
 * settings the seed edited), then any other sample dish, offer, notice, menu category / dietary tag /
 * ingredient term and media item (D2 types flagged by ROLE-01/02). Seeded pages and branches are only
 * ever removed/restored by the Resetter; a sample-flagged page or branch that the seed did not create is
 * reported as skipped (system pages and branches with reservations are never deleted blindly).
 *
 * Non-production: runs directly. Production: requires `--yes` and a Super Administrator (`--user=`),
 * D8. Every run is audited `content.samples_purged` (counts only, no PII).
 */
final class PurgeSamples {
	public const AUDIT_ACTION = 'content.samples_purged';

	/** Sample post types deleted outright (D2 + notices). */
	public const DELETE_TYPES = [ ContentTypes::DISH, 'momo_offer', 'momo_notice' ];

	/** Sample-flagged post types only reported (removed by the Resetter when seeded). */
	public const REPORT_TYPES = [ 'momo_branch', 'page', 'post' ];

	/** @var array<string,int> */
	private array $counts = [];

	public static function register(): void {
		\WP_CLI::add_command( 'momos purge-samples', [ self::class, 'cli' ] );
	}

	/**
	 * Delete all sample content (momo_is_sample = 1).
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : List what would be removed (wp momos content sample-check) without changing anything.
	 *
	 * [--yes]
	 * : Required in production (together with --user=<Super Administrator>).
	 *
	 * ## EXAMPLES
	 *
	 *     wp momos purge-samples --dry-run
	 *     wp momos purge-samples
	 *     wp momos purge-samples --yes --user=admin   # production
	 *
	 * @param list<string>         $args
	 * @param array<string,string> $assoc
	 */
	public static function cli( array $args, array $assoc ): void {
		if ( isset( $assoc['dry-run'] ) ) {
			$rows = array_values( array_filter( SampleCheck::findings(), static fn( array $r ) => 'item' === $r['kind'] ) );
			if ( $rows ) {
				\WP_CLI\Utils\format_items( 'table', $rows, [ 'type', 'id', 'status', 'title', 'issue' ] );
			}
			\WP_CLI::success( sprintf( '%d sample item(s) would be removed (dry run).', count( $rows ) ) );
			return;
		}
		$error = self::refusal( isset( $assoc['yes'] ) );
		if ( null !== $error ) {
			\WP_CLI::error( $error );
		}
		try {
			$counts = ( new self() )->run();
			\WP_CLI\Utils\format_items( 'table', array_map( static fn( $k, $v ) => [ 'item' => $k, 'count' => $v ], array_keys( $counts ), $counts ), [ 'item', 'count' ] );
			\WP_CLI::success( 'Sample content purged.' );
		} catch ( SeedException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
	}

	public static function is_production(): bool {
		$wp_env = defined( 'WP_ENVIRONMENT_TYPE' ) ? (string) WP_ENVIRONMENT_TYPE : (string) getenv( 'WP_ENVIRONMENT_TYPE' );
		return \Momos\Kernel\Env::is_production() || 'production' === $wp_env;
	}

	/** Why the purge is refused for the current user/environment, or null (D8). */
	public static function refusal( bool $confirmed ): ?string {
		if ( ! self::is_production() ) {
			return null;
		}
		if ( ! $confirmed ) {
			return 'In production purge-samples requires --yes (content-strings.md §10.1 D8).';
		}
		$user = wp_get_current_user();
		if ( ! $user->exists() || ! in_array( 'administrator', (array) $user->roles, true ) ) {
			return 'In production purge-samples must be run by a Super Administrator (--user=<login>).';
		}
		return null;
	}

	/** @return array<string,int> */
	public function run(): array {
		$production = self::is_production();
		if ( $production && null !== self::refusal( true ) ) {
			throw new SeedException( 'Refused: a Super Administrator is required in production.' );
		}
		$previous = get_current_user_id();
		// 1. Everything the demo seed created (and the pages/settings it edited).
		$this->counts = ( new Resetter() )->run( $production );
		wp_set_current_user( $previous ?: DemoSeeder::admin_id() );
		try {
			// 2. Remaining sample posts (not created by the seed).
			foreach ( self::DELETE_TYPES as $type ) {
				foreach ( self::sample_posts( $type ) as $id ) {
					$this->delete_post( $id, $type );
				}
			}
			foreach ( self::REPORT_TYPES as $type ) {
				$left = count( self::sample_posts( $type ) );
				if ( $left ) {
					$this->counts[ 'skipped_' . $type ] = $left;
				}
			}
			// 3. Sample terms (menu categories carry the flag as term meta, C12) and media.
			foreach ( [ ContentTypes::CATEGORY, ContentTypes::TAG, ContentTypes::INGREDIENT ] as $tax ) {
				$ids = get_terms( [ 'taxonomy' => $tax, 'hide_empty' => false, 'fields' => 'ids', 'meta_key' => Writer::SAMPLE_META, 'meta_value' => '1' ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
				foreach ( is_array( $ids ) ? $ids : [] as $tid ) {
					if ( true === wp_delete_term( (int) $tid, $tax ) ) {
						$this->add( 'terms' );
					}
				}
			}
			foreach ( self::sample_posts( 'attachment' ) as $id ) {
				if ( wp_delete_attachment( $id, true ) ) {
					$this->add( 'images' );
				}
			}
		} finally {
			wp_set_current_user( $previous );
		}
		if ( class_exists( \Momos\Kernel\Cache\CacheInvalidator::class ) ) {
			\Momos\Kernel\Cache\CacheInvalidator::purge_all();
		}
		wp_cache_flush();
		momos_audit( self::AUDIT_ACTION, 'system', null, null, [ 'environment' => $production ? 'production' : \Momos\Kernel\Env::name(), 'counts' => $this->counts ] );
		return $this->counts;
	}

	/** @return list<int> */
	private static function sample_posts( string $type ): array {
		$ids = get_posts(
			[
				'post_type'        => $type,
				'post_status'      => 'attachment' === $type ? 'inherit' : [ 'publish', 'draft', 'pending', 'private', 'future', 'trash' ],
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_key'         => Writer::SAMPLE_META, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'       => '1', // phpcs:ignore WordPress.DB.SlowDBQuery
			]
		);
		return array_map( 'intval', $ids );
	}

	private function delete_post( int $id, string $type ): void {
		global $wpdb;
		$guard = null;
		$had   = false;
		if ( ContentTypes::DISH === $type ) {
			// Branch menu rows first; the dish delete guard (once-published dishes) is bypassed for sample dishes only.
			$wpdb->delete( Tables::menu_items(), [ 'dish_id' => $id ], [ '%d' ] );
			$guard = [ \Momos\Menu\Service\DishLifecycle::class, 'guard_delete' ];
			$had   = has_filter( 'pre_delete_post', $guard );
			if ( false !== $had ) {
				remove_filter( 'pre_delete_post', $guard, (int) $had );
			}
		}
		try {
			$ok = (bool) wp_delete_post( $id, true );
		} finally {
			if ( null !== $guard && false !== $had ) {
				add_filter( 'pre_delete_post', $guard, (int) $had, 2 );
			}
		}
		if ( ! $ok ) {
			throw new SeedException( "Could not delete sample {$type} {$id}." );
		}
		$this->add( $type );
	}

	private function add( string $what ): void {
		$this->counts[ $what ] = ( $this->counts[ $what ] ?? 0 ) + 1;
	}
}
