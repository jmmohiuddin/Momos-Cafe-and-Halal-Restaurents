<?php
declare(strict_types=1);

namespace Momos\Kernel\Events;

use Momos\Kernel\Db\Transaction;

/**
 * Domain events are fired only after the surrounding transaction commits (SDD §2.5).
 * Outside a transaction the action fires immediately.
 */
final class AfterCommit {
	/** @var list<array{0:string,1:array<int,mixed>}> */
	private static array $queue = [];

	public static function dispatch( string $hook, mixed ...$args ): void {
		if ( Transaction::active() ) {
			self::$queue[] = [ $hook, $args ];
			return;
		}
		do_action( $hook, ...$args );
	}

	public static function flush(): void {
		$queue       = self::$queue;
		self::$queue = [];
		foreach ( $queue as [ $hook, $args ] ) {
			do_action( $hook, ...$args );
		}
	}

	public static function discard(): void {
		self::$queue = [];
	}
}
