<?php
declare(strict_types=1);

namespace Momos\Reservation\Job;

use Momos\Branch\Repository\BranchSettingsRepository;
use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Db\NamedLock;
use Momos\Kernel\Jobs\Schedule;
use Momos\Kernel\Log\Logger;
use Momos\Reservation\Repository\ReservationRepository;
use Momos\Reservation\Service\ExpiryService;

/**
 * `momos_job_expire_pending` every 5 min (DEC-012, FR-RES-016, spec §3.9). Per branch (disabled ones
 * included) at most 200 pending rows with start ≤ now + cutoff; one failure never stops the batch.
 * Pings the `expiry` heartbeat (NFR-OBS-01).
 */
final class ExpiryJob {
	public const HOOK             = 'momos_job_expire_pending';
	public const HEARTBEAT_OPTION = 'momos_heartbeat_expiry';

	public static function register(): void {
		Schedule::recurring( self::HOOK, 300 );
		add_action( self::HOOK, [ self::class, 'run' ] );
	}

	/** @return int number of reservations expired */
	public static function run(): int {
		if ( ! NamedLock::acquire( 'momos_job_expire_pending', 0 ) ) {
			return 0;
		}
		$count = 0;
		try {
			$now = Clock::utc();
			foreach ( ReservationRepository::branches_with_pending() as $branch_id ) {
				$cutoff = (int) BranchSettingsRepository::get( $branch_id )['decision_cutoff_minutes'];
				foreach ( ReservationRepository::pending_due( $branch_id, Clock::mysql_utc( $now->modify( '+' . $cutoff . ' minutes' ) ) ) as $id ) {
					try {
						if ( null !== ExpiryService::expire_if_due( $id ) ) {
							++$count;
						}
					} catch ( \Throwable $e ) {
						Logger::error( 'reservation.expiry_failed', [ 'reservation_id' => $id, 'error' => $e->getMessage() ] );
					}
				}
			}
			self::heartbeat();
		} finally {
			NamedLock::release( 'momos_job_expire_pending' );
		}
		return $count;
	}

	private static function heartbeat(): void {
		update_option( self::HEARTBEAT_OPTION, Clock::mysql_utc(), false );
		$url = defined( 'MOMOS_HEARTBEAT_URL_EXPIRY' ) ? (string) MOMOS_HEARTBEAT_URL_EXPIRY : (string) getenv( 'MOMOS_HEARTBEAT_URL_EXPIRY' );
		if ( '' !== $url && wp_http_validate_url( $url ) ) {
			wp_remote_get( $url, [ 'blocking' => false, 'timeout' => 2 ] );
		}
	}

	/** Minutes since the last expiry run, null when it never ran. */
	public static function minutes_since_heartbeat(): ?int {
		$last = Clock::from_mysql_utc( (string) get_option( self::HEARTBEAT_OPTION, '' ) );
		return null === $last ? null : intdiv( Clock::utc()->getTimestamp() - $last->getTimestamp(), 60 );
	}
}
