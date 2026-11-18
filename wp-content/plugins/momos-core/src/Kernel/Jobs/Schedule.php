<?php
declare(strict_types=1);

namespace Momos\Kernel\Jobs;

/**
 * Recurring jobs via Action Scheduler, group "momos" (TRD §3.14). In production the queue is run
 * by real server cron (DISABLE_WP_CRON + `wp action-scheduler run`), see docs/operations.
 */
final class Schedule {
	public const GROUP = 'momos';

	/** @var array<string, array{interval:int, args:array<mixed>}> */
	private static array $recurring = [];

	private static bool $hooked = false;

	/** Register a recurring job (idempotent). $interval in seconds. */
	public static function recurring( string $hook, int $interval, array $args = [] ): void {
		self::$recurring[ $hook ] = [ 'interval' => $interval, 'args' => $args ];
		// Heartbeat after all handlers of the job completed without throwing (FR-FND-003(f)).
		add_action( $hook, static fn() => self::beat( $hook ), PHP_INT_MAX, 0 );
		if ( ! self::$hooked ) {
			self::$hooked = true;
			add_action( 'init', [ self::class, 'ensure' ], 30 );
		}
	}

	/** @return array<string, array{interval:int, args:array<mixed>}> */
	public static function all(): array {
		return self::$recurring;
	}

	public static function ensure(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || wp_installing() ) {
			return;
		}
		$signature = md5( (string) wp_json_encode( self::$recurring ) );
		if ( get_option( 'momos_jobs_signature' ) === $signature ) {
			return;
		}
		foreach ( self::$recurring as $hook => $def ) {
			if ( ! as_has_scheduled_action( $hook, $def['args'], self::GROUP ) ) {
				as_schedule_recurring_action( time() + 60, $def['interval'], $hook, $def['args'], self::GROUP, true );
			}
		}
		update_option( 'momos_jobs_signature', $signature, true );
	}

	/** Record a heartbeat and ping the external monitor (dead-man's switch, NFR-OBS-01). */
	public static function beat( string $hook ): void {
		$beats          = (array) get_option( 'momos_heartbeat', [] );
		$beats[ $hook ] = gmdate( 'c' );
		update_option( 'momos_heartbeat', $beats, false );
		$key = 'MOMOS_HEARTBEAT_URL_' . strtoupper( (string) preg_replace( '/^momos_job_/', '', $hook ) );
		$url = defined( $key ) ? (string) constant( $key ) : ( defined( 'MOMOS_HEARTBEAT_URL' ) ? (string) MOMOS_HEARTBEAT_URL : '' );
		if ( '' !== $url ) {
			wp_remote_get( $url, [ 'timeout' => 2, 'blocking' => false ] );
		}
	}

	/**
	 * Job health for the monitoring panel: hook => [interval, last (ISO|null), state ok|late|never].
	 *
	 * @return array<string, array{interval:int,last:?string,state:string}>
	 */
	public static function health(): array {
		$beats = (array) get_option( 'momos_heartbeat', [] );
		$out   = [];
		foreach ( self::$recurring as $hook => $def ) {
			$last = $beats[ $hook ] ?? null;
			$age  = $last ? time() - (int) strtotime( (string) $last ) : null;
			$out[ $hook ] = [
				'interval' => $def['interval'],
				'last'     => $last,
				'state'    => null === $age ? 'never' : ( $age <= 2 * $def['interval'] + 120 ? 'ok' : 'late' ),
			];
		}
		return $out;
	}

	/** Force (re)registration of all recurring actions (deploy step `wp momos jobs sync`). */
	public static function sync(): void {
		delete_option( 'momos_jobs_signature' );
		self::ensure();
	}

	/** One-off action at a time (e.g. break-glass expiry). */
	public static function at( \DateTimeImmutable $when, string $hook, array $args = [] ): void {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( $when->getTimestamp(), $hook, $args, self::GROUP, true );
		}
	}

	/** Run every due momos job now (used by WP-CLI `wp momos jobs run` and tests). */
	public static function run_all_now(): void {
		foreach ( array_keys( self::$recurring ) as $hook ) {
			do_action( $hook, ...self::$recurring[ $hook ]['args'] );
		}
	}
}
