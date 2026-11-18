<?php
declare(strict_types=1);

namespace Momos\Reservation\Service;

use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Db\Tables;
use Momos\Kernel\Db\Transaction;
use Momos\Kernel\Events\AfterCommit;
use Momos\Kernel\Log\Logger;
use Momos\Reservation\Domain\Actor;
use Momos\Reservation\Domain\EventType;
use Momos\Reservation\Repository\EventRepository;
use Momos\Reservation\Repository\ReservationRepository;

/**
 * Follow-up flags set by other modules' events:
 *  - closure conflicts (BR-RES-13, spec §3.13): `momos/branch/schedule_changed` and
 *    `momos/branch/accepting_changed(false)` → needs_action_flag = 1 + event `closure_conflict`;
 *    never a status change or a customer email. Also supplies SlotGenerator to the Branch impact
 *    preview through filter `momos/branch/slot_valid`.
 *  - final notification failures (BR-RES-12): `momos/notification/failed` → customer_notified_flag = 0
 *    + event `notification_failed` for customer templates E01–E06.
 */
final class FlagService {
	public static function register(): void {
		add_action( 'momos/branch/schedule_changed', [ self::class, 'on_schedule_changed' ], 10, 2 );
		add_action( 'momos/branch/accepting_changed', [ self::class, 'on_accepting_changed' ], 10, 3 );
		add_filter( 'momos/branch/slot_valid', [ self::class, 'slot_valid' ], 10, 3 );
		add_action( 'momos/notification/failed', [ self::class, 'on_notification_failed' ], 10, 2 );
	}

	/** @param list<string> $dates */
	public static function on_schedule_changed( int $branch_id, array $dates = [] ): int {
		$check = null;
		if ( $dates ) {
			$check = [];
			foreach ( $dates as $d ) {
				$check[] = (string) $d;
				$check[] = ( new \DateTimeImmutable( (string) $d, Clock::tz() ) )->modify( '+1 day' )->format( 'Y-m-d' );
			}
			$check = array_values( array_unique( $check ) );
		}
		\Momos\Branch\Service\ScheduleService::flush();
		$n = 0;
		foreach ( ReservationRepository::active_future( $branch_id, $check ) as $r ) {
			if ( ! SlotGenerator::is_valid_slot( $branch_id, (string) $r['requested_date'], (string) $r['requested_time'] ) ) {
				$n += self::flag_conflict( $r, 'schedule_changed' ) ? 1 : 0;
			}
		}
		return $n;
	}

	public static function on_accepting_changed( int $branch_id, bool $accepting, string $reason = '' ): int {
		if ( $accepting ) {
			return 0;
		}
		$n = 0;
		foreach ( ReservationRepository::active_future( $branch_id ) as $r ) {
			$n += self::flag_conflict( $r, '' !== $reason ? $reason : 'not_accepting' ) ? 1 : 0;
		}
		return $n;
	}

	/** @param array<string,mixed> $reservation */
	public static function slot_valid( bool $valid, array $reservation, int $branch_id ): bool {
		return SlotGenerator::is_valid_slot( $branch_id, (string) $reservation['requested_date'], (string) $reservation['requested_time'] );
	}

	/** @param array<string,mixed> $r (id, status, version, needs_action_flag) */
	private static function flag_conflict( array $r, string $reason ): bool {
		if ( '1' === (string) $r['needs_action_flag'] ) {
			return false;
		}
		$id = (int) $r['id'];
		return Transaction::run(
			static function () use ( $id, $r, $reason ): bool {
				if ( 1 !== ReservationRepository::update_conditional( $id, (int) $r['version'], (string) $r['status'], [ 'needs_action_flag' => 1 ] ) ) {
					return false;
				}
				EventRepository::add( $id, EventType::ClosureConflict, Actor::system(), null, null, null, null, [ 'trigger' => $reason ] );
				AfterCommit::dispatch( 'momos/reservation/closure_conflict', $id );
				return true;
			}
		);
	}

	public static function on_notification_failed( int $notification_id, ?int $reservation_id = null ): void {
		if ( ! $reservation_id ) {
			return;
		}
		global $wpdb;
		$t     = Tables::notifications();
		$notif = $wpdb->get_row( $wpdb->prepare( "SELECT template_key, template_variant, recipient_type, last_error_code FROM {$t} WHERE id = %d", $notification_id ), ARRAY_A ); // phpcs:ignore
		$tpl   = $notif ? (string) $notif['template_key'] : '';
		if ( $notif && ( 'customer' !== $notif['recipient_type'] || ! in_array( $tpl, [ 'E01', 'E02', 'E03', 'E04', 'E05', 'E06' ], true ) ) ) {
			return;
		}
		try {
			Transaction::run(
				static function () use ( $reservation_id, $notification_id, $tpl, $notif ): void {
					$row = ReservationRepository::find( $reservation_id );
					if ( null === $row ) {
						return;
					}
					if ( '0' !== (string) $row['customer_notified_flag'] ) {
						ReservationRepository::update_conditional( $reservation_id, null, null, [ 'customer_notified_flag' => 0 ] );
					}
					EventRepository::add(
						$reservation_id,
						EventType::NotificationFailed,
						Actor::system(),
						null,
						null,
						null,
						null,
						[ 'notification_id' => $notification_id, 'template' => $tpl, 'error_code' => $notif['last_error_code'] ?? null ]
					);
				}
			);
		} catch ( \Throwable $e ) {
			Logger::error( 'reservation.flag_failed', [ 'reservation_id' => $reservation_id, 'error' => $e->getMessage() ] );
		}
	}
}
