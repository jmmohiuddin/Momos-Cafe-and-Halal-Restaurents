<?php
declare(strict_types=1);

namespace Momos\Notification\Queue;

use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Db\NamedLock;
use Momos\Kernel\Jobs\Schedule;
use Momos\Kernel\Log\Logger;
use Momos\Notification\Recipient\RecipientResolver;
use Momos\Notification\Repository\NotificationRepository;
use Momos\Notification\Support\ReservationReader;
use Momos\Notification\Template\RenderException;
use Momos\Notification\Template\Renderer;
use Momos\Notification\Template\TemplateRegistry;
use Momos\Notification\Transport\MailIdentity;
use Momos\Notification\Transport\Message;
use Momos\Notification\Transport\TransportFactory;

/**
 * Worker (F-NOT-03, spec §4.3): claim → guards → render → transport → mark. Triggered by the async
 * Action Scheduler action `momos_send_notification` [id] enqueued after commit, by retry single
 * actions, and by the every-minute runner `momos_job_notification_dispatch` (NFR-PERF-05). Safe to
 * run concurrently: the conditional claim UPDATE gives each row to exactly one worker.
 */
final class Dispatcher {
	public const ACTION     = 'momos_send_notification';
	public const RUNNER     = 'momos_job_notification_dispatch';
	public const BATCH      = 25;
	public const TIME_LIMIT = 50;

	/** Schedule the immediate async attempt for a committed row. */
	public static function schedule_async( int $id ): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::ACTION, [ $id ], Schedule::GROUP );
		}
	}

	/**
	 * Every-minute runner: due rows oldest first, ≤ 25 per run, ≤ 50 s (spec §4.3 AS settings,
	 * NFR-PERF-05). The budget is wall time (a runtime limit, not business time); the filter
	 * `momos/notification/runner_time_limit` can only lower it (hosts with a shorter max_execution_time).
	 */
	public static function run_due(): int {
		if ( ! NamedLock::acquire( self::RUNNER, 0 ) ) {
			return 0;
		}
		$sent   = 0;
		$start  = microtime( true );
		$budget = min( (float) self::TIME_LIMIT, max( 0.0, (float) apply_filters( 'momos/notification/runner_time_limit', (float) self::TIME_LIMIT ) ) );
		try {
			foreach ( NotificationRepository::due_ids( self::BATCH ) as $id ) {
				if ( microtime( true ) - $start > $budget ) {
					break;
				}
				if ( 'sent' === self::send( $id ) ) {
					++$sent;
				}
			}
			update_option( 'momos_job_heartbeat_queue', Clock::mysql_utc(), false );
		} finally {
			NamedLock::release( self::RUNNER );
		}
		return $sent;
	}

	/**
	 * Process one row. Returns the resulting status ('sent', 'queued' (retry), 'failed', 'cancelled')
	 * or 'skipped' when the row could not be claimed (not due / owned by another worker).
	 */
	public static function send( int $id ): string {
		if ( ! NotificationRepository::claim( $id ) ) {
			return 'skipped';
		}
		$row = NotificationRepository::find( $id );
		if ( null === $row ) {
			return 'skipped';
		}
		$guard = self::guard( $row );
		if ( null !== $guard ) {
			NotificationRepository::mark_cancelled( $id, $guard );
			return 'cancelled';
		}
		if ( ! is_email( (string) $row['recipient_email'] ) ) {
			return self::fail( $row, 'invalid_recipient', 'recipient address is not valid' );
		}
		try {
			$rendered = Renderer::render( $row );
		} catch ( RenderException $e ) {
			return self::fail( $row, 'render_failed', $e->getMessage() );
		} catch ( \Throwable $e ) {
			Logger::error( 'notification.render_error', [ 'id' => $id, 'template' => $row['template_key'], 'error' => $e->getMessage() ] );
			return self::fail( $row, 'render_failed', get_class( $e ) );
		}
		$message = MailIdentity::finalize(
			new Message(
				(string) $row['recipient_email'],
				$rendered->subject,
				$rendered->html,
				$rendered->text,
				MailIdentity::from_address(),
				$rendered->from_name,
				$rendered->reply_to,
				MailIdentity::message_id( (string) $row['uuid'] ),
				$rendered->lang,
				(string) $row['recipient_masked']
			)
		);
		$result  = TransportFactory::make()->send( $message );
		if ( $result->ok ) {
			$info = $rendered->fell_back() ? 'requested=' . $rendered->requested_lang . ' used=' . $rendered->lang : null;
			NotificationRepository::mark_sent( $id, $result->message_id, $rendered->lang, $info ? 'lang_fallback' : null, $info );
			if ( $info ) {
				Logger::warning( 'notification.lang_fallback', [ 'id' => $id, 'template' => $row['template_key'], 'requested' => $rendered->requested_lang, 'used' => $rendered->lang ] );
			}
			do_action( 'momos/notification/sent', $id );
			if ( str_starts_with( (string) $row['dedupe_key'], 'resend:' ) && ! empty( $row['reservation_id'] ) ) {
				/** A resent customer email was accepted: Reservation resets `customer_notified_flag` (spec §8.3). */
				do_action( 'momos/notification/resend_sent', $id, (int) $row['reservation_id'] );
			}
			return 'sent';
		}
		if ( in_array( $result->error_code, RetryPolicy::CRITICAL, true ) ) {
			Logger::critical( 'notification.transport_' . $result->error_code, [ 'id' => $id ] );
		}
		return self::fail( $row, $result->error_code, $result->error );
	}

	/**
	 * Guards (spec §4.3 step 2) → cancellation code or null.
	 *
	 * @param array<string,mixed> $row
	 */
	private static function guard( array $row ): ?string {
		if ( null === $row['recipient_email'] || '' === (string) $row['recipient_email'] ) {
			return 'recipient_purged';
		}
		$reservation = ! empty( $row['reservation_id'] ) ? ReservationReader::find( (int) $row['reservation_id'] ) : null;
		if ( $reservation && ! empty( $reservation['pii_purged_at'] ) && 'customer' === $row['recipient_type'] ) {
			return 'recipient_purged';
		}
		if ( 'E08' === $row['template_key'] && in_array( (string) $row['template_variant'], [ 'sla_reminder', 'sla_escalation' ], true ) && ( ! $reservation || 'pending' !== $reservation['status'] ) ) {
			return 'not_applicable'; // FR-RES-017(a): decided before the reminder went out.
		}
		if ( ! TemplateRegistry::is_customer( (string) $row['template_key'] ) && $reservation && ! empty( $reservation['email'] ) && hash_equals( RecipientResolver::hash( (string) $reservation['email'] ), (string) $row['recipient_hash'] ) ) {
			return 'not_applicable'; // A staff email never goes to the customer's address (Doc 11 §5.4).
		}
		return null;
	}

	/**
	 * Failed attempt: retry with backoff, or final failure with its effects (spec §4.4).
	 *
	 * @param array<string,mixed> $row
	 */
	private static function fail( array $row, string $code, string $error ): string {
		$id       = (int) $row['id'];
		$attempts = (int) $row['attempts'];
		$next     = RetryPolicy::next_attempt( $attempts, $code, Clock::utc() );
		if ( null !== $next ) {
			NotificationRepository::mark_retry( $id, $next, $code, $error );
			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( $next->getTimestamp(), self::ACTION, [ $id ], Schedule::GROUP );
			}
			Logger::warning( 'notification.retry', [ 'id' => $id, 'template' => $row['template_key'], 'attempt' => $attempts, 'code' => $code ] );
			return 'queued';
		}
		NotificationRepository::mark_failed( $id, $code, $error );
		FailureHandler::handle( $id );
		return 'failed';
	}
}
