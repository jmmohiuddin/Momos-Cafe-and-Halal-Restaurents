<?php
declare(strict_types=1);

namespace Momos\Access\Users;

use Momos\Access\AccountStatus;
use Momos\Access\BranchScope;
use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Db\Tables;
use Momos\Kernel\Db\Transaction;
use Momos\Kernel\Events\AfterCommit;

/**
 * Branch assignments `momo_user_branches` (ENT-19, FR-ADM-002/008, BR-ADM-03; spec §2.3/§5.6).
 * Rows exist only for ROLE-06/07; every insert/delete is audited and fires
 * `momos/user/assignment_changed (user_id, added, removed)` after commit, which destroys the user's
 * sessions (SessionPolicy). Rows are kept when a user is disabled.
 */
final class AssignmentService {
	/**
	 * Replace the user's assignments with $branch_ids.
	 *
	 * @param list<int> $branch_ids canonical momo_branch post IDs
	 * @return array{added:list<int>,removed:list<int>}
	 */
	public static function set( int $user_id, array $branch_ids, int $actor_id ): array {
		$wanted = array_values( array_unique( array_filter( array_map( 'intval', $branch_ids ), static fn( int $b ) => $b > 0 && 'momo_branch' === get_post_type( $b ) ) ) );
		sort( $wanted );
		$current = BranchScope::rows( $user_id );
		$added   = array_values( array_diff( $wanted, $current ) );
		$removed = array_values( array_diff( $current, $wanted ) );
		if ( ! $added && ! $removed ) {
			return [ 'added' => [], 'removed' => [] ];
		}
		Transaction::run(
			static function () use ( $user_id, $added, $removed, $actor_id ): void {
				global $wpdb;
				$t = Tables::user_branches();
				foreach ( $added as $b ) {
					$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$t} (user_id, branch_id, assigned_by, assigned_at) VALUES (%d, %d, %d, %s)", $user_id, $b, $actor_id, Clock::mysql_utc() ) ); // phpcs:ignore
					momos_audit( 'user.branch_assigned', 'user', $user_id, $b, [ 'branch_id' => $b ] );
				}
				foreach ( $removed as $b ) {
					$wpdb->delete( $t, [ 'user_id' => $user_id, 'branch_id' => $b ], [ '%d', '%d' ] );
					momos_audit( 'user.branch_unassigned', 'user', $user_id, $b, [ 'branch_id' => $b ] );
				}
				AfterCommit::dispatch( 'momos/user/assignment_changed', $user_id, $added, $removed );
			}
		);
		return [ 'added' => $added, 'removed' => $removed ];
	}

	/** Role changed to a non-scoped role: rows are deleted (audited `user.branch_unassigned`). */
	public static function on_role_change( $user_id, $role = '', $old_roles = [] ): void {
		$user_id = (int) $user_id;
		if ( '' === (string) $role || AccountStatus::is_scoped_role( (string) $role ) ) {
			return;
		}
		if ( BranchScope::rows( $user_id ) ) {
			self::set( $user_id, [], get_current_user_id() );
		}
	}
}
