<?php
declare(strict_types=1);

namespace Momos\Reservation\Job;

use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Db\NamedLock;
use Momos\Kernel\Db\Tables;
use Momos\Kernel\Jobs\Schedule;
use Momos\Kernel\Log\Logger;

/**
 * Reservation part of the integrity check (SDD §3.6 nightly `momos_job_integrity_check` item 4, spec §9):
 * `pending` reservations whose requested start passed more than 1 h ago mean the expiry job stalled
 * (FR-RES-016, NFR-OBS-01). Read-only: it reports (critical log → SA alert channel) and never changes
 * a row; the expiry job / lazy expiry remain the only writers. The Branch integrity job exposes no
 * extension hook, so this runs as its own hourly job (an hourly cadence also catches a stalled
 * expiry job sooner than the nightly check).
 */
final class IntegrityJob {
	public const HOOK = 'momos_job_reservation_integrity';

	/** Grace after the requested start (spec: "past start by > 1 h"). */
	public const GRACE_MINUTES = 60;

	public static function register(): void {
		Schedule::recurring( self::HOOK, HOUR_IN_SECONDS );
		add_action( self::HOOK, [ self::class, 'run' ] );
	}

	/** @return array{count:int,ids:list<int>} stale pending reservations (internal ids only, no PII) */
	public static function run(): array {
		if ( ! NamedLock::acquire( self::HOOK, 0 ) ) {
			return [ 'count' => 0, 'ids' => [] ];
		}
		try {
			$found = self::stale_pending();
			if ( $found['count'] > 0 ) {
				Logger::critical( 'reservation.integrity.pending_past_start', [ 'count' => $found['count'], 'reservation_ids' => $found['ids'] ] );
			}
			do_action( 'momos/reservation/integrity_checked', $found );
			return $found;
		} finally {
			NamedLock::release( self::HOOK );
		}
	}

	/** @return array{count:int,ids:list<int>} */
	public static function stale_pending( int $limit = 50 ): array {
		global $wpdb;
		$t     = Tables::reservations();
		$until = Clock::mysql_utc( Clock::utc()->modify( '-' . self::GRACE_MINUTES . ' minutes' ) );
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE status = 'pending' AND requested_start_utc < %s", $until ) ); // phpcs:ignore
		$ids   = $count ? array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE status = 'pending' AND requested_start_utc < %s ORDER BY requested_start_utc ASC LIMIT %d", $until, $limit ) ) ) : []; // phpcs:ignore
		return [ 'count' => $count, 'ids' => $ids ];
	}
}
