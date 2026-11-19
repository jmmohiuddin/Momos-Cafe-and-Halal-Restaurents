<?php
declare(strict_types=1);

namespace Momos\Reservation\Service;

/**
 * Reservation permissions (Doc 09 §2.7.3 rule M8, spec §4, BR-RES-02).
 *
 * momo_view_reservation(id) / momo_process_reservation(id) = the primitive capability plus
 * assigned(user, reservation.branch_id). Evaluated here directly so the module is correct whether or
 * not the Access module's MetaCapMapper is loaded; per-user denies (vendor accounts, disabled users)
 * reach us through user_has_cap and the `momos/access/user_branch_ids` filter.
 */
final class ReservationAccess {
	/** @param array<string,mixed> $row */
	public static function can_view( array $row, ?int $user_id = null ): bool {
		$user_id = $user_id ?? get_current_user_id();
		return $user_id > 0 && user_can( $user_id, 'momo_view_reservations' ) && momos_user_can_branch( (int) $row['branch_id'], $user_id );
	}

	/** @param array<string,mixed> $row */
	public static function can_process( array $row, ?int $user_id = null ): bool {
		$user_id = $user_id ?? get_current_user_id();
		return $user_id > 0 && user_can( $user_id, 'momo_process_reservations' ) && momos_user_can_branch( (int) $row['branch_id'], $user_id );
	}

	/** @param array<string,mixed> $row */
	public static function can_view_pii( array $row, ?int $user_id = null ): bool {
		$user_id = $user_id ?? get_current_user_id();
		return self::can_view( $row, $user_id ) && user_can( $user_id, 'momo_view_customer_pii' );
	}

	public static function all_branches( ?int $user_id = null ): bool {
		$user_id = $user_id ?? get_current_user_id();
		return $user_id > 0 && user_can( $user_id, 'momo_access_all_branches' ) && null === momos_user_branch_ids( $user_id );
	}

	/** Record a denied access (audit `access.denied`, no PII). */
	public static function deny( ?int $reservation_id, ?int $branch_id, string $reason, string $where ): void {
		momos_audit( 'access.denied', 'reservation', $reservation_id, $branch_id, [ 'reason' => $reason, 'route' => $where ] );
	}

	/**
	 * Map the reservation meta capabilities (rule M8) so current_user_can('momo_view_reservation', $id)
	 * works for every module; equivalent to the Access module's MetaCapMapper rule.
	 *
	 * @param list<string> $caps
	 * @param array<int,mixed> $args
	 * @return list<string>
	 */
	public static function map_meta_cap( array $caps, string $cap, int $user_id, array $args ): array {
		if ( ! in_array( $cap, [ 'momo_view_reservation', 'momo_process_reservation' ], true ) ) {
			return $caps;
		}
		$row = isset( $args[0] ) ? \Momos\Reservation\Repository\ReservationRepository::find( (int) $args[0] ) : null;
		if ( null === $row || ! momos_user_can_branch( (int) $row['branch_id'], $user_id ) ) {
			return [ 'do_not_allow' ];
		}
		return [ 'momo_view_reservation' === $cap ? 'momo_view_reservations' : 'momo_process_reservations' ];
	}
}
