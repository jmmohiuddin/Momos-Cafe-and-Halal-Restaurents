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
