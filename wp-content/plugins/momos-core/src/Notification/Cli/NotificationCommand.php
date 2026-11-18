<?php
declare(strict_types=1);

namespace Momos\Notification\Cli;

use Momos\Kernel\Env;
use Momos\Notification\Job\DigestJob;
use Momos\Notification\Queue\Dispatcher;
use Momos\Notification\Queue\QueueSweeper;
use Momos\Notification\Service\Retention;
use Momos\Notification\Template\Renderer;

/**
 * WP-CLI `wp momos notifications …` (spec §8.4). Rendering is refused in production (never with real
 * data).
 */
final class NotificationCommand {
	public static function register(): void {
		\WP_CLI::add_command( 'momos notifications', self::class );
	}

	/**
	 * Send every due queued notification now.
	 *
	 * @subcommand send-due
	 */
	public function send_due(): void {
		$n = Dispatcher::run_due();
		\WP_CLI::success( "Sent {$n} notification(s)." );
	}

	/** Run the sweeper (stale locks, re-enqueue, heartbeat, alerts). */
	public function sweep(): void {
		\WP_CLI::log( (string) wp_json_encode( QueueSweeper::run() ) );
	}

	/** Queue today's E10 digests now. */
	public function digest(): void {
		$ids = DigestJob::run();
		\WP_CLI::success( count( $ids ) . ' digest(s) queued.' );
	}

	/** Retention: purge recipients of terminal rows (30 days) and delete rows older than the retention period. */
	public function retention(): void {
		\WP_CLI::log( (string) wp_json_encode( Retention::run() ) );
	}

	/**
	 * Render a stored notification row to stdout (HTML or text) for snapshot tests. Non-production only.
	 *
	 * ## OPTIONS
	 * <id>
	 * : Notification id.
	 * [--part=<part>]
	 * : html|text|subject. Default html.
	 */
	public function render( array $args, array $assoc ): void {
		if ( Env::is_production() ) {
			\WP_CLI::error( 'Rendering is disabled in production.' );
		}
		$row = \Momos\Notification\Repository\NotificationRepository::find( (int) $args[0] );
		if ( ! $row ) {
			\WP_CLI::error( 'Not found.' );
		}
		$r    = Renderer::render( $row );
		$part = (string) ( $assoc['part'] ?? 'html' );
		\WP_CLI::line( 'text' === $part ? $r->text : ( 'subject' === $part ? $r->subject : $r->html ) );
	}
}
