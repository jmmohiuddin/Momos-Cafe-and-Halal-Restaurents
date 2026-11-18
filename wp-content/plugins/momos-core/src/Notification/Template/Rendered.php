<?php
declare(strict_types=1);

namespace Momos\Notification\Template;

/** Result of rendering one notification row (never stored, PRV-011). */
final class Rendered {
	public function __construct(
		public readonly string $subject,
		public readonly string $html,
		public readonly string $text,
		public readonly string $lang,
		public readonly string $requested_lang,
		public readonly ?string $reply_to,
		public readonly string $from_name,
	) {}

	public function fell_back(): bool {
		return $this->lang !== $this->requested_lang;
	}
}
