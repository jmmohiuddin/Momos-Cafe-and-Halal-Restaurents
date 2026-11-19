<?php
declare(strict_types=1);

namespace Momos\Kernel\Monitoring;

use Momos\Kernel\Log\Logger;

/**
 * Transport for operational alerts (`do_action( 'momos/monitoring/alert', string $type, array $context )`,
 * NFR-OBS-01): logs at critical level and emails the G3 `momo_alert_recipients` list (fallback: the
 * site admin email). One mail per alert type per hour; context must not contain personal data.
 * In development, mail goes through the configured transport (log/mail-catcher), never to real inboxes.
 */
final class AlertMailer {
	/**
	 * Critical log events that are operational alerts (FR-SEC-012(b)(c), docs/operations/monitoring.md §4):
	 * `Logger::critical()` of one of these (or of a CRITICAL_PREFIXES event) is mailed as alert type
	 * `<event with . → _>` — throttled per type like every alert. `monitoring.alert` itself is never re-routed.
	 */
	public const CRITICAL_EVENTS = [
		'notification.failure_rate',
		'notification.queue_age',
		'notification.digest_failed',
		'notification.digest_not_delivered',
		'job.expiry_heartbeat_missing',
		'reservation.integrity.pending_past_start',
		'captcha.degraded_submissions',
		'captcha.secret_missing',
		'dietary.evidence_integrity_failed',
		'migration.failed',
		'secret.missing',
	];

	/** Families of critical events (e.g. `notification.transport_smtp_auth_failed`). */
	public const CRITICAL_PREFIXES = [ 'notification.transport_' ];

	public static function register(): void {
		add_action( 'momos/monitoring/alert', [ self::class, 'send' ], 10, 2 );
		// After other observers, so they see the source event before the nested `monitoring.alert` line.
		add_action( 'momos/log/critical', [ self::class, 'on_critical' ], 20, 2 );
	}

	public static function is_alert_event( string $event ): bool {
		if ( in_array( $event, self::CRITICAL_EVENTS, true ) ) {
			return true;
		}
		foreach ( self::CRITICAL_PREFIXES as $prefix ) {
			if ( str_starts_with( $event, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * `momos/log/critical` listener: notify, not only log (FR-SEC-012).
	 *
	 * @param array<string,mixed> $context
	 */
	public static function on_critical( string $event, $context = [] ): void {
		if ( ! self::is_alert_event( $event ) ) {
			return;
		}
		do_action( 'momos/monitoring/alert', str_replace( '.', '_', $event ), \Momos\Kernel\Log\Redactor::redact( is_array( $context ) ? $context : [] ) );
	}

	/** @param array<string,mixed> $context */
	public static function send( string $type, array $context = [] ): void {
		$type = sanitize_key( $type );
		Logger::critical( 'monitoring.alert', [ 'type' => $type ] + $context );
		$throttle = 'momos_alert_' . $type;
		if ( get_transient( $throttle ) ) {
			return;
		}
		set_transient( $throttle, 1, HOUR_IN_SECONDS );
		$to = self::recipients();
		if ( ! $to ) {
			return;
		}
		$subject = sprintf( '[%s] Alert: %s', wp_parse_url( home_url(), PHP_URL_HOST ), $type );
		$lines   = [ 'Type: ' . $type, 'Time (UTC): ' . gmdate( 'Y-m-d H:i:s' ) ];
		foreach ( $context as $k => $v ) {
			$lines[] = sanitize_key( (string) $k ) . ': ' . ( is_scalar( $v ) ? (string) $v : (string) wp_json_encode( $v ) );
		}
		$lines[] = '';
		$lines[] = 'Details: ' . admin_url( 'admin.php?page=momo-settings' );
		wp_mail( $to, $subject, implode( "\n", $lines ) );
	}

	/** @return list<string> */
	public static function recipients(): array {
		$raw = \Momos\Kernel\Settings::get( 'momo_alert_recipients' );
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			$raw = (string) get_option( 'momo_alert_recipients', '' );
		}
		$list = array_values( array_filter( array_map( 'trim', preg_split( '/[\s,;]+/', $raw ) ?: [] ), 'is_email' ) );
		return $list ?: array_filter( [ (string) get_option( 'admin_email' ) ], 'is_email' );
	}
}
