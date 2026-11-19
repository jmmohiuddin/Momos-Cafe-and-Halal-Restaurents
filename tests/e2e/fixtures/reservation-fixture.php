<?php
/**
 * E2E fixture for the reservation specs (development/CI installs only).
 *
 * Setup:    bin/wp eval-file tests/e2e/fixtures/reservation-fixture.php [setup]
 * Teardown: bin/wp eval-file tests/e2e/fixtures/reservation-fixture.php teardown
 *
 * Setup is idempotent: two fictional branches XEA/XEB (published, open, accepting, daily 11:00–22:00 L.O. 21:30,
 * en + ar translations approved, a test recipient each) and two Reservation Staff accounts, one per
 * branch. Relaxes the public rate limits of this install so repeated runs from 127.0.0.1 are possible.
 * Prints JSON {branch_a, branch_b, staff_a, staff_b, password}. Synthetic data only (example.test).
 *
 * Teardown reverses everything setup changed, so later specs see the plain demo seed again (E2E isolation):
 * reservations (+ events, notifications), hours, closures, branch-menu rows, assignments, scope terms and the
 * posts of XEA/XEB; the accounts e2e_rs_a/e2e_rs_b; the rate-limit and language options are restored to the
 * values they had before the first setup (snapshot option `momos_e2e_fixture_snapshot`) and the public
 * rate-limit counters are cleared. Every spec that runs the setup must run the teardown in its afterAll.
 * Prints JSON {teardown: counts}.
 *
 * @package momos-tests
 */

defined( 'ABSPATH' ) || exit;

if ( \Momos\Kernel\Env::is_production() ) {
	fwrite( STDERR, "Refusing to seed E2E fixtures in production.\n" ); // phpcs:ignore
	exit( 1 );
}

use Momos\Branch\Domain\AccessContext;
use Momos\Branch\Repository\HoursRepository;
use Momos\Kernel\Db\Tables;
use Momos\Kernel\I18n\Translations;

// Wrapped in a closure: eval-file runs in the global scope; WP-CLI passes the positional arguments as $args.
( static function ( array $cli_args ): void {
	$password      = 'E2E-Staff-2026!';
	$codes         = [ 'XEA', 'XEB' ];
	$logins        = [ 'e2e_rs_a', 'e2e_rs_b' ];
	$snapshot_key  = 'momos_e2e_fixture_snapshot';
	$absent        = '__momos_e2e_absent__';
	$overrides     = [
		'momo_rate_ip_per_hour'          => 1000,
		'momo_rate_email_per_hour'       => 1000,
		'momo_rate_status_ip_per_hour'   => 1000,
		'momo_rate_cancel_ip_per_hour'   => 1000,
		'momo_rate_ip_degraded_per_hour' => 1000,
		'momos_languages_enabled'        => [ 'ja', 'en', 'ar', 'ur', 'hi', 'bn' ],
	];
	$clear_counters = static function (): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_momos\\_rl\\_%' OR option_name LIKE '\\_transient\\_timeout\\_momos\\_rl\\_%'" ); // phpcs:ignore
	};
	$mode = isset( $cli_args[0] ) ? (string) $cli_args[0] : 'setup';

	if ( 'teardown' === $mode ) {
		global $wpdb;
		$counts = [ 'reservations' => 0, 'branches' => 0, 'users' => 0, 'options_restored' => 0 ];
		foreach ( $codes as $code ) {
			$ids = get_posts( [ 'post_type' => 'momo_branch', 'post_status' => 'any', 'meta_key' => 'momo_branch_code', 'meta_value' => $code, 'fields' => 'ids', 'posts_per_page' => -1 ] ); // phpcs:ignore
			foreach ( $ids as $id ) {
				$id      = (int) $id;
				$res_ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . Tables::reservations() . ' WHERE branch_id = %d', $id ) ) ); // phpcs:ignore
				if ( $res_ids ) {
					$in = implode( ',', $res_ids );
					$wpdb->query( 'DELETE FROM ' . Tables::notifications() . " WHERE reservation_id IN ({$in})" ); // phpcs:ignore -- integer list.
					$wpdb->query( 'DELETE FROM ' . Tables::events() . " WHERE reservation_id IN ({$in})" ); // phpcs:ignore
					$counts['reservations'] += (int) $wpdb->query( 'DELETE FROM ' . Tables::reservations() . " WHERE id IN ({$in})" ); // phpcs:ignore
				}
				foreach ( [ Tables::notifications(), Tables::menu_items(), Tables::hours(), Tables::closures(), Tables::user_branches() ] as $table ) {
					$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE branch_id = %d", $id ) ); // phpcs:ignore
				}
				if ( class_exists( \Momos\Branch\PostType\BranchScopeTaxonomy::class ) ) {
					$term = \Momos\Branch\PostType\BranchScopeTaxonomy::term_for_branch( $id );
					if ( $term ) {
						wp_delete_term( $term->term_id, \Momos\Branch\PostType\BranchScopeTaxonomy::TAX );
					}
				}
				if ( ! wp_delete_post( $id, true ) ) {
					fwrite( STDERR, "Branch {$code} ({$id}) could not be deleted.\n" ); // phpcs:ignore
					exit( 1 );
				}
				++$counts['branches'];
			}
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( $logins as $login ) {
			$user = get_user_by( 'login', $login );
			if ( $user && wp_delete_user( $user->ID ) ) {
				++$counts['users'];
			}
		}
		$snapshot = get_option( $snapshot_key, null );
		if ( is_array( $snapshot ) ) {
			foreach ( $snapshot as $k => $v ) {
				$absent === $v ? delete_option( $k ) : update_option( $k, $v, false );
				++$counts['options_restored'];
			}
			delete_option( $snapshot_key );
		}
		$clear_counters();
		\Momos\Branch\Service\ScheduleService::flush();
		wp_cache_flush();
		echo wp_json_encode( [ 'teardown' => $counts ] ) . "\n"; // phpcs:ignore
		return;
	}
	if ( 'setup' !== $mode ) {
		fwrite( STDERR, "Unknown mode {$mode} (setup|teardown).\n" ); // phpcs:ignore
		exit( 2 );
	}

	// Remember the pre-fixture values once, so teardown restores them even after repeated setups.
	if ( ! is_array( get_option( $snapshot_key, null ) ) ) {
		$snapshot = [];
		foreach ( array_keys( $overrides ) as $k ) {
			$snapshot[ $k ] = get_option( $k, $absent );
		}
		add_option( $snapshot_key, $snapshot, '', false );
	}

	$branch = static function ( string $code, string $ja, string $en, string $ar ): int {
		$found = get_posts( [ 'post_type' => 'momo_branch', 'post_status' => 'any', 'meta_key' => 'momo_branch_code', 'meta_value' => $code, 'fields' => 'ids', 'posts_per_page' => 1 ] ); // phpcs:ignore
		$id    = $found ? (int) $found[0] : (int) wp_insert_post( [ 'post_type' => 'momo_branch', 'post_status' => 'draft', 'post_title' => $ja, 'post_name' => strtolower( $code ) . '-e2e' ] );
		$meta  = [
			'momo_branch_code'                 => $code,
			'momo_operating_status'            => 'open',
			'momo_postal_code'                 => '530-0001',
			'momo_prefecture'                  => '大阪府',
			'momo_city'                        => '大阪市北区',
			'momo_address_line1'               => '梅田9-9-9',
			'momo_address_ja'                  => '〒530-0001 大阪府大阪市北区梅田9-9-9',
			'momo_lat'                         => '34.702485',
			'momo_lng'                         => '135.495951',
			'momo_phone_e164'                  => '+81600000000',
			'momo_phone_display'               => '06-0000-0000',
			'momo_branch_email'                => strtolower( $code ) . '@momos.example.test',
			'momo_facts_confirmed_by'          => 'E2E',
			'momo_facts_confirmed_at'          => '2026-11-01',
			'momo_res_notification_recipients' => wp_json_encode( [ [ 'email' => strtolower( $code ) . '-inbox@momos.example.test', 'language' => 'en', 'active' => true ] ] ),
		];
		foreach ( $meta as $k => $v ) {
			update_post_meta( $id, $k, $v );
		}
		wp_update_post( [ 'ID' => $id, 'post_status' => 'publish', 'post_title' => $ja ] );
		if ( 'publish' !== get_post_status( $id ) ) {
			fwrite( STDERR, "Branch {$code} could not be published (publish rules).\n" ); // phpcs:ignore
			exit( 1 );
		}
		$periods = [];
		foreach ( range( 1, 7 ) as $wd ) {
			$periods[] = [ 'weekday' => $wd, 'period_no' => 1, 'label' => 'all_day', 'opens_at' => '11:00', 'closes_at' => '22:00', 'last_order_at' => '21:30' ];
		}
		HoursRepository::replace_week( $id, null, null, $periods, AccessContext::system() );
		update_post_meta( $id, 'momo_res_enabled', '1' );
		Translations::save( 'post', $id, 'en', [ 'title' => $en ], 'approved' );
		Translations::save( 'post', $id, 'ar', [ 'title' => $ar ], 'approved' );
		\Momos\Branch\Service\ScheduleService::flush();
		return $id;
	};

	$a = $branch( $codes[0], 'E2Eテスト梅田店', 'E2E Umeda', 'فرع أوميدا التجريبي' );
	$b = $branch( $codes[1], 'E2Eテスト難波店', 'E2E Namba', 'فرع نامبا التجريبي' );

	$staff = static function ( string $login, int $branch_id ) use ( $password ): int {
		$user = get_user_by( 'login', $login );
		$id   = $user ? (int) $user->ID : (int) wp_insert_user( [ 'user_login' => $login, 'user_pass' => $password, 'user_email' => $login . '@momos.example.test', 'role' => 'momo_reservation_staff', 'display_name' => $login ] );
		wp_set_password( $password, $id );
		( new WP_User( $id ) )->set_role( 'momo_reservation_staff' );
		update_user_meta( $id, 'locale', 'en_US' );
		\Momos\Access\Users\AssignmentService::set( $id, [ $branch_id ], 1 );
		return $id;
	};
	$sa = $staff( $logins[0], $a );
	$sb = $staff( $logins[1], $b );

	foreach ( $overrides as $k => $v ) {
		update_option( $k, $v, false );
	}
	wp_cache_flush();

	echo wp_json_encode( [ 'branch_a' => $a, 'branch_b' => $b, 'staff_a' => $sa, 'staff_b' => $sb, 'password' => $password ] ) . "\n"; // phpcs:ignore
} )( isset( $args ) && is_array( $args ) ? $args : [] );
