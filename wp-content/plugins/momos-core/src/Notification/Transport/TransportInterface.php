<?php
declare(strict_types=1);

namespace Momos\Notification\Transport;

interface TransportInterface {
	/** Hand one message to the provider. Never throws: failures are returned as SendResult::fail(). */
	public function send( Message $message ): SendResult;

	/** Short identifier for the status block (`smtp`, `log`, …). */
	public function name(): string;
}
