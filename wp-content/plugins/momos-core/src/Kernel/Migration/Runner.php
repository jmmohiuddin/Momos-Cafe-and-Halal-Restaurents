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
		self::run();
		update_option( 'momos_migrations_signature', $signature, true );
	}

	/** @return list<string> IDs applied in this run. */
	public static function run( bool $force = false ): array {
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
		if ( $ran ) {
			flush_rewrite_rules( false );
		}
		return $ran;
	}
}
