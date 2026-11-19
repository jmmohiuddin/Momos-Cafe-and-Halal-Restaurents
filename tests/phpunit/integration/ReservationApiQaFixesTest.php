<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/ReservationTestCaseTest.php';

use Momos\Api\ApiModule;
use Momos\Api\RateLimit\RateLimiter;
use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Db\Tables;
use Momos\Kernel\Settings;
use Momos\Kernel\Support\Hash;
use Momos\Reservation\Admin\InboxPage;
use Momos\Reservation\Domain\ReservationError;
use Momos\Reservation\Domain\ReservationStatus;
use Momos\Reservation\Domain\Scope;
use Momos\Reservation\Front\CustomerPages;
use Momos\Reservation\Repository\ReservationRepository;
use Momos\Reservation\Service\ExpiryService;

/**
 * Regression tests for the reservation-api fixes of the Wave 3 QA round (QA-011/076 error context,
 * QA-012 queue rows in the business transaction, QA-040 foreign branch filter, QA-043 TEST banner,
 * QA-045 limiter lock, QA-059 inbox predicates, QA-060 CORS, QA-015 accommodation channel, QA-047
 * live region). The QA reproducers themselves live in the Qa*Test files and the e2e specs.
 */
final class ReservationApiQaFixesTest extends ReservationTestCaseTest {
	private int $a;

	public function set_up(): void {
		parent::set_up();
		update_option( 'momo_rate_ip_per_hour', 1000 );
		update_option( 'momo_rate_email_per_hour', 1000 );
		update_option( 'momo_rate_status_ip_per_hour', 1000 );
		update_option( 'momo_rate_cancel_ip_per_hour', 1000 );
		$this->freeze( '2026-12-01 10:00:00' );
		$this->a = $this->make_branch( 'QRA', [ 'momo_phone_display' => '06-1234-0001', 'momo_phone_e164' => '+81612340001' ] );
	}

	public function tear_down(): void {
		Settings::set( 'momo_contact_phone_display', '' );
		Settings::set( 'momo_contact_phone_e164', '' );
		Settings::set( 'momo_contact_email', '' );
		Settings::flush();
		unset( $_GET['branch'] );
		parent::tear_down();
	}

	private function submitted( array $over = [] ): int {
		wp_set_current_user( 0 );
		$r = $this->submit( $this->body( 'QRA', '2026-12-03', '19:00', $over ) );
		$this->assertSame( 201, $r->get_status(), wp_json_encode( $r->get_data() ) );
		return (int) ReservationRepository::find_by_ref( (string) $r->get_data()['reference'] )['id'];
	}

	/** @return list<array<string,mixed>> */
	private function notifs( int $id, string $template ): array {
		global $wpdb;
		$t = Tables::notifications();
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE reservation_id = %d AND template_key = %s", $id, $template ), ARRAY_A ); // phpcs:ignore
	}

	private function break_notification_inserts(): callable {
		$t  = Tables::notifications();
		$br = static fn( string $sql ): string => str_starts_with( ltrim( $sql ), "INSERT INTO {$t}" ) ? 'INSERT INTO momos_qa_no_such_table VALUES (1)' : $sql;
		add_filter( 'query', $br );
		return $br;
	}

	// ------------------------------------------------------------------ QA-076 / QA-011 placeholders

	public function test_guard_error_without_branch_context_names_the_head_office_phone(): void {
		Settings::set( 'momo_contact_phone_display', '06-5555-0100' );
		Settings::flush();
		$r = $this->rest( 'POST', '/momos/v1/reservations', '{"lang":"en"}', [ 'content-type' => 'text/plain' ] );
		$this->assertSame( [ 415, 'unsupported_media_type' ], [ $r->get_status(), $r->get_data()['code'] ] );
		$this->assertStringContainsString( 'call 06-5555-0100.', (string) $r->get_data()['message'] );
		$this->assertSame( 'res.err.system', $r->get_data()['data']['message_key'] );
	}

	public function test_guard_error_without_any_phone_uses_the_wording_without_a_placeholder(): void {
		Settings::set( 'momo_contact_phone_display', '' );
		Settings::flush();
		foreach ( [ 'en', 'ja', 'ar' ] as $lang ) {
			$r   = $this->rest( 'POST', '/momos/v1/reservations', '{"lang":"' . $lang . '"}', [ 'content-type' => 'text/plain' ] );
			$msg = (string) $r->get_data()['message'];
			$this->assertSame( momos_t( 'res.err.system.no_phone', [], $lang ), $msg, $lang );
			$this->assertDoesNotMatchRegularExpression( '/call \.|へお電話|\{phone\}/u', $msg );
			$this->assertSame( 'res.err.system', $r->get_data()['data']['message_key'], 'message_key stays canonical' );
		}
	}

	public function test_errors_after_token_verification_still_name_the_branch_phone(): void {
		$id    = $this->submitted( [ 'email' => 'late-cancel@example.org' ] );
		$row   = $this->row( $id );
		$token = \Momos\Reservation\Service\TokenService::token_for( $row );
		$route = '/momos/v1/reservations/' . $row['public_ref'] . '/cancel';
		$this->assertSame( 200, $this->rest( 'POST', $route, [ 'lang' => 'en' ], [ 'X-Momo-Token' => $token ] )->get_status() );
		$r = $this->rest( 'POST', $route, [ 'lang' => 'en' ], [ 'X-Momo-Token' => $token ] ); // terminal → 409 cancel_not_allowed (Ruling C-1)
		$this->assertSame( [ 409, 'cancel_not_allowed' ], [ $r->get_status(), $r->get_data()['code'] ], wp_json_encode( $r->get_data() ) );
		$this->assertStringContainsString( '06-1234-0001', (string) $r->get_data()['message'], 'verified reservation → its branch phone' );
	}

	public function test_unverified_status_request_never_names_the_branch_phone(): void {
		$id = $this->submitted( [ 'email' => 'oracle@example.org' ] );
		Settings::set( 'momo_contact_phone_display', '06-5555-0100' );
		Settings::flush();
		$bad   = str_repeat( 'A', 43 );
		$known = $this->rest( 'GET', '/momos/v1/reservations/' . $this->row( $id )['public_ref'], null, [ 'X-Momo-Token' => $bad ], [ 'lang' => 'en' ] );
		$this->assertSame( 404, $known->get_status() );
		$this->assertStringNotContainsString( '06-1234-0001', (string) wp_json_encode( $known->get_data() ) );
	}

	// ------------------------------------------------------------------ QA-012 queue rows in the transaction

	public function test_accept_queues_exactly_one_e02_inside_the_transaction(): void {
		$id = $this->submitted();
		$this->assertCount( 1, $this->notifs( $id, 'E01' ) );
		$rs = $this->staff( 'momo_reservation_staff', [ $this->a ] );
		wp_set_current_user( $rs );
		$r = $this->rest( 'POST', '/momos/v1/staff/reservations/' . $id . '/transitions', [ 'action' => 'accept', 'version' => 1 ] );
		$this->assertSame( 200, $r->get_status(), wp_json_encode( $r->get_data() ) );
		$this->assertSame( [ 'E02' ], $r->get_data()['notifications_queued'] );
		$this->assertCount( 1, $this->notifs( $id, 'E02' ), 'after-commit subscriber deduplicates against the in-transaction row' );
	}

	public function test_expiry_rolls_back_when_its_e05_row_cannot_be_stored_and_read_paths_survive(): void {
		$id = $this->submitted( [ 'email' => 'expire@example.org' ] );
		$this->freeze( '2026-12-03 18:59:00' ); // past the decision cutoff
		$br = $this->break_notification_inserts();
		try {
			ExpiryService::expire_if_due( $id );
			$this->fail( 'expected service_unavailable' );
		} catch ( ReservationError $e ) {
			$this->assertSame( 'service_unavailable', $e->error_code );
		}
		ExpiryService::expire_quietly( $id ); // inbox/detail/API-03 path: logged, no exception
		remove_filter( 'query', $br );
		$this->assertSame( 'pending', $this->row( $id )['status'], 'no expiry without its E05 row' );
		$this->assertNotNull( ExpiryService::expire_if_due( $id ) );
		$this->assertCount( 1, $this->notifs( $id, 'E05' ) );
	}

	public function test_modification_is_refused_when_its_e06_row_cannot_be_stored(): void {
		$id = $this->submitted( [ 'email' => 'modify@example.org' ] );
		$bm = $this->staff( 'momo_branch_manager', [ $this->a ] );
		wp_set_current_user( $bm );
		$br = $this->break_notification_inserts();
		$r  = $this->rest( 'PATCH', '/momos/v1/staff/reservations/' . $id, [ 'version' => 1, 'requested_time' => '19:30', 'reason' => 'agreed_with_guest' ] );
		remove_filter( 'query', $br );
		$this->assertSame( 503, $r->get_status(), wp_json_encode( $r->get_data() ) );
		$row = $this->row( $id );
		$this->assertSame( [ '19:00:00', '1' ], [ $row['requested_time'], (string) $row['version'] ] );
	}

	// ------------------------------------------------------------------ QA-040 foreign branch filter

	public function test_foreign_branch_filter_is_refused_with_403_by_code_or_id(): void {
		$b  = $this->make_branch( 'QRB' );
		$bm = $this->staff( 'momo_branch_manager', [ $this->a ] );
		wp_set_current_user( $bm );
		$status  = null;
		$handler = static function () use ( &$status ) {
			return static function ( $message, $title, $args ) use ( &$status ) {
				$status = (int) ( $args['response'] ?? 0 );
				throw new \WPDieException( (string) $message );
			};
		};
		add_filter( 'wp_die_handler', $handler, 99 );
		foreach ( [ (string) $b, 'QRB', 'qra,QRB' ] as $value ) {
			$status         = null;
			$_GET['branch'] = $value;
			try {
				InboxPage::forbid_foreign_branch_filter( 'SCR-A08' );
				$this->fail( "branch={$value} must be refused" );
			} catch ( \WPDieException $e ) {
				unset( $e );
			}
			$this->assertSame( 403, $status, $value );
		}
		foreach ( [ (string) $this->a, 'QRA', 'ZZZ', '999999' ] as $value ) {
			$_GET['branch'] = $value;
			InboxPage::forbid_foreign_branch_filter( 'SCR-A08' ); // own or unknown: no exception
		}
		remove_filter( 'wp_die_handler', $handler, 99 );
		$this->assertContains( 'access.denied', array_column( $this->audits, 0 ) );
	}

	// ------------------------------------------------------------------ QA-043 TEST banner

	public function test_test_banner_is_rendered_only_for_viewers_offered_the_test_branch(): void {
		$this->make_branch( 'QRT', [ 'momo_is_test_branch' => '1' ] );
		$render = function (): string {
			ob_start();
			get_template_part( 'template-parts/reservation/form', null, CustomerPages::form_view( 'ja' ) );
			return (string) ob_get_clean();
		};
		wp_set_current_user( 0 );
		$anon = $render();
		$this->assertStringNotContainsString( 'data-res-test-banner', $anon );
		$this->assertStringNotContainsString( momos_t( 'res.test_banner', [], 'ja' ), $anon );
		wp_set_current_user( $this->staff( 'momo_head_office' ) );
		$this->assertStringContainsString( 'data-res-test-banner', $render(), 'assigned staff keep the banner' );
		wp_set_current_user( 0 );
	}

	// ------------------------------------------------------------------ QA-015 / QA-017 / QA-047 form markup

	public function test_form_markup_alt_contact_visible_form_and_no_times_live_region(): void {
		Settings::set( 'momo_contact_phone_e164', '+81655550100' );
		Settings::set( 'momo_contact_phone_display', '06-5555-0100' );
		Settings::set( 'momo_contact_email', 'office@momos.example' );
		Settings::flush();
		wp_set_current_user( 0 );
		$view = CustomerPages::form_view( 'en' );
		$this->assertSame( [ 'tel:+81655550100', 'office@momos.example' ], [ $view['alt_contact']['tel'], $view['alt_contact']['email'] ] );
		$this->assertSame( '+81 6-5555-0100', preg_replace( '/\s/u', ' ', $view['alt_contact']['phone'] ), 'international format outside ja' );
		ob_start();
		get_template_part( 'template-parts/reservation/alt-contact', null, [ 'contact' => $view['alt_contact'] ] );
		get_template_part( 'template-parts/reservation/form', null, $view );
		$html = (string) ob_get_clean();
		$this->assertMatchesRegularExpression( '#data-res-alt-contact.*href="tel:\+81655550100".*href="mailto:office@momos\.example".*id="res-branch"#s', $html, 'alternative before the branch field' );
		$this->assertMatchesRegularExpression( '#<form [^>]*data-momo-res-form[^>]*>#', $html );
		$this->assertDoesNotMatchRegularExpression( '#<form [^>]*\shidden[\s>]#', $html, 'form visible at first paint (CLS)' );
		$this->assertMatchesRegularExpression( '#<button type="submit"[^>]*data-res-submit disabled#', $html, 'no native submit before the script runs' );
		$this->assertMatchesRegularExpression( '#<div role="status" data-res-no-times-region>\s*(<\?php[^>]*>)?\s*<p[^>]*data-res-no-times hidden#', $html );
	}

	// ------------------------------------------------------------------ QA-045 limiter lock

	public function test_rate_limiter_fails_closed_while_another_request_holds_the_key_lock(): void {
		$ip     = '198.51.100.7';
		$window = 3600;
		$start  = intdiv( Clock::utc()->getTimestamp(), $window ) * $window;
		$key    = 'momos_rl:res_ip:' . substr( Hash::hmac( $ip, 'MOMOS_HASH_PEPPER' ), 0, 32 ) . ':' . $start;
		$lock   = 'momos_' . substr( hash( 'sha256', 'rl:' . $key ), 0, 40 );
		$other  = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$this->assertSame( '1', (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) );
		$held = RateLimiter::hit( 'res_ip', $ip );
		$other->query( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		$other->close();
		$this->assertSame( [ false, 1 ], [ $held['allowed'], $held['retry_after'] ] );
		$this->assertTrue( RateLimiter::hit( 'res_ip', $ip )['allowed'], 'lock released → counted normally' );
	}

	// ------------------------------------------------------------------ QA-059 inbox predicates and index

	public function test_all_statuses_view_has_no_status_predicate_and_the_start_index_exists(): void {
		global $wpdb;
		$scope = Scope::system();
		$all   = (string) ReservationRepository::scoped_where( $scope, [ 'statuses' => ReservationStatus::values() ] );
		$this->assertStringNotContainsString( 'status IN', $all );
		$some = (string) ReservationRepository::scoped_where( $scope, [ 'statuses' => [ 'pending', 'confirmed' ] ] );
		$this->assertStringContainsString( "status IN ('pending','confirmed')", $some );
		$t = Tables::reservations();
		$this->assertNotNull( $wpdb->get_var( "SHOW INDEX FROM {$t} WHERE Key_name = 'idx_start'" ) ); // phpcs:ignore
	}

	// ------------------------------------------------------------------ QA-060 CORS

	public function test_momos_namespace_strips_core_cors_headers(): void {
		$this->assertSame( 100, has_filter( 'rest_pre_serve_request', [ ApiModule::class, 'strip_cors' ] ) );
	}
}
