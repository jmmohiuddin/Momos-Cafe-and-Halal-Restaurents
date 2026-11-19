<?php
declare(strict_types=1);

namespace Momos\Seo;

use Momos\Seo\Redirects\RedirectStore;

/**
 * `wp momos seo redirects import <csv> [--verify] | verify | export` (FR-SEO-008(a), i18n-seo-analytics.md
 * §11, §16). CSV columns `old_url,new_url,status,owner,verified` (header row optional; versioned in Git as
 * `config/seo/legacy-redirects.csv`). Every row passes the same validation as the admin screen (no
 * chains, loops, external targets or personal data); `--verify` adds HEAD checks (target must answer
 * 200). Non-zero exit on any refused row (CI).
 */
final class Cli {
	public static function register(): void {
		\WP_CLI::add_command( 'momos seo redirects', [ self::class, 'redirects' ] );
	}

	/**
	 * Manage the legacy redirect map.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : import | verify | export
	 *
	 * [<file>]
	 * : CSV file for import.
	 *
	 * [--verify]
	 * : HEAD-check targets during import.
	 *
	 * @param list<string>         $args
	 * @param array<string,string> $assoc
	 */
	public static function redirects( array $args, array $assoc ): void {
		$action = $args[0] ?? '';
		if ( 'export' === $action ) {
			$out = fopen( 'php://stdout', 'w' );
			fputcsv( $out, [ 'old_url', 'new_url', 'status', 'owner', 'verified' ], ',', '"', '\\' );
			foreach ( RedirectStore::all() as $r ) {
				fputcsv( $out, [ $r['from'], $r['to'], $r['status'], $r['owner'], $r['verified'] ? 'yes' : 'no' ], ',', '"', '\\' );
			}
			return;
		}
		if ( 'verify' === $action ) {
			$res = Admin\SeoAdmin::verify( RedirectStore::all() );
			RedirectStore::set_verified( $res );
			$bad = array_keys( array_filter( $res, static fn( $ok ) => ! $ok ) );
			foreach ( $bad as $id ) {
				$r = RedirectStore::get( (string) $id );
				\WP_CLI::warning( 'Target not 200: ' . ( $r['from'] ?? $id ) . ' → ' . ( $r['to'] ?? '' ) );
			}
			$bad ? \WP_CLI::error( count( $bad ) . ' failing target(s).' ) : \WP_CLI::success( count( $res ) . ' redirect(s) verified.' );
			return;
		}
		if ( 'import' !== $action ) {
			\WP_CLI::error( 'Usage: wp momos seo redirects import <csv> [--verify] | verify | export' );
		}
		$file = (string) ( $args[1] ?? '' );
		if ( '' === $file || ! is_readable( $file ) ) {
			\WP_CLI::error( 'CSV file not readable.' );
		}
		$res = self::import( $file, isset( $assoc['verify'] ) );
		foreach ( $res['errors'] as $line => $msg ) {
			\WP_CLI::warning( "Line {$line}: {$msg}" );
		}
		if ( $res['imported'] > 0 ) {
			momos_audit( 'settings.updated', 'redirects', null, null, [ 'action' => 'import', 'imported' => $res['imported'], 'refused' => count( $res['errors'] ) ], 'system' );
		}
		$res['errors'] ? \WP_CLI::error( "Imported {$res['imported']}, refused " . count( $res['errors'] ) . '.' ) : \WP_CLI::success( "Imported {$res['imported']} redirect(s)." );
	}

	/** @return array{imported:int,errors:array<int,string>} */
	public static function import( string $file, bool $verify = false ): array {
		$fh       = fopen( $file, 'r' );
		$line     = 0;
		$imported = 0;
		$errors   = [];
		while ( $fh && false !== ( $row = fgetcsv( $fh, 0, ',', '"', '\\' ) ) ) {
			++$line;
			if ( 1 === $line && isset( $row[0] ) && 'old_url' === strtolower( trim( (string) $row[0] ) ) ) {
				continue;
			}
			if ( [ null ] === $row || '' === trim( (string) ( $row[0] ?? '' ) ) ) {
				continue;
			}
			$in = [
				'from'     => (string) ( $row[0] ?? '' ),
				'to'       => (string) ( $row[1] ?? '' ),
				'status'   => '' !== trim( (string) ( $row[2] ?? '' ) ) ? (int) $row[2] : 301,
				'owner'    => (string) ( $row[3] ?? '' ),
				'verified' => in_array( strtolower( trim( (string) ( $row[4] ?? '' ) ) ), [ '1', 'yes', 'true' ], true ),
				'source'   => 'legacy',
			];
			$existing = RedirectStore::match( (string) wp_parse_url( $in['from'], PHP_URL_PATH ), (string) wp_parse_url( $in['from'], PHP_URL_QUERY ) );
			$res      = RedirectStore::save( $in, null !== $existing && $existing['from'] === RedirectStore::key( $in['from'] ) ? (string) $existing['id'] : '' );
			if ( $res['errors'] ) {
				$errors[ $line ] = implode( ' ', $res['errors'] );
				continue;
			}
			if ( $verify && null !== $res['rule'] ) {
				$ok = Admin\SeoAdmin::verify( [ $res['rule'] ] );
				RedirectStore::set_verified( $ok );
				if ( ! reset( $ok ) ) {
					$errors[ $line ] = 'Target does not return 200: ' . $res['rule']['to'];
				}
			}
			++$imported;
		}
		if ( $fh ) {
			fclose( $fh );
		}
		return [ 'imported' => $imported, 'errors' => $errors ];
	}
}
