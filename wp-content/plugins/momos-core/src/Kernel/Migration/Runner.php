<?php
declare(strict_types=1);

namespace Momos\Kernel\Migration;

use Momos\Kernel\Db\NamedLock;
use Momos\Kernel\Log\Logger;

/**
 * Controlled, idempotent, forward-only migrations (TRD §6.5). Applied IDs are stored in the
 * option `momos_migrations`. Modules contribute migrations through the `momos/migrations` filter.
 *
 * The signature option (`momos_migrations_signature`, "version:count") only short-cuts the per-request
 * check; it is written only when every migration is applied. A run that cannot take the lock or whose
 * migration fails leaves it untouched, so the next request/CLI call retries, and `wp momos migrate`
 * exits non-zero while anything is pending (fresh-install defect, Wave 3).
 */
final class Runner {
	public const OPTION = 'momos_migrations';

	public const SIGNATURE_OPTION = 'momos_migrations_signature';

	public const LOCK = 'momos_migrate';

	/** @return list<MigrationInterface> */
	public static function migrations(): array {
		$list = [ new M0001_CreateTables(), new M0002_Constraints() ];
		/** @var list<MigrationInterface> $list */
		$list = apply_filters( 'momos/migrations', $list );
		usort( $list, static fn( $a, $b ) => strcmp( $a->id(), $b->id() ) );
		return $list;
	}

	public static function signature(): string {
		return MOMOS_CORE_VERSION . ':' . count( self::migrations() );
	}

	/** @return list<string> IDs of registered migrations that are not applied yet. */
	public static function pending(): array {
		$applied = (array) get_option( self::OPTION, [] );
		$out     = [];
		foreach ( self::migrations() as $migration ) {
			if ( ! in_array( $migration->id(), $applied, true ) ) {
				$out[] = $migration->id();
			}
		}
		return $out;
	}

	public static function maybe_run(): void {
		if ( wp_installing() || ! is_blog_installed() ) {
			return;
		}
		if ( get_option( self::SIGNATURE_OPTION ) === self::signature() ) {
			return;
		}
		// Production/staging: migrations run only from the deploy step (`wp momos migrate`), never on a
		// web request (FR-FND-003(d)); show an SA notice instead.
		if ( 'development' !== \Momos\Kernel\Env::name() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					if ( current_user_can( 'manage_options' ) ) {
						echo '<div class="notice notice-error"><p>' . esc_html__( 'Database update required — run: wp momos migrate', 'momos-core' ) . '</p></div>';
					}
				}
			);
			return;
		}
		self::run(); // Writes the signature itself, and only once nothing is pending.
	}

	/**
	 * Apply pending migrations (all of them with $force). Waits up to $wait_seconds for a concurrent run
	 * in the same database; when the lock is not obtained nothing is applied or recorded.
	 *
	 * @return list<string> IDs applied in this run.
	 */
	public static function run( bool $force = false, int $wait_seconds = 0 ): array {
		if ( ! NamedLock::acquire( self::LOCK, max( 0, $wait_seconds ) ) ) {
			Logger::warning( 'migration.locked', [] );
			return [];
		}
		try {
			return self::run_locked( $force );
		} finally {
			NamedLock::release( self::LOCK );
		}
	}

	/** @return list<string> */
	private static function run_locked( bool $force ): array {
		$applied = (array) get_option( self::OPTION, [] );
		$ran     = [];
		$failed  = false;
		foreach ( self::migrations() as $migration ) {
			if ( ! $force && in_array( $migration->id(), $applied, true ) ) {
				continue;
			}
			try {
				$migration->up();
				$applied[] = $migration->id();
				$ran[]     = $migration->id();
				Logger::info( 'migration.applied', [ 'id' => $migration->id() ] );
			} catch ( \Throwable $e ) {
				Logger::critical( 'migration.failed', [ 'id' => $migration->id(), 'error' => $e->getMessage() ] );
				$failed = true;
				break; // Later migrations may depend on this one.
			}
		}
		update_option( self::OPTION, array_values( array_unique( $applied ) ), true );
		if ( ! $failed && ! self::pending() ) {
			update_option( self::SIGNATURE_OPTION, self::signature(), true );
		}
		if ( $ran ) {
			flush_rewrite_rules( false );
		}
		return $ran;
	}
}
