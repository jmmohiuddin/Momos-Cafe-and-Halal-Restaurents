<?php
declare(strict_types=1);

namespace Momos\Access;

/**
 * Audit helper for events whose actor is not (yet) the logged-in user, e.g. login and 2FA
 * steps before a session exists. Falls back to `momos_audit()` when the Audit module is absent.
 */
final class AuditTrail {
	/** @param array<string,mixed> $changes */
	public static function for_user( ?int $actor_id, string $action, string $object_type, ?int $object_id = null, ?int $branch_id = null, array $changes = [] ): void {
		if ( class_exists( \Momos\Audit\AuditLogger::class ) ) {
			\Momos\Audit\AuditLogger::record_for( $actor_id, $action, $object_type, $object_id, $branch_id, $changes );
			return;
		}
		momos_audit( $action, $object_type, $object_id, $branch_id, $changes, $actor_id ? 'staff' : 'system' );
	}
}
