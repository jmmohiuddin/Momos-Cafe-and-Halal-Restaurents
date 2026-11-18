<?php
declare(strict_types=1);

namespace Momos\Kernel\Migration;

use Momos\Kernel\Log\Logger;

/**
 * Controlled, idempotent, forward-only migrations (TRD §6.5). Applied IDs are stored in the
 * option `momos_migrations`. Modules contribute migrations through the `momos/migrations` filter.
 */
final class Runner {
	public const OPTION = 'momos_migrations';

	/** @return list<MigrationInterface> */
	public static function migrations(): array {
		$list = [ new M0001_CreateTables(), new M0002_Constraints() ];
		/** @var list<MigrationInterface> $list */
		$list = apply_filters( 'momos/migrations', $list );
		usort( $list, static fn( $a, $b ) => strcmp( $a->id(), $b->id() ) );
		return $list;
	}

	public static function maybe_run(): void {
		if ( wp_installing() || ! is_blog_installed() ) {
			return;
		}
		$signature = MOMOS_CORE_VERSION . ':' . count( self::migrations() );
		if ( get_option( 'momos_migrations_signature' ) === $signature ) {
			return;
		}
		// Production/staging: migrations run only from the deploy step (`wp momos migrate`), never on a
		// web request (FR-FND-003(d)); show an SA notice instead.
		if ( 'development' !== \Momos\Kernel\Env::name() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			add_action( 'admin_notices', static function (): void {
				if ( current_user_can( 'manage_options' ) ) {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'Database update required — run: wp momos migrate', 'momos-core' ) . '</p></div>';
				}
			} );
			return;
		}
		self::run();
		update_option( 'momos_migrations_signature', $signature, true );
	}

	/** @return list<string> IDs applied in this run. */
	public static function run( bool $force = false ): array {
		if ( ! \Momos\Kernel\Db\NamedLock::acquire( 'momos_migrate', 0 ) ) {
			Logger::warning( 'migration.locked', [] );
			return [];
		}
		try {
			return self::run_locked( $force );
		} finally {
			\Momos\Kernel\Db\NamedLock::release( 'momos_migrate' );
		}
	}

	/** @return list<string> */
	private static function run_locked( bool $force ): array {
		$applied = (array) get_option( self::OPTION, [] );
		$ran     = [];
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
				break; // Later migrations may depend on this one.
			}
		}
		update_option( self::OPTION, array_values( array_unique( $applied ) ), true );
		update_option( 'momos_migrations_signature', MOMOS_CORE_VERSION . ':' . count( self::migrations() ), true );
		if ( $ran ) {
			flush_rewrite_rules( false );
		}
		return $ran;
	}
}
