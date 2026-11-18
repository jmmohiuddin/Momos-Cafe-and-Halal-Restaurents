<?php
declare(strict_types=1);

namespace Momos\Kernel\Db;

/**
 * InnoDB transaction wrapper (SDD §3.6). Nested calls join the outer transaction.
 *
 * If a transaction is already open outside this class (the WordPress test suite runs every test
 * inside one; autocommit=0), the outermost call uses a SAVEPOINT instead of START/COMMIT so it
 * never commits the enclosing transaction. After-commit hooks still fire when the outermost
 * call completes.
 */
final class Transaction {
	private static int $depth = 0;

	private static bool $savepoint = false;

	/**
	 * @template T
	 * @param callable():T $work
	 * @return T
	 */
	public static function run( callable $work ) {
		global $wpdb;
		$outer = 0 === self::$depth;
		if ( $outer ) {
			self::$savepoint = self::enclosing_transaction_open();
			$wpdb->query( self::$savepoint ? 'SAVEPOINT momos_tx' : 'START TRANSACTION' );
		}
		++self::$depth;
		try {
			$result = $work();
			--self::$depth;
			if ( $outer ) {
				$wpdb->query( self::$savepoint ? 'RELEASE SAVEPOINT momos_tx' : 'COMMIT' );
				\Momos\Kernel\Events\AfterCommit::flush();
			}
			return $result;
		} catch ( \Throwable $e ) {
			--self::$depth;
			if ( $outer ) {
				$wpdb->query( self::$savepoint ? 'ROLLBACK TO SAVEPOINT momos_tx' : 'ROLLBACK' );
				\Momos\Kernel\Events\AfterCommit::discard();
			}
			throw $e;
		}
	}

	/** autocommit off, or (MariaDB) a transaction already in progress on this connection. */
	private static function enclosing_transaction_open(): bool {
		global $wpdb;
		if ( '0' === (string) $wpdb->get_var( 'SELECT @@autocommit' ) ) {
			return true;
		}
		$suppress = $wpdb->suppress_errors( true );
		$in       = $wpdb->get_var( 'SELECT @@in_transaction' ); // MariaDB only; MySQL returns an error → null.
		$wpdb->suppress_errors( $suppress );
		return '1' === (string) $in;
	}

	public static function active(): bool {
		return self::$depth > 0;
	}
}
