<?php
declare(strict_types=1);

namespace Momos\Access;

use Momos\Kernel\Db\Tables;

/**
 * Scope resolution (Access spec §4.1, TRD §2.7.3):
 * assigned(user, branch) ≡ not disabled ∧ not expired ∧ (momo_access_all_branches ∨ row in
 * momo_user_branches). Implements the `momos/access/user_branch_ids` short-circuit so the kernel
 * helper `momos_user_branch_ids()` returns [] for disabled/expired accounts (fail closed).
 */
final class BranchScope {
	public static function register(): void {
		add_filter( 'momos/access/user_branch_ids', [ self::class, 'short_circuit' ], 10, 2 );
	}

	/**
	 * @param list<int>|null|false $pre
	 * @return list<int>|null|false
	 */
	public static function short_circuit( $pre, int $user_id ) {
		if ( false !== $pre ) {
			return $pre;
		}
		try {
			return AccountStatus::is_inactive( $user_id ) ? [] : false;
		} catch ( \Throwable $e ) {
			\Momos\Kernel\Log\Logger::error( 'access.map_failed', [ 'where' => 'user_branch_ids' ] );
			return [];
		}
	}

	public static function assigned( int $user_id, int $branch_id ): bool {
		return $user_id > 0 && $branch_id > 0 && momos_user_can_branch( $branch_id, $user_id );
	}

	/** Raw assignment rows (also for disabled users — rows are kept, SEC-006). @return list<int> */
	public static function rows( int $user_id ): array {
		global $wpdb;
		$t = Tables::user_branches();
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT branch_id FROM {$t} WHERE user_id = %d ORDER BY branch_id", $user_id ) ) ); // phpcs:ignore
	}
}
