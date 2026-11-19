<?php
/**
 * E2E fixture for tests/e2e/specs/reservation-lifecycle.spec.ts (development/CI installs only).
 * Run: bin/wp eval-file tests/e2e/fixtures/reservation-fixture.php
 * Idempotent: two fictional branches XEA/XEB (published, open, accepting, daily 11:00–22:00 L.O. 21:30,
 * en + ar translations approved, a test recipient each) and two Reservation Staff accounts, one per
 * branch. Relaxes the public rate limits of this install so repeated runs from 127.0.0.1 are possible.
 * Prints JSON {branch_a, branch_b, staff_a, staff_b, password}. Synthetic data only (example.test).
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
use Momos\Kernel\I18n\Translations;

$password = 'E2E-Staff-2026!';

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

$a = $branch( 'XEA', 'E2Eテスト梅田店', 'E2E Umeda', 'فرع أوميدا التجريبي' );
$b = $branch( 'XEB', 'E2Eテスト難波店', 'E2E Namba', 'فرع نامبا التجريبي' );

$staff = static function ( string $login, int $branch_id ) use ( $password ): int {
	$user = get_user_by( 'login', $login );
	$id   = $user ? (int) $user->ID : (int) wp_insert_user( [ 'user_login' => $login, 'user_pass' => $password, 'user_email' => $login . '@momos.example.test', 'role' => 'momo_reservation_staff', 'display_name' => $login ] );
	wp_set_password( $password, $id );
	( new WP_User( $id ) )->set_role( 'momo_reservation_staff' );
	update_user_meta( $id, 'locale', 'en_US' );
	\Momos\Access\Users\AssignmentService::set( $id, [ $branch_id ], 1 );
	return $id;
};
$sa = $staff( 'e2e_rs_a', $a );
$sb = $staff( 'e2e_rs_b', $b );

foreach ( [ 'momo_rate_ip_per_hour' => 1000, 'momo_rate_email_per_hour' => 1000, 'momo_rate_status_ip_per_hour' => 1000, 'momo_rate_cancel_ip_per_hour' => 1000, 'momo_rate_ip_degraded_per_hour' => 1000 ] as $k => $v ) {
	update_option( $k, $v, false );
}
update_option( 'momos_languages_enabled', [ 'ja', 'en', 'ar', 'ur', 'hi', 'bn' ] );
wp_cache_flush();

echo wp_json_encode( [ 'branch_a' => $a, 'branch_b' => $b, 'staff_a' => $sa, 'staff_b' => $sb, 'password' => $password ] ) . "\n"; // phpcs:ignore
