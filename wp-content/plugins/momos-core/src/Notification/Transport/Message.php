<?php
declare(strict_types=1);

namespace Momos\Notification\Transport;

/**
 * One outgoing email: exactly one To, no Cc/Bcc, fixed From, optional branch Reply-To (PRV-009,
 * SEC-033). Header values are sanitised in the constructor (no CR/LF, no quotes in display names).
 */
final class Message {
	public readonly string $to;
	public readonly string $subject;
	public readonly string $from_name;
	public readonly ?string $reply_to;

	public function __construct(
		string $to,
		string $subject,
		public readonly string $html,
		public readonly string $text,
		public readonly string $from_email,
		string $from_name,
		?string $reply_to,
		public readonly string $message_id,
		public readonly string $lang,
		public readonly string $original_to_masked = '',
	) {
		$this->to        = self::header_value( $to );
		$this->subject   = self::header_value( $subject );
		$this->from_name = trim( str_replace( [ '"', '\\', '<', '>', '@' ], '', self::header_value( $from_name ) ) );
		$reply           = null === $reply_to ? '' : self::header_value( $reply_to );
		$this->reply_to  = '' !== $reply && is_email( $reply ) ? $reply : null;
	}

	/** Strip CR/LF and other control characters (header-injection guard, SEC-033). */
	public static function header_value( string $v ): string {
		return trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $v ) );
	}

	public function with_recipient( string $to, string $subject ): self {
		return new self( $to, $subject, $this->html, $this->text, $this->from_email, $this->from_name, $this->reply_to, $this->message_id, $this->lang, $this->original_to_masked );
	}
}
