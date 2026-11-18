<?php
declare(strict_types=1);

namespace Momos\Notification\Queue;

use Momos\Kernel\Events\AfterCommit;
use Momos\Kernel\Log\Logger;
use Momos\Notification\Repository\NotificationRepository;
use Momos\Notification\Template\TemplateRegistry;

/**
 * Final-failure effects (FR-NOT-003(c), BR-RES-12, US-NOT-005; spec §4.4). Never changes the
 * reservation status and never deletes the row.
 *  - Every final failure fires `momos/notification/failed (int $id, ?int $reservation_id)`; the
 *    Reservation module sets `customer_notified_flag = 0` and writes event `notification_failed`.
 *  - Customer template (E01–E06): E08 `notify_failed` to the branch recipients.
 *  - Staff template: no cascade; `error` log (E10: `critical` for monitoring).
 */
final class FailureHandler {
	public static function handle( int $id ): void {
		$row = NotificationRepository::find( $id );
		if ( null === $row || 'failed' !== $row['status'] ) {
			return;
		}
		$reservation_id = ! empty( $row['reservation_id'] ) ? (int) $row['reservation_id'] : null;
		$key            = (string) $row['template_key'];
		if ( TemplateRegistry::is_customer( $key ) && null !== $reservation_id ) {
			NotificationQueue::enqueue(
				new NotificationRequest( 'E08', 'branch_recipients', 'res:' . $reservation_id . ':notify_failed:' . $id, 'notify_failed', $reservation_id, (int) $row['branch_id'] ?: null, 'notification', $id )
			);
			Logger::warning( 'notification.customer_not_notified', [ 'id' => $id, 'template' => $key, 'code' => $row['last_error_code'] ] );
		} elseif ( 'E10' === $key ) {
			Logger::critical( 'notification.digest_not_delivered', [ 'id' => $id, 'code' => $row['last_error_code'] ] );
		} else {
			Logger::error( 'notification.staff_failed', [ 'id' => $id, 'template' => $key, 'code' => $row['last_error_code'] ] );
		}
		AfterCommit::dispatch( 'momos/notification/failed', $id, $reservation_id );
	}
}
