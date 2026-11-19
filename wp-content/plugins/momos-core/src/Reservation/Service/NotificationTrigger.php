<?php
declare(strict_types=1);

namespace Momos\Reservation\Service;

use Momos\Kernel\Log\Logger;
use Momos\Notification\Queue\NotificationQueue;
use Momos\Notification\Queue\NotificationRequest;
use Momos\Reservation\Domain\ReservationError;

/**
 * Queues the customer/staff e-mails of a reservation transition INSIDE the business transaction
 * (reservation spec §3.15, notifications spec §4.1 "Every queue insert happens inside the business
 * transaction", TC-RES-001, NFR-REL-01). A failed queue insert rolls the state change back
 * (ReservationError `service_unavailable`, 503) instead of committing a request or an accept that
 * nobody is told about. Rows use the documented dedupe keys (notifications spec §4.2), so the
 * Notification module's after-commit subscriber (ReservationSubscriber) finds them already queued.
 *
 * Must be called inside `Transaction::run()` after the reservation row was written.
 */
final class NotificationTrigger {
	/** T1: E01 (customer) + E07 × each active branch recipient. */
	public static function created( int $id, int $branch_id ): void {
		self::queue( $id, $branch_id, [ [ 'E01', 'customer', "res:{$id}:E01", null ], [ 'E07', 'branch_recipients', "res:{$id}:E07", null ] ] );
	}

	/**
	 * T2–T6 (T7/T8 send nothing). `$version` is the reservation version after the change.
	 *
	 * @return list<string> template keys queued
	 */
	public static function status_changed( int $id, int $branch_id, string $from, string $to, int $version, string $actor_type, ?string $reason ): array {
		$jobs = match ( $to ) {
			'confirmed' => 'pending' === $from ? [ [ 'E02', 'customer', "res:{$id}:E02:{$version}", null ] ] : [],
			'rejected'  => [ [ 'E03', 'customer', "res:{$id}:E03:{$version}", null ] ],
			'expired'   => [ [ 'E05', 'customer', "res:{$id}:E05:{$version}", null ] ],
			'cancelled' => 'customer' === $actor_type
				? [ [ 'E04', 'customer', "res:{$id}:E04:{$version}", 'by_customer' ], [ 'E09', 'branch_recipients', "res:{$id}:E09:{$version}", null ] ]
				: ( 'suspected_spam' === $reason ? [] : [ [ 'E04', 'customer', "res:{$id}:E04:{$version}", 'by_branch' ] ] ),
			default     => [],
		};
		return self::queue( $id, $branch_id, $jobs );
	}

	/** N3: E06 `pending` or `confirmed` (status after the change). */
	public static function modified( int $id, int $branch_id, string $status, int $version ): void {
		if ( in_array( $status, [ 'pending', 'confirmed' ], true ) ) {
			self::queue( $id, $branch_id, [ [ 'E06', 'customer', "res:{$id}:E06:{$version}", $status ] ] );
		}
	}

	/**
	 * @param list<array{0:string,1:string,2:string,3:?string}> $jobs [template, audience, dedupe base, variant]
	 * @return list<string> templates for which at least one row is queued
	 * @throws ReservationError service_unavailable when a row cannot be stored
	 */
	private static function queue( int $id, int $branch_id, array $jobs ): array {
		if ( ! $jobs || ! class_exists( NotificationQueue::class ) ) {
			return [];
		}
		$queued = [];
		try {
			foreach ( $jobs as [ $template, $audience, $key, $variant ] ) {
				if ( NotificationQueue::enqueue( new NotificationRequest( $template, $audience, $key, $variant, $id, $branch_id, 'reservation', $id ) ) ) {
					$queued[] = $template;
				}
			}
		} catch ( \Throwable $e ) {
			Logger::error( 'reservation.notification_enqueue_failed', [ 'reservation_id' => $id, 'error' => substr( $e->getMessage(), 0, 200 ) ] );
			throw new ReservationError( 'service_unavailable', [], [ 'retryable' => true ] );
		}
		return $queued;
	}
}
