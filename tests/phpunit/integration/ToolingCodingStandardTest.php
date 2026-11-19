<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

use Momos\Tests\TestCase;

/**
 * Static-analysis gates (NFR-MNT-01, docs/specs/gaps.md §5; DEF-015, DEF-017).
 *
 * phpcs.xml.dist excludes a few reviewed security-sniff false positives file by file. Each exclusion is only
 * safe while a code invariant holds; these tests enforce that invariant so the exclusion cannot hide a new
 * unsafe line. They also pin the PHPStan rule that a fixed finding must leave the baseline.
 */
final class ToolingCodingStandardTest extends TestCase {
	private static function root(): string {
		return dirname( __DIR__, 3 );
	}

	/** SeoAdmin is excluded from NonceVerification.Missing: every method that reads $_POST must be an admin-post handler that calls self::guard() first. */
	public function test_seo_admin_reads_post_data_only_after_guard(): void {
		$class = new \ReflectionClass( \Momos\Seo\Admin\SeoAdmin::class );
		$lines = file( (string) $class->getFileName() );
		$this->assertIsArray( $lines );
		$checked = 0;
		foreach ( $class->getMethods() as $m ) {
			if ( $m->getDeclaringClass()->getName() !== $class->getName() ) {
				continue;
			}
			$body = implode( '', array_slice( $lines, $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1 ) );
			if ( ! str_contains( $body, '$_POST' ) && ! str_contains( $body, '$_REQUEST' ) ) {
				continue;
			}
			++$checked;
			$this->assertStringStartsWith( 'handle_', $m->getName(), "{$m->getName()} reads form data but is not an admin-post handler." );
			$this->assertMatchesRegularExpression( '/\)\s*:\s*void\s*\{\s*self::guard\(/', $body, "{$m->getName()} must call self::guard() before anything else." );
		}
		$this->assertGreaterThan( 0, $checked, 'No handler found: the test no longer matches SeoAdmin.' );

		$guard = $class->getMethod( 'guard' );
		$body  = implode( '', array_slice( $lines, $guard->getStartLine() - 1, $guard->getEndLine() - $guard->getStartLine() + 1 ) );
		$this->assertStringContainsString( 'current_user_can(', $body );
		$this->assertStringContainsString( 'check_admin_referer(', $body );
	}

	/** Kernel\Db\Transaction is excluded from PreparedSQL.NotPrepared: every statement it runs must be a string literal. */
	public function test_transaction_runs_only_literal_statements(): void {
		$src = (string) file_get_contents( self::root() . '/wp-content/plugins/momos-core/src/Kernel/Db/Transaction.php' );
		$sql_calls = preg_match_all( '/\$wpdb->(query|get_var|get_row|get_col|get_results|prepare)\(/', $src );
		preg_match_all( '/\$wpdb->(query|get_var|get_row|get_col|get_results)\(\s*([^()]*?)\s*\)/', $src, $calls, PREG_SET_ORDER );
		$this->assertNotEmpty( $calls );
		$this->assertCount( $sql_calls, $calls, 'A query in Transaction takes a non-literal argument.' );
		$literal = "'[A-Z_@ a-z]+'";
		foreach ( $calls as [ $all, , $arg ] ) {
			$this->assertMatchesRegularExpression( "/^(self::\\\$savepoint\\s*\\?\\s*{$literal}\\s*:\\s*)?{$literal}$/", $arg, "Non-literal SQL in Transaction: {$all}" );
		}
	}

	/** Email text parts are excluded from EscapeOutput: they must stay text/plain (no markup is emitted). */
	public function test_email_text_parts_emit_no_markup(): void {
		$files = glob( self::root() . '/wp-content/plugins/momos-core/templates/email/*.txt.php' );
		$this->assertNotEmpty( $files );
		foreach ( (array) $files as $file ) {
			$src = (string) file_get_contents( $file );
			$this->assertDoesNotMatchRegularExpression( '/echo[^;]*[\'"]<[a-z\/!]/i', $src, basename( $file ) . ' echoes HTML markup; it is excluded from EscapeOutput as plain text.' );
			$this->assertStringNotContainsString( '?>' . "\n<", $src, basename( $file ) . ' contains inline HTML.' );
		}
	}

	/** The PHPMailer allowlist in phpcs.xml.dist covers only PHPMailer's names, not arbitrary PascalCase properties. */
	public function test_phpcs_allows_phpmailer_properties_only(): void {
		$phpcs = self::root() . '/vendor/bin/phpcs';
		if ( ! is_executable( $phpcs ) ) {
			$this->markTestSkipped( 'vendor/bin/phpcs is not installed.' );
		}
		$code = "<?php\nnamespace Momos\\Notification\\Transport;\nfinal class X {\n\tpublic static function f( \\PHPMailer\\PHPMailer\\PHPMailer \$m ): void {\n\t\t\$m->SMTPSecure = 'tls';\n\t\t\$m->AltBody    = 'x';\n\t\t\$m->FooBar     = 'y';\n\t}\n}\n";
		$cmd  = sprintf(
			'cd %s && printf %%s %s | %s -q -n --report=json --sniffs=WordPress.NamingConventions.ValidVariableName --stdin-path=wp-content/plugins/momos-core/src/Notification/Transport/X.php - 2>&1',
			escapeshellarg( self::root() ),
			escapeshellarg( $code ),
			escapeshellarg( $phpcs )
		);
		$out  = (string) shell_exec( $cmd );
		$json = json_decode( $out, true );
		$this->assertIsArray( $json, "phpcs output: {$out}" );
		$messages = [];
		foreach ( $json['files'] as $file ) {
			foreach ( $file['messages'] as $msg ) {
				$messages[] = $msg['line'] . ':' . $msg['source'];
			}
		}
		$this->assertSame( [ '7:WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase' ], $messages );
	}

	/** DEF-015: a baseline entry whose finding was fixed fails PHPStan, and every entry points at an existing file. */
	public function test_phpstan_baseline_can_only_shrink(): void {
		$config = (string) file_get_contents( self::root() . '/phpstan.neon.dist' );
		$this->assertMatchesRegularExpression( '/^\treportUnmatchedIgnoredErrors:\s*true\s*$/m', $config );
		$baseline = (string) file_get_contents( self::root() . '/phpstan-baseline.neon' );
		preg_match_all( '/^\t\t\tpath: (.+)$/m', $baseline, $paths );
		$this->assertNotEmpty( $paths[1] );
		foreach ( $paths[1] as $path ) {
			$this->assertFileExists( self::root() . '/' . trim( $path ), 'Stale phpstan-baseline.neon entry.' );
		}
	}
}
