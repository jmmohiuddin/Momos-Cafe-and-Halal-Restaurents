<?php
declare(strict_types=1);

namespace Momos\Seed;

use Momos\Kernel\ModuleInterface;

/**
 * Demo/sample content tooling (NFR-MNT-02, DEC-047): `wp momos seed --demo|--reset`, the go-live check `wp momos content sample-check` and
 * `wp momos purge-samples` (D8), plus the production publish block for sample content (SampleGuard, BR-CORP-03 / D2) and its audit actions. Registered from Kernel\Plugin::MODULES (or, until that line exists, from
 * cli-bootstrap.php through the `momos/modules` filter).
 */
final class SeedModule implements ModuleInterface {
	public function register(): void {
		// Audit actions of `wp momos purge-samples` (D8) and of the sample publish block, known to the audit viewer.
		add_filter( 'momos/audit/catalogue', [ self::class, 'audit_actions' ] );
		// BR-CORP-03 / D2: sample content is never public in production, whatever the channel.
		SampleGuard::register();
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			add_action( 'momos/cli', [ SeedCommand::class, 'register' ] );
			add_action( 'momos/cli', [ SampleCheck::class, 'register' ] );
			add_action( 'momos/cli', [ PurgeSamples::class, 'register' ] );
		}
	}

	/** @param list<string> $actions @return list<string> */
	public static function audit_actions( $actions ): array {
		$actions   = is_array( $actions ) ? $actions : [];
		$actions[] = PurgeSamples::AUDIT_ACTION;
		$actions[] = SampleGuard::AUDIT_ACTION;
		return $actions;
	}
}
