<?php
declare(strict_types=1);

namespace Momos\Seed;

/**
 * WP-CLI `wp momos seed` (NFR-MNT-02, DEC-047, content-strings.md §10, qa.md §3.3).
 */
final class SeedCommand {
	public static function register(): void {
		\WP_CLI::add_command( 'momos seed', [ self::class, 'run' ] );
	}

	/**
	 * Create (--demo) or remove (--reset) the fictional demo content. Refused in production.
	 *
	 * ## OPTIONS
	 *
	 * [--demo]
	 * : Create (or bring up to date) the fictional demo content. Idempotent.
	 *
	 * [--reset]
	 * : Remove only the sample content created by --demo and restore the pages/settings it changed.
	 *   Combined with --demo: reset first, then seed again.
	 *
	 * [--photos=<dir>]
	 * : Directory with UI-baseline food photos (items_*-lg.jpg). Default: MOMOS_SEED_PHOTO_DIR or the
	 *   local UI baseline checkout; GD placeholders are generated when it does not exist.
	 *
	 * [--no-photos]
	 * : Always generate placeholder images.
	 *
	 * ## EXAMPLES
	 *
	 *     wp momos seed --demo
	 *     wp momos seed --reset
	 *     wp momos seed --reset --demo
	 *
	 * @param list<string>         $args
	 * @param array<string,string> $assoc
	 */
	public static function run( array $args, array $assoc ): void {
		$demo  = isset( $assoc['demo'] );
		$reset = isset( $assoc['reset'] );
		if ( ! $demo && ! $reset ) {
			\WP_CLI::error( 'Choose --demo and/or --reset.' );
		}
		try {
			DemoSeeder::assert_allowed();
			if ( $reset ) {
				$counts = ( new Resetter() )->run();
				self::table( 'Removed / restored', $counts );
				\WP_CLI::success( 'Sample content removed.' );
			}
			if ( $demo ) {
				$photos = isset( $assoc['photos'] ) ? (string) $assoc['photos'] : null;
				$seeder = new DemoSeeder( $photos, ! isset( $assoc['no-photos'] ), static fn( string $m ) => \WP_CLI::log( $m ) );
				$counts = $seeder->run();
				self::table( 'Seeded (processed)', $counts );
				\WP_CLI::success( 'Demo content is ready. Staff accounts: demo_ho, demo_mm, demo_ce, demo_tr, demo_bm_kitahama, demo_rs_kitahama, demo_bm_namba, demo_rs_namba (development password).' );
			}
		} catch ( SeedException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
	}

	/** @param array<string,int|string> $counts */
	private static function table( string $title, array $counts ): void {
		\WP_CLI::log( $title . ':' );
		\WP_CLI\Utils\format_items( 'table', array_map( static fn( $k, $v ) => [ 'item' => $k, 'count' => $v ], array_keys( $counts ), $counts ), [ 'item', 'count' ] );
	}
}
