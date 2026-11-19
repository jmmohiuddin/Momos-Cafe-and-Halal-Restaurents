<?php
declare(strict_types=1);

namespace Momos\Reservation\Service;

use Momos\Branch\Repository\BranchSettingsRepository;
use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Db\Transaction;
use Momos\Kernel\Events\AfterCommit;
use Momos\Reservation\Domain\Actor;
use Momos\Reservation\Domain\EventType;
use Momos\Reservation\Repository\EventRepository;
use Momos\Reservation\Repository\ReservationRepository;

/**
 * T5 expiry (DEC-012, FR-RES-016, spec §3.9): a pending request expires when
 * now ≥ requested_start_utc − decision_cutoff (branch value at run time). Used lazily by API-03/04/11/
 * 12/16 and by the 5-minute job. Own transaction; a 0-row conditional UPDATE is skipped silently, so
 * re-runs produce one event and one hook per reservation.
 */
final class ExpiryService {
	public static function is_due( array $row, ?\DateTimeImmutable $now = null ): bool {
		if ( 'pending' !== $row['status'] ) {
			return false;
		}
		$cutoff = (int) BranchSettingsRepository::get( (int) $row['branch_id'] )['decision_cutoff_minutes'];
		$start  = Clock::from_mysql_utc( (string) $row['requested_start_utc'] );
		return null !== $start && ( $now ?? Clock::utc() ) >= $start->modify( '-' . $cutoff . ' minutes' );
	}

	/**
	 * Lazy expiry on read paths (inbox, detail, API-03/11): a failure (e.g. the E05 queue row cannot be
	 * stored, so the expiry rolled back) is logged and the row is shown as it is; ExpiryJob retries.
	 */
	public static function expire_quietly( int $id ): void {
		try {
			self::expire_if_due( $id );
		} catch ( \Throwable $e ) {
			\Momos\Kernel\Log\Logger::error( 'reservation.expiry_failed', [ 'reservation_id' => $id, 'error' => substr( $e->getMessage(), 0, 200 ) ] );
		}
	}

	/**
	 * Expire the row when due. Returns the updated row when this call expired it, null otherwise.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function expire_if_due( int $id ): ?array {
		$row = ReservationRepository::find( $id );
		if ( null === $row || ! self::is_due( $row ) ) {
			return null;
		}
		$done = Transaction::run(
			static function () use ( $row ): bool {
				$now      = Clock::mysql_utc();
				$affected = ReservationRepository::update_conditional( (int) $row['id'], null, 'pending', [ 'status' => 'expired', 'expired_at' => $now ] );
				if ( 1 !== $affected ) {
					return false;
				}
				EventRepository::add( (int) $row['id'], EventType::StatusChanged, Actor::system(), 'pending', 'expired' );
				$version = (int) ( ReservationRepository::find( (int) $row['id'] )['version'] ?? 0 );
				NotificationTrigger::status_changed( (int) $row['id'], (int) $row['branch_id'], 'pending', 'expired', $version, 'system', null ); // E05, same transaction.
				AfterCommit::dispatch( 'momos/reservation/status_changed', (int) $row['id'], 'pending', 'expired', Actor::system()->to_array() );
				return true;
			}
		);
		return $done ? ReservationRepository::find( $id ) : null;
	}
}
