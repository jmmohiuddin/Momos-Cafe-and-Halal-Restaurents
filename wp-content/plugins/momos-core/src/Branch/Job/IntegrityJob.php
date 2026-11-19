<?php
declare(strict_types=1);

namespace Momos\Branch\Job;

use Momos\Branch\Repository\BranchSettingsRepository;
use Momos\Branch\Repository\ClosureRepository;
use Momos\Branch\Repository\HoursRepository;
use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Db\NamedLock;
use Momos\Kernel\Db\Tables;
use Momos\Kernel\Log\Logger;

/**
 * Branch part of the daily integrity check (spec §4.13): purge closure rows older than
 * ⟦24 months⟧ (ENT-04 [Rec]); warn about rows whose branch_id is not a branch and about branches
 * accepting requests without an active recipient or with a period lacking last order.
 */
final class IntegrityJob {
	public const HOOK            = 'momos_job_branch_integrity';
	public const RETENTION_MONTHS = 24;

	public static function register(): void {
		\Momos\Kernel\Jobs\Schedule::recurring( self::HOOK, DAY_IN_SECONDS );
		add_action( self::HOOK, [ self::class, 'run' ] );
	}

	/** @return array{purged:int,orphans:int,misconfigured:list<int>} */
	public static function run(): array {
		if ( ! NamedLock::acquire( self::HOOK, 0 ) ) {
			return [ 'purged' => 0, 'orphans' => 0, 'misconfigured' => [] ];
		}
		try {
			global $wpdb;
			$cutoff = Clock::jst()->modify( '-' . self::RETENTION_MONTHS . ' months' )->format( 'Y-m-d' );
			$purged = ClosureRepository::purge_before( $cutoff );
			$orphans = 0;
			foreach ( [ Tables::hours(), Tables::closures() ] as $t ) {
				$orphans += (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} x LEFT JOIN {$wpdb->posts} p ON p.ID = x.branch_id AND p.post_type = 'momo_branch' WHERE p.ID IS NULL" ); // phpcs:ignore
			}
			$bad = [];
			$ids = get_posts( [ 'post_type' => 'momo_branch', 'post_status' => 'publish', 'posts_per_page' => 200, 'fields' => 'ids', 'no_found_rows' => true, 'meta_key' => 'momo_res_enabled', 'meta_value' => '1' ] ); // phpcs:ignore
			foreach ( array_map( 'intval', $ids ) as $id ) {
				$rows = HoursRepository::all_rows( $id );
				if ( ! BranchSettingsRepository::active_recipients( $id ) || ! $rows || array_filter( $rows, static fn( $r ) => null === $r['last_order_at'] ) ) {
					$bad[] = $id;
				}
			}
			if ( $orphans || $bad ) {
				Logger::warning( 'branch.integrity', [ 'orphan_rows' => $orphans, 'misconfigured_branches' => $bad ] );
			}
			/** Other modules' read-only nightly integrity checks (e.g. Reservation pending-past-start). */
			do_action( 'momos/branch/integrity_check' );
			return [ 'purged' => $purged, 'orphans' => $orphans, 'misconfigured' => $bad ];
		} finally {
			NamedLock::release( self::HOOK );
		}
	}
}
