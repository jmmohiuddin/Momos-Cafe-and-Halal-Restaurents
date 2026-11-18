<?php
declare(strict_types=1);

namespace Momos\Kernel\Db;

/**
 * InnoDB transaction wrapper (SDD §3.6). Nested calls join the outer transaction.
 */
final class Transaction {
	private static int $depth = 0;

	/**
	 * @template T
	 * @param callable():T $work
	 * @return T
	 */
	public static function run( callable $work ) {
		global $wpdb;
		$outer = 0 === self::$depth;
		if ( $outer ) {
			$wpdb->query( 'START TRANSACTION' );
		}
		++self::$depth;
		try {
			$result = $work();
			--self::$depth;
			if ( $outer ) {
				$wpdb->query( 'COMMIT' );
				\Momos\Kernel\Events\AfterCommit::flush();
			}
			return $result;
		} catch ( \Throwable $e ) {
			--self::$depth;
			if ( $outer ) {
				$wpdb->query( 'ROLLBACK' );
				\Momos\Kernel\Events\AfterCommit::discard();
			}
			throw $e;
		}
	}

	public static function active(): bool {
		return self::$depth > 0;
	}
}
