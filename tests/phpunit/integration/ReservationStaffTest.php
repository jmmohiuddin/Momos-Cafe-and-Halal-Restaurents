<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/ReservationTestCaseTest.php';

use Momos\Reservation\Domain\Actor;
use Momos\Reservation\Domain\ReservationError;
use Momos\Reservation\Service\ActionPolicy;
use Momos\Reservation\Service\TransitionService;

/**
 * Staff API-10..13/16 and branch isolation (F-RES-04/05/09/10; TC-RSA-001, 003–006, 011–014, 016,
 * 017, 022, 023, 025; TC-RES-037; AC-RES-04/05/09/10).
 */
final class ReservationStaffTest extends ReservationTestCaseTest {
	private int $a;
	private int $b;
	private int $rs_a;
	private int $ho;

	public function set_up(): void {
		parent::set_up();
		$this->freeze( '2026-12-01 10:00:00' );
		$this->a    = $this->make_branch( 'QRA' );
		$this->b    = $this->make_branch( 'QRB' );
		$this->rs_a = $this->staff( 'momo_reservation_staff', [ $this->a ] );
		$this->ho   = $this->staff( 'momo_head_office' );
	}

	private function transition( int $id, array $body ): \WP_REST_Response {
		return $this->rest( 'POST', '/momos/v1/staff/reservations/' . $id . '/transitions', $body );
	}

	public function test_inbox_scope_and_counts_ac_res_04_01(): void {
		$ra = $this->make_reservation( $this->a, '2026-12-03', '19:00', [ 'email' => 'x@docomo.ne.jp', 'special_request' => 'secret' ] );
		$this->make_reservation( $this->b, '2026-12-03', '19:00' );
		$this->assertSame( 401, $this->rest( 'GET', '/momos/v1/staff/reservations' )->get_status() );
		wp_set_current_user( $this->rs_a );
		$r = $this->rest( 'GET', '/momos/v1/staff/reservations' );
		$this->assertSame( 200, $r->get_status(), wp_json_encode( $r->get_data() ) );
		$rows = $r->get_data();
		$this->assertCount( 1, $rows );
		$this->assertSame( $ra, $rows[0]['id'] );
		$this->assertSame( '1', $r->get_headers()['X-WP-Total'] );
		$this->assertSame( 1, json_decode( $r->get_headers()['X-Momo-Status-Counts'], true )['pending'] );
		$this->assertTrue( $rows[0]['flags']['carrier_email'] );
		$this->assertTrue( $rows[0]['flags']['has_special_request'] );
		$json = wp_json_encode( $rows );
		$this->assertStringNotContainsString( 'docomo', $json, 'no email in list rows' );
		$this->assertStringNotContainsString( 'secret', $json, 'no special request text in list rows' );
		// Out-of-scope branch filter → 403 forbidden_branch + access.denied.
		$f = $this->rest( 'GET', '/momos/v1/staff/reservations', null, [], [ 'branch' => 'QRB' ] );
		$this->assertSame( 403, $f->get_status() );
		$this->assertSame( 'forbidden_branch', $f->get_data()['code'] );
		$this->assertContains( 'access.denied', $this->audit_actions() );
		// HO sees both.
		wp_set_current_user( $this->ho );
		$this->assertCount( 2, $this->rest( 'GET', '/momos/v1/staff/reservations' )->get_data() );
		// Content editor has no reservation capability.
		wp_set_current_user( $this->staff( 'momo_content_editor' ) );
		$this->assertSame( 403, $this->rest( 'GET', '/momos/v1/staff/reservations' )->get_status() );
	}

	public function test_search_by_reference_and_nfkc_ac_res_04_03(): void {
		$id = $this->make_reservation( $this->a, '2026-12-03', '19:00', [ 'public_ref' => '7KQ2M9XR', 'guest_name' => 'ヤマダ タロウ' ] );
		$this->make_reservation( $this->a, '2026-12-03', '19:30', [ 'guest_name' => 'Someone Else' ] );
		wp_set_current_user( $this->rs_a );
		$this->assertSame( [ $id ], array_column( $this->rest( 'GET', '/momos/v1/staff/reservations', null, [], [ 'q' => '7kq2m9xr' ] )->get_data(), 'id' ) );
		$this->assertSame( [ $id ], array_column( $this->rest( 'GET', '/momos/v1/staff/reservations', null, [], [ 'q' => '7KQ2-M9XR' ] )->get_data(), 'id' ) );
		$this->assertSame( [ $id ], array_column( $this->rest( 'GET', '/momos/v1/staff/reservations', null, [], [ 'q' => 'ﾔﾏﾀﾞ' ] )->get_data(), 'id' ) );
		$this->assertSame( 400, $this->rest( 'GET', '/momos/v1/staff/reservations', null, [], [ 'q' => 'x' ] )->get_status() );
		$this->assertSame( 400, $this->rest( 'GET', '/momos/v1/staff/reservations', null, [], [ 'per_page' => '51' ] )->get_status() );
	}

	public function test_overdue_flag_sla_ac_res_04_04(): void {
		$id = $this->make_reservation( $this->a, '2026-12-03', '19:00' );
		$this->freeze( '2026-12-01 12:01:00' ); // 121 min, SLA 120.
		wp_set_current_user( $this->rs_a );
		$rows = $this->rest( 'GET', '/momos/v1/staff/reservations', null, [], [ 'flags' => 'overdue' ] )->get_data();
		$this->assertSame( [ $id ], array_column( $rows, 'id' ) );
		$this->assertSame( 'overdue', $rows[0]['sla_state'] );
	}

	public function test_detail_pii_and_isolation_ac_res_04_02(): void {
		$ra = $this->make_reservation( $this->a, '2026-12-03', '19:00', [ 'special_request' => '<script>alert(1)</script>', 'phone_e164' => '+819012345678' ] );
		$rb = $this->make_reservation( $this->b, '2026-12-03', '19:00' );
		wp_set_current_user( $this->rs_a );
		$r = $this->rest( 'GET', '/momos/v1/staff/reservations/' . $ra );
		$this->assertSame( 200, $r->get_status() );
		$d = $r->get_data();
		$this->assertSame( '+819012345678', $d['phone_e164'] );
		$this->assertSame( '<script>alert(1)</script>', $d['special_request'], 'stored as entered; escaped on output' );
		$this->assertContains( 'accept', $d['allowed_actions'] );
		$this->assertContains( 'reservation.detail_viewed', $this->audit_actions() );
		$count = count( array_keys( $this->audit_actions(), 'reservation.detail_viewed', true ) );
		$this->rest( 'GET', '/momos/v1/staff/reservations/' . $ra );
		$this->assertSame( $count, count( array_keys( $this->audit_actions(), 'reservation.detail_viewed', true ) ), 'deduplicated for 30 min' );
		$x = $this->rest( 'GET', '/momos/v1/staff/reservations/' . $rb );
		$this->assertSame( 403, $x->get_status() );
		$this->assertSame( 'forbidden_branch', $x->get_data()['code'] );
		$this->assertArrayNotHasKey( 'guest_name', (array) $x->get_data()['data'] );
		$this->assertSame( 404, $this->rest( 'GET', '/momos/v1/staff/reservations/999999' )->get_status() );
		// A user without momo_view_customer_pii gets no PII (vendor-style per-user deny).
		$u = get_userdata( $this->rs_a );
		$u->add_cap( 'momo_view_customer_pii', false );
		$d = $this->rest( 'GET', '/momos/v1/staff/reservations/' . $ra )->get_data();
		$this->assertArrayNotHasKey( 'email', $d );
		$this->assertArrayNotHasKey( 'special_request', $d );
		// Meta capability mapping (rule M8).
		$this->assertTrue( user_can( $this->rs_a, 'momo_view_reservation', $ra ) );
		$this->assertFalse( user_can( $this->rs_a, 'momo_view_reservation', $rb ) );
		$this->assertFalse( user_can( $this->rs_a, 'momo_process_reservation', 999999 ) );
	}

	public function test_accept_and_double_accept_ac_res_05_01_02(): void {
		$id = $this->make_reservation( $this->a, '2026-12-03', '19:00' );
		wp_set_current_user( $this->rs_a );
		$r = $this->transition( $id, [ 'action' => 'accept', 'version' => 1, 'message_to_customer' => '<b>See you</b>' ] );
		$this->assertSame( 200, $r->get_status(), wp_json_encode( $r->get_data() ) );
		$this->assertSame( 'confirmed', $r->get_data()['status'] );
		$this->assertSame( 2, $r->get_data()['version'] );
		$this->assertSame( [ 'E02' ], $r->get_data()['notifications_queued'] );
		$this->assertSame( $this->rs_a, $r->get_data()['handled_by']['id'] );
		$row = $this->row( $id );
		$this->assertSame( 'See you', $row['message_to_customer'] );
		$this->assertNotNull( $row['confirmed_at'] );
		$this->assertContains( 'reservation.status_changed', $this->audit_actions() );
		$ev = $this->events( $id );
		$this->assertSame( [ 'status_changed', 'staff', 'pending', 'confirmed', 'See you' ], [ $ev[0]['event_type'], $ev[0]['actor_type'], $ev[0]['from_status'], $ev[0]['to_status'], $ev[0]['note'] ] );
		// Second user with the stale version → invalid_transition; one hook only.
		wp_set_current_user( $this->ho );
		$again = $this->transition( $id, [ 'action' => 'accept', 'version' => 1 ] );
		$this->assertSame( 409, $again->get_status() );
		$this->assertSame( 'invalid_transition', $again->get_data()['code'] );
		$this->assertSame( 'confirmed', $again->get_data()['data']['current']['status'] );
		$this->assertCount( 1, $this->hooks_of( 'status_changed' ) );
	}

	public function test_version_conflict(): void {
		$id = $this->make_reservation( $this->a, '2026-12-03', '19:00' );
		wp_set_current_user( $this->rs_a );
		$this->rest( 'POST', '/momos/v1/staff/reservations/' . $id . '/notes', [ 'type' => 'claim', 'version' => 1 ] );
		$r = $this->transition( $id, [ 'action' => 'accept', 'version' => 1 ] );
		$this->assertSame( 409, $r->get_status() );
		$this->assertSame( 'version_conflict', $r->get_data()['code'] );
		$this->assertSame( 2, $r->get_data()['data']['current']['version'] );
	}

	public function test_decline_and_cancel_rules_ac_res_05_03_04(): void {
		$id = $this->make_reservation( $this->a, '2026-12-03', '19:00' );
		wp_set_current_user( $this->rs_a );
		$r = $this->transition( $id, [ 'action' => 'decline', 'version' => 1 ] );
		$this->assertSame( 400, $r->get_status() );
		$this->assertSame( 'reason_required', $r->get_data()['code'] );
		$this->assertSame( 'reason_required', $this->transition( $id, [ 'action' => 'decline', 'version' => 1, 'reason_code' => 'other' ] )->get_data()['code'] );
		$this->assertSame( 'invalid_param', $this->transition( $id, [ 'action' => 'decline', 'version' => 1, 'reason_code' => 'fully_booked', 'suggested_times' => [ [ 'date' => '2026-11-30', 'time' => '19:00' ] ] ] )->get_data()['code'] );
		$this->assertSame( 'invalid_param', $this->transition( $id, [ 'action' => 'decline', 'version' => 1, 'reason_code' => 'fully_booked', 'message_to_customer' => str_repeat( 'x', 501 ) ] )->get_data()['code'] );
		$ok = $this->transition( $id, [ 'action' => 'decline', 'version' => 1, 'reason_code' => 'fully_booked', 'suggested_times' => [ [ 'date' => '2026-12-04', 'time' => '19:00' ], [ 'date' => '2026-12-05', 'time' => '18:00' ] ] ] );
		$this->assertSame( 'rejected', $ok->get_data()['status'] );
		$this->assertSame( [ [ 'date' => '2026-12-04', 'time' => '19:00' ], [ 'date' => '2026-12-05', 'time' => '18:00' ] ], json_decode( $this->row( $id )['suggested_times'], true ) );
		// Accept on rejected → invalid_transition.
		$this->assertSame( 'invalid_transition', $this->transition( $id, [ 'action' => 'accept', 'version' => 2 ] )->get_data()['code'] );

		$spam = $this->make_reservation( $this->a, '2026-12-04', '19:00', [ 'needs_action_flag' => 1 ] );
		$this->assertSame( 'reason_required', $this->transition( $spam, [ 'action' => 'cancel', 'version' => 1 ] )->get_data()['code'] );
		$r = $this->transition( $spam, [ 'action' => 'cancel', 'version' => 1, 'reason_code' => 'suspected_spam' ] );
		$this->assertSame( [], $r->get_data()['notifications_queued'], 'no E04 for suspected_spam' );
		$row = $this->row( $spam );
		$this->assertSame( [ 'cancelled', 'staff', 'suspected_spam', '0' ], [ $row['status'], $row['cancelled_by'], $row['decision_reason_code'], (string) $row['needs_action_flag'] ] );
		$dup = $this->make_reservation( $this->a, '2026-12-05', '19:00' );
		$this->assertSame( [ 'E04' ], $this->transition( $dup, [ 'action' => 'cancel', 'version' => 1, 'reason_code' => 'duplicate' ] )->get_data()['notifications_queued'] );
	}

	public function test_accept_after_cutoff_expires_ac_res_05_06(): void {
		$id = $this->make_reservation( $this->a, '2026-12-01', '19:00' );
		$this->freeze( '2026-12-01 18:02:00' ); // cutoff 60 → 18:00.
		wp_set_current_user( $this->rs_a );
		$this->assertNotContains( 'accept', ActionPolicy::allowed( $this->row( $id ), $this->rs_a ) );
		$r = $this->transition( $id, [ 'action' => 'accept', 'version' => 1 ] );
		$this->assertSame( 409, $r->get_status() );
		$this->assertSame( 'invalid_transition', $r->get_data()['code'] );
		$this->assertSame( 'staff.err.expired', $r->get_data()['data']['message_key'] );
		$this->assertSame( 'expired', $this->row( $id )['status'] );
		$this->assertSame( 'system', $this->hooks_of( 'status_changed' )[0][4]['type'] );
	}

	public function test_other_branch_manager_cannot_act_ac_res_05_09(): void {
		$id = $this->make_reservation( $this->b, '2026-12-03', '19:00' );
		wp_set_current_user( $this->staff( 'momo_branch_manager', [ $this->a ] ) );
		$r = $this->transition( $id, [ 'action' => 'accept', 'version' => 1 ] );
		$this->assertSame( 403, $r->get_status() );
		$this->assertSame( 'forbidden_branch', $r->get_data()['code'] );
		$this->assertSame( 'pending', $this->row( $id )['status'] );
	}

	public function test_attendance_window_ac_res_09(): void {
		$id = $this->make_reservation( $this->a, '2026-12-01', '19:00', [ 'status' => 'confirmed' ] );
		wp_set_current_user( $this->rs_a );
		$this->freeze( '2026-12-01 18:00:00' );
		$this->assertSame( 'attendance_window', $this->transition( $id, [ 'action' => 'mark_no_show', 'version' => 1 ] )->get_data()['code'] );
		$this->freeze( '2026-12-04 20:00:00' ); // start + 73 h.
		$this->assertSame( 422, $this->transition( $id, [ 'action' => 'mark_no_show', 'version' => 1 ] )->get_status() );
		wp_set_current_user( $this->ho );
		$this->assertSame( 'no_show', $this->transition( $id, [ 'action' => 'mark_no_show', 'version' => 1 ] )->get_data()['status'] );
		$id2 = $this->make_reservation( $this->a, '2026-12-04', '19:00', [ 'status' => 'confirmed' ] );
		wp_set_current_user( $this->rs_a );
		$this->freeze( '2026-12-04 20:30:00' );
		$r = $this->transition( $id2, [ 'action' => 'mark_completed', 'version' => 1 ] );
		$this->assertSame( 'completed', $r->get_data()['status'] );
		$this->assertSame( [], $r->get_data()['notifications_queued'] );
	}

	public function test_claim_takeover_notes_ac_res_04_08(): void {
		$id = $this->make_reservation( $this->a, '2026-12-03', '19:00' );
		wp_set_current_user( $this->rs_a );
		$c = $this->rest( 'POST', '/momos/v1/staff/reservations/' . $id . '/notes', [ 'type' => 'claim', 'version' => 1 ] );
		$this->assertSame( 201, $c->get_status(), wp_json_encode( $c->get_data() ) );
		$this->assertSame( 2, $c->get_data()['version'] );
		$other = $this->staff( 'momo_reservation_staff', [ $this->a ] );
		wp_set_current_user( $other );
		$this->assertContains( 'claim', ActionPolicy::allowed( $this->row( $id ), $other ) );
		$t = $this->rest( 'POST', '/momos/v1/staff/reservations/' . $id . '/notes', [ 'type' => 'claim', 'version' => 2 ] );
		$this->assertSame( 201, $t->get_status() );
		$ev = $this->events( $id );
		$this->assertSame( $this->rs_a, json_decode( $ev[1]['payload'], true )['previous_handler_id'] );
		$this->assertSame( $other, (int) $this->row( $id )['handled_by'] );
		$this->assertContains( 'reservation.claimed', $this->audit_actions() );
		// Notes: no version needed, 1–1,000 chars, tags stripped, version unchanged, never in hooks.
		$n = $this->rest( 'POST', '/momos/v1/staff/reservations/' . $id . '/notes', [ 'type' => 'note', 'text' => '<i>Called guest</i>' ] );
		$this->assertSame( 201, $n->get_status() );
		$this->assertSame( 3, $n->get_data()['version'] );
		$ev = $this->events( $id );
		$this->assertSame( 'Called guest', end( $ev )['note'] );
		$this->assertSame( 400, $this->rest( 'POST', '/momos/v1/staff/reservations/' . $id . '/notes', [ 'type' => 'note', 'text' => str_repeat( 'x', 1001 ) ] )->get_status() );
		$this->assertSame( 400, $this->rest( 'POST', '/momos/v1/staff/reservations/' . $id . '/notes', [ 'type' => 'note' ] )->get_status() );
		$note_audit = array_values( array_filter( $this->audits, static fn( $a ) => 'reservation.note_added' === $a[0] ) );
		$this->assertStringNotContainsString( 'Called guest', wp_json_encode( $note_audit ), 'audit without note text' );
		// Flags.
		global $wpdb;
		$wpdb->update( \Momos\Kernel\Db\Tables::reservations(), [ 'needs_action_flag' => 1, 'customer_notified_flag' => 0 ], [ 'id' => $id ] );
		$this->assertSame( 400, $this->rest( 'POST', '/momos/v1/staff/reservations/' . $id . '/notes', [ 'type' => 'flag_resolved', 'version' => 3 ] )->get_status(), 'text required' );
		$this->assertSame( 201, $this->rest( 'POST', '/momos/v1/staff/reservations/' . $id . '/notes', [ 'type' => 'flag_resolved', 'version' => 3, 'text' => 'Guest moved by phone' ] )->get_status() );
		$this->assertSame( 201, $this->rest( 'POST', '/momos/v1/staff/reservations/' . $id . '/notes', [ 'type' => 'customer_contacted', 'version' => 4 ] )->get_status() );
		$row = $this->row( $id );
		$this->assertSame( [ '0', '1' ], [ (string) $row['needs_action_flag'], (string) $row['customer_notified_flag'] ] );
	}

	public function test_modification_ac_res_10(): void {
		$id = $this->make_reservation( $this->a, '2026-12-03', '19:00', [ 'status' => 'confirmed', 'party_size' => 4, 'needs_action_flag' => 1 ] );
		wp_set_current_user( $this->rs_a );
		$patch = fn( array $body ) => $this->rest( 'PATCH', '/momos/v1/staff/reservations/' . $id, $body );
		$this->assertSame( 'reason_required', $patch( [ 'version' => 1, 'party_size' => 5 ] )->get_data()['code'] );
		$r = $patch( [ 'version' => 1, 'requested_time' => '19:30', 'party_size' => 5, 'reason' => 'agreed_with_guest' ] );
		$this->assertSame( 200, $r->get_status(), wp_json_encode( $r->get_data() ) );
		$this->assertSame( [ 'E06' ], $r->get_data()['notifications_queued'] );
		$row = $this->row( $id );
		$this->assertSame( [ 'confirmed', '19:30:00', '5', '0', '2026-12-04 10:30:00' ], [ $row['status'], $row['requested_time'], (string) $row['party_size'], (string) $row['needs_action_flag'], $row['token_expires_utc'] ] );
		$mod = array_values( array_filter( $this->events( $id ), static fn( $e ) => 'modified' === $e['event_type'] ) )[0];
		$this->assertSame( [ 'before' => [ 'date' => '2026-12-03', 'time' => '19:00', 'party' => 4 ], 'after' => [ 'date' => '2026-12-03', 'time' => '19:30', 'party' => 5 ] ], json_decode( $mod['payload'], true ) );
		$this->assertCount( 1, $this->hooks_of( 'modified' ) );
		// Lead-time breach: RS → 422; RS override → 403 forbidden_field; HO with override → OK.
		$soon = $patch( [ 'version' => 2, 'requested_date' => '2026-12-01', 'requested_time' => '11:30', 'reason' => 'agreed_with_guest' ] );
		$this->assertSame( 'lead_time_not_met', $soon->get_data()['code'] );
		$this->assertSame( 'forbidden_field', $patch( [ 'version' => 2, 'requested_date' => '2026-12-01', 'requested_time' => '11:30', 'reason' => 'agreed_with_guest', 'override_rules' => true, 'override_reason' => 'VIP' ] )->get_data()['code'] );
		wp_set_current_user( $this->ho );
		$ho = $patch( [ 'version' => 2, 'requested_date' => '2026-12-01', 'requested_time' => '11:30', 'party_size' => 12, 'reason' => 'agreed_with_guest', 'override_rules' => true, 'override_reason' => 'Agreed by HO' ] );
		$this->assertSame( 200, $ho->get_status(), wp_json_encode( $ho->get_data() ) );
		$this->assertContains( 'reservation.modified', $this->audit_actions() );
		// After last order → 409 even for HO with override.
		$lo = $patch( [ 'version' => 3, 'requested_date' => '2026-12-03', 'requested_time' => '21:45', 'reason' => 'agreed_with_guest', 'override_rules' => true, 'override_reason' => 'x' ] );
		$this->assertSame( 409, $lo->get_status() );
		$this->assertSame( [ 'time' => 'after_last_order' ], $lo->get_data()['data']['fields'] );
	}

	public function test_modification_moves_expiry_ac_res_10_04(): void {
		$id = $this->make_reservation( $this->a, '2026-12-01', '19:00' );
		wp_set_current_user( $this->rs_a );
		$this->rest( 'PATCH', '/momos/v1/staff/reservations/' . $id, [ 'version' => 1, 'requested_time' => '21:00', 'reason' => 'agreed_with_guest' ] );
		$this->freeze( '2026-12-01 18:30:00' ); // after the old cutoff (18:00), before the new one (20:00).
		\Momos\Reservation\Job\ExpiryJob::run();
		$this->assertSame( 'pending', $this->row( $id )['status'] );
	}

	public function test_service_level_actor_rules(): void {
		$id = $this->make_reservation( $this->a, '2026-12-03', '19:00' );
		try {
			TransitionService::staff( $id, 'expire', [ 'version' => 1 ], Actor::staff( $this->ho ) );
			$this->fail( 'expire is not a staff action' );
		} catch ( ReservationError $e ) {
			$this->assertSame( 'invalid_param', $e->error_code );
		}
	}
}
