<?php
declare(strict_types=1);

namespace Momos\Access\Job;

use Momos\Access\AccountStatus;
use Momos\Access\Users\AccountService;
use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Db\NamedLock;

/**
 * `momos_job_access_expiry` (spec §5.6, FR-ADM-007, TC-ADM-008): hourly + a single action at each
 * window end. Users past `momo_access_expires_at` and not yet disabled → disabled, sessions
 * destroyed, audited; vendors past `momo_breakglass_until` → denies re-applied, audited revoke.
 * Idempotent; named lock.
 */
final class AccessExpiryJob {
	public const HOOK = 'momos_job_access_expiry';

	public static function register(): void {
		\Momos\Kernel\Jobs\Schedule::recurring( self::HOOK, HOUR_IN_SECONDS );
		add_action( self::HOOK, [ self::class, 'run' ] );
	}

	/** @return array{disabled:list<int>,revoked:list<int>} */
	public static function run(): array {
		$out = [ 'disabled' => [], 'revoked' => [] ];
		if ( ! NamedLock::acquire( self::HOOK, 0 ) ) {
			return $out;
		}
		try {
			$now     = Clock::mysql_utc();
			$expired = get_users(
				[
					'fields'     => 'ID',
					'number'     => 500,
					'meta_query' => [ [ 'key' => AccountStatus::META_EXPIRES, 'value' => $now, 'compare' => '<=', 'type' => 'DATETIME' ] ], // phpcs:ignore
				]
			);
			foreach ( $expired as $uid ) {
				$uid = (int) $uid;
				if ( ! AccountStatus::is_disabled( $uid ) ) {
					AccountService::disable( $uid, 0, 'access_window_expired' );
					$out['disabled'][] = $uid;
				}
			}
			$glass = get_users(
				[
					'fields'     => 'ID',
					'number'     => 500,
					'meta_query' => [ [ 'key' => AccountStatus::META_BG_UNTIL, 'value' => $now, 'compare' => '<=', 'type' => 'DATETIME' ] ], // phpcs:ignore
				]
			);
			foreach ( $glass as $uid ) {
				AccountService::revoke_breakglass( (int) $uid, 'expired' );
				$out['revoked'][] = (int) $uid;
			}
		} finally {
			NamedLock::release( self::HOOK );
		}
		return $out;
	}
}
