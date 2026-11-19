<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

use Momos\Tests\TestCase;

/**
 * AC-SEC-01-06 / FR-SEC-008 (Doc 12 TC-SEC-011): the weekly workflow runs gitleaks over the full git history,
 * blocking on findings, with an allowlist of documented dev/test fixtures only; and the working tree holds no
 * credential in a well-known provider format (the same patterns as the local pre-release grep).
 */
final class ToolingSecretScanTest extends TestCase {
	private const PROVIDER_PATTERNS = [
		'aws_access_key'  => '/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/',
		'stripe_live'     => '/\b[rs]k_live_[0-9a-zA-Z]{20,}/',
		'github_token'    => '/\b(?:gh[pousr]_[A-Za-z0-9]{36}|github_pat_[A-Za-z0-9_]{50,})/',
		'slack_token'     => '/\bxox[baprs]-[A-Za-z0-9-]{10,}/',
		'slack_webhook'   => '#hooks\.slack\.com/services/[A-Za-z0-9]+/[A-Za-z0-9]+/[A-Za-z0-9]+#',
		'private_key'     => '/-----BEGIN (?:RSA |EC |DSA |OPENSSH |PGP |ENCRYPTED )?PRIVATE KEY(?: BLOCK)?-----/',
		'google_api_key'  => '/\bAIza[0-9A-Za-z_-]{35}\b/',
		'sendgrid_key'    => '/\bSG\.[A-Za-z0-9_-]{22}\.[A-Za-z0-9_-]{43}\b/',
		'anthropic_key'   => '/\bsk-ant-[A-Za-z0-9_-]{20,}/',
		'openai_key'      => '/\bsk-(?:proj-)?[A-Za-z0-9]{32,}\b/',
		'turnstile_secret' => '/\b0x4AAAAAAA[A-Za-z0-9_-]{20,}\b/',
		'url_credentials' => '#https?://[^/\s:@\'"]+:[^/\s:@\'"]{6,}@[a-z0-9.-]+#i',
	];

	private const SKIP_DIRS = [ '.git', '.wp', 'vendor', 'node_modules', 'uploads', 'test-results', 'playwright-report', '.phpstan-cache', 'backups', 'build' ];

	private static function root(): string {
		return dirname( __DIR__, 3 );
	}

	private static function read( string $rel ): string {
		$path = self::root() . '/' . $rel;
		self::assertFileExists( $path );
		return (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	public function test_weekly_workflow_runs_gitleaks_over_the_full_history_and_blocks_on_findings(): void {
		$wf = self::read( '.github/workflows/security-weekly.yml' );
		$this->assertMatchesRegularExpression( '/^  gitleaks:\n/m', $wf, 'gitleaks job' );
		$job = (string) preg_replace( '/^.*?^  gitleaks:\n(.*?)(?=^  [a-z][\w-]*:\n).*$/ms', '$1', $wf );
		$this->assertStringContainsString( 'fetch-depth: 0', $job, 'full history checked out' );
		$this->assertStringContainsString( '--log-opts="--all"', $job, 'every ref scanned' );
		$this->assertStringContainsString( '--exit-code 1', $job, 'findings fail the job' );
		$this->assertStringContainsString( '--config .github/gitleaks.toml', $job );
		$this->assertStringContainsString( '--redact', $job, 'secrets never printed in the log' );
		$this->assertStringNotContainsString( 'continue-on-error', $job, 'blocking' );
		$this->assertMatchesRegularExpression( "/GITLEAKS_VERSION: '\\d+\\.\\d+\\.\\d+'/", $job, 'pinned version' );
		$this->assertMatchesRegularExpression( '/GITLEAKS_SHA256: [0-9a-f]{64}\b/', $job );
		$this->assertStringContainsString( 'sha256sum -c -', $job, 'download verified' );
	}

	public function test_allowlist_holds_only_documented_anchored_fixture_values(): void {
		$toml = self::read( '.github/gitleaks.toml' );
		$this->assertMatchesRegularExpression( '/^\[extend\]\nuseDefault = true$/m', $toml, 'default rules stay on' );
		$this->assertStringNotContainsString( '[[rules]]', $toml, 'no rule is redefined or weakened' );
		$this->assertDoesNotMatchRegularExpression( '/^\s*(stopwords|commits)\s*=/m', $toml, 'no blanket stopword/commit allowlists' );
		preg_match_all( "/^(\\s*#[^\\n]*\\n)?\\s*'''([^']+)''',?$/m", $toml, $m, PREG_SET_ORDER );
		$this->assertNotEmpty( $m );
		foreach ( $m as $entry ) {
			$this->assertNotSame( '', trim( $entry[1] ), "allowlist entry {$entry[2]} is documented by a comment" );
			$this->assertStringStartsWith( '^', $entry[2], "allowlist entry {$entry[2]} is anchored" );
			$this->assertDoesNotMatchRegularExpression( '/^\^?\.\*|^\^?\(\?i\)\.\*/', $entry[2], 'no catch-all pattern' );
		}
		// Each path entry is one exact file, never a directory.
		preg_match( '/^paths = \[(.*?)^\]/ms', $toml, $paths );
		preg_match_all( "/'''([^']+)'''/", $paths[1] ?? '', $p );
		foreach ( $p[1] as $path ) {
			$this->assertStringEndsWith( '$', $path, "path allowlist {$path} names one file" );
		}
	}

	public function test_working_tree_has_no_provider_format_credentials(): void {
		$it    = new \RecursiveIteratorIterator(
			new \RecursiveCallbackFilterIterator(
				new \RecursiveDirectoryIterator( self::root(), \FilesystemIterator::SKIP_DOTS ),
				static fn( \SplFileInfo $f ) => ! ( $f->isDir() && in_array( $f->getFilename(), self::SKIP_DIRS, true ) )
			)
		);
		$hits  = [];
		$files = 0;
		foreach ( $it as $f ) {
			/** @var \SplFileInfo $f */
			if ( ! $f->isFile() || $f->getSize() > 2 * MB_IN_BYTES || ! preg_match( '/\.(php|js|ts|json|ya?ml|toml|md|sh|conf|txt|neon|xml|dist|css|html|env|ini|sql)$|^\.[a-z]+$/i', $f->getFilename() ) ) {
				continue;
			}
			if ( __FILE__ === $f->getPathname() ) {
				continue; // The patterns themselves.
			}
			++$files;
			$src = (string) file_get_contents( $f->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			foreach ( self::PROVIDER_PATTERNS as $name => $re ) {
				if ( preg_match( $re, $src ) ) {
					$hits[] = $name . ' in ' . substr( $f->getPathname(), strlen( self::root() ) + 1 );
				}
			}
		}
		$this->assertGreaterThan( 500, $files, 'the scan covered the repository' );
		$this->assertSame( [], $hits );
	}
}
