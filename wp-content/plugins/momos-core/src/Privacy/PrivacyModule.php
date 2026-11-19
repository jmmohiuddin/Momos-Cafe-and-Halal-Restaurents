<?php
declare(strict_types=1);

namespace Momos\Privacy;

use Momos\Kernel\ModuleInterface;

/**
 * Privacy module (F-PRV-02/03, FR-PRV-002/003/004, PRV-003/012/013/016/017, DEC-034; Access spec §10):
 * daily retention/anonymisation job, the single Anonymizer, data-subject request tool SCR-A19 with
 * WordPress core exporter/eraser integration, consent version registry and WP-CLI.
 *
 * Hooks fired: `momos/reservation/anonymized (int $id)` after commit (SDD §2.5).
 * Extension points: filter `momos/privacy/retention_steps` (extra retention steps of other modules).
 */
final class PrivacyModule implements ModuleInterface {
	public function register(): void {
		Retention\RetentionJob::register();
		Consent\ConsentRegistry::register();
		Dsr\PersonalData::register();
		Admin\DsrScreen::register();
		add_action( 'momos/cli', [ Cli\PrivacyCommand::class, 'register' ] );
	}
}
