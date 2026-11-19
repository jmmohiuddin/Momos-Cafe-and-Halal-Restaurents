<?php
declare(strict_types=1);

namespace Momos\Kernel\Cli;

use Momos\Kernel\Cache\CacheInvalidator;
use Momos\Kernel\Db\Tables;
use Momos\Kernel\Env;
use Momos\Kernel\Jobs\Schedule;
use Momos\Kernel\Migration\Runner;
use Momos\Kernel\Migration\M0002_Constraints;

/**
 * Kernel WP-CLI commands (deploy and operations, TRD §6.7, gaps.md §1–§4):
 *   wp momos migrate [--force]
 *   wp momos jobs sync|run|list
 *   wp momos smoke
 *   wp momos cache purge --all
 *   wp momos sanitize --yes          (production copy → staging; refused in production)
 * Modules add their own commands on the `momos/cli` action (e.g. `wp momos seed`).
 */
final class Commands {
	public static function register(): void {
		\WP_CLI::add_command(
			'momos migrate',
			static function ( $args, $assoc ): void {
				// Waits for a concurrent run in the same database; verifies the result instead of trusting it, so a
				// skipped or failed run can never print "up to date" over an empty schema (Wave 3 fresh-install defect).
				$ran     = Runner::run( isset( $assoc['force'] ), 120 );
				$pending = Runner::pending();
				$missing = Tables::missing();
				if ( $pending || $missing ) {
					\WP_CLI::error( 'Migrations incomplete' . ( $ran ? ' (applied: ' . implode( ', ', $ran ) . ')' : '' ) . '. Pending: ' . ( $pending ? implode( ', ', $pending ) : 'none' ) . '; missing tables: ' . ( $missing ? implode( ', ', $missing ) : 'none' ) . '. See the momos log (migration.locked / migration.failed).' );
				}
				\WP_CLI::success( $ran ? 'Applied: ' . implode( ', ', $ran ) : 'Database is up to date.' );
			}
		);

		\WP_CLI::add_command(
			'momos jobs',
			static function ( $args ): void {
				$sub = $args[0] ?? 'list';
				if ( 'sync' === $sub ) {
					Schedule::sync();
					\WP_CLI::success( 'Recurring jobs registered: ' . count( Schedule::all() ) );
				} elseif ( 'run' === $sub ) {
					Schedule::run_all_now();
					\WP_CLI::success( 'All momos jobs executed once.' );
				} else {
					$rows = [];
					foreach ( Schedule::health() as $hook => $h ) {
						$rows[] = [ 'job' => $hook, 'interval_min' => (int) round( $h['interval'] / 60 ), 'last' => $h['last'] ?? '-', 'state' => $h['state'] ];
					}
					\WP_CLI\Utils\format_items( 'table', $rows, [ 'job', 'interval_min', 'last', 'state' ] );
				}
			}
		);

		\WP_CLI::add_command(
			'momos cache',
			static function ( $args, $assoc ): void {
				CacheInvalidator::purge_all();
				\WP_CLI::success( 'Purge requested (adapter: ' . CacheInvalidator::adapter() . ').' );
			}
		);

		\WP_CLI::add_command( 'momos smoke', [ self::class, 'smoke' ] );
		\WP_CLI::add_command( 'momos sanitize', [ self::class, 'sanitize' ] );

		do_action( 'momos/cli' );
	}

	/** Post-deploy smoke checks; exits non-zero on failure. */
	public static function smoke(): void {
		$fail = self::smoke_failures();
		if ( $fail ) {
			\WP_CLI::error( "Smoke failed:\n - " . implode( "\n - ", $fail ) );
		}
		\WP_CLI::success( 'Smoke checks passed.' );
	}

	/** @return list<string> smoke check failures (empty = pass); separate from WP-CLI output for tests. */
	public static function smoke_failures(): array {
		global $wpdb;
		$fail = [];
		foreach ( Tables::missing() as $short ) {
			$fail[] = 'missing table ' . $short;
		}
		foreach ( Runner::pending() as $id ) {
			$fail[] = 'pending migration ' . $id;
		}
		$names = $wpdb->get_col( $wpdb->prepare( 'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = %s', DB_NAME ) );
		foreach ( array_keys( M0002_Constraints::constraints() ) as $c ) {
			if ( ! in_array( $c, $names, true ) ) {
				$fail[] = 'missing constraint ' . $c;
			}
		}
		foreach ( [ 'momo_head_office', 'momo_menu_manager', 'momo_content_editor', 'momo_translator', 'momo_branch_manager', 'momo_reservation_staff' ] as $role ) {
			if ( ! get_role( $role ) ) {
				$fail[] = 'missing role ' . $role;
			}
		}
		foreach ( \Momos\Kernel\DependencyCheck::problems() as $p ) {
			$fail[] = $p;
		}
		foreach ( \Momos\Kernel\Monitoring\StatusPanel::unregistered_plugins() as $p ) {
			$fail[] = 'unregistered plugin ' . $p;
		}
		if ( \Momos\Kernel\Monitoring\StatusPanel::active_count() > \Momos\Kernel\Monitoring\StatusPanel::MAX_PLUGINS ) {
			$fail[] = 'more than ' . \Momos\Kernel\Monitoring\StatusPanel::MAX_PLUGINS . ' active plugins (FR-FND-006)';
		}
		if ( Env::is_production() ) {
			$samples = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'momo_is_sample' AND m.meta_value = '1' WHERE p.post_status = 'publish'" ); // phpcs:ignore
			if ( $samples > 0 ) {
				$fail[] = "{$samples} published sample items (DEC-047)";
			}
			foreach ( Schedule::health() as $hook => $h ) {
				if ( 'late' === $h['state'] ) {
					$fail[] = 'job late: ' . $hook;
				}
			}
		}
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}actionscheduler_actions'" ) ) { // phpcs:ignore
			$failed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions a JOIN {$wpdb->prefix}actionscheduler_groups g ON g.group_id = a.group_id WHERE g.slug = 'momos' AND a.status = 'failed' AND a.last_attempt_gmt > UTC_TIMESTAMP() - INTERVAL 1 HOUR" ); // phpcs:ignore
			if ( $failed > 0 ) {
				$fail[] = "{$failed} failed momos jobs in the last hour";
			}
		}
		do_action_ref_array( 'momos/smoke', [ &$fail ] );
		return array_values( array_map( 'strval', $fail ) );
	}

	/**
	 * Remove personal data from a production copy before it is used on staging (FR-FND-002(b),
	 * AC-FND-01-08). Refused in production.
	 *
	 * ## OPTIONS
	 * [--yes]
	 * : Confirm.
	 */
	public static function sanitize( $args, $assoc ): void {
		if ( Env::is_production() || 'production' === wp_get_environment_type() ) {
			\WP_CLI::error( 'Refusing to sanitize a production environment.' );
		}
		if ( ! isset( $assoc['yes'] ) ) {
			\WP_CLI::error( 'This rewrites personal data. Re-run with --yes.' );
		}
		global $wpdb;
		$r                       = Tables::reservations();
		$counts                  = [];
		$counts['reservations'] = (int) $wpdb->query( "UPDATE {$r} SET guest_name = CONCAT('Guest ', id), email = CONCAT('res', id, '@example.com'), phone_e164 = NULL, special_request = NULL, message_to_customer = NULL, ip_hash = NULL, user_agent_hash = NULL WHERE pii_purged_at IS NULL" ); // phpcs:ignore
		$counts['events']        = (int) $wpdb->query( 'UPDATE ' . Tables::events() . ' SET note = NULL, payload = NULL, actor_display = NULL' ); // phpcs:ignore
		$counts['notifications'] = (int) $wpdb->query( 'UPDATE ' . Tables::notifications() . " SET recipient_email = 'masked@example.com', recipient_masked = 'm***@e***.com', provider_message_id = NULL, last_error = NULL" ); // phpcs:ignore
		$counts['audit']         = (int) $wpdb->query( 'UPDATE ' . Tables::audit() . ' SET ip_truncated = NULL' ); // phpcs:ignore
		$counts['users']         = 0;
		foreach ( get_users( [ 'fields' => [ 'ID' ] ] ) as $u ) {
			$id = (int) $u->ID;
			$wpdb->update( $wpdb->users, [ 'user_email' => "user{$id}@example.com", 'user_pass' => wp_hash_password( wp_generate_password( 32 ) ) ], [ 'ID' => $id ] );
			foreach ( [ 'momo_2fa_secret', 'momo_2fa_recovery', 'session_tokens', 'momo_phone' ] as $k ) {
				delete_user_meta( $id, $k );
			}
			++$counts['users'];
		}
		do_action( 'momos/sanitize' ); // Modules reset their own options (recipient lists, GTM id…).
		$left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$r} WHERE email IS NOT NULL AND email NOT LIKE '%@example.com'" ); // phpcs:ignore
		$left += (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_email NOT LIKE '%@example.com'" ); // phpcs:ignore
		momos_audit( 'environment.sanitized', 'environment', null, null, $counts, 'system' );
		\WP_CLI\Utils\format_items( 'table', array_map( static fn( $k, $v ) => [ 'table' => $k, 'rows' => $v ], array_keys( $counts ), $counts ), [ 'table', 'rows' ] );
		if ( $left > 0 ) {
			\WP_CLI::error( "{$left} real email addresses remain." );
		}
		\WP_CLI::success( 'Sanitized. All users must reset passwords and re-enrol 2FA.' );
	}
}
