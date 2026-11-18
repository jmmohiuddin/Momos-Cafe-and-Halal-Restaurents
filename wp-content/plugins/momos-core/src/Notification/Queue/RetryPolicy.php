<?php
declare(strict_types=1);

namespace Momos\Notification\Queue;

/**
 * Backoff table, attempt limit and error classification (FR-NOT-003, spec §4.4, conflict C13:
 * 1/5/15/60 minutes, 5 attempts).
 */
final class RetryPolicy {
	public const MAX_ATTEMPTS = 5;

	/** Minutes to wait after the Nth failed attempt (1-based). */
	public const BACKOFF_MINUTES = [ 1 => 1, 2 => 5, 3 => 15, 4 => 60 ];

	/** Codes that never succeed on retry. */
	public const PERMANENT = [ 'smtp_rejected_recipient', 'smtp_rejected_message', 'invalid_recipient', 'render_failed', 'worker_crash' ];

	/** Codes that indicate a configuration problem (critical log, still retried). */
	public const CRITICAL = [ 'smtp_auth_failed', 'transport_not_configured' ];

	public const ALL_CODES = [
		'smtp_connect_failed', 'smtp_timeout', 'smtp_auth_failed', 'smtp_tempfail', 'smtp_rejected_recipient',
		'smtp_rejected_message', 'invalid_recipient', 'transport_not_configured', 'render_failed', 'worker_crash',
		'recipient_purged', 'not_applicable', 'lang_fallback', 'transport_error',
	];

	public static function is_permanent( string $code ): bool {
		return in_array( $code, self::PERMANENT, true );
	}

	/**
	 * Next attempt time after a failed attempt, or null when the row must become `failed`.
	 */
	public static function next_attempt( int $attempts_made, string $code, \DateTimeImmutable $now ): ?\DateTimeImmutable {
		if ( self::is_permanent( $code ) || $attempts_made >= self::MAX_ATTEMPTS ) {
			return null;
		}
		$minutes = self::BACKOFF_MINUTES[ max( 1, $attempts_made ) ] ?? 60;
		return $now->modify( '+' . $minutes . ' minutes' );
	}

	/**
	 * Classify a transport error from the SMTP reply code and/or message (spec §4.4 table).
	 */
	public static function classify( ?int $smtp_code, string $message, string $stage = '' ): string {
		$m = strtolower( $message );
		if ( str_contains( $m, 'could not authenticate' ) || str_contains( $m, 'authentication' ) || 535 === $smtp_code ) {
			return 'smtp_auth_failed';
		}
		if ( str_contains( $m, 'timed out' ) || str_contains( $m, 'timeout' ) ) {
			return 'smtp_timeout';
		}
		if ( str_contains( $m, 'connect()' ) || str_contains( $m, 'could not connect' ) || str_contains( $m, 'connection refused' ) || str_contains( $m, 'getaddrinfo' ) || str_contains( $m, 'could not instantiate mail function' ) ) {
			return 'smtp_connect_failed';
		}
		if ( null !== $smtp_code && $smtp_code >= 400 && $smtp_code < 500 ) {
			return 'smtp_tempfail';
		}
		$rcpt = 'rcpt' === $stage || str_contains( $m, 'recipients failed' ) || str_contains( $m, 'rcpt' );
		if ( null !== $smtp_code && $smtp_code >= 500 ) {
			return $rcpt ? 'smtp_rejected_recipient' : 'smtp_rejected_message';
		}
		if ( $rcpt ) {
			return 'smtp_rejected_recipient';
		}
		if ( str_contains( $m, 'invalid address' ) ) {
			return 'invalid_recipient';
		}
		if ( str_contains( $m, 'data not accepted' ) ) {
			return 'smtp_rejected_message';
		}
		return 'transport_error';
	}
}
