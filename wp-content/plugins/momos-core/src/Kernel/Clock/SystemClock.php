<?php
declare(strict_types=1);

namespace Momos\Kernel\Clock;

final class SystemClock extends Clock {
	public function now(): \DateTimeImmutable {
		return new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
	}
}
