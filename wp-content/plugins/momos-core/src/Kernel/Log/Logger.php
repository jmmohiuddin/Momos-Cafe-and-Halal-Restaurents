<?php
declare(strict_types=1);

namespace Momos\Kernel\Log;

/**
 * Technical logger (SDD §7.4). Writes JSON lines to the PHP error log (or MOMOS_LOG_FILE).
 * Personal data is redacted (SDD §7.5): email, phone, names and tokens never appear in logs.
 */
final class Logger {
	public const LEVELS = [ 'debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3, 'critical' => 4 ];

	/** @param array<string,mixed> $context */
	public static function log( string $level, string $event, array $context = [] ): void {
		$min = defined( 'MOMOS_LOG_LEVEL' ) ? (string) MOMOS_LOG_LEVEL : 'info';
		if ( ( self::LEVELS[ $level ] ?? 1 ) < ( self::LEVELS[ $min ] ?? 1 ) ) {
			return;
		}
		$line = \Momos\Kernel\Support\Json::encode(
			[
				'ts'      => gmdate( 'c' ),
				'level'   => $level,
				'event'   => $event,
				'request' => \Momos\Kernel\Http\RequestContext::id(),
				'context' => Redactor::redact( $context ),
			]
		);
		if ( defined( 'MOMOS_LOG_FILE' ) && MOMOS_LOG_FILE ) {
			error_log( $line . PHP_EOL, 3, (string) MOMOS_LOG_FILE ); // phpcs:ignore
		} else {
			error_log( 'momos ' . $line ); // phpcs:ignore
		}
		if ( 'critical' === $level ) {
			do_action( 'momos/log/critical', $event, $context );
		}
	}

	/** @param array<string,mixed> $c */
	public static function info( string $event, array $c = [] ): void { self::log( 'info', $event, $c ); }
	/** @param array<string,mixed> $c */
	public static function warning( string $event, array $c = [] ): void { self::log( 'warning', $event, $c ); }
	/** @param array<string,mixed> $c */
	public static function error( string $event, array $c = [] ): void { self::log( 'error', $event, $c ); }
	/** @param array<string,mixed> $c */
	public static function critical( string $event, array $c = [] ): void { self::log( 'critical', $event, $c ); }
}
