<?php
declare(strict_types=1);

namespace Momos\Kernel\Db;

/**
 * MySQL named lock (GET_LOCK) used to serialise per-email submissions and job runs (SDD §5.12).
 */
final class NamedLock {
	public static function acquire( string $name, int $timeout_seconds = 5 ): bool {
		global $wpdb;
		$key = 'momos_' . substr( hash( 'sha256', $name ), 0, 40 );
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $key, $timeout_seconds ) );
	}

	public static function release( string $name ): void {
		global $wpdb;
		$key = 'momos_' . substr( hash( 'sha256', $name ), 0, 40 );
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );
	}
}
