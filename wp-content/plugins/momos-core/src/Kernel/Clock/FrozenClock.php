<?php
declare(strict_types=1);

namespace Momos\Kernel\Clock;

final class FrozenClock extends Clock {
	public function __construct( private \DateTimeImmutable $now ) {}

	public function now(): \DateTimeImmutable {
		return $this->now;
	}

	public function advance( string $modifier ): void {
		$this->now = $this->now->modify( $modifier );
	}
}
