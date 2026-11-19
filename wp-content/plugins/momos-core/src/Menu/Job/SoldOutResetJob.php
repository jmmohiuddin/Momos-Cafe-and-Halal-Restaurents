<?php
declare(strict_types=1);

namespace Momos\Menu\Job;

use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Db\NamedLock;
use Momos\Kernel\Db\Tables;
use Momos\Kernel\Events\AfterCommit;
use Momos\Kernel\Log\Logger;
use Momos\Menu\Support\CachePurge;

/**
 * Sold-out auto-reset every 15 minutes (FR-MENU-007, BR-MENU-04, spec §3.6, TC-WP-004).
 * Idempotent and safe to overlap (named lock + conditional UPDATE). On failure rows stay sold out
 * (safe) and are retried on the next run.
 */
final class SoldOutResetJob {
	public const HOOK     = 'momos_job_soldout_reset';
	public const INTERVAL = 900;

	/** @return int number of rows reset */
	public static function run(): int {
		if ( ! NamedLock::acquire( self::HOOK, 0 ) ) {
			return 0;
		}
		try {
			global $wpdb;
			$t     = Tables::menu_items();
			$now   = Clock::mysql_utc();
			$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, branch_id, dish_id, sold_out_until, availability_reason FROM %i WHERE availability = 'sold_out' AND sold_out_until <= %s", $t, $now ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$reset = 0;
			foreach ( $rows as $r ) {
				$done = (int) $wpdb->query( // phpcs:ignore
					$wpdb->prepare(
						"UPDATE %i SET availability = 'available', sold_out_until = NULL, availability_reason = NULL, version = version + 1, updated_at = %s, updated_by = NULL
						 WHERE id = %d AND availability = 'sold_out' AND sold_out_until <= %s",
						$t,
						$now,
						(int) $r['id'],
						$now
					)
				);
				if ( 1 !== $done ) {
					continue; // Changed concurrently by staff — nothing to do.
				}
				\Momos\Menu\Repository\BranchMenuItemRepository::flush_cache();
				++$reset;
				momos_audit(
					'menu_item.auto_reset',
					'branch_menu_item',
					(int) $r['id'],
					(int) $r['branch_id'],
					[ 'dish_id' => (int) $r['dish_id'], 'before' => [ 'availability' => 'sold_out', 'sold_out_until' => $r['sold_out_until'], 'reason' => $r['availability_reason'] ], 'after' => [ 'availability' => 'available' ] ],
					'system'
				);
				AfterCommit::dispatch( 'momos/branch_menu_item/updated', [ 'branch_id' => (int) $r['branch_id'], 'dish_id' => (int) $r['dish_id'], 'fields' => [ 'availability' ], 'id' => (int) $r['id'] ] );
				CachePurge::dish( (int) $r['dish_id'] );
			}
			update_option( 'momos_job_heartbeat_soldout_reset', $now, false );
			if ( $reset ) {
				Logger::info( 'menu.soldout_reset', [ 'rows' => $reset ] );
			}
			return $reset;
		} catch ( \Throwable $e ) {
			Logger::error( 'menu.soldout_reset_failed', [ 'error' => $e->getMessage() ] );
			return 0;
		} finally {
			NamedLock::release( self::HOOK );
		}
	}
}
