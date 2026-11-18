<?php
declare(strict_types=1);

namespace Momos\Notification\Transport;

/** Outcome of one transport attempt. */
final class SendResult {
	private function __construct(
		public readonly bool $ok,
		public readonly string $message_id,
		public readonly string $error_code,
		public readonly string $error,
	) {}

	public static function ok( string $message_id ): self {
		return new self( true, $message_id, '', '' );
	}

	public static function fail( string $code, string $error ): self {
		return new self( false, '', $code, $error );
	}
}
