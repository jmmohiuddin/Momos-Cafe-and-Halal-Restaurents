<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

use Momos\Tests\TestCase;

/**
 * Tooling/CI contract (qa.md §3.7, FR-SEC-010, NFR-CMP-01) and E2E isolation: file-level checks that keep the
 * weekly security scans, the cross-browser projects and the fixture teardown wiring from silently disappearing.
 */
final class ToolingCiTest extends TestCase {
	private static function root(): string {
		return dirname( __DIR__, 3 );
	}

	private static function read( string $rel ): string {
		$path = self::root() . '/' . $rel;
		self::assertFileExists( $path );
		return (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	public function test_weekly_security_workflow_scans_dependencies_and_the_seeded_site(): void {
		$wf = self::read( '.github/workflows/security-weekly.yml' );
		$this->assertMatchesRegularExpression( '/schedule:\s*\n\s*- cron: \'[0-9]+ [0-9]+ \* \* [0-6]\'/', $wf, 'weekly cron' );
		$this->assertStringContainsString( 'composer audit --locked', $wf );
		$this->assertStringContainsString( 'npm audit', $wf );
		$this->assertMatchesRegularExpression( '#uses: zaproxy/action-baseline@v\d+\.\d+\.\d+#', $wf, 'pinned ZAP baseline action' );
		$this->assertStringContainsString( 'artifact_name: zap-baseline-report', $wf );
		$this->assertStringContainsString( 'uses: ./.github/actions/seeded-site', $wf, 'ZAP scans a site started in the job' );
		// WPScan only with a token; the secret is never interpolated into a job-level `if`.
		$this->assertStringContainsString( "if: needs.wpscan-token.outputs.present == 'true'", $wf );
		$this->assertDoesNotMatchRegularExpression( '/^\s*if:.*secrets\./m', $wf );
	}

	public function test_ci_runs_phpunit_on_mariadb_full_playwright_and_browser_compat(): void {
		$ci = self::read( '.github/workflows/ci.yml' );
		$this->assertStringContainsString( 'image: mariadb:10.11', $ci );
		$this->assertStringContainsString( 'npx playwright test --project=desktop', $ci, 'full suite on Chromium' );
		$this->assertStringContainsString( "MOMOS_E2E_ALL_BROWSERS: '1'", $ci );
		$this->assertStringContainsString( 'npx playwright install --with-deps', $ci );
		foreach ( [ 'firefox', 'webkit', 'mobile-android', 'mobile-ios' ] as $project ) {
			$this->assertMatchesRegularExpression( '/project: \[[^\]]*\b' . preg_quote( $project, '/' ) . '\b/', $ci, "compat matrix has {$project}" );
		}
		$this->assertStringContainsString( "COMPOSER_ALLOW_SUPERUSER: '1'", $ci );

		$config = self::read( 'tests/e2e/playwright.config.ts' );
		$this->assertStringContainsString( "process.env.MOMOS_E2E_ALL_BROWSERS === '1'", $config, 'extra browsers only behind the flag' );
		foreach ( [ "name: 'desktop'", "name: 'firefox'", "name: 'webkit'", "name: 'mobile-android'", "name: 'mobile-ios'" ] as $needle ) {
			$this->assertStringContainsString( $needle, $config );
		}
	}

	/** E2E isolation: every spec that runs the reservation fixture also runs its teardown in an afterAll. */
	public function test_every_reservation_fixture_user_tears_it_down(): void {
		$users = 0;
		foreach ( glob( self::root() . '/tests/e2e/specs/*.spec.ts' ) ?: [] as $file ) {
			$src = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( ! str_contains( $src, 'reservation-fixture.php' ) ) {
				continue;
			}
			++$users;
			$this->assertMatchesRegularExpression( "/afterAll\\([^)]*\\)\\s*=>\\s*\\{[^}]*reservation-fixture\\.php'?,\\s*'teardown'/s", $src, basename( $file ) . ' must call the fixture teardown in afterAll' );
		}
		$this->assertGreaterThanOrEqual( 2, $users );
		$fixture = self::read( 'tests/e2e/fixtures/reservation-fixture.php' );
		$this->assertStringContainsString( "'teardown' === \$mode", $fixture );
		$this->assertStringContainsString( 'momos_e2e_fixture_snapshot', $fixture, 'options are restored from a snapshot' );
	}

	public function test_composer_scripts_document_running_as_root(): void {
		$composer = json_decode( self::read( 'composer.json' ), true );
		$this->assertIsArray( $composer );
		foreach ( [ 'lint', 'lint:php', 'phpstan', 'test', 'i18n:pot', 'i18n:mo' ] as $script ) {
			$this->assertArrayHasKey( $script, $composer['scripts'] );
		}
		$this->assertStringContainsString( 'COMPOSER_ALLOW_SUPERUSER=1', (string) ( $composer['scripts-descriptions']['lint'] ?? '' ) );
	}
}
