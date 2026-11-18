<?php
declare(strict_types=1);

namespace Momos\Reservation\Service;

use Momos\Branch\Repository\BranchSettingsRepository;
use Momos\Kernel\Clock\Clock;

/**
 * allowed_actions of API-11 / SCR-A09 (spec §3.8): the subset of accept, decline, cancel,
 * mark_completed, mark_no_show, claim, release, note, modify, mark_contacted, flag_resolved whose
 * guards pass for this user now. Accept/decline are never offered at or after the decision cutoff.
 * The server re-checks every guard when the action is submitted.
 */
final class ActionPolicy {
	/**
	 * @param array<string,mixed> $row
	 * @return list<string>
	 */
	public static function allowed( array $row, int $user_id, ?\DateTimeImmutable $now = null ): array {
		if ( ! ReservationAccess::can_process( $row, $user_id ) ) {
			return [];
		}
		$now    = $now ?? Clock::utc();
		$status = (string) $row['status'];
		$start  = Clock::from_mysql_utc( (string) $row['requested_start_utc'] );
		$cutoff = (int) BranchSettingsRepository::get( (int) $row['branch_id'] )['decision_cutoff_minutes'];
		$out    = [];
		if ( 'pending' === $status && $now < $start->modify( '-' . $cutoff . ' minutes' ) ) {
			$out[] = 'accept';
			$out[] = 'decline';
		}
		if ( in_array( $status, [ 'pending', 'confirmed' ], true ) ) {
			$out[] = 'cancel';
		}
		if ( 'confirmed' === $status && TransitionService::attendance_open( $row, $user_id, $now ) ) {
			$out[] = 'mark_completed';
			$out[] = 'mark_no_show';
		}
		if ( in_array( $status, [ 'pending', 'confirmed' ], true ) ) {
			if ( (int) $row['handled_by'] !== $user_id ) {
				$out[] = 'claim';
			}
			if ( ! empty( $row['handled_by'] ) ) {
				$out[] = 'release';
			}
			if ( $now < $start ) {
				$out[] = 'modify';
			}
		}
		$out[] = 'note';
		if ( '0' === (string) $row['customer_notified_flag'] ) {
			$out[] = 'mark_contacted';
		}
		if ( '1' === (string) $row['needs_action_flag'] ) {
			$out[] = 'flag_resolved';
		}
		return $out;
	}
}
