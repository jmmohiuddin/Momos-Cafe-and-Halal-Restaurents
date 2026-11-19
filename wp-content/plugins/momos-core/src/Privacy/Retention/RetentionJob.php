<?php
declare(strict_types=1);

namespace Momos\Privacy\Retention;

use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Db\NamedLock;
use Momos\Kernel\Db\Tables;
use Momos\Kernel\Jobs\Schedule;
use Momos\Kernel\Log\Logger;
use Momos\Kernel\Settings;

/**
 * Daily retention and anonymisation job (F-PRV-02 / FR-PRV-003, PRV-012/013, BR-PRV-02, DEC-034;
 * Access spec §10.4, TRD FR-PRV-003, SDD §5.10). Hook `momos_job_retention`, 03:15 JST, named lock
 * `momos_job_retention`, idempotent and resumable (every step is condition-based), batch-limited.
 *
 * Steps (periods from the G3 Privacy tab, never hard-coded):
 *  2 reservations with `pii_purged_at IS NULL` and `requested_date < today(JST) − momo_retention_pii_months`
 *    → Anonymizer, one transaction each, batches of 500 by id (future dates are never selected);
 *  3 `ip_hash`/`user_agent_hash` → NULL when `created_at < now − momo_retention_ip_hash_days`;
 *  4 notifications: Notification `Retention::run()` (recipient purge 30 days after terminal status,
 *    rows older than `momo_retention_notification_days` deleted);
 *  5/6 audit rows: `AuditRepository::purge()` under the Audit job's own lock (no second delete path);
 *  7 Action Scheduler logs: kernel (`action_scheduler_retention_period` = 30 days, Schedule::configure_runner);
 *  + extra steps registered on `momos/privacy/retention_steps` (e.g. the SEO 404 log);
 *  8 audit `retention.run` (counts, duration, settings snapshot — no personal data) and heartbeat;
 *  9 a failing reservation is rolled back alone and the run continues; at the end
 *    `privacy.retention_failed` (error codes only) + monitoring alert; the next run resumes.
 *
 * Dry run (`wp momos privacy retention --dry-run`): counts what would change, changes nothing.
 */
final class RetentionJob {
	public const HOOK        = 'momos_job_retention';
	public const WATCH_HOOK  = 'momos_job_retention_watch';
	public const LOCK        = 'momos_job_retention';
	public const LAST_OPTION = 'momos_privacy_retention_last';
	public const BATCH       = 500;
	public const MAX_BATCHES = 20;
	public const STALE_HOURS = 26;

	public static function register(): void {
		Schedule::daily_at( self::HOOK, '03:15' );
		add_action( self::HOOK, [ self::class, 'handle' ] );
		Schedule::recurring( self::WATCH_HOOK, HOUR_IN_SECONDS );
		add_action( self::WATCH_HOOK, [ self::class, 'watch' ] );
	}

	// ------------------------------------------------------------------ settings

	public static function pii_months(): int {
		return self::bounded( 'momo_retention_pii_months', 12, 1, 60 );
	}

	public static function ip_hash_days(): int {
		return self::bounded( 'momo_retention_ip_hash_days', 30, 1, 365 );
	}

	public static function notification_days(): int {
		return self::bounded( 'momo_retention_notification_days', 180, 30, 730 );
	}

	private static function bounded( string $name, int $default, int $min, int $max ): int {
		$v = Settings::get( $name, $default );
		$v = is_numeric( $v ) ? (int) $v : $default;
		return $v >= $min && $v <= $max ? $v : $default;
	}

	/** Reservations with a requested_date before this JST date are past retention. */
	public static function pii_cutoff_date( ?\DateTimeImmutable $now = null ): string {
		return self::minus_months( ( $now ?? Clock::utc() )->setTimezone( Clock::tz() ), self::pii_months() )->format( 'Y-m-d' );
	}

	/** Calendar month subtraction clamped to the month end (2026-03-31 − 1 month = 2026-02-28). */
	public static function minus_months( \DateTimeImmutable $d, int $months ): \DateTimeImmutable {
		$total = (int) $d->format( 'Y' ) * 12 + (int) $d->format( 'n' ) - 1 - $months;
		$y     = intdiv( $total, 12 );
		$m     = $total % 12 + 1;
		$first = $d->setDate( $y, $m, 1 );
		$day   = min( (int) $d->format( 'j' ), (int) $first->format( 't' ) );
		return $first->setDate( $y, $m, $day );
	}

	// ------------------------------------------------------------------ run

	/** Action Scheduler entry point: a run with failures throws so the heartbeat is not recorded. */
	public static function handle(): void {
		$result = self::run();
		if ( null !== $result && $result['failed'] > 0 ) {
			throw new \RuntimeException( 'privacy.retention_failed' );
		}
	}

	/**
	 * Run all steps. Returns null when another run holds the lock.
	 *
	 * @return array{dry_run:bool,counts:array<string,int|string>,failed:int,errors:list<string>,duration_ms:int,settings:array<string,int|string>}|null
	 */
	public static function run( bool $dry_run = false ): ?array {
		if ( ! NamedLock::acquire( self::LOCK, 0 ) ) {
			return null;
		}
		$started = microtime( true );
		$now     = Clock::utc();
		$counts  = [];
		$errors  = [];
		$failed  = 0;
		try {
			[ $counts['reservations_anonymized'], $res_failed, $res_errors ] = self::anonymize_due( $now, $dry_run );
			$failed += $res_failed;
			$errors  = array_merge( $errors, $res_errors );

			$steps = [
				'abuse_hashes_cleared' => static fn(): int => self::clear_abuse_hashes( $now, $dry_run ),
				'notifications'        => static fn(): array => self::notifications( $now, $dry_run ),
				'audit'                => static fn(): array => self::audit( $now, $dry_run ),
			];
			/**
			 * Extra retention steps of other modules (e.g. SEO 404 log). Each callable receives
			 * (bool $dry_run, \DateTimeImmutable $now_utc) and returns the number of items removed
			 * (or that would be removed). Must not process personal data in its return value.
			 *
			 * @param array<string, callable(bool, \DateTimeImmutable): int> $extra
			 */
			$extra = (array) apply_filters( 'momos/privacy/retention_steps', [] );
			foreach ( $extra as $name => $cb ) {
				if ( is_string( $name ) && is_callable( $cb ) ) {
					$steps[ sanitize_key( $name ) ] = static fn(): int => (int) $cb( $dry_run, $now );
				}
			}
			foreach ( $steps as $name => $step ) {
				try {
					$out = $step();
					if ( is_array( $out ) ) {
						foreach ( $out as $k => $v ) {
							$counts[ $name . '_' . $k ] = $v;
						}
					} else {
						$counts[ $name ] = $out;
					}
				} catch ( \Throwable $e ) {
					++$failed;
					$errors[] = $name . '_failed';
					Logger::error( 'privacy.retention_step_failed', [ 'step' => $name, 'error' => get_class( $e ) ] );
				}
			}
			$counts['action_scheduler_logs'] = 'kernel';

			$summary = [
				'dry_run'     => $dry_run,
				'counts'      => $counts,
				'failed'      => $failed,
				'errors'      => array_values( array_unique( $errors ) ),
				'duration_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
				'settings'    => [
					'pii_months'        => self::pii_months(),
					'pii_cutoff_date'   => self::pii_cutoff_date( $now ),
					'ip_hash_days'      => self::ip_hash_days(),
					'notification_days' => self::notification_days(),
				],
			];
			if ( ! $dry_run ) {
				self::finish( $summary, $now );
			}
			return $summary;
		} finally {
			NamedLock::release( self::LOCK );
		}
	}

	/** @param array<string,mixed> $summary */
	private static function finish( array $summary, \DateTimeImmutable $now ): void {
		momos_audit(
			'retention.run',
			'privacy',
			null,
			null,
			[ 'counts' => $summary['counts'], 'failed' => $summary['failed'], 'duration_ms' => $summary['duration_ms'], 'settings' => $summary['settings'] ],
			'system'
		);
		$last = (array) get_option( self::LAST_OPTION, [] );
		$data = [
			'finished_at' => Clock::mysql_utc( $now ),
			'ok'          => 0 === $summary['failed'],
			'counts'      => $summary['counts'],
			'failed'      => $summary['failed'],
			'last_ok_at'  => 0 === $summary['failed'] ? Clock::mysql_utc( $now ) : ( $last['last_ok_at'] ?? null ),
		];
		update_option( self::LAST_OPTION, $data, false );
		if ( $summary['failed'] > 0 ) {
			momos_audit( 'privacy.retention_failed', 'privacy', null, null, [ 'errors' => $summary['errors'], 'failed' => $summary['failed'] ], 'system' );
			do_action( 'momos/monitoring/alert', 'retention_failed', [ 'failed' => $summary['failed'], 'errors' => implode( ',', $summary['errors'] ) ] );
		}
	}

	/** Hourly: alert when no successful retention run for 26 h (Access spec §10.4). */
	public static function watch(): void {
		$last = (array) get_option( self::LAST_OPTION, [] );
		$ok   = Clock::from_mysql_utc( isset( $last['last_ok_at'] ) ? (string) $last['last_ok_at'] : null );
		$ref  = $ok ?? Clock::from_mysql_utc( (string) get_option( 'momos_privacy_retention_since', '' ) );
		if ( null === $ref ) {
			update_option( 'momos_privacy_retention_since', Clock::mysql_utc(), false );
			return;
		}
		if ( Clock::utc()->getTimestamp() - $ref->getTimestamp() > self::STALE_HOURS * HOUR_IN_SECONDS ) {
			do_action( 'momos/monitoring/alert', 'retention_stale', [ 'hours' => self::STALE_HOURS ] );
		}
	}

	// ------------------------------------------------------------------ steps

	/**
	 * Step 2. Cursor by id so a row that fails is not retried in the same run.
	 *
	 * @return array{0:int,1:int,2:list<string>} [anonymised, failed, error codes]
	 */
	public static function anonymize_due( \DateTimeImmutable $now, bool $dry_run = false ): array {
		global $wpdb;
		$t      = Tables::reservations();
		$cutoff = self::pii_cutoff_date( $now );
		if ( $dry_run ) {
			$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE pii_purged_at IS NULL AND requested_date < %s", $cutoff ) ); // phpcs:ignore
			return [ min( $n, self::BATCH * self::MAX_BATCHES ), 0, [] ];
		}
		$done   = 0;
		$failed = 0;
		$errors = [];
		$last   = 0;
		for ( $i = 0; $i < self::MAX_BATCHES; $i++ ) {
			$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE pii_purged_at IS NULL AND requested_date < %s AND id > %d ORDER BY id ASC LIMIT %d", $cutoff, $last, self::BATCH ) ) ); // phpcs:ignore
			foreach ( $ids as $id ) {
				$last = $id;
				try {
					if ( Anonymizer::anonymize_reservation( $id, 'retention' ) ) {
						++$done;
					}
				} catch ( \Throwable $e ) {
					++$failed;
					$errors[] = 'reservation_failed';
					Logger::error( 'privacy.anonymize_failed', [ 'reservation_id' => $id, 'error' => get_class( $e ) ] );
				}
			}
			if ( count( $ids ) < self::BATCH ) {
				break;
			}
		}
		return [ $done, $failed, $errors ];
	}

	/** Step 3: abuse hashes older than the configured days (no version bump: not a business change). */
	public static function clear_abuse_hashes( \DateTimeImmutable $now, bool $dry_run = false ): int {
		global $wpdb;
		$t      = Tables::reservations();
		$cutoff = Clock::mysql_utc( $now->modify( '-' . self::ip_hash_days() . ' days' ) );
		if ( $dry_run ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE ( ip_hash IS NOT NULL OR user_agent_hash IS NOT NULL ) AND created_at < %s", $cutoff ) ); // phpcs:ignore
		}
		$total = 0;
		for ( $i = 0; $i < self::MAX_BATCHES; $i++ ) {
			$n = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET ip_hash = NULL, user_agent_hash = NULL WHERE ( ip_hash IS NOT NULL OR user_agent_hash IS NOT NULL ) AND created_at < %s ORDER BY id LIMIT %d", $cutoff, self::BATCH ) ); // phpcs:ignore
			if ( false === $n ) {
				throw new \RuntimeException( 'abuse_hash_update_failed' );
			}
			$total += (int) $n;
			if ( (int) $n < self::BATCH ) {
				break;
			}
		}
		return $total;
	}

	/**
	 * Step 4: delegated to the Notification module (its own batches); dry run counts read-only.
	 *
	 * @return array<string,int>
	 */
	public static function notifications( \DateTimeImmutable $now, bool $dry_run = false ): array {
		if ( ! class_exists( \Momos\Notification\Service\Retention::class ) ) {
			return [];
		}
		if ( ! $dry_run ) {
			$out = \Momos\Notification\Service\Retention::run();
			return array_map( 'intval', $out );
		}
		global $wpdb;
		$t         = Tables::notifications();
		$recipient = Clock::mysql_utc( $now->modify( '-' . \Momos\Notification\Service\Retention::RECIPIENT_PURGE_DAYS . ' days' ) );
		$rows      = Clock::mysql_utc( $now->modify( '-' . \Momos\Notification\Service\Retention::days() . ' days' ) );
		return [
			'recipients_purged' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE status IN ('sent','failed','cancelled') AND recipient_email IS NOT NULL AND updated_at < %s", $recipient ) ), // phpcs:ignore
			'rows_deleted'      => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE created_at < %s AND status <> 'sending'", $rows ) ), // phpcs:ignore
		];
	}

	/**
	 * Steps 5–6: audit domain rows (24 months) and activity-class rows (12 months) through the Audit
	 * module's purge, serialised with the Audit job by its lock so the two never delete concurrently.
	 *
	 * @return array<string,int|string>
	 */
	public static function audit( \DateTimeImmutable $now, bool $dry_run = false ): array {
		if ( ! class_exists( \Momos\Audit\AuditRepository::class ) || ! class_exists( \Momos\Audit\Job\AuditRetentionJob::class ) ) {
			return [];
		}
		$domain   = \Momos\Audit\Job\AuditRetentionJob::domain_months();
		$activity = \Momos\Audit\Job\AuditRetentionJob::activity_months();
		if ( $dry_run ) {
			global $wpdb;
			$t    = Tables::audit();
			$acts = \Momos\Audit\Catalogue::ACTIVITY;
			$in   = implode( ',', array_fill( 0, count( $acts ), '%s' ) );
			$d    = Clock::mysql_utc( $now->modify( '-' . $domain . ' months' ) );
			$a    = Clock::mysql_utc( $now->modify( '-' . $activity . ' months' ) );
			return [
				'domain'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE occurred_at < %s AND action NOT IN ({$in})", array_merge( [ $d ], $acts ) ) ), // phpcs:ignore
				'activity' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE occurred_at < %s AND action IN ({$in})", array_merge( [ $a ], $acts ) ) ), // phpcs:ignore
			];
		}
		$lock = \Momos\Audit\Job\AuditRetentionJob::HOOK;
		if ( ! NamedLock::acquire( $lock, 0 ) ) {
			return [ 'skipped' => 'audit_job_running' ];
		}
		try {
			return \Momos\Audit\AuditRepository::purge( $domain, $activity );
		} finally {
			NamedLock::release( $lock );
		}
	}

	/** @return array<string,mixed> last run summary (for SCR-A19 and the CLI) */
	public static function last(): array {
		$v = get_option( self::LAST_OPTION, [] );
		return is_array( $v ) ? $v : [];
	}
}
