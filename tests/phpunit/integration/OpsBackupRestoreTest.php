<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

use Momos\Tests\TestCase;

/**
 * DEC-033 / NFR-BCK-01 / TC-BCK-002, TC-BCK-005 (PRV-033): control flow of bin/backup.sh and bin/restore.sh with
 * a stub WP-CLI, so it runs anywhere bash, tar, gzip and sha256sum exist (no MySQL root needed). The full
 * database round trip against a real MySQL lives in tests/ops/backup-restore-roundtrip.sh
 * (docs/operations/backup-restore.md). Regression guards for the defects the restore drill found:
 * failed imports reported as success, world-readable dump left in /tmp, evidence store not backed up,
 * restore into a fresh tree, missing retention run.
 */
final class OpsBackupRestoreTest extends TestCase {
	private string $root;
	private string $tmp;
	private string $log;

	public function set_up(): void {
		parent::set_up();
		foreach ( [ 'bash', 'tar', 'gzip', 'sha256sum' ] as $tool ) {
			if ( '' === trim( (string) shell_exec( 'command -v ' . $tool . ' 2>/dev/null' ) ) ) { // phpcs:ignore
				$this->markTestSkipped( "{$tool} not available" );
			}
		}
		$this->root = dirname( __DIR__, 3 );
		$this->tmp  = rtrim( sys_get_temp_dir(), '/' ) . '/momos-ops-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->tmp . '/src/wp-content/uploads/2026', 0700, true );
		mkdir( $this->tmp . '/src-private/evidence', 0700, true );
		mkdir( $this->tmp . '/tmp', 0700, true );
		file_put_contents( $this->tmp . '/src/wp-content/uploads/2026/dish.jpg', 'jpeg' );
		file_put_contents( $this->tmp . '/src-private/evidence/cert.pdf', '%PDF evidence' );
		$this->log = $this->tmp . '/wpcli.log';
		// Stub WP-CLI: logs every call; `db export` writes a dump; `db query` fails on "NOT SQL" like the mysql client.
		file_put_contents(
			$this->tmp . '/wp',
			<<<'SH'
			#!/usr/bin/env bash
			echo "$*" >> "$STUB_LOG"
			if [ "$1" = db ] && [ "$2" = export ]; then printf 'CREATE TABLE t (id int);\n' > "$3"; exit 0; fi
			if [ "$1" = db ] && [ "$2" = query ]; then
			  data="$(cat)"; printf '%s' "$data" > "$STUB_TMP/imported.sql"
			  case "$data" in *"NOT SQL"*) echo 'ERROR 1064 (42000) at line 1' >&2; exit 1 ;; esac
			  exit 0
			fi
			if [ "$1" = eval ]; then
			  case "$2" in *MOMOS_CORE_VERSION*) echo 9.9.9 ;; *EvidenceStore*) echo "$STUB_EVIDENCE" ;; esac
			  exit 0
			fi
			exit 0
			SH
		);
		chmod( $this->tmp . '/wp', 0700 );
	}

	public function tear_down(): void {
		if ( is_dir( $this->tmp ) ) {
			exec( 'rm -rf ' . escapeshellarg( $this->tmp ) ); // phpcs:ignore
		}
		parent::tear_down();
	}

	/** @param array<string,string> $env @return array{0:int,1:string} exit code, stdout+stderr */
	private function run_script( string $script, array $args, array $env ): array {
		$env += [
			'PATH'        => (string) getenv( 'PATH' ),
			'HOME'        => $this->tmp,
			'TMPDIR'      => $this->tmp . '/tmp',
			'MOMOS_WPCLI' => $this->tmp . '/wp',
			'STUB_LOG'    => $this->log,
			'STUB_TMP'    => $this->tmp,
		];
		$cmd  = escapeshellarg( $this->root . '/bin/' . $script ) . ' ' . implode( ' ', array_map( 'escapeshellarg', $args ) ) . ' 2>&1';
		$proc = proc_open( $cmd, [ 1 => [ 'pipe', 'w' ] ], $pipes, $this->tmp, $env );
		$this->assertIsResource( $proc );
		$out = (string) stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );
		return [ proc_close( $proc ), $out ];
	}

	private function backup( array $args = [] ): array {
		return $this->run_script(
			'backup.sh',
			array_merge( [ '--dest', $this->tmp . '/backups' ], $args ),
			[ 'MOMOS_WP_PATH' => $this->tmp . '/src', 'STUB_EVIDENCE' => $this->tmp . '/src-private/evidence' ]
		);
	}

	private function restore( string $dir, bool $yes = true ): array {
		file_put_contents( $this->log, '' );
		return $this->run_script(
			'restore.sh',
			$yes ? [ $dir, '--yes' ] : [ $dir ],
			[ 'MOMOS_WP_PATH' => $this->tmp . '/dst', 'MOMOS_EVIDENCE_DIR' => $this->tmp . '/dst-private/evidence' ]
		);
	}

	public function test_full_backup_is_private_complete_and_finds_the_evidence_store_through_wordpress(): void {
		[ $code, $out ] = $this->backup();
		$this->assertSame( 0, $code, $out );
		$dir = trim( (string) strrchr( "\n" . trim( $out ), "\n" ) );
		foreach ( [ 'database.sql.gz', 'uploads.tar.gz', 'evidence.tar.gz', 'MANIFEST', 'SHA256SUMS' ] as $f ) {
			$this->assertFileExists( "{$dir}/{$f}" );
			$this->assertSame( '600', substr( sprintf( '%o', fileperms( "{$dir}/{$f}" ) ), -3 ), "{$f} must be owner-only" );
		}
		$this->assertSame( '700', substr( sprintf( '%o', fileperms( $dir ) ), -3 ) );
		$manifest = (string) file_get_contents( "{$dir}/MANIFEST" );
		$this->assertStringContainsString( 'kind=full', $manifest );
		$this->assertStringContainsString( 'momos_core_version=9.9.9', $manifest );
		$this->assertStringContainsString( 'cert.pdf', (string) shell_exec( 'tar -tzf ' . escapeshellarg( "{$dir}/evidence.tar.gz" ) ) ); // phpcs:ignore
	}

	public function test_db_only_backup_and_unknown_options(): void {
		[ $code, $out ] = $this->backup( [ '--db-only' ] );
		$this->assertSame( 0, $code, $out );
		$dir = trim( (string) strrchr( "\n" . trim( $out ), "\n" ) );
		$this->assertFileExists( "{$dir}/database.sql.gz" );
		$this->assertFileDoesNotExist( "{$dir}/uploads.tar.gz" );
		$this->assertStringContainsString( 'kind=db-only', (string) file_get_contents( "{$dir}/MANIFEST" ) );
		[ $code ] = $this->backup( [ '--bogus' ] );
		$this->assertSame( 2, $code );
	}

	public function test_restore_imports_extracts_migrates_runs_retention_and_cleans_up(): void {
		[ , $out ] = $this->backup();
		$dir       = trim( (string) strrchr( "\n" . trim( $out ), "\n" ) );
		[ $code, $out ] = $this->restore( $dir );
		$this->assertSame( 0, $code, $out );
		$this->assertStringContainsString( 'Restored from', $out );
		$this->assertStringEqualsFile( $this->tmp . '/dst/wp-content/uploads/2026/dish.jpg', 'jpeg' );
		$this->assertStringEqualsFile( $this->tmp . '/dst-private/evidence/cert.pdf', '%PDF evidence' );
		$this->assertStringContainsString( 'CREATE TABLE t', (string) file_get_contents( $this->tmp . '/imported.sql' ) );
		$calls = array_values( array_filter( explode( "\n", (string) file_get_contents( $this->log ) ) ) );
		$this->assertSame( [ 'db query', 'momos migrate', 'momos privacy retention', 'cache flush' ], $calls, 'import through `db query`, then migrate, retention (PRV-033), cache flush' );
		$this->assertSame( [], array_diff( (array) scandir( $this->tmp . '/tmp' ), [ '.', '..' ] ), 'no dump left in TMPDIR' );
	}

	public function test_restore_refuses_without_yes_and_on_checksum_mismatch(): void {
		[ , $out ] = $this->backup();
		$dir       = trim( (string) strrchr( "\n" . trim( $out ), "\n" ) );
		[ $code ] = $this->restore( $dir, false );
		$this->assertSame( 2, $code );
		file_put_contents( "{$dir}/database.sql.gz", 'x', FILE_APPEND );
		[ $code, $out ] = $this->restore( $dir );
		$this->assertNotSame( 0, $code );
		$this->assertStringNotContainsString( 'Restored from', $out );
		$this->assertStringNotContainsString( 'db query', (string) file_get_contents( $this->log ), 'nothing imported' );
	}

	public function test_failed_import_stops_the_restore_and_leaves_no_dump(): void {
		mkdir( $this->tmp . '/broken' );
		exec( 'printf "THIS IS NOT SQL;\n" | gzip -9 > ' . escapeshellarg( $this->tmp . '/broken/database.sql.gz' ) . ' && cd ' . escapeshellarg( $this->tmp . '/broken' ) . ' && sha256sum -- * > SHA256SUMS' ); // phpcs:ignore
		[ $code, $out ] = $this->restore( $this->tmp . '/broken' );
		$this->assertNotSame( 0, $code );
		$this->assertStringContainsString( 'Database import FAILED', $out );
		$this->assertStringNotContainsString( 'Restored from', $out );
		$this->assertStringNotContainsString( 'momos migrate', (string) file_get_contents( $this->log ), 'no migrate over a half-imported schema' );
		$this->assertSame( [], array_diff( (array) scandir( $this->tmp . '/tmp' ), [ '.', '..' ] ) );
	}
}
