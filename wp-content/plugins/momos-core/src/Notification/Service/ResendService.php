<?php
declare(strict_types=1);

namespace Momos\Notification\Service;

use Momos\Notification\Queue\NotificationQueue;
use Momos\Notification\Repository\NotificationRepository;
use Momos\Notification\Support\ReservationReader;
use Momos\Notification\Template\TemplateRegistry;

/**
 * Resend a failed customer email (TK-10-07, spec §8.3). Creates a NEW row (same template, variant,
 * language, recipient; dedupe `resend:{original_id}:{n}`) and enqueues it; audit `notification.resent`.
 * Error codes are the catalogue ones: forbidden_branch, not_found, invalid_transition, rate_limited.
 */
final class ResendService {
	public const MAX_RESENDS_PER_ROW = 3;
	public const RATE_PER_HOUR       = 10;

	/**
	 * Can the user resend this row? Returns null when allowed, else an error code.
	 *
	 * @param array<string,mixed> $row
	 */
	public static function check( array $row, int $user_id, ?int $expected_reservation_id = null ): ?string {
		if ( null !== $expected_reservation_id && (int) $row['reservation_id'] !== $expected_reservation_id ) {
			return 'not_found';
		}
		if ( ! self::user_can( $row, $user_id ) ) {
			return 'forbidden_branch';
		}
		if ( 'failed' !== $row['status'] || 'customer' !== $row['recipient_type'] || ! TemplateRegistry::is_customer( (string) $row['template_key'] ) || empty( $row['recipient_email'] ) || empty( $row['reservation_id'] ) ) {
			return 'invalid_transition';
		}
		$r = ReservationReader::find( (int) $row['reservation_id'] );
		if ( ! $r || ! empty( $r['pii_purged_at'] ) || ! self::matches_status( (string) $row['template_key'], (string) $row['template_variant'], (string) $r['status'] ) ) {
			return 'invalid_transition';
		}
		if ( NotificationRepository::resend_count( (int) $row['id'] ) >= self::MAX_RESENDS_PER_ROW ) {
			return 'invalid_transition';
		}
		return null;
	}

	/**
	 * Object-level permission: SA/HO log access, or `momo_process_reservations` within the user's
	 * branch scope (equivalent of meta cap `momo_process_reservation(id)`, TRD §2.7.3).
	 *
	 * @param array<string,mixed> $row
	 */
	public static function user_can( array $row, int $user_id ): bool {
		$branch = (int) ( $row['branch_id'] ?? 0 );
		if ( user_can( $user_id, 'momo_view_notification_log' ) && momos_user_can_branch( $branch, $user_id ) ) {
			return true;
		}
		return user_can( $user_id, 'momo_process_reservations' ) && $branch > 0 && momos_user_can_branch( $branch, $user_id );
	}

	/** Template still matches the current status (spec §8.3). */
	public static function matches_status( string $template, string $variant, string $status ): bool {
		return match ( $template ) {
			'E01'   => 'pending' === $status,
			'E02'   => 'confirmed' === $status,
			'E03'   => 'rejected' === $status,
			'E04'   => 'cancelled' === $status,
			'E05'   => 'expired' === $status,
			'E06'   => ( 'confirmed' === $variant && 'confirmed' === $status ) || ( in_array( $variant, [ 'pending', 'new_link' ], true ) && 'pending' === $status ) || ( 'new_link' === $variant && 'confirmed' === $status ),
			default => false,
		};
	}

	/** Latest failed customer row of a reservation that can be resent (SCR-A09 "Resend" button). */
	public static function is_latest_resendable( array $row, int $user_id ): bool {
		if ( null !== self::check( $row, $user_id ) ) {
			return false;
		}
		foreach ( NotificationRepository::for_reservation( (int) $row['reservation_id'] ) as $other ) {
			if ( 'customer' === $other['recipient_type'] && 'failed' === $other['status'] ) {
				return (int) $other['id'] === (int) $row['id'];
			}
		}
		return false;
	}

	/**
	 * @return array{ok:bool,code:?string,id:?int}
	 */
	public static function resend( int $notification_id, int $user_id, ?int $reservation_id = null ): array {
		$row = NotificationRepository::find( $notification_id );
		if ( null === $row ) {
			return [ 'ok' => false, 'code' => 'not_found', 'id' => null ];
		}
		$err = self::check( $row, $user_id, $reservation_id );
		if ( null !== $err ) {
			if ( 'forbidden_branch' === $err ) {
				momos_audit( 'access.denied', 'notification', $notification_id, (int) $row['branch_id'] ?: null, [ 'action' => 'notification.resend' ] );
				do_action( 'momos/access/denied', [ 'action' => 'notification.resend', 'object_type' => 'notification', 'object_id' => $notification_id, 'user_id' => $user_id ] );
			}
			return [ 'ok' => false, 'code' => $err, 'id' => null ];
		}
		$key   = 'momos_resend_rate_' . $user_id;
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_PER_HOUR ) {
			return [ 'ok' => false, 'code' => 'rate_limited', 'id' => null ];
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		$n  = NotificationRepository::resend_count( $notification_id ) + 1;
		$id = NotificationQueue::insert(
			(string) $row['template_key'],
			[ 'type' => 'customer', 'email' => (string) $row['recipient_email'], 'lang' => (string) $row['lang'], 'user_id' => null ],
			'resend:' . $notification_id . ':' . $n,
			$row['template_variant'] ? (string) $row['template_variant'] : null,
			(int) $row['reservation_id'],
			$row['branch_id'] ? (int) $row['branch_id'] : null,
			(string) ( $row['object_type'] ?? 'reservation' ),
			$row['object_id'] ? (int) $row['object_id'] : null
		);
		if ( null === $id ) {
			return [ 'ok' => false, 'code' => 'invalid_transition', 'id' => null ];
		}
		momos_audit( 'notification.resent', 'notification', $id, (int) $row['branch_id'] ?: null, [ 'original_id' => $notification_id, 'template' => $row['template_key'], 'reservation_id' => (int) $row['reservation_id'], 'n' => $n ] );
		return [ 'ok' => true, 'code' => null, 'id' => $id ];
	}
}
