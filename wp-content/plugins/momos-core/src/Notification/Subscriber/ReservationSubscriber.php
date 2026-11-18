<?php
declare(strict_types=1);

namespace Momos\Notification\Subscriber;

use Momos\Kernel\Log\Logger;
use Momos\Notification\Queue\NotificationQueue;
use Momos\Notification\Queue\NotificationRequest;
use Momos\Notification\Support\ReservationReader;

/**
 * Which emails go to whom for each reservation transition (docs/CONTRACTS.md; spec §4.1 trigger
 * table, §4.2 dedupe keys). Subscribes to the Reservation domain hooks (fired after commit). The
 * dedupe keys are the documented ones, so if the Reservation module also enqueues inside its own
 * transaction (reservation spec §3.15) nothing is sent twice.
 *
 *  T1 created            → E01 (customer) + E07 × each active branch recipient
 *  T2 pending→confirmed  → E02
 *  T3 pending→rejected   → E03
 *  T4/T6 → cancelled     → customer actor: E04 by_customer + E09 × branch; staff: E04 by_branch unless `suspected_spam`
 *  T5 pending→expired    → E05 (no staff email; listed in E10)
 *  N3 modified           → E06 pending|confirmed (status after the change)
 *  token re-issued       → E06 new_link
 *  N5 SLA reminder/escalation → E08 sla_reminder (branch + handled_by) / sla_escalation (HO list)
 *  T7/T8 attendance, closure conflict → none (closure conflicts are listed in E10)
 */
final class ReservationSubscriber {
	public static function register(): void {
		add_action( 'momos/reservation/created', [ self::class, 'created' ], 10, 1 );
		add_action( 'momos/reservation/status_changed', [ self::class, 'status_changed' ], 10, 4 );
		add_action( 'momos/reservation/modified', [ self::class, 'modified' ], 10, 4 );
		add_action( 'momos/reservation/sla_reminder', [ self::class, 'sla_reminder' ], 10, 1 );
		add_action( 'momos/reservation/sla_escalated', [ self::class, 'sla_escalated' ], 10, 1 );
		add_action( 'momos/reservation/token_reissued', [ self::class, 'token_reissued' ], 10, 1 );
	}

	public static function created( int $id ): void {
		self::safely(
			static function () use ( $id ): void {
				$r = ReservationReader::find( $id );
				if ( ! $r ) {
					return;
				}
				$branch = (int) $r['branch_id'];
				NotificationQueue::enqueue( new NotificationRequest( 'E01', 'customer', 'res:' . $id . ':E01', null, $id, $branch, 'reservation', $id ) );
				NotificationQueue::enqueue( new NotificationRequest( 'E07', 'branch_recipients', 'res:' . $id . ':E07', null, $id, $branch, 'reservation', $id ) );
			},
			'created'
		);
	}

	/** @param array<string,mixed> $actor */
	public static function status_changed( int $id, string $from, string $to, array $actor = [] ): void {
		self::safely(
			static function () use ( $id, $from, $to, $actor ): void {
				$r = ReservationReader::find( $id );
				if ( ! $r ) {
					return;
				}
				$branch  = (int) $r['branch_id'];
				$version = (int) $r['version'];
				$req     = static fn( string $tpl, string $audience, string $key, ?string $variant = null ) => NotificationQueue::enqueue( new NotificationRequest( $tpl, $audience, $key, $variant, $id, $branch, 'reservation', $id ) );
				switch ( $to ) {
					case 'confirmed':
						if ( 'pending' === $from ) {
							$req( 'E02', 'customer', "res:{$id}:E02:{$version}" );
						}
						break;
					case 'rejected':
						$req( 'E03', 'customer', "res:{$id}:E03:{$version}" );
						break;
					case 'expired':
						$req( 'E05', 'customer', "res:{$id}:E05:{$version}" );
						break;
					case 'cancelled':
						$by = (string) ( $actor['type'] ?? $r['cancelled_by'] ?? '' );
						if ( 'customer' === $by || 'customer' === ( $r['cancelled_by'] ?? '' ) ) {
							$req( 'E04', 'customer', "res:{$id}:E04:{$version}", 'by_customer' );
							$req( 'E09', 'branch_recipients', "res:{$id}:E09:{$version}" );
						} elseif ( 'suspected_spam' !== (string) ( $r['decision_reason_code'] ?? '' ) ) {
							$req( 'E04', 'customer', "res:{$id}:E04:{$version}", 'by_branch' );
						}
						break;
				}
			},
			'status_changed'
		);
	}

	/**
	 * @param array<string,mixed> $before
	 * @param array<string,mixed> $after
	 * @param array<string,mixed> $actor
	 */
	public static function modified( int $id, array $before = [], array $after = [], array $actor = [] ): void {
		self::safely(
			static function () use ( $id ): void {
				$r = ReservationReader::find( $id );
				if ( ! $r || ! in_array( $r['status'], [ 'pending', 'confirmed' ], true ) ) {
					return;
				}
				$variant = (string) $r['status'];
				NotificationQueue::enqueue( new NotificationRequest( 'E06', 'customer', 'res:' . $id . ':E06:' . (int) $r['version'], $variant, $id, (int) $r['branch_id'], 'reservation', $id ) );
			},
			'modified'
		);
	}

	public static function token_reissued( int $id ): void {
		self::safely(
			static function () use ( $id ): void {
				$r = ReservationReader::find( $id );
				if ( ! $r || ! in_array( $r['status'], [ 'pending', 'confirmed' ], true ) ) {
					return;
				}
				NotificationQueue::enqueue( new NotificationRequest( 'E06', 'customer', 'res:' . $id . ':E06:new_link:' . (int) $r['token_key_version'], 'new_link', $id, (int) $r['branch_id'], 'reservation', $id ) );
			},
			'token_reissued'
		);
	}

	public static function sla_reminder( int $id ): void {
		self::safely(
			static function () use ( $id ): void {
				$r = ReservationReader::find( $id );
				if ( ! $r || 'pending' !== $r['status'] ) {
					return;
				}
				$extra = ! empty( $r['handled_by'] ) ? [ (int) $r['handled_by'] ] : [];
				NotificationQueue::enqueue( new NotificationRequest( 'E08', 'branch_recipients', 'res:' . $id . ':sla_reminder', 'sla_reminder', $id, (int) $r['branch_id'], 'reservation', $id, [], $extra ) );
			},
			'sla_reminder'
		);
	}

	public static function sla_escalated( int $id ): void {
		self::safely(
			static function () use ( $id ): void {
				$r = ReservationReader::find( $id );
				if ( ! $r || 'pending' !== $r['status'] ) {
					return;
				}
				NotificationQueue::enqueue( new NotificationRequest( 'E08', 'head_office', 'res:' . $id . ':sla_escalation', 'sla_escalation', $id, (int) $r['branch_id'], 'reservation', $id ) );
			},
			'sla_escalated'
		);
	}

	/** An email problem never breaks the reservation flow (NFR-REL-01, FR-NOT-003(d)). */
	private static function safely( callable $fn, string $event ): void {
		try {
			$fn();
		} catch ( \Throwable $e ) {
			Logger::error( 'notification.enqueue_failed', [ 'event' => $event, 'error' => $e->getMessage() ] );
		}
	}
}
