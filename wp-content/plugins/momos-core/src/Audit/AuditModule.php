<?php
declare(strict_types=1);

namespace Momos\Audit;

use Momos\Kernel\ModuleInterface;

/**
 * Audit module (F-ADM-03 / FR-ADM-004, SEC-080…084): append-only `momo_audit_log` writer,
 * SCR-A16 viewer, retention purge and `wp momos audit verify`.
 */
final class AuditModule implements ModuleInterface {
	public function register(): void {
		AuditLogger::register();
		Admin\AuditLogScreen::register();
		Job\AuditRetentionJob::register();
		add_action( 'momos/cli', [ Cli\AuditCommand::class, 'register' ] );
	}
}
