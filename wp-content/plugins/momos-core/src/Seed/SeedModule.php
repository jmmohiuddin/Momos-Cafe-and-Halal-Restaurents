<?php
declare(strict_types=1);

namespace Momos\Seed;

use Momos\Kernel\ModuleInterface;

/**
 * Demo/sample content tooling (NFR-MNT-02, DEC-047): `wp momos seed --demo|--reset` and the go-live check `wp momos content sample-check`. CLI only — no
 * hooks on web requests. Registered from Kernel\Plugin::MODULES (or, until that line exists, from
 * cli-bootstrap.php through the `momos/modules` filter).
 */
final class SeedModule implements ModuleInterface {
	public function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			add_action( 'momos/cli', [ SeedCommand::class, 'register' ] );
			add_action( 'momos/cli', [ SampleCheck::class, 'register' ] );
		}
	}
}
