<?php
declare(strict_types=1);

namespace Momos\Notification\Transport;

use Momos\Kernel\Env;

/**
 * Sending identity from wp-config constants (FR-NOT-006, DEC-018, spec §4.11). Never stored in the
 * database, never logged.
 */
final class MailIdentity {
	public static function from_address(): string {
		$from = defined( 'MOMOS_MAIL_FROM' ) ? (string) MOMOS_MAIL_FROM : '';
		if ( '' === $from || ! is_email( $from ) ) {
			$host = (string) wp_parse_url( (string) get_option( 'home' ), PHP_URL_HOST );
			$host = '' === $host || ! str_contains( $host, '.' ) ? 'example.test' : $host;
			$from = 'reservations@' . preg_replace( '/^www\./', '', $host ); // ⟦Q-409⟧ placeholder.
			if ( ! is_email( $from ) ) {
				$from = 'reservations@example.test';
			}
		}
		return strtolower( $from );
	}

	public static function is_configured(): bool {
		return defined( 'MOMOS_MAIL_FROM' ) && is_email( (string) MOMOS_MAIL_FROM );
	}

	/** Domain used in Message-ID and the carrier whitelisting tip. */
	public static function domain(): string {
		if ( defined( 'MOMOS_MAIL_DOMAIN' ) && '' !== (string) MOMOS_MAIL_DOMAIN ) {
			return strtolower( (string) MOMOS_MAIL_DOMAIN );
		}
		$from = self::from_address();
		return strtolower( substr( (string) strrchr( $from, '@' ), 1 ) );
	}

	/** Registrable domain for the carrier tip `@{domain}` (strip one leading label like `mail.`). */
	public static function whitelist_domain(): string {
		$d     = self::domain();
		$parts = explode( '.', $d );
		// e.g. mail.example.co.jp keeps example.co.jp; example.com stays.
		if ( count( $parts ) > 2 && in_array( $parts[0], [ 'mail', 'mx', 'smtp', 'bounce', 'send', 'em' ], true ) ) {
			array_shift( $parts );
		}
		return implode( '.', $parts );
	}

	public static function message_id( string $uuid ): string {
		return '<' . $uuid . '@' . self::domain() . '>';
	}

	/** Non-production sandbox address (SEC-055); never honoured in production. */
	public static function sandbox(): ?string {
		if ( Env::is_production() || ! defined( 'MOMOS_MAIL_SANDBOX' ) ) {
			return null;
		}
		$v = strtolower( trim( (string) MOMOS_MAIL_SANDBOX ) );
		return is_email( $v ) ? $v : null;
	}

	/** Apply sandbox reroute and the `[STAGING] ` subject prefix outside production (spec §5.1). */
	public static function finalize( Message $m ): Message {
		if ( Env::is_production() ) {
			return $m;
		}
		$subject = str_starts_with( $m->subject, '[STAGING] ' ) ? $m->subject : '[STAGING] ' . $m->subject;
		$to      = self::sandbox() ?? $m->to;
		return $m->with_recipient( $to, $subject );
	}
}
