<?php
declare(strict_types=1);

namespace Momos\Kernel\Db;

/**
 * MySQL named lock (GET_LOCK) used to serialise per-email submissions and job runs (SDD §5.12).
 *
 * GET_LOCK names are global to the database server, not to a schema: the key therefore includes the
 * database name and table prefix, so two sites on one server (production + staging, parallel CI/E2E
 * databases) never block each other — a foreign site's migration once made `wp momos migrate` skip
 * every migration on a fresh install (Wave 3 tooling report).
 */
final class NamedLock {
	public static function acquire( string $name, int $timeout_seconds = 5 ): bool {
		global $wpdb;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::key( $name ), $timeout_seconds ) );
	}

	public static function release( string $name ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::key( $name ) ) );
	}

	/** Server-wide lock name for $name in this site (≤ 64 characters, MySQL limit). */
	public static function key( string $name ): string {
		global $wpdb;
		$scope = ( defined( 'DB_NAME' ) ? (string) DB_NAME : '' ) . '|' . (string) $wpdb->prefix;
		return 'momos_' . substr( hash( 'sha256', $scope . '|' . $name ), 0, 40 );
	}
}
