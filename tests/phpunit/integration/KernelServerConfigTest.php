<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

use Momos\Access\Http\SecurityHeaders;
use Momos\Tests\TestCase;

/**
 * FR-FND-004 / Doc 11 SEC-050 / TC-SEC-005: the shipped server rules redirect http:// and non-canonical hosts
 * with one 301 to https://<canonical host>, and HSTS has a single source (Access SecurityHeaders, G3 ramp) that
 * the server files do not contradict (config/server/README.md).
 */
final class KernelServerConfigTest extends TestCase {
	private string $dir;

	public function set_up(): void {
		parent::set_up();
		$this->dir = dirname( __DIR__, 3 ) . '/config/server';
	}

	public function tear_down(): void {
		unset( $_SERVER['HTTPS'] );
		parent::tear_down();
	}

	private function file( string $name ): string {
		$this->assertFileExists( $this->dir . '/' . $name );
		return (string) file_get_contents( $this->dir . '/' . $name );
	}

	/** Configuration lines only (comments dropped). */
	private function directives( string $name ): string {
		return implode( "\n", array_filter( explode( "\n", $this->file( $name ) ), static fn( string $l ): bool => ! str_starts_with( ltrim( $l ), '#' ) ) );
	}

	public function test_apache_redirects_http_and_other_hosts_once_to_https_canonical(): void {
		$conf = $this->directives( 'htaccess-root.conf' );
		$this->assertMatchesRegularExpression( '/RewriteCond %\{HTTPS\} !=on \[OR\]\s*\n\s*RewriteCond %\{HTTP_HOST\} !=__MOMOS_CANONICAL_HOST__ \[NC\]/', $conf );
		$this->assertStringContainsString( 'RewriteRule ^ https://__MOMOS_CANONICAL_HOST__%{REQUEST_URI} [R=301,L]', $conf );
		// The redirect is the first rewrite rule, so no deny/WordPress rule answers an http:// request first.
		preg_match_all( '/^\s*RewriteRule\b.*$/m', $conf, $m );
		$this->assertStringContainsString( 'https://__MOMOS_CANONICAL_HOST__', $m[0][0] );
	}

	public function test_nginx_redirects_http_and_aliases_and_checks_the_host(): void {
		$redirects = $this->directives( 'nginx-redirects.conf' );
		$this->assertMatchesRegularExpression( '/listen 80;.*server_name __MOMOS_CANONICAL_HOST__ __MOMOS_ALIAS_HOSTS__;.*return 301 https:\/\/__MOMOS_CANONICAL_HOST__\$request_uri;/s', $redirects );
		$this->assertMatchesRegularExpression( '/listen 443 ssl;.*server_name __MOMOS_ALIAS_HOSTS__;.*ssl_protocols TLSv1\.2 TLSv1\.3;.*return 301 https:\/\/__MOMOS_CANONICAL_HOST__\$request_uri;/s', $redirects );
		$this->assertStringContainsString( 'if ($host != "__MOMOS_CANONICAL_HOST__") { return 301 https://__MOMOS_CANONICAL_HOST__$request_uri; }', $this->directives( 'nginx-momos.conf' ) );
	}

	public function test_release_build_fills_the_canonical_host(): void {
		$build = (string) file_get_contents( dirname( __DIR__, 3 ) . '/bin/build-release.sh' );
		$this->assertStringContainsString( 's/__MOMOS_CANONICAL_HOST__/$MOMOS_CANONICAL_HOST/g', $build );
		$this->assertStringContainsString( 's/__MOMOS_ALIAS_HOSTS__/$ALIASES/g', $build );
	}

	public function test_hsts_has_one_source(): void {
		foreach ( glob( $this->dir . '/*.conf' ) ?: [] as $f ) {
			$this->assertStringNotContainsStringIgnoringCase( 'Strict-Transport-Security', $this->directives( basename( $f ) ), basename( $f ) . ' must not set HSTS (SecurityHeaders owns the ramp)' );
		}
		unset( $_SERVER['HTTPS'] );
		$this->assertArrayNotHasKey( 'Strict-Transport-Security', SecurityHeaders::global_headers(), 'never on plain HTTP' );
		$_SERVER['HTTPS'] = 'on';
		$this->assertSame( 'max-age=' . SecurityHeaders::hsts_max_age(), SecurityHeaders::global_headers()['Strict-Transport-Security'] );
		$this->assertContains( SecurityHeaders::hsts_max_age(), SecurityHeaders::HSTS_STEPS );
		$this->assertSame( 'max-age=' . SecurityHeaders::hsts_max_age(), SecurityHeaders::public_headers( [] )['Strict-Transport-Security'] ?? null, 'front-end responses carry it' );
	}
}
