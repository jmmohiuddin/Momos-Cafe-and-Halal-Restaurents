<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/ReservationTestCaseTest.php';

use Momos\Kernel\Db\Tables;
use Momos\Reservation\Repository\ReservationRepository;
use Momos\Reservation\Service\TokenService;

/**
 * API-01/02/03/04 (F-RES-01..03): SDD §5.2 order, idempotency, duplicates, limits, abuse controls,
 * token routes. TC-RES-001, 003–011, 013–015, 017, 018, 020–022, 025, 030, 039, 040.
 */
final class ReservationSubmissionTest extends ReservationTestCaseTest {
	private int $b;

	public function set_up(): void {
		parent::set_up();
		$this->freeze( '2026-12-01 10:00:00' ); // Tuesday JST.
		$this->b = $this->make_branch( 'QRA' );
	}

	private function count_rows(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::reservations() ); // phpcs:ignore
	}

	public function test_valid_submission_ac_res_01_01(): void {
		$r = $this->submit( $this->body( 'QRA', '2026-12-03', '19:00', [ 'special_request' => 'High chair please' ] ) );
		$this->assertSame( 201, $r->get_status(), wp_json_encode( $r->get_data() ) );
		$d = $r->get_data();
		$this->assertFalse( $d['duplicate'] );
		$this->assertSame( 'pending', $d['status'] );
		$this->assertMatchesRegularExpression( '/^[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$/', $d['reference_display'] );
		$this->assertSame( 'a***@example.org', $d['email_masked'] );
		$this->assertSame( '2026-12-03T18:00:00+09:00', $d['decision_deadline'] );
		$this->assertStringContainsString( '/en/reservation/status/' . $d['reference'] . '/#t=', $d['status_url'] );
		$this->assertSame( 'no-store, private', $r->get_headers()['Cache-Control'] );
		$this->assertArrayHasKey( 'X-Request-Id', $r->get_headers() );
		$row = ReservationRepository::find_by_ref( $d['reference'] );
		$this->assertSame( 'pending', $row['status'] );
		$this->assertSame( '+60123456789', $row['phone_e164'] );
		$this->assertSame( '2026-12-03 10:00:00', $row['requested_start_utc'] );
		$this->assertSame( '2026-12-04 10:00:00', $row['token_expires_utc'] );
		$this->assertSame( 'verified', $row['captcha_status'] );
		$this->assertSame( 'unversioned', $row['consent_version'] );
		$this->assertSame( 64, strlen( (string) $row['ip_hash'] ) );
		$this->assertNotSame( '192.0.2.', substr( (string) $row['ip_hash'], 0, 8 ) );
		$token = substr( $d['status_url'], strpos( $d['status_url'], '#t=' ) + 3 );
		$this->assertSame( TokenService::hash( $token ), $row['token_hash'], 'only the hash is stored' );
		$events = $this->events( (int) $row['id'] );
		$this->assertCount( 1, $events );
		$this->assertSame( 'created', $events[0]['event_type'] );
		$this->assertSame( 'customer', $events[0]['actor_type'] );
		$this->assertSame( [ 'captcha' => 'verified', 'source_lang' => 'en' ], json_decode( $events[0]['payload'], true ) );
		$this->assertCount( 1, $this->hooks_of( 'created' ) );
		$this->assertNotContains( 'reservation.created', $this->audit_actions(), 'the event row is the record' );
	}

	public function test_transport_and_origin_checks_come_first(): void {
		$req = new \WP_REST_Request( 'POST', '/momos/v1/reservations' );
		$req->set_header( 'content-type', 'text/plain' );
		$req->set_header( 'origin', 'http://example.org' );
		$req->set_body( '{}' );
		$this->assertSame( 415, rest_get_server()->dispatch( $req )->get_status() );
		$this->assertSame( 'invalid_json', $this->rest( 'POST', '/momos/v1/reservations', '{"branch_code":' )->get_data()['code'] );
		$this->assertSame( 413, $this->rest( 'POST', '/momos/v1/reservations', '{"x":"' . str_repeat( 'a', 9000 ) . '"}' )->get_status() );
		$r = $this->rest( 'POST', '/momos/v1/reservations', $this->body( 'QRA', '2026-12-03', '19:00' ), [ 'origin' => 'https://evil.example' ] );
		$this->assertSame( 403, $r->get_status() );
		$this->assertSame( 'origin_rejected', $r->get_data()['code'] );
		$this->assertSame( 0, $this->count_rows() );
	}

	public function test_schema_errors_list_fields(): void {
		$r = $this->submit( $this->body( 'QRA', '2026-12-03', '19:15:00', [ 'email' => null, 'extra' => 1, 'party_size' => 51 ] ) );
		$this->assertSame( 400, $r->get_status() );
		$d = $r->get_data();
		$this->assertSame( 'invalid_param', $d['code'] );
		$this->assertSame( [ 'extra' => 'not_allowed', 'email' => 'required', 'time' => 'invalid_format', 'party_size' => 'out_of_range' ], $d['data']['fields'] );
		$this->assertSame( 'res.err.summary', $d['data']['message_key'] );
		$this->assertSame( 'Please correct 4 item(s) below.', $d['message'] );
	}

	public function test_crafted_time_and_party_ac_res_01_06_07(): void {
		$r = $this->submit( $this->body( 'QRA', '2026-12-03', '18:15' ) );
		$this->assertSame( 409, $r->get_status() );
		$this->assertSame( 'slot_unavailable', $r->get_data()['code'] );
		$this->assertSame( [ 'time' => 'not_a_slot' ], $r->get_data()['data']['fields'] );
		$r = $this->submit( $this->body( 'QRA', '2026-12-03', '19:00', [ 'party_size' => 9 ] ) );
		$this->assertSame( 422, $r->get_status() );
		$this->assertSame( 'party_size_out_of_range', $r->get_data()['code'] );
		$this->assertSame( '+81600000000', $r->get_data()['data']['branch_phone']['e164'] );
		$this->assertStringContainsString( '06-0000-0000', $r->get_data()['message'] );
		$this->assertSame( 0, $this->count_rows() );
	}

	public function test_bengali_digits_party_and_phone_ac_res_01_17(): void {
		$r = $this->submit( $this->body( 'QRA', '2026-12-03', '19:00', [ 'party_size' => '৪', 'phone' => '০৯০-১২৩৪-৫৬৭৮', 'lang' => 'bn', 'preferred_lang' => 'bn' ] ) );
		$this->assertSame( 201, $r->get_status(), wp_json_encode( $r->get_data() ) );
		$row = ReservationRepository::find_by_ref( $r->get_data()['reference'] );
		$this->assertSame( '4', (string) $row['party_size'] );
		$this->assertSame( '+819012345678', $row['phone_e164'] );
		$this->assertSame( 'bn', $row['preferred_lang'] );
	}

	public function test_rules_lead_time_window_closed_branch(): void {
		$r = $this->submit( $this->body( 'QRA', '2026-12-01', '11:30' ) ); // now 10:00 + 120 min lead.
		$this->assertSame( 'lead_time_not_met', $r->get_data()['code'] );
		$this->assertSame( 422, $r->get_status() );
		update_option( 'momo_rate_email_per_hour', 20 );
		$this->assertSame( 'outside_booking_window', $this->submit( $this->body( 'QRA', '2027-02-01', '19:00' ) )->get_data()['code'] );
		$this->assertSame( 'outside_booking_window', $this->submit( $this->body( 'QRA', '2026-11-30', '19:00' ) )->get_data()['code'] );
		$this->assertSame( 201, $this->submit( $this->body( 'QRA', '2026-12-01', '12:00' ) )->get_status(), 'exactly now + lead time' );
		update_post_meta( $this->b, 'momo_res_enabled', '0' );
		$r = $this->submit( $this->body( 'QRA', '2026-12-03', '19:00' ) );
		$this->assertSame( 422, $r->get_status() );
		$this->assertSame( 'branch_not_accepting', $r->get_data()['code'] );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.99'; // 5/h per IP reached above.
		$this->assertSame( 'branch_not_found', $this->submit( $this->body( 'QRZ', '2026-12-03', '19:00' ) )->get_data()['code'] );
	}

	public function test_idempotent_replay_ac_res_01_08(): void {
		$body = $this->body( 'QRA', '2026-12-03', '19:00' );
		$a    = $this->submit( $body );
		$b    = $this->submit( $body );
		$this->assertSame( 201, $a->get_status() );
		$this->assertSame( 200, $b->get_status() );
		$this->assertSame( $a->get_data(), $b->get_data(), 'identical body, status_url re-derived' );
		$this->assertSame( 1, $this->count_rows() );
		$this->assertCount( 1, $this->hooks_of( 'created' ) );
		// Same key with another person's email is refused (no token disclosure).
		$c = $this->submit( array_merge( $body, [ 'email' => 'mallory@example.org' ] ) );
		$this->assertSame( 400, $c->get_status() );
		$this->assertSame( [ 'idempotency_key' => 'not_allowed' ], $c->get_data()['data']['fields'] );
	}

	public function test_soft_duplicate_and_pending_limit_ac_res_01_09_10(): void {
		update_option( 'momo_rate_email_per_hour', 20 ); // The 3/h e-mail limit would otherwise answer first (check 8 < 19).
		$this->assertSame( 201, $this->submit( $this->body( 'QRA', '2026-12-03', '19:00' ) )->get_status() );
		$dup = $this->submit( $this->body( 'QRA', '2026-12-03', '19:00', [ 'email' => 'AISHA@Example.org' ] ) );
		$this->assertSame( 200, $dup->get_status() );
		$this->assertTrue( $dup->get_data()['duplicate'] );
		$this->assertArrayNotHasKey( 'status_url', $dup->get_data() );
		$this->assertStringContainsString( $dup->get_data()['reference_display'], $dup->get_data()['message'] );
		$this->assertSame( 201, $this->submit( $this->body( 'QRA', '2026-12-04', '19:00' ) )->get_status() );
		$this->assertSame( 201, $this->submit( $this->body( 'QRA', '2026-12-05', '19:00' ) )->get_status() );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		$fourth = $this->submit( $this->body( 'QRA', '2026-12-06', '19:00' ) );
		$this->assertSame( 409, $fourth->get_status() );
		$this->assertSame( 'pending_limit_reached', $fourth->get_data()['code'] );
		$this->assertStringContainsString( '3', $fourth->get_data()['message'] );
		// Another branch is accepted (limit is per branch); the email limit (3/h) applies to the address.
		$this->make_branch( 'QRB' );
		$other = $this->submit( $this->body( 'QRB', '2026-12-06', '19:00', [ 'email' => 'other@example.org' ] ) );
		$this->assertSame( 201, $other->get_status() );
	}

	public function test_rate_limit_ip_ac_res_01_11(): void {
		for ( $i = 1; $i <= 5; $i++ ) {
			$r = $this->submit( $this->body( 'QRA', '2026-12-0' . ( $i + 2 ), '19:00', [ 'email' => "g{$i}@example.org" ] ) );
			$this->assertSame( 201, $r->get_status(), "request {$i}" );
		}
		$r = $this->submit( $this->body( 'QRA', '2026-12-09', '19:00', [ 'email' => 'g6@example.org', 'lang' => 'ja', 'preferred_lang' => 'ja' ] ) );
		$this->assertSame( 429, $r->get_status() );
		$this->assertSame( 'rate_limited', $r->get_data()['code'] );
		$this->assertGreaterThan( 0, (int) $r->get_headers()['Retry-After'] );
		$this->assertStringContainsString( '短時間に多くのリクエスト', $r->get_data()['message'], 'localised' );
		$this->assertSame( '0', $r->get_headers()['X-RateLimit-Remaining'] );
	}

	public function test_rate_limit_email(): void {
		for ( $i = 1; $i <= 3; $i++ ) {
			$_SERVER['REMOTE_ADDR'] = '203.0.113.' . $i;
			$this->submit( $this->body( 'QRA', '2026-12-0' . ( $i + 2 ), '19:00', [ 'email' => 'same@example.org' ] ) );
		}
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$this->assertSame( 'rate_limited', $this->submit( $this->body( 'QRA', '2026-12-09', '19:00', [ 'email' => 'Same@example.org' ] ) )->get_data()['code'] );
	}

	public function test_honeypot_and_captcha_ac_res_01_12(): void {
		$r = $this->submit( $this->body( 'QRA', '2026-12-03', '19:00', [ 'website' => 'http://spam.example' ] ) );
		$this->assertSame( 403, $r->get_status() );
		$this->assertSame( 'captcha_failed', $r->get_data()['code'] );
		$r = $this->submit( $this->body( 'QRA', '2026-12-03', '19:00', [ 'captcha_token' => 'test-fail' ] ) );
		$this->assertSame( 'captcha_failed', $r->get_data()['code'] );
		$this->assertSame( 0, $this->count_rows() );
	}

	public function test_captcha_outage_degrades(): void {
		$r = $this->submit( $this->body( 'QRA', '2026-12-03', '19:00', [ 'captcha_token' => 'test-outage' ] ) );
		$this->assertSame( 201, $r->get_status() );
		$row = ReservationRepository::find_by_ref( $r->get_data()['reference'] );
		$this->assertSame( 'unverified_outage', $row['captcha_status'] );
		$this->assertContains( 'security.captcha_degraded', $this->audit_actions() );
		$this->assertTrue( \Momos\Api\Captcha\TurnstileVerifier::outage_active() );
		// Degraded IP limit (2/h) now applies to new connections.
		$_SERVER['REMOTE_ADDR'] = '198.51.100.20';
		$this->assertSame( 201, $this->submit( $this->body( 'QRA', '2026-12-04', '19:00', [ 'email' => 'x1@example.org' ] ) )->get_status() );
		$this->assertSame( 201, $this->submit( $this->body( 'QRA', '2026-12-05', '19:00', [ 'email' => 'x2@example.org' ] ) )->get_status() );
		$this->assertSame( 429, $this->submit( $this->body( 'QRA', '2026-12-06', '19:00', [ 'email' => 'x3@example.org' ] ) )->get_status() );
	}

	public function test_test_branch_hidden_fr_res_026(): void {
		$t = $this->make_branch( 'QRT', [ 'momo_is_test_branch' => '1' ] );
		$this->assertSame( 'branch_not_found', $this->submit( $this->body( 'QRT', '2026-12-03', '19:00' ) )->get_data()['code'] );
		$this->assertSame( 404, $this->rest( 'GET', '/momos/v1/branches/QRT/reservation-rules' )->get_status() );
		$rs = $this->staff( 'momo_reservation_staff', [ $t ] );
		wp_set_current_user( $rs );
		$r = $this->submit( $this->body( 'QRT', '2026-12-03', '19:00', [ 'captcha_token' => 'test-fail' ] ) );
		$this->assertSame( 201, $r->get_status(), wp_json_encode( $r->get_data() ) );
		$this->assertSame( 'not_applicable', ReservationRepository::find_by_ref( $r->get_data()['reference'] )['captcha_status'] );
		$rules = $this->rest( 'GET', '/momos/v1/branches/QRT/reservation-rules' );
		$this->assertSame( 200, $rules->get_status() );
		$this->assertTrue( $rules->get_data()['is_test'] );
		$this->assertSame( 'no-store, private', $rules->get_headers()['Cache-Control'] );
	}

	public function test_api_01_rules(): void {
		$r = $this->rest( 'GET', '/momos/v1/branches/QRA/reservation-rules', null, [], [ 'lang' => 'en' ] );
		$this->assertSame( 200, $r->get_status() );
		$d = $r->get_data();
		$this->assertTrue( $d['accepting'] );
		$this->assertSame( [ 'party_min' => 1, 'party_max' => 8, 'lead_time_minutes' => 120, 'booking_window_days' => 60, 'slot_interval_minutes' => 30, 'response_promise_minutes' => null ], $d['rules'] );
		$this->assertSame( '12:00', $d['days'][0]['slots'][0] );
		$this->assertSame( 'public, max-age=300, s-maxage=300', $r->get_headers()['Cache-Control'] );
		$this->assertStringNotContainsString( 'inbox@', wp_json_encode( $d ), 'no staff data' );
		update_post_meta( $this->b, 'momo_operating_status', 'temporarily_closed' );
		$d = $this->rest( 'GET', '/momos/v1/branches/QRA/reservation-rules' )->get_data();
		$this->assertFalse( $d['accepting'] );
		$this->assertSame( 'temporarily_closed', $d['not_accepting_reason'] );
		$this->assertSame( [], $d['days'] );
		$this->assertSame( 'invalid_param', $this->rest( 'GET', '/momos/v1/branches/QRA/reservation-rules', null, [], [ 'lang' => 'xx' ] )->get_data()['code'] );
	}

	// ------------------------------------------------------------------ API-03 / API-04

	/** @return array{0:string,1:string,2:int} ref, token, id */
	private function create(): array {
		$d     = $this->submit( $this->body( 'QRA', '2026-12-03', '19:00' ) )->get_data();
		$token = substr( $d['status_url'], strpos( $d['status_url'], '#t=' ) + 3 );
		return [ $d['reference'], $token, (int) ReservationRepository::find_by_ref( $d['reference'] )['id'] ];
	}

	public function test_status_page_api_03_ac_res_03(): void {
		[ $ref, $token ] = $this->create();
		$r = $this->rest( 'GET', '/momos/v1/reservations/' . strtolower( substr( $ref, 0, 4 ) . '-' . substr( $ref, 4 ) ), null, [ 'x-momo-token' => $token ], [ 'lang' => 'en' ] );
		$this->assertSame( 200, $r->get_status(), wp_json_encode( $r->get_data() ) );
		$d = $r->get_data();
		$this->assertSame( 'pending', $d['status'] );
		$this->assertSame( 'Request received — not yet confirmed', $d['status_label'] );
		$this->assertTrue( $d['can_cancel'] );
		$this->assertSame( 'Aisha', $d['guest_given_name'] );
		$this->assertArrayNotHasKey( 'phone_e164', $d );
		$this->assertStringNotContainsString( '+60123456789', wp_json_encode( $d ) );
		$this->assertSame( [ 'pending' ], array_column( $d['history'], 'status' ) );
		$this->assertSame( 'no-store, private', $r->get_headers()['Cache-Control'] );
		// Wrong token → 404, indistinguishable from an unknown ref; ?t= → 400; missing header → 400.
		$this->assertSame( 404, $this->rest( 'GET', '/momos/v1/reservations/' . $ref, null, [ 'x-momo-token' => str_repeat( 'A', 43 ) ] )->get_status() );
		$this->assertSame( 404, $this->rest( 'GET', '/momos/v1/reservations/ZZZZZZZZ', null, [ 'x-momo-token' => $token ] )->get_status() );
		$q = $this->rest( 'GET', '/momos/v1/reservations/' . $ref, null, [ 'x-momo-token' => $token ], [ 't' => $token ] );
		$this->assertSame( 400, $q->get_status() );
		$this->assertSame( [ 't' => 'not_allowed' ], $q->get_data()['data']['fields'] );
		$this->assertSame( 400, $this->rest( 'GET', '/momos/v1/reservations/' . $ref )->get_status() );
		// Expired link → 410 (only after a hash match).
		$this->freeze( '2026-12-04 19:00:01' );
		$this->assertSame( 410, $this->rest( 'GET', '/momos/v1/reservations/' . $ref, null, [ 'x-momo-token' => $token ] )->get_status() );
	}

	public function test_customer_cancel_api_04(): void {
		[ $ref, $token, $id ] = $this->create();
		$r = $this->rest( 'POST', '/momos/v1/reservations/' . $ref . '/cancel', [ 'lang' => 'en' ], [ 'x-momo-token' => $token ] );
		$this->assertSame( 200, $r->get_status(), wp_json_encode( $r->get_data() ) );
		$this->assertSame( 'cancelled', $r->get_data()['status'] );
		$this->assertFalse( $r->get_data()['can_cancel'] );
		$this->assertSame( 'customer', $r->get_data()['cancelled_by'] );
		$row = $this->row( $id );
		$this->assertSame( 'customer', $row['cancelled_by'] );
		$this->assertSame( 'cancelled', $this->hooks_of( 'status_changed' )[0][3] );
		$this->assertSame( 'customer', $this->hooks_of( 'status_changed' )[0][4]['type'] );
		$this->assertNotContains( 'reservation.status_changed', $this->audit_actions(), 'customer transitions: event only' );
		// Repeating → 409 cancel_not_allowed [Ruling C-1].
		$again = $this->rest( 'POST', '/momos/v1/reservations/' . $ref . '/cancel', [ 'lang' => 'en' ], [ 'x-momo-token' => $token ] );
		$this->assertSame( 409, $again->get_status() );
		$this->assertSame( 'cancel_not_allowed', $again->get_data()['code'] );
		$this->assertStringContainsString( '06-0000-0000', $again->get_data()['message'] );
		// Origin and body guards.
		$this->assertSame( 'origin_rejected', $this->rest( 'POST', '/momos/v1/reservations/' . $ref . '/cancel', [ 'lang' => 'en' ], [ 'x-momo-token' => $token, 'origin' => 'https://evil.example' ] )->get_data()['code'] );
		$this->assertSame( 'invalid_param', $this->rest( 'POST', '/momos/v1/reservations/' . $ref . '/cancel', [ 'lang' => 'en', 't' => $token ], [ 'x-momo-token' => $token ] )->get_data()['code'] );
	}

	public function test_customer_cancel_after_start_and_lazy_expiry(): void {
		[ $ref, $token, $id ] = $this->create();
		// After the decision cutoff (18:00) a pending request expires lazily → invalid_transition.
		$this->freeze( '2026-12-03 18:01:00' );
		$r = $this->rest( 'POST', '/momos/v1/reservations/' . $ref . '/cancel', [], [ 'x-momo-token' => $token ] );
		$this->assertSame( 409, $r->get_status() );
		$this->assertSame( 'invalid_transition', $r->get_data()['code'] );
		$this->assertSame( 'expired', $this->row( $id )['status'] );
		$s = $this->rest( 'GET', '/momos/v1/reservations/' . $ref, null, [ 'x-momo-token' => $token ] )->get_data();
		$this->assertSame( 'expired', $s['status'] );
		$this->assertFalse( $s['can_cancel'] );
	}
}
