<?php
declare(strict_types=1);

namespace Momos\Reservation\Job;

use Momos\Branch\Repository\BranchSettingsRepository;
use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Db\NamedLock;
use Momos\Kernel\Db\Transaction;
use Momos\Kernel\Events\AfterCommit;
use Momos\Kernel\Jobs\Schedule;
use Momos\Kernel\Log\Logger;
use Momos\Reservation\Domain\Actor;
use Momos\Reservation\Domain\EventType;
use Momos\Reservation\Repository\EventRepository;
use Momos\Reservation\Repository\ReservationRepository;
use Momos\Reservation\Service\SlaClock;

/**
 * `momos_job_sla_check` every 5 min (FR-RES-017, DEC-013, spec §3.10): reminder at SLA, Head Office
 * escalation at 2 × SLA, once each (event rows make re-runs idempotent). Only `pending` rows of
 * branches with SLA > 0. Fires `momos/reservation/sla_reminder` / `sla_escalated` after commit; the
 * Notification module sends E08. Also raises the expiry-heartbeat alert (NFR-OBS-01).
 */
final class SlaJob {
	public const HOOK = 'momos_job_sla_check';

	public static function register(): void {
		Schedule::recurring( self::HOOK, 300 );
		add_action( self::HOOK, [ self::class, 'run' ] );
	}

	/** @return array{reminders:int,escalations:int} */
	public static function run(): array {
		$out = [ 'reminders' => 0, 'escalations' => 0 ];
		if ( ! NamedLock::acquire( 'momos_job_sla_check', 0 ) ) {
			return $out;
		}
		try {
			$now = Clock::utc();
			foreach ( ReservationRepository::branches_with_pending() as $branch_id ) {
				$s   = BranchSettingsRepository::get( $branch_id );
				$sla = (int) $s['response_sla_minutes'];
				if ( $sla <= 0 ) {
					continue;
				}
				foreach ( ReservationRepository::pending_of_branch( $branch_id ) as $row ) {
					$id      = (int) $row['id'];
					$elapsed = SlaClock::minutes( $branch_id, Clock::from_mysql_utc( (string) $row['created_at'] ), $now, $s['sla_clock'] );
					if ( $elapsed >= $sla && ! EventRepository::has( $id, EventType::SlaReminder ) ) {
						self::record( $id, EventType::SlaReminder, 'momos/reservation/sla_reminder', $elapsed );
						++$out['reminders'];
					}
					if ( $elapsed >= 2 * $sla && ! EventRepository::has( $id, EventType::SlaEscalated ) ) {
						self::record( $id, EventType::SlaEscalated, 'momos/reservation/sla_escalated', $elapsed );
						++$out['escalations'];
					}
				}
			}
			$since = ExpiryJob::minutes_since_heartbeat();
			if ( null !== $since && $since >= 30 ) {
				Logger::critical( 'job.expiry_heartbeat_missing', [ 'minutes' => $since ] );
			}
		} finally {
			NamedLock::release( 'momos_job_sla_check' );
		}
		return $out;
	}

	private static function record( int $id, EventType $type, string $hook, int $minutes ): void {
		Transaction::run(
			static function () use ( $id, $type, $hook, $minutes ): void {
				$row = ReservationRepository::find( $id );
				if ( null === $row || 'pending' !== $row['status'] ) {
					return;
				}
				EventRepository::add( $id, $type, Actor::system(), null, null, null, null, [ 'minutes_waiting' => $minutes ] );
				AfterCommit::dispatch( $hook, $id );
			}
		);
	}
}
