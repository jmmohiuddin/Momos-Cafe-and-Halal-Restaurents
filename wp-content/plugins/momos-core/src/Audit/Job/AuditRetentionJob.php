<?php
declare(strict_types=1);

namespace Momos\Audit\Job;

use Momos\Audit\AuditLogger;
use Momos\Audit\AuditRepository;
use Momos\Kernel\Db\NamedLock;
use Momos\Kernel\Log\Logger;
use Momos\Kernel\Settings;

/**
 * Audit retention purge (DEC-034, BR-PRV-02, SEC-084, Access spec §6.5/§10.4 steps 5–6): domain rows
 * older than `momo_retention_audit_months` (24, bounds 12–84) and activity-class rows older than
 * `momo_retention_activity_months` (12, bounds 3–24) are deleted in batches. Daily; idempotent;
 * named lock. The run itself is audited (`retention.run`, counts only).
 */
final class AuditRetentionJob {
	public const HOOK     = 'momos_job_audit_retention';
	public const INTERVAL = DAY_IN_SECONDS;

	public static function register(): void {
		\Momos\Kernel\Jobs\Schedule::recurring( self::HOOK, self::INTERVAL );
		add_action( self::HOOK, [ self::class, 'run' ] );
	}

	public static function domain_months(): int {
		return max( 12, min( 84, (int) Settings::get( 'momo_retention_audit_months', 24 ) ?: 24 ) );
	}

	public static function activity_months(): int {
		return max( 3, min( 24, (int) Settings::get( 'momo_retention_activity_months', 12 ) ?: 12 ) );
	}

	/** @return array{domain:int,activity:int}|null null when another run holds the lock */
	public static function run(): ?array {
		if ( ! NamedLock::acquire( self::HOOK, 0 ) ) {
			return null;
		}
		try {
			$counts = AuditRepository::purge( self::domain_months(), self::activity_months() );
			AuditLogger::record(
				'retention.run',
				'audit',
				null,
				null,
				[ 'scope' => 'audit', 'deleted' => $counts, 'settings' => [ 'audit_months' => self::domain_months(), 'activity_months' => self::activity_months() ] ],
				'system'
			);
			return $counts;
		} catch ( \Throwable $e ) {
			Logger::error( 'audit.retention_failed', [ 'error' => $e->getMessage() ] );
			AuditLogger::record( 'privacy.retention_failed', 'audit', null, null, [ 'scope' => 'audit', 'error' => 'exception' ], 'system' );
			throw $e;
		} finally {
			NamedLock::release( self::HOOK );
		}
	}
}
