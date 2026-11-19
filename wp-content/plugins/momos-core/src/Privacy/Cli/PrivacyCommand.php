<?php
declare(strict_types=1);

namespace Momos\Privacy\Cli;

use Momos\Kernel\Env;
use Momos\Kernel\Jobs\Schedule;
use Momos\Kernel\Support\Crockford;
use Momos\Privacy\Consent\ConsentRegistry;
use Momos\Privacy\Dsr\DsrService;
use Momos\Privacy\Retention\Anonymizer;
use Momos\Privacy\Retention\RetentionJob;

/**
 * WP-CLI (operations; Access spec §10.4/§10.5/§11 restore runbook):
 *
 *   wp momos privacy retention [--dry-run]
 *   wp momos privacy export --email=<email> [--file=<path>]
 *   wp momos privacy erase  --email=<email> [--yes]
 *   wp momos privacy consent list|current
 *   wp momos privacy consent activate <key>
 *   wp momos privacy consent add --privacy=<x.y> --policy=<x.y> --effective=<YYYY-MM-DD[ HH:MM]>
 *
 * Erase always asks for confirmation (`--yes` skips it for scripted runs on staging/dev). Output and
 * audit rows never contain the email address; the export writes personal data to stdout or the given
 * file only.
 */
final class PrivacyCommand {
	public static function register(): void {
		\WP_CLI::add_command( 'momos privacy', self::class );
	}

	/**
	 * Run the retention job now (or count what it would do).
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Count only; change nothing.
	 *
	 * @param list<string>          $args
	 * @param array<string,string>  $assoc
	 */
	public function retention( array $args, array $assoc ): void {
		$dry = isset( $assoc['dry-run'] );
		$out = RetentionJob::run( $dry );
		if ( null === $out ) {
			\WP_CLI::warning( 'Another retention run holds the lock; nothing done.' );
			return;
		}
		$rows = [];
		foreach ( $out['counts'] as $k => $v ) {
			$rows[] = [ 'step' => $k, 'count' => is_scalar( $v ) ? (string) $v : wp_json_encode( $v ) ];
		}
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'step', 'count' ] );
		\WP_CLI::log( sprintf( 'PII cutoff: requested_date < %s (%d months); duration %d ms.', $out['settings']['pii_cutoff_date'], $out['settings']['pii_months'], $out['duration_ms'] ) );
		if ( $dry ) {
			\WP_CLI::success( 'Dry run: nothing was changed.' );
			return;
		}
		if ( $out['failed'] > 0 ) {
			\WP_CLI::error( sprintf( 'Retention finished with %d failure(s): %s. The next run resumes.', $out['failed'], implode( ',', $out['errors'] ) ) );
		}
		Schedule::beat( RetentionJob::HOOK );
		\WP_CLI::success( 'Retention run completed.' );
	}

	/**
	 * Export all reservation data of one email address as JSON.
	 *
	 * ## OPTIONS
	 *
	 * --email=<email>
	 * : Exact email address.
	 *
	 * [--file=<path>]
	 * : Write to this file instead of stdout.
	 *
	 * @param list<string>          $args
	 * @param array<string,string>  $assoc
	 */
	public function export( array $args, array $assoc ): void {
		$found = DsrService::search( (string) ( $assoc['email'] ?? '' ) );
		if ( null !== $found['error'] ) {
			\WP_CLI::error( 'Give a complete, valid --email.' );
		}
		$json = (string) wp_json_encode( DsrService::bundle( $found['ids'], 'email' ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! empty( $assoc['file'] ) ) {
			if ( false === file_put_contents( (string) $assoc['file'], $json ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- operator-chosen path.
				\WP_CLI::error( 'Could not write the file.' );
			}
			\WP_CLI::success( sprintf( 'Exported %d reservation(s) to the file. Delete it after hand-over.', count( $found['ids'] ) ) );
			return;
		}
		\WP_CLI::line( $json );
	}

	/**
	 * Anonymise all past/terminal reservations of one email address (active future ones are kept).
	 *
	 * ## OPTIONS
	 *
	 * --email=<email>
	 * : Exact email address.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * @param list<string>          $args
	 * @param array<string,string>  $assoc
	 */
	public function erase( array $args, array $assoc ): void {
		$found = DsrService::search( (string) ( $assoc['email'] ?? '' ) );
		if ( null !== $found['error'] ) {
			\WP_CLI::error( 'Give a complete, valid --email.' );
		}
		$rows = DsrService::rows( $found['ids'] );
		if ( ! $rows ) {
			\WP_CLI::success( 'No reservations found.' );
			return;
		}
		\WP_CLI::confirm( sprintf( 'Anonymise up to %d reservation(s) on %s? This cannot be undone.', count( $rows ), Env::is_production() ? 'PRODUCTION' : Env::name() ), $assoc );
		$done = 0;
		foreach ( $rows as $row ) {
			if ( DsrService::is_active_future( $row ) ) {
				\WP_CLI::warning( sprintf( 'Kept %s: active future request — cancel it first.', Crockford::display( (string) $row['public_ref'] ) ) );
				continue;
			}
			if ( Anonymizer::anonymize_reservation( (int) $row['id'], 'dsr' ) ) {
				momos_audit( 'dsr.anonymized', 'reservation', (int) $row['id'], (int) $row['branch_id'], [ 'reason' => 'erasure_request', 'channel' => 'cli' ], 'system' );
				++$done;
			}
		}
		\WP_CLI::success( sprintf( 'Anonymised %d reservation(s).', $done ) );
	}

	/**
	 * Consent registry: list, current, add.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : list | current | add | activate
	 *
	 * [<key>]
	 * : Consent key to activate, e.g. PP1.1-RP1.0
	 *
	 * [--privacy=<version>]
	 * : Privacy Policy version (add), e.g. 1.1
	 *
	 * [--policy=<version>]
	 * : Reservation Policy version (add), e.g. 1.0
	 *
	 * [--effective=<jst>]
	 * : Effective from, Japan time (add), e.g. 2026-12-01 or "2026-12-01 09:00"
	 *
	 * @param list<string>          $args
	 * @param array<string,string>  $assoc
	 */
	public function consent( array $args, array $assoc ): void {
		$sub = $args[0] ?? 'list';
		if ( 'current' === $sub ) {
			\WP_CLI::line( 'stored on new reservations: ' . ConsentRegistry::stored_key() );
			\WP_CLI::line( 'registry version in force: ' . ConsentRegistry::current() );
			return;
		}
		if ( 'activate' === $sub ) {
			try {
				ConsentRegistry::activate( (string) ( $args[1] ?? '' ) );
			} catch ( \InvalidArgumentException $e ) {
				\WP_CLI::error( $e->getMessage() );
			}
			\WP_CLI::success( 'New reservations now store ' . ConsentRegistry::stored_key() . '.' );
			return;
		}
		if ( 'add' === $sub ) {
			try {
				$row = ConsentRegistry::add_version( (string) ( $assoc['privacy'] ?? '' ), (string) ( $assoc['policy'] ?? '' ), (string) ( $assoc['effective'] ?? '' ) );
			} catch ( \InvalidArgumentException $e ) {
				\WP_CLI::error( $e->getMessage() );
			}
			\WP_CLI::success( 'Added ' . $row['consent_key'] . '.' );
			return;
		}
		$rows = array_map(
			static fn( array $c ): array => [ 'key' => $c['consent_key'], 'effective_from' => $c['effective_from'], 'reservations' => $c['reservations'], 'state' => $c['active'] ? 'in force' : ( $c['in_registry'] ? 'registered' : 'not in registry' ) ],
			ConsentRegistry::history()
		);
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'key', 'effective_from', 'reservations', 'state' ] );
	}
}
