<?php
declare(strict_types=1);

namespace Momos\Notification;

use Momos\Kernel\Jobs\Schedule;
use Momos\Kernel\ModuleInterface;

/**
 * Notification module (docs/specs/notifications.md): queue on `momo_notifications`, Action Scheduler
 * worker, SMTP/log transports, customer E01–E06 and staff E07–E11 templates, recipient routing, E10
 * digest, notification log, resend, retention helpers.
 *
 * Public API: Notifier::queue() (docs/CONTRACTS.md), Queue\NotificationQueue::enqueue(),
 * Repository\NotificationRepository (purge/delete/export helpers), Service\Retention,
 * Admin\NotificationPanel::api_rows() (API-11 `notifications`).
 * Hooks fired: `momos/notification/queued (int $id)`, `momos/notification/sent (int $id)`,
 * `momos/notification/failed (int $id, ?int $reservation_id)`, `momos/notification/resend_sent (int $id, int $reservation_id)`.
 * Filters: `momos/notification/recipients`, `momos/notification/transport`, `momos/notification/admin_reservation_url`,
 * `momos/notification/lang_ready`, `momos/notification/e05_walk_in_allowed`.
 */
final class NotificationModule implements ModuleInterface {
	public function register(): void {
		// Worker: immediate async attempt after commit + retry single actions + every-minute runner.
		add_action( 'momos/notification/queued', [ Queue\Dispatcher::class, 'schedule_async' ], 10, 1 );
		add_action(
			Queue\Dispatcher::ACTION,
			static function ( $id ): void {
				Queue\Dispatcher::send( (int) $id );
			},
			10,
			1
		);
		Schedule::recurring( Queue\Dispatcher::RUNNER, 60 );
		add_action( Queue\Dispatcher::RUNNER, [ Queue\Dispatcher::class, 'run_due' ] );
		Schedule::recurring( Queue\QueueSweeper::HOOK, 300 );
		add_action( Queue\QueueSweeper::HOOK, [ Queue\QueueSweeper::class, 'run' ] );
		add_filter( 'action_scheduler_queue_runner_batch_size', static fn( $size ) => max( (int) $size, Queue\Dispatcher::BATCH ) );

		Transport\SmtpConfigurator::register();
		Subscriber\ReservationSubscriber::register();
		Job\DigestJob::register();
		Admin\SettingsFields::register();
		Service\Retention::register();
		Rest\ResendController::register();
		if ( is_admin() ) {
			Admin\NotificationLogScreen::register();
			Admin\NotificationPanel::register();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Cli\NotificationCommand::register();
		}
	}
}
