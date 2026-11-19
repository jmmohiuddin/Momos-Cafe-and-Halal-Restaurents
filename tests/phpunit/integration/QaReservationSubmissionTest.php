<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/ReservationTestCaseTest.php';

use Momos\Branch\Domain\AccessContext;
use Momos\Branch\Repository\ClosureRepository;
use Momos\Kernel\Db\Tables;

/**
 * QA (reservation team) — API-02 submission, written independently from docs/specs/reservation.md §3.1–3.5
 * and Doc 12: TC-RES-001, 003, 005, 008, 009, 010, 011, 012, 013, 014, 016, 017, 018, 019, 024, 032,
 * 033, 039; AC-RES-01-06/07/08/09/10. Boundaries are exercised at ±1 minute / ±1 day with a frozen clock.
 * All data is fictional (branches QSA/QSB, guests at example.org).
 */
final class QaReservationSubmissionTest extends ReservationTestCaseTest {
	public function set_up(): void {
		parent::set_up();
		// Rate limits are not under test here (separate tests below) — raise them so loops do not trip them.
		update_option( 'momo_rate_ip_per_hour', 1000 );
		update_option( 'momo_rate_email_per_hour', 1000 );
	}

	private function count_rows( string $table ): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore
	}

	private function reservations(): int {
		return $this->count_rows( Tables::reservations() );
	}

	/** @return list<array<string,mixed>> */
	private function notifications( ?int $reservation_id = null ): array {
		global $wpdb;
		$t = Tables::notifications();
		$q = null === $reservation_id ? "SELECT * FROM {$t} ORDER BY id" : $wpdb->prepare( "SELECT * FROM {$t} WHERE reservation_id = %d ORDER BY id", $reservation_id ); // phpcs:ignore
		return (array) $wpdb->get_results( $q, ARRAY_A ); // phpcs:ignore
	}

	private function id_of( \WP_REST_Response $r ): int {
		$row = \Momos\Reservation\Repository\ReservationRepository::find_by_ref( (string) $r->get_data()['reference'] );
		return (int) ( $row['id'] ?? 0 );
	}

	// ------------------------------------------------------------------ TC-RES-009 lead time ±1 min

	public function test_tc_res_009_lead_time_boundary_minus_one_equal_plus_one(): void {
		$this->freeze( '2026-12-01 16:59:00' );
		$this->make_branch( 'QSA' );
		// start = now + L + 1 min → accepted.
		$r = $this->submit( $this->body( 'QSA', '2026-12-01', '19:00', [ 'email' => 'lead1@example.org' ] ) );
		$this->assertSame( 201, $r->get_status(), wp_json_encode( $r->get_data() ) );
		// start = now + L exactly → accepted (rule is start ≥ now + lead).
		$this->freeze( '2026-12-01 17:00:00' );
		$r = $this->submit( $this->body( 'QSA', '2026-12-01', '19:00', [ 'email' => 'lead2@example.org' ] ) );
		$this->assertSame( 201, $r->get_status(), 'start == now + lead_time must be accepted: ' . wp_json_encode( $r->get_data() ) );
		// start = now + L − 1 min → 422 lead_time_not_met with the branch phone, no row.
		$this->freeze( '2026-12-01 17:01:00' );
		$before = $this->reservations();
		$r      = $this->submit( $this->body( 'QSA', '2026-12-01', '19:00', [ 'email' => 'lead3@example.org' ] ) );
		$this->assertSame( 422, $r->get_status() );
		$this->assertSame( 'lead_time_not_met', $r->get_data()['code'] );
		$this->assertSame( '+81600000000', $r->get_data()['data']['branch_phone']['e164'] ?? null, 'phone route (FR-RES-004)' );
		$this->assertStringContainsString( '06-0000-0000', (string) $r->get_data()['message'], 'localised message names the branch phone' );
		$this->assertSame( $before, $this->reservations(), 'no row' );
	}

	// ------------------------------------------------------------------ TC-RES-010 booking window at 23:59 and 00:00 JST

	public function test_tc_res_010_booking_window_last_day_at_2359_and_0000_jst(): void {
		$this->freeze( '2026-12-01 23:59:00' );
		$this->make_branch( 'QSA' );
		// today + 60 = 2027-01-30 accepted; + 61 refused.
		$this->assertSame( 201, $this->submit( $this->body( 'QSA', '2027-01-30', '19:00', [ 'email' => 'w1@example.org' ] ) )->get_status() );
		$r = $this->submit( $this->body( 'QSA', '2027-01-31', '19:00', [ 'email' => 'w2@example.org' ] ) );
		$this->assertSame( 422, $r->get_status() );
		$this->assertSame( 'outside_booking_window', $r->get_data()['code'] );
		// One minute later the JST day rolled over: the window moved by one day.
		$this->freeze( '2026-12-02 00:00:00' );
		$this->assertSame( 201, $this->submit( $this->body( 'QSA', '2027-01-31', '19:00', [ 'email' => 'w3@example.org' ] ) )->get_status(), "'today' is computed in JST" );
		$this->assertSame( 'outside_booking_window', $this->submit( $this->body( 'QSA', '2027-02-01', '19:00', [ 'email' => 'w4@example.org' ] ) )->get_data()['code'] );
		// Yesterday (JST) is outside the window too.
		$this->assertSame( 'outside_booking_window', $this->submit( $this->body( 'QSA', '2026-12-01', '19:00', [ 'email' => 'w5@example.org' ] ) )->get_data()['code'] );
	}

	// ------------------------------------------------------------------ TC-RES-011 last order, gap, grid

	public function test_tc_res_011_last_order_gap_and_off_grid(): void {
		$this->freeze( '2026-12-01 08:00:00' );
		$this->make_branch( 'QSA', [], array_fill_keys( range( 1, 7 ), [ [ '11:30', '15:00', '14:30', 'lunch' ], [ '17:30', '22:00', '21:30', 'dinner' ] ] ) );
		$this->assertSame( 201, $this->submit( $this->body( 'QSA', '2026-12-02', '14:30', [ 'email' => 'lo@example.org' ] ) )->get_status(), 'last order slot accepted' );
		$cases = [
			'15:00' => 'outside_opening_hours',
			'16:00' => 'outside_opening_hours',
			'19:10' => 'not_a_slot',
			'21:45' => 'after_last_order',
			'11:00' => 'outside_opening_hours',
		];
		$before = $this->reservations();
		foreach ( $cases as $time => $reason ) {
			$r = $this->submit( $this->body( 'QSA', '2026-12-02', $time, [ 'email' => 'g' . str_replace( ':', '', $time ) . '@example.org' ] ) );
			$this->assertSame( 409, $r->get_status(), $time );
			$this->assertSame( 'slot_unavailable', $r->get_data()['code'], $time );
			$this->assertSame( $reason, $r->get_data()['data']['fields']['time'] ?? null, "fields.time for {$time}" );
		}
		$this->assertSame( $before, $this->reservations(), 'no rows for refused slots' );
	}

	// ------------------------------------------------------------------ TC-RES-012 cross-midnight

	public function test_tc_res_012_after_midnight_slots_belong_to_next_calendar_date_and_follow_friday_closure(): void {
		$this->freeze( '2026-11-30 10:00:00' ); // Monday.
		$b = $this->make_branch( 'QSA', [], [ 5 => [ [ '18:00', '01:00', '00:30', 'dinner' ] ] ] ); // Friday only.
		$r = $this->submit( $this->body( 'QSA', '2026-12-05', '00:30', [ 'email' => 'night@example.org' ] ) ); // Saturday 00:30.
		$this->assertSame( 201, $r->get_status(), wp_json_encode( $r->get_data() ) );
		$row = $this->row( $this->id_of( $r ) );
		$this->assertSame( '2026-12-05', $row['requested_date'] );
		$this->assertSame( '00:30:00', $row['requested_time'] );
		$this->assertSame( '2026-12-04 15:30:00', $row['requested_start_utc'], 'JST → UTC' );
		$this->assertSame( 'slot_unavailable', $this->submit( $this->body( 'QSA', '2026-12-05', '01:00', [ 'email' => 'n2@example.org' ] ) )->get_data()['code'], 'closing time is not a slot' );
		// A closure on Friday 11 Dec removes Friday's business day including its Saturday tail.
		ClosureRepository::replace_dates( $b, [ '2026-12-11' ], 'closed_all_day', [], 'private_event', '', AccessContext::system() );
		$r = $this->submit( $this->body( 'QSA', '2026-12-12', '00:00', [ 'email' => 'n3@example.org' ] ) );
		$this->assertSame( 409, $r->get_status() );
		$this->assertSame( 'slot_unavailable', $r->get_data()['code'] );
		// A closure on Saturday does not remove Friday's tail running into Saturday (spec §3.3).
		ClosureRepository::replace_dates( $b, [ '2026-12-19' ], 'closed_all_day', [], 'private_event', '', AccessContext::system() );
		$this->assertSame( 201, $this->submit( $this->body( 'QSA', '2026-12-19', '00:30', [ 'email' => 'n4@example.org' ] ) )->get_status() );
	}

	// ------------------------------------------------------------------ TC-RES-013 closures and special hours

	public function test_tc_res_013_closure_and_special_hours_enforced_server_side(): void {
		$this->freeze( '2026-12-01 08:00:00' );
		$b = $this->make_branch( 'QSA' );
		ClosureRepository::replace_dates( $b, [ '2026-12-10' ], 'closed_all_day', [], 'maintenance', '', AccessContext::system() );
		ClosureRepository::replace_dates( $b, [ '2026-12-11' ], 'special_hours', [ [ 'label' => 'all_day', 'opens_at' => '12:00', 'closes_at' => '15:00', 'last_order_at' => '14:30' ] ], 'other', '', AccessContext::system() );
		$r = $this->submit( $this->body( 'QSA', '2026-12-10', '19:00', [ 'email' => 'c1@example.org' ] ) );
		$this->assertSame( 409, $r->get_status() );
		$this->assertSame( 'branch_closed_on_date', $r->get_data()['data']['fields']['time'] ?? null );
		$this->assertSame( 'res.err.date.closed', $r->get_data()['data']['message_key'] );
		$r = $this->submit( $this->body( 'QSA', '2026-12-11', '19:00', [ 'email' => 'c2@example.org' ] ) );
		$this->assertSame( 409, $r->get_status() );
		$this->assertSame( 'outside_opening_hours', $r->get_data()['data']['fields']['time'] ?? null );
		$this->assertSame( 201, $this->submit( $this->body( 'QSA', '2026-12-11', '13:00', [ 'email' => 'c3@example.org' ] ) )->get_status() );
	}

	// ------------------------------------------------------------------ TC-RES-014 branch status variants

	public function test_tc_res_014_not_accepting_variants_return_422_with_phone_and_store_nothing(): void {
		$this->freeze( '2026-12-01 08:00:00' );
		$variants = [
			'temporarily_closed' => [ 'momo_operating_status' => 'temporarily_closed' ],
			'disabled'           => [ 'momo_res_enabled' => '0' ],
			'closed_permanently' => [ 'momo_operating_status' => 'closed_permanently' ],
		];
		$i = 0;
		foreach ( $variants as $label => $meta ) {
			$code = 'QS' . chr( 65 + $i++ );
			$b    = $this->make_branch( $code );
			foreach ( $meta as $k => $v ) {
				update_post_meta( $b, $k, $v );
			}
			\Momos\Reservation\Service\Presenter::flush();
			$r = $this->submit( $this->body( $code, '2026-12-03', '19:00', [ 'email' => "{$label}@example.org" ] ) );
			$this->assertSame( 422, $r->get_status(), $label . ' ' . wp_json_encode( $r->get_data() ) );
			$this->assertSame( 'branch_not_accepting', $r->get_data()['code'], $label );
			$this->assertSame( '06-0000-0000', $r->get_data()['data']['branch_phone']['display'] ?? null, "{$label}: phone route" );
		}
		// Global kill switch.
		$g = $this->make_branch( 'QSG' );
		update_option( 'momo_res_global_enabled', 0 );
		$this->assertSame( 'branch_not_accepting', $this->submit( $this->body( 'QSG', '2026-12-03', '19:00', [ 'email' => 'kill@example.org' ] ) )->get_data()['code'] );
		update_option( 'momo_res_global_enabled', 1 );
		// Unpublished branch → 404 branch_not_found (check 11, spec §3.1).
		wp_update_post( [ 'ID' => $g, 'post_status' => 'draft' ] );
		\Momos\Reservation\Service\Presenter::flush();
		$this->assertSame( 'branch_not_found', $this->submit( $this->body( 'QSG', '2026-12-03', '19:00', [ 'email' => 'draft@example.org' ] ) )->get_data()['code'] );
		$this->assertSame( 0, $this->reservations() );
	}

	// ------------------------------------------------------------------ TC-RES-008 party size boundaries

	public function test_tc_res_008_party_size_min_minus_one_min_max_max_plus_one(): void {
		$this->freeze( '2026-12-01 08:00:00' );
		$this->make_branch( 'QSA', [ 'momo_res_party_min' => '2', 'momo_res_party_max' => '6' ] );
		$rules = $this->rest( 'GET', '/momos/v1/branches/QSA/reservation-rules', null, [], [ 'lang' => 'en' ] )->get_data();
		$this->assertSame( 2, $rules['rules']['party_min'] );
		$this->assertSame( 6, $rules['rules']['party_max'] );
		$r = $this->submit( $this->body( 'QSA', '2026-12-03', '19:00', [ 'party_size' => 1, 'email' => 'p1@example.org' ] ) );
		$this->assertSame( 422, $r->get_status() );
		$this->assertSame( 'party_size_out_of_range', $r->get_data()['code'] );
		$this->assertSame( 'res.err.party.min', $r->get_data()['data']['message_key'] );
		$this->assertSame( 201, $this->submit( $this->body( 'QSA', '2026-12-03', '19:00', [ 'party_size' => 2, 'email' => 'p2@example.org' ] ) )->get_status() );
		$this->assertSame( 201, $this->submit( $this->body( 'QSA', '2026-12-03', '19:00', [ 'party_size' => 6, 'email' => 'p6@example.org' ] ) )->get_status() );
		$r = $this->submit( $this->body( 'QSA', '2026-12-03', '19:00', [ 'party_size' => 7, 'email' => 'p7@example.org' ] ) );
		$this->assertSame( 'party_size_out_of_range', $r->get_data()['code'] );
		$this->assertSame( 'res.err.party.max_call', $r->get_data()['data']['message_key'] );
		$this->assertStringContainsString( '06-0000-0000', (string) $r->get_data()['message'], 'call route with the branch phone (BR-RES-10)' );
		$this->assertSame( 2, $this->reservations() );
	}

	// ------------------------------------------------------------------ TC-RES-003 data minimisation

	public function test_tc_res_003_unknown_or_tampered_fields_rejected_and_status_always_pending(): void {
		$this->freeze( '2026-12-01 08:00:00' );
		$this->make_branch( 'QSA' );
		foreach ( [ 'religion' => 'x', 'nationality' => 'x', 'dob' => '1990-01-01', 'marketing_opt_in' => true, 'status' => 'confirmed', 'deposit' => 1000 ] as $k => $v ) {
			$r = $this->submit( $this->body( 'QSA', '2026-12-03', '19:00', [ $k => $v, 'email' => "{$k}@example.org" ] ) );
			$this->assertSame( 400, $r->get_status(), $k );
			$this->assertSame( 'invalid_param', $r->get_data()['code'], $k );
			$this->assertSame( 'not_allowed', $r->get_data()['data']['fields'][ $k ] ?? null, $k );
		}
		$this->assertSame( 0, $this->reservations() );
		$r = $this->submit( $this->body( 'QSA', '2026-12-03', '19:00' ) );
		$this->assertSame( 'pending', $this->row( $this->id_of( $r ) )['status'] );
	}

	// ------------------------------------------------------------------ TC-RES-005 invalid formats and hostile payloads

	public function test_tc_res_005_invalid_formats_rejected_with_fields_and_no_row(): void {
		$this->freeze( '2026-12-01 08:00:00' );
		$this->make_branch( 'QSA' );
		$cases = [
			[ [ 'email' => 'a@' ], 'email' ],
			[ [ 'email' => 'a b@x.jp' ], 'email' ],
			[ [ 'email' => "a@example.org\r\nBcc: x@example.org" ], 'email' ],
			[ [ 'party_size' => -1 ], 'party_size' ],
			[ [ 'party_size' => 0 ], 'party_size' ],
			[ [ 'party_size' => 'abc' ], 'party_size' ],
			[ [ 'party_size' => 2.5 ], 'party_size' ],
			[ [ 'name' => str_repeat( 'あ', 101 ) ], 'name' ],
			[ [ 'name' => "Aisha\r\nBcc: victim@example.org" ], 'name' ],
			[ [ 'name' => '"><img src=x onerror=alert(1)>' ], 'name' ],
			[ [ 'name' => '   ' ], 'name' ],
			[ [ 'special_request' => str_repeat( 'x', 1001 ) ], 'special_request' ],
			[ [ 'consent' => false ], 'consent' ],
			[ [ 'consent' => 'true' ], 'consent' ],
			[ [ 'date' => '2026-02-30' ], 'date' ],
			[ [ 'time' => '24:00' ], 'time' ],
			[ [ 'lang' => 'fr' ], 'lang' ],
			[ [ 'idempotency_key' => 'not-a-uuid' ], 'idempotency_key' ],
			[ [ 'branch_code' => 'qsa' ], 'branch_code' ],
		];
		foreach ( $cases as [ $over, $field ] ) {
			$r = $this->submit( $this->body( 'QSA', '2026-12-03', '19:00', $over ) );
			$this->assertSame( 400, $r->get_status(), $field . ' ' . wp_json_encode( $over ) . ' → ' . wp_json_encode( $r->get_data() ) );
			$this->assertSame( 'invalid_param', $r->get_data()['code'], $field );
			$this->assertArrayHasKey( $field, (array) ( $r->get_data()['data']['fields'] ?? [] ), $field . ' ' . wp_json_encode( $r->get_data() ) );
		}
		$this->assertSame( 0, $this->reservations(), 'no row for any invalid payload' );
	}

	public function test_tc_res_005_hostile_text_is_stored_inert(): void {
		$this->freeze( '2026-12-01 08:00:00' );
		$this->make_branch( 'QSA' );
		$r = $this->submit(
			$this->body(
				'QSA',
				'2026-12-03',
				'19:00',
				[
					'name'            => "O'Connor ' OR 1=1--",
					'special_request' => "<script>alert(1)</script>=HYPERLINK(\"http://evil.example\",\"x\")\n' OR 1=1--",
				]
			)
		);
		$this->assertSame( 201, $r->get_status(), wp_json_encode( $r->get_data() ) );
		$row = $this->row( $this->id_of( $r ) );
		$this->assertSame( "O'Connor ' OR 1=1--", $row['guest_name'], 'SQL-looking text stored literally' );
		$this->assertStringNotContainsString( '<script', (string) $row['special_request'], 'tags stripped from the special request' );
		$this->assertStringContainsString( '=HYPERLINK(', (string) $row['special_request'], 'formula text kept as data (escaped on export)' );
		$this->assertSame( 1, $this->reservations() );
	}

	// ------------------------------------------------------------------ TC-RES-016 calendar edge dates

	public function test_tc_res_016_year_end_new_year_and_leap_day(): void {
		$this->freeze( '2027-12-31 08:00:00' );
		$this->make_branch( 'QSA' );
		$cases = [ [ '2027-12-31', '19:00', '2027-12-31 10:00:00' ], [ '2028-01-01', '12:00', '2028-01-01 03:00:00' ], [ '2028-02-29', '19:00', '2028-02-29 10:00:00' ] ];
		foreach ( $cases as $i => [ $date, $time, $utc ] ) {
			$r = $this->submit( $this->body( 'QSA', $date, $time, [ 'email' => "edge{$i}@example.org" ] ) );
			$this->assertSame( 201, $r->get_status(), $date . ' ' . wp_json_encode( $r->get_data() ) );
			$row = $this->row( $this->id_of( $r ) );
			$this->assertSame( $date, $row['requested_date'] );
			$this->assertSame( $utc, $row['requested_start_utc'] );
		}
		// 29 Feb 2028 is the 60th day: 1 Mar is outside.
		$this->assertSame( 'outside_booking_window', $this->submit( $this->body( 'QSA', '2028-03-01', '19:00', [ 'email' => 'edge9@example.org' ] ) )->get_data()['code'] );
	}

	// ------------------------------------------------------------------ TC-RES-017 idempotency

	public function test_tc_res_017_same_key_three_times_one_row_same_ref_one_e01_one_e07(): void {
		$this->freeze( '2026-12-01 08:00:00' );
		$b = $this->make_branch(
			'QSA',
			[ 'momo_res_notification_recipients' => wp_json_encode( [ [ 'email' => 'qsa-one@momos.example', 'language' => 'ja', 'active' => true ], [ 'email' => 'qsa-two@momos.example', 'language' => 'en', 'active' => true ], [ 'email' => 'qsa-off@momos.example', 'language' => 'en', 'active' => false ] ] ) ]
		);
		$body  = $this->body( 'QSA', '2026-12-03', '19:00' );
		$first = $this->submit( $body );
		$this->assertSame( 201, $first->get_status() );
		$this->assertFalse( $first->get_data()['duplicate'] );
		foreach ( [ 1, 2 ] as $n ) {
			$again = $this->submit( $body );
			$this->assertSame( 200, $again->get_status(), "replay {$n}" );
			$this->assertSame( $first->get_data()['reference'], $again->get_data()['reference'] );
			$this->assertFalse( $again->get_data()['duplicate'], 'replay is not a soft duplicate' );
			if ( isset( $first->get_data()['status_url'] ) ) {
				$this->assertSame( $first->get_data()['status_url'], $again->get_data()['status_url'] ?? null, 'status_url re-derived identically' );
			}
		}
		$this->assertSame( 1, $this->reservations() );
		$rows = $this->notifications();
		$this->assertCount( 1, array_filter( $rows, static fn( $n ) => 'E01' === $n['template_key'] ), 'exactly one E01' );
		$e07 = array_values( array_filter( $rows, static fn( $n ) => 'E07' === $n['template_key'] ) );
		$this->assertCount( 2, $e07, 'one E07 per ACTIVE branch recipient' );
		$this->assertEqualsCanonicalizing( [ 'q***@m***.example', 'q***@m***.example' ], array_column( $e07, 'recipient_masked' ) );
		$this->assertSame( [ 'branch', 'branch' ], array_column( $e07, 'recipient_type' ) );
		$this->assertNotContains( 'qsa-off@momos.example', array_column( $e07, 'recipient_email' ), 'inactive recipient never notified' );
		unset( $b );
	}

	// ------------------------------------------------------------------ TC-RES-018 soft duplicate window

	public function test_tc_res_018_soft_duplicate_window_9m59s_and_10m01s_confirmed_and_at_pending_limit(): void {
		$this->freeze( '2026-12-01 08:00:00' );
		$this->make_branch( 'QSA' );
		$data = [ 'email' => 'soft@example.org' ];
		$r1   = $this->submit( $this->body( 'QSA', '2026-12-03', '19:00', $data ) );
		$this->assertSame( 201, $r1->get_status() );
		// +9 min 59 s, new form load (new key) → R1's reference, no row, no status_url.
		$this->freeze( '2026-12-01 08:09:59' );
		$d = $this->submit( $this->body( 'QSA', '2026-12-03', '19:00', $data ) );
		$this->assertSame( 200, $d->get_status() );
		$this->assertTrue( $d->get_data()['duplicate'] );
		$this->assertSame( $r1->get_data()['reference'], $d->get_data()['reference'] );
		$this->assertArrayNotHasKey( 'status_url', $d->get_data(), 'soft duplicate never carries a status link' );
		$this->assertSame( 1, $this->reservations() );
		// +10 min 01 s → new pending row.
		$this->freeze( '2026-12-01 08:10:01' );
		$r2 = $this->submit( $this->body( 'QSA', '2026-12-03', '19:00', $data ) );
		$this->assertSame( 201, $r2->get_status(), wp_json_encode( $r2->get_data() ) );
		$this->assertNotSame( $r1->get_data()['reference'], $r2->get_data()['reference'] );
		// Step 4: a confirmed request is covered too.
		$r4 = $this->submit( $this->body( 'QSA', '2026-12-04', '19:00', $data ) );
		$id4 = $this->id_of( $r4 );
		$rs  = $this->staff( 'momo_reservation_staff', [ (int) $this->row( $id4 )['branch_id'] ] );
		wp_set_current_user( $rs );
		$this->assertSame( 200, $this->rest( 'POST', '/momos/v1/staff/reservations/' . $id4 . '/transitions', [ 'action' => 'accept', 'version' => 1 ] )->get_status() );
		wp_set_current_user( 0 );
		$this->freeze( '2026-12-01 08:15:00' );
		$d4 = $this->submit( $this->body( 'QSA', '2026-12-04', '19:00', $data ) );
		$this->assertSame( 200, $d4->get_status() );
		$this->assertTrue( $d4->get_data()['duplicate'] );
		$this->assertSame( $r4->get_data()['reference'], $d4->get_data()['reference'] );
		// Step 5: at the pending limit (3 pending: R1, R2 and R5) a resubmission of R5 within 10 min is a soft duplicate, not 409.
		$r5 = $this->submit( $this->body( 'QSA', '2026-12-05', '19:00', $data ) );
		$this->assertSame( 201, $r5->get_status() );
		$d5 = $this->submit( $this->body( 'QSA', '2026-12-05', '19:00', $data ) );
		$this->assertSame( 200, $d5->get_status(), 'soft-duplicate check precedes the pending limit: ' . wp_json_encode( $d5->get_data() ) );
		$this->assertSame( $r5->get_data()['reference'], $d5->get_data()['reference'] );
	}

	// ------------------------------------------------------------------ TC-RES-019 pending limit, case-insensitive

	public function test_tc_res_019_pending_limit_case_insensitive_per_branch_and_freed_by_decline(): void {
		$this->freeze( '2026-12-01 08:00:00' );
		$a = $this->make_branch( 'QSA' );
		$this->make_branch( 'QSB' );
		$variants = [ 'Mixed.Case@Example.ORG', 'mixed.case@example.org', 'MIXED.CASE@example.org' ];
		$ids      = [];
		foreach ( $variants as $i => $email ) {
			$r = $this->submit( $this->body( 'QSA', '2026-12-0' . ( $i + 3 ), '19:00', [ 'email' => $email ] ) );
			$this->assertSame( 201, $r->get_status(), $email );
			$ids[] = $this->id_of( $r );
		}
		$r = $this->submit( $this->body( 'QSA', '2026-12-08', '19:00', [ 'email' => 'mixed.CASE@example.org' ] ) );
		$this->assertSame( 409, $r->get_status(), 'the 4th pending request with a differently cased address: ' . wp_json_encode( $r->get_data() ) );
		$this->assertSame( 'pending_limit_reached', $r->get_data()['code'] );
		$this->assertStringContainsString( '3', (string) $r->get_data()['message'], 'localised message names the limit' );
		$this->assertSame( 3, $this->reservations(), 'no 4th row' );
		$this->assertSame( 201, $this->submit( $this->body( 'QSB', '2026-12-08', '19:00', [ 'email' => 'mixed.case@example.org' ] ) )->get_status(), 'other branch accepted' );
		// Decline one → the next request at QSA is accepted.
		$rs = $this->staff( 'momo_reservation_staff', [ $a ] );
		wp_set_current_user( $rs );
		$this->assertSame( 200, $this->rest( 'POST', '/momos/v1/staff/reservations/' . $ids[0] . '/transitions', [ 'action' => 'decline', 'version' => 1, 'reason_code' => 'fully_booked' ] )->get_status() );
		wp_set_current_user( 0 );
		$this->assertSame( 201, $this->submit( $this->body( 'QSA', '2026-12-08', '19:00', [ 'email' => 'mixed.case@example.org' ] ) )->get_status() );
	}

	// ------------------------------------------------------------------ TC-RES-024 no capacity logic

	public function test_tc_res_024_two_customers_same_slot_both_stored(): void {
		$this->freeze( '2026-12-01 08:00:00' );
		$this->make_branch( 'QSA' );
		$this->assertSame( 201, $this->submit( $this->body( 'QSA', '2026-12-03', '19:00', [ 'email' => 'one@example.org' ] ) )->get_status() );
		$this->assertSame( 201, $this->submit( $this->body( 'QSA', '2026-12-03', '19:00', [ 'email' => 'two@example.org' ] ) )->get_status() );
		$this->assertSame( 2, $this->reservations() );
	}

	// ------------------------------------------------------------------ TC-RES-032 phone normalisation

	public function test_tc_res_032_phone_fixture_f_phone(): void {
		$this->freeze( '2026-12-01 08:00:00' );
		$this->make_branch( 'QSA' );
		$valid = [
			'090-0000-0101'       => '+819000000101',
			'03-0000-0101'        => '+81300000101',
			'+81 (0)90 0000 0101' => '+819000000101',
			'+880 1700-000000'    => '+8801700000000',
			'+92 300 0000000'     => '+923000000000',
			'+971 50 000 0000'    => '+971500000000',
			'+44 7700 900123'     => '+447700900123',
			'０９０-０００１-０２０２' => '+819000010202',
		];
		$i = 0;
		foreach ( $valid as $in => $e164 ) {
			$r = $this->submit( $this->body( 'QSA', '2026-12-03', '19:00', [ 'phone' => $in, 'email' => 'ph' . ( ++$i ) . '@example.org' ] ) );
			$this->assertSame( 201, $r->get_status(), "{$in}: " . wp_json_encode( $r->get_data() ) );
			$this->assertSame( $e164, $this->row( $this->id_of( $r ) )['phone_e164'], $in );
		}
		$r = $this->submit( $this->body( 'QSA', '2026-12-03', '19:00', [ 'phone' => 'abc-123', 'email' => 'phx@example.org' ] ) );
		$this->assertSame( 400, $r->get_status() );
		$this->assertSame( 'invalid_format', $r->get_data()['data']['fields']['phone'] ?? null );
		$r = $this->submit( $this->body( 'QSA', '2026-12-03', '19:00', [ 'phone' => '', 'email' => 'phe@example.org' ] ) );
		$this->assertSame( 201, $r->get_status() );
		$this->assertNull( $this->row( $this->id_of( $r ) )['phone_e164'] );
	}

	// ------------------------------------------------------------------ TC-RES-033 scripts and emoji

	public function test_tc_res_033_six_scripts_and_emoji_round_trip_in_db_api11_and_e07(): void {
		$this->freeze( '2026-12-01 08:00:00' );
		$b     = $this->make_branch( 'QSA' );
		$names = [ '山田 太郎', 'محمد علي', 'عائشہ خان', 'अमित शर्मा', 'রহিম উদ্দিন', "O'Connor-Smith" ];
		$ho    = $this->staff( 'momo_head_office' );
		foreach ( $names as $i => $name ) {
			$sr = 'Birthday 🎂🎉 ' . $name;
			$r  = $this->submit( $this->body( 'QSA', '2026-12-03', '19:00', [ 'name' => $name, 'special_request' => $sr, 'email' => "script{$i}@example.org" ] ) );
			$this->assertSame( 201, $r->get_status(), $name );
			$id  = $this->id_of( $r );
			$row = $this->row( $id );
			$this->assertSame( $name, $row['guest_name'], 'DB keeps the exact characters' );
			$this->assertSame( $sr, $row['special_request'], 'emoji kept (utf8mb4)' );
			wp_set_current_user( $ho );
			$detail = $this->rest( 'GET', '/momos/v1/staff/reservations/' . $id )->get_data();
			wp_set_current_user( 0 );
			$this->assertSame( $name, $detail['guest_name'] );
			$this->assertSame( $sr, $detail['special_request'] );
		}
		unset( $b );
	}

	// ------------------------------------------------------------------ TC-RES-001 atomic T1 incl. E01/E07

	/**
	 * TC-RES-001 / reservation.md §3.4 / notifications.md §4.1: "E01 and E07 queued in the same transaction".
	 * Fault injection: every INSERT into momo_notifications fails. A request must then either be refused
	 * as a whole (503, nothing stored) or be stored WITH its E01/E07 rows — never stored silently without
	 * the guest acknowledgement and the branch alert.
	 */
	public function test_tc_res_001_reservation_never_committed_without_its_e01_and_e07_rows(): void {
		$this->freeze( '2026-12-01 08:00:00' );
		$this->make_branch( 'QSA' );
		$t      = Tables::notifications();
		$breaks = static function ( string $sql ) use ( $t ): string {
			return str_starts_with( ltrim( $sql ), "INSERT INTO {$t}" ) ? 'INSERT INTO momos_qa_no_such_table VALUES (1)' : $sql;
		};
		add_filter( 'query', $breaks );
		$r = $this->submit( $this->body( 'QSA', '2026-12-03', '19:00', [ 'email' => 'atomic@example.org' ] ) );
		remove_filter( 'query', $breaks );
		$stored = $this->reservations();
		$queued = count( $this->notifications() );
		if ( 1 === $stored ) {
			$this->assertSame( 2, $queued, 'a stored request must have its E01 + E07 rows (same transaction); got HTTP ' . $r->get_status() . ' with ' . $queued . ' notification rows' );
		} else {
			$this->assertSame( 503, $r->get_status() );
			$this->assertSame( 'service_unavailable', $r->get_data()['code'] );
		}
	}

	// ------------------------------------------------------------------ TC-RES-039 DB failure → 503, nothing stored

	public function test_tc_res_039_database_failure_returns_503_nothing_stored_then_same_key_succeeds_once(): void {
		$this->freeze( '2026-12-01 08:00:00' );
		$this->make_branch( 'QSA' );
		$t      = Tables::reservations();
		$breaks = static function ( string $sql ) use ( $t ): string {
			return str_starts_with( ltrim( $sql ), "INSERT INTO `{$t}`" ) || str_starts_with( ltrim( $sql ), "INSERT INTO {$t}" ) ? 'INSERT INTO momos_qa_no_such_table VALUES (1)' : $sql;
		};
		$body = $this->body( 'QSA', '2026-12-03', '19:00', [ 'lang' => 'ar', 'preferred_lang' => 'ar' ] );
		add_filter( 'query', $breaks );
		$r = $this->submit( $body );
		remove_filter( 'query', $breaks );
		$this->assertSame( 503, $r->get_status(), wp_json_encode( $r->get_data() ) );
		$this->assertSame( 'service_unavailable', $r->get_data()['code'] );
		$this->assertNotSame( '', (string) $r->get_data()['message'] );
		$this->assertSame( 0, $this->reservations() );
		$this->assertSame( 0, count( $this->notifications() ) );
		// Retry with the same idempotency key → exactly one row and one E01.
		$this->assertSame( 201, $this->submit( $body )->get_status() );
		$this->assertSame( 1, $this->reservations() );
		$this->assertCount( 1, array_filter( $this->notifications(), static fn( $n ) => 'E01' === $n['template_key'] ) );
	}

	// ------------------------------------------------------------------ TC-RES-022 abuse controls give no hint

	public function test_tc_res_022_honeypot_and_failed_captcha_are_indistinguishable_and_store_nothing(): void {
		$this->freeze( '2026-12-01 08:00:00' );
		$this->make_branch( 'QSA' );
		$h = $this->submit( $this->body( 'QSA', '2026-12-03', '19:00', [ 'website' => 'x', 'email' => 'hp@example.org' ] ) );
		$c = $this->submit( $this->body( 'QSA', '2026-12-03', '19:00', [ 'captcha_token' => 'test-fail', 'email' => 'cf@example.org' ] ) );
		$this->assertSame( [ 403, 'captcha_failed' ], [ $h->get_status(), $h->get_data()['code'] ] );
		$this->assertSame( [ 403, 'captcha_failed' ], [ $c->get_status(), $c->get_data()['code'] ] );
		$this->assertSame( $c->get_data()['message'], $h->get_data()['message'], 'no hint which check failed' );
		$this->assertSame( $c->get_data()['data']['message_key'], $h->get_data()['data']['message_key'] );
		$this->assertSame( 0, $this->reservations() );
	}

	public function test_tc_res_023_captcha_outage_accepts_up_to_the_degraded_limit_and_flags_rows(): void {
		$this->freeze( '2026-12-01 08:00:00' );
		$this->make_branch( 'QSA' );
		update_option( 'momo_rate_ip_per_hour', 5 );
		update_option( 'momo_rate_ip_degraded_per_hour', 2 );
		$codes = [];
		for ( $i = 1; $i <= 4; $i++ ) {
			$r       = $this->submit( $this->body( 'QSA', '2026-12-0' . ( $i + 2 ), '19:00', [ 'captcha_token' => 'test-outage', 'email' => "out{$i}@example.org" ] ) );
			$codes[] = $r->get_status();
		}
		// 1st request discovers the outage (counted on res_ip), then res_ip_degraded (2/h) applies.
		$this->assertSame( 201, $codes[0] );
		$this->assertContains( 429, $codes, 'stricter limit applies during the outage: ' . wp_json_encode( $codes ) );
		$this->assertLessThanOrEqual( 3, count( array_filter( $codes, static fn( $c ) => 201 === $c ) ), wp_json_encode( $codes ) );
		global $wpdb;
		$statuses = $wpdb->get_col( 'SELECT DISTINCT captcha_status FROM ' . Tables::reservations() ); // phpcs:ignore
		$this->assertSame( [ 'unverified_outage' ], $statuses );
		$this->assertContains( 'security.captcha_degraded', $this->audit_actions() );
	}
}
