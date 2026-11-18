<?php
declare(strict_types=1);

namespace Momos\Notification\Queue;

use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Db\NamedLock;
use Momos\Kernel\Jobs\Schedule;
use Momos\Kernel\Log\Logger;
use Momos\Notification\Repository\NotificationRepository;

/**
 * Crash recovery, re-enqueue, heartbeat and alerts every 5 minutes (NFR-OBS-01, SEC-083; spec §4.10).
 */
final class QueueSweeper {
	public const HOOK            = 'momos_job_notification_sweeper';
	public const STALE_MINUTES   = 10;
	public const QUEUE_AGE_MINUTES = 15;

	/** @return array{recovered:int,failed:int,requeued:int} */
	public static function run(): array {
		$out = [ 'recovered' => 0, 'failed' => 0, 'requeued' => 0 ];
		if ( ! NamedLock::acquire( self::HOOK, 0 ) ) {
			return $out;
		}
		try {
			$now = Clock::utc();
			// 1. Stale `sending` rows (worker crashed between claim and mark).
			foreach ( NotificationRepository::stale_sending( $now->modify( '-' . self::STALE_MINUTES . ' minutes' ) ) as $row ) {
				$id = (int) $row['id'];
				if ( (int) $row['attempts'] < RetryPolicy::MAX_ATTEMPTS ) {
					NotificationRepository::mark_retry( $id, $now, 'worker_crash', 'stale sending lock recovered by sweeper' );
					Dispatcher::schedule_async( $id );
					++$out['recovered'];
				} else {
					NotificationRepository::mark_failed( $id, 'worker_crash', 'stale sending lock at final attempt' );
					FailureHandler::handle( $id );
					++$out['failed'];
				}
			}
			// 2. Due queued rows without a pending action → re-enqueue (oldest first, ≤ 200).
			foreach ( NotificationRepository::due_ids( 200 ) as $id ) {
				if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( Dispatcher::ACTION, [ $id ], Schedule::GROUP ) ) {
					Dispatcher::schedule_async( $id );
					++$out['requeued'];
				}
			}
			// 3. Heartbeat (monitor alerts when older than 30 min).
			update_option( 'momos_job_heartbeat_queue', Clock::mysql_utc(), false );
			// 4. Failure rate > 5 % with ≥ 20 attempts in the last 60 minutes.
			$stats = NotificationRepository::attempt_stats( $now->modify( '-60 minutes' ) );
			if ( $stats['attempts'] >= 20 && $stats['failed'] / $stats['attempts'] > 0.05 ) {
				Logger::critical( 'notification.failure_rate', $stats );
			}
			// 5. Queue age alert (WF-EXC-04 step 6).
			$oldest = NotificationRepository::oldest_due_created_at();
			if ( $oldest && $oldest < $now->modify( '-' . self::QUEUE_AGE_MINUTES . ' minutes' ) ) {
				Logger::critical( 'notification.queue_age', [ 'oldest_utc' => Clock::mysql_utc( $oldest ) ] );
			}
		} finally {
			NamedLock::release( self::HOOK );
		}
		return $out;
	}
}
