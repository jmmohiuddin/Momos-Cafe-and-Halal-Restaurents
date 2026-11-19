<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/ReservationTestCaseTest.php';

use Momos\Branch\Domain\AccessContext;
use Momos\Branch\Repository\ClosureRepository;
use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Db\Tables;
use Momos\Notification\Queue\Dispatcher;
use Momos\Notification\Transport\Message;
use Momos\Notification\Transport\SendResult;
use Momos\Notification\Transport\TransportInterface;
use Momos\Reservation\Job\ExpiryJob;
use Momos\Reservation\Job\SlaJob;
use Momos\Reservation\Service\TokenService;

/**
 * QA (reservation team) — status lifecycle by staff, customer and system with a frozen clock, written
 * from docs/specs/reservation.md §3.6–3.13, docs/specs/notifications.md §4.1–4.5 and Doc 12:
 * TC-RSA-004/005/006/007/008/009/010/012/013/014/016/017/021/022/025, TC-RES-028/029/042,
 * AC-RES-03/05/07/08/09/10, BR-RES-12/13. Every transition is checked for its emails (rows queued in
 * momo_notifications with the documented template, variant and recipients) and for exactly-once effects.
 * Fictional data: branches QLA/QLB, guests at example.org, recipients at momos.example.
 */
final class QaReservationLifecycleTest extends ReservationTestCaseTest {
	private int $a;
	private int $b;
	private int $rs_a;
	private int $bm_a;
	private int $rs_b;
	private int $ho;

	public function set_up(): void {
		parent::set_up();
		update_option( 'momo_rate_ip_per_hour', 1000 );
		update_option( 'momo_rate_email_per_hour', 1000 );
		update_option( 'momo_rate_cancel_ip_per_hour', 1000 );
		update_option( 'momo_rate_status_ip_per_hour', 1000 );
		update_option( 'momo_ho_escalation_recipients', [ [ 'email' => 'ho-desk@momos.example', 'language' => 'en', 'active' => '1' ] ] );
		\Momos\Kernel\Settings::set( 'momo_ho_escalation_recipients', [ [ 'email' => 'ho-desk@momos.example', 'language' => 'en', 'active' => '1' ] ] );
		$this->freeze( '2026-12-01 10:00:00' );
		$recipients = wp_json_encode(
			[
				[ 'email' => 'qla-inbox@momos.example', 'language' => 'ja', 'active' => true ],
				[ 'email' => 'qla-manager@momos.example', 'language' => 'en', 'active' => true ],
			]
		);
		$this->a    = $this->make_branch( 'QLA', [ 'momo_res_notification_recipients' => $recipients ] );
		$this->b    = $this->make_branch( 'QLB' );
		$this->rs_a = $this->staff( 'momo_reservation_staff', [ $this->a ] );
		$this->bm_a = $this->staff( 'momo_branch_manager', [ $this->a ] );
		$this->rs_b = $this->staff( 'momo_reservation_staff', [ $this->b ] );
		$this->ho   = $this->staff( 'momo_head_office' );
	}

	public function tear_down(): void {
		delete_option( 'momo_ho_escalation_recipients' );
		\Momos\Kernel\Settings::set( 'momo_ho_escalation_recipients', [] );
		\Momos\Kernel\Settings::flush();
		parent::tear_down();
	}

	// ------------------------------------------------------------------ helpers

	private function as( int $user ): void {
		wp_set_current_user( $user );
	}

	private function transition( int $id, array $body, ?int $user = null ): \WP_REST_Response {
		$this->as( $user ?? $this->rs_a );
		return $this->rest( 'POST', '/momos/v1/staff/reservations/' . $id . '/transitions', $body );
	}

	private function note( int $id, array $body, ?int $user = null ): \WP_REST_Response {
		$this->as( $user ?? $this->rs_a );
		return $this->rest( 'POST', '/momos/v1/staff/reservations/' . $id . '/notes', $body );
	}

	private function patch( int $id, array $body, int $user ): \WP_REST_Response {
		$this->as( $user );
		return $this->rest( 'PATCH', '/momos/v1/staff/reservations/' . $id, $body );
	}

	/** @return list<array<string,mixed>> */
	private function notifs( int $id, ?string $template = null ): array {
		global $wpdb;
		$t    = Tables::notifications();
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE reservation_id = %d ORDER BY id", $id ), ARRAY_A ); // phpcs:ignore
		return array_values( array_filter( $rows, static fn( $r ) => null === $template || $r['template_key'] === $template ) );
	}

	/** Pending reservation created through API-02 (so E01/E07 and the token exist). */
	private function submitted( string $code, string $date, string $time, array $over = [] ): int {
		$this->as( 0 );
		$r = $this->submit( $this->body( $code, $date, $time, $over ) );
		$this->assertSame( 201, $r->get_status(), wp_json_encode( $r->get_data() ) );
		return (int) \Momos\Reservation\Repository\ReservationRepository::find_by_ref( (string) $r->get_data()['reference'] )['id'];
	}

	private function token( int $id ): string {
		$row   = $this->row( $id );
		$token = TokenService::token_for( $row );
		if ( TokenService::hash( $token ) !== $row['token_hash'] ) { // rows inserted directly by the fixture
			global $wpdb;
			$wpdb->update( Tables::reservations(), [ 'token_hash' => TokenService::hash( $token ) ], [ 'id' => $id ] );
		}
		return $token;
	}

	private function status_api( int $id, ?string $token = null, array $query = [ 'lang' => 'en' ] ): \WP_REST_Response {
		$this->as( 0 );
		return $this->rest( 'GET', '/momos/v1/reservations/' . $this->row( $id )['public_ref'], null, [ 'X-Momo-Token' => $token ?? $this->token( $id ) ], $query );
	}

	private function cancel_api( int $id, ?string $token = null ): \WP_REST_Response {
		$this->as( 0 );
		return $this->rest( 'POST', '/momos/v1/reservations/' . $this->row( $id )['public_ref'] . '/cancel', [ 'lang' => 'en' ], [ 'X-Momo-Token' => $token ?? $this->token( $id ) ] );
	}

	private function stub_transport( callable $fn ): void {
		add_filter(
			'momos/notification/transport',
			static fn() => new class( $fn ) implements TransportInterface {
				/** @var callable */
				private $fn;

				public function __construct( callable $fn ) {
					$this->fn = $fn;
				}

				public function name(): string {
					return 'qa-stub';
				}

				public function send( Message $m ): SendResult {
					return ( $this->fn )( $m );
				}
			}
		);
	}

	// ------------------------------------------------------------------ TC-RSA-004 accept

	public function test_tc_rsa_004_accept_500_char_message_ok_501_rejected_e02_only_to_customer(): void {
		$r1 = $this->submitted( 'QLA', '2026-12-03', '19:00' );
		$r2 = $this->submitted( 'QLA', '2026-12-03', '19:30', [ 'email' => 'other@example.org' ] );
		$bad = $this->transition( $r2, [ 'action' => 'accept', 'version' => 1, 'message_to_customer' => str_repeat( 'm', 501 ) ] );
		$this->assertSame( 400, $bad->get_status() );
		$this->assertSame( 'invalid_param', $bad->get_data()['code'] );
		$this->assertSame( 'too_long', $bad->get_data()['data']['fields']['message_to_customer'] ?? null );
		$this->assertSame( 'pending', $this->row( $r2 )['status'] );

		$ok = $this->transition( $r1, [ 'action' => 'accept', 'version' => 1, 'message_to_customer' => str_repeat( 'm', 500 ) ] );
		$this->assertSame( 200, $ok->get_status(), wp_json_encode( $ok->get_data() ) );
		$this->assertSame( 'confirmed', $ok->get_data()['status'] );
		$this->assertSame( 2, $ok->get_data()['version'] );
		$this->assertSame( [ 'E02' ], $ok->get_data()['notifications_queued'] );
		$row = $this->row( $r1 );
		$this->assertNotEmpty( $row['confirmed_at'] );
		$this->assertSame( (string) $this->rs_a, (string) $row['handled_by'], 'handled_by set when null' );
		$e02 = $this->notifs( $r1, 'E02' );
		$this->assertCount( 1, $e02 );
		$this->assertSame( 'customer', $e02[0]['recipient_type'] );
		$this->assertSame( 'en', $e02[0]['lang'], 'preferred language' );
		$this->assertSame( [], array_filter( $this->notifs( $r1 ), static fn( $n ) => 'customer' !== $n['recipient_type'] && 'E07' !== $n['template_key'] ), 'no staff email on accept' );
		$this->assertContains( 'reservation.status_changed', $this->audit_actions() );
	}

	// ------------------------------------------------------------------ TC-RSA-005 decline

	public function test_tc_rsa_005_decline_reason_required_max_two_future_suggestions_e03(): void {
		$id = $this->submitted( 'QLA', '2026-12-03', '19:00' );
		$r  = $this->transition( $id, [ 'action' => 'decline', 'version' => 1 ] );
		$this->assertSame( [ 400, 'reason_required' ], [ $r->get_status(), $r->get_data()['code'] ] );
		$three = [ [ 'date' => '2026-12-04', 'time' => '19:00' ], [ 'date' => '2026-12-05', 'time' => '19:00' ], [ 'date' => '2026-12-06', 'time' => '19:00' ] ];
		$r     = $this->transition( $id, [ 'action' => 'decline', 'version' => 1, 'reason_code' => 'fully_booked', 'suggested_times' => $three ] );
		$this->assertSame( [ 400, 'invalid_param' ], [ $r->get_status(), $r->get_data()['code'] ] );
		$this->assertArrayHasKey( 'suggested_times', $r->get_data()['data']['fields'] );
		$past = $this->transition( $id, [ 'action' => 'decline', 'version' => 1, 'reason_code' => 'fully_booked', 'suggested_times' => [ [ 'date' => '2026-11-30', 'time' => '19:00' ] ] ] );
		$this->assertSame( 400, $past->get_status(), 'past suggestion refused' );
		$other = $this->transition( $id, [ 'action' => 'decline', 'version' => 1, 'reason_code' => 'other' ] );
		$this->assertSame( [ 400, 'reason_required' ], [ $other->get_status(), $other->get_data()['code'] ], 'reason other needs a message or note' );
		$bad = $this->transition( $id, [ 'action' => 'decline', 'version' => 1, 'reason_code' => 'suspected_spam' ] );
		$this->assertSame( 400, $bad->get_status(), 'cancel reason is not a decline reason' );
		$this->assertSame( 'pending', $this->row( $id )['status'] );
		$this->assertSame( [], $this->notifs( $id, 'E03' ) );

		// 2 suggestions; one is not a slot (03:00) — informational only, not validated as a slot.
		$two = [ [ 'date' => '2026-12-04', 'time' => '19:00' ], [ 'date' => '2026-12-05', 'time' => '03:00' ] ];
		$ok  = $this->transition( $id, [ 'action' => 'decline', 'version' => 1, 'reason_code' => 'fully_booked', 'suggested_times' => $two, 'message_to_customer' => 'Sorry!' ] );
		$this->assertSame( 200, $ok->get_status(), wp_json_encode( $ok->get_data() ) );
		$row = $this->row( $id );
		$this->assertSame( 'rejected', $row['status'] );
		$this->assertSame( 'fully_booked', $row['decision_reason_code'] );
		$this->assertSame( $two, json_decode( (string) $row['suggested_times'], true ) );
		$this->assertCount( 1, $this->notifs( $id, 'E03' ) );
		// Customer view shows reason label, message and both times.
		$s = $this->status_api( $id )->get_data();
		$this->assertSame( 'rejected', $s['status'] );
		$this->assertSame( 'The branch is fully booked at that time.', $s['decline_reason_label'] );
		$this->assertSame( 'Sorry!', $s['message_from_branch'] );
		$this->assertCount( 2, $s['suggested_times'] );
	}

	// ------------------------------------------------------------------ TC-RSA-006 staff cancel

	public function test_tc_rsa_006_staff_cancel_reasons_and_e04_suppression_only_for_spam(): void {
		$p    = $this->submitted( 'QLA', '2026-12-03', '19:00', [ 'email' => 'c1@example.org' ] );
		$c    = $this->submitted( 'QLA', '2026-12-03', '19:30', [ 'email' => 'c2@example.org' ] );
		$spam = $this->submitted( 'QLA', '2026-12-03', '20:00', [ 'email' => 'c3@example.org' ] );
		$dup  = $this->submitted( 'QLA', '2026-12-03', '20:30', [ 'email' => 'c4@example.org' ] );
		$this->assertSame( 200, $this->transition( $c, [ 'action' => 'accept', 'version' => 1 ] )->get_status() );
		$r = $this->transition( $p, [ 'action' => 'cancel', 'version' => 1 ] );
		$this->assertSame( [ 400, 'reason_required' ], [ $r->get_status(), $r->get_data()['code'] ] );
		$r = $this->transition( $p, [ 'action' => 'cancel', 'version' => 1, 'reason_code' => 'fully_booked' ] );
		$this->assertSame( 400, $r->get_status(), 'decline reason is not a cancel reason' );

		$this->assertSame( 200, $this->transition( $p, [ 'action' => 'cancel', 'version' => 1, 'reason_code' => 'branch_unable_to_serve' ] )->get_status() );
		$this->assertSame( 200, $this->transition( $c, [ 'action' => 'cancel', 'version' => 2, 'reason_code' => 'customer_request_by_phone' ] )->get_status(), 'T6b confirmed → cancelled' );
		$spam_r = $this->transition( $spam, [ 'action' => 'cancel', 'version' => 1, 'reason_code' => 'suspected_spam' ] );
		$this->assertSame( [], $spam_r->get_data()['notifications_queued'] );
		$this->assertSame( 200, $this->transition( $dup, [ 'action' => 'cancel', 'version' => 1, 'reason_code' => 'duplicate' ] )->get_status() );
		foreach ( [ $p, $c, $dup ] as $id ) {
			$row = $this->row( $id );
			$this->assertSame( [ 'cancelled', 'staff' ], [ $row['status'], $row['cancelled_by'] ] );
			$e04 = $this->notifs( $id, 'E04' );
			$this->assertCount( 1, $e04, "E04 for {$row['decision_reason_code']}" );
			$this->assertSame( 'by_branch', $e04[0]['template_variant'] );
		}
		$this->assertSame( [], $this->notifs( $spam, 'E04' ), 'suspected_spam → no customer email' );
		$this->assertSame( [], $this->notifs( $p, 'E09' ), 'E09 only for customer cancellations' );
	}

	// ------------------------------------------------------------------ TC-RSA-012 invalid transitions

	public function test_tc_rsa_012_every_invalid_staff_transition_is_409_without_change_or_email(): void {
		$terminal = [
			'rejected'  => [ 'rejected_at' => Clock::mysql_utc(), 'decision_reason_code' => 'fully_booked' ],
			'cancelled' => [ 'cancelled_at' => Clock::mysql_utc(), 'cancelled_by' => 'customer' ],
			'expired'   => [ 'expired_at' => Clock::mysql_utc() ],
			'completed' => [ 'attended_at' => Clock::mysql_utc() ],
			'no_show'   => [ 'attended_at' => Clock::mysql_utc() ],
		];
		$actions = [
			'accept'         => [],
			'decline'        => [ 'reason_code' => 'fully_booked' ],
			'cancel'         => [ 'reason_code' => 'branch_unable_to_serve' ],
			'mark_completed' => [],
			'mark_no_show'   => [],
		];
		$cases = [];
		foreach ( $terminal as $status => $cols ) {
			$id = $this->make_reservation( $this->a, '2026-12-03', '19:00', [ 'status' => $status, 'version' => 3 ] + $cols );
			foreach ( $actions as $action => $extra ) {
				$cases[] = [ $id, $action, $extra, $status ];
			}
		}
		$pending   = $this->make_reservation( $this->a, '2026-12-03', '19:00' );
		$confirmed = $this->make_reservation( $this->a, '2026-12-03', '19:00', [ 'status' => 'confirmed', 'confirmed_at' => Clock::mysql_utc() ] );
		$cases[]   = [ $pending, 'mark_completed', [], 'pending' ];
		$cases[]   = [ $pending, 'mark_no_show', [], 'pending' ];
		$cases[]   = [ $confirmed, 'decline', [ 'reason_code' => 'fully_booked' ], 'confirmed' ];
		$cases[]   = [ $confirmed, 'accept', [], 'confirmed' ];
		foreach ( $cases as [ $id, $action, $extra, $status ] ) {
			$before = $this->row( $id );
			$r      = $this->transition( $id, [ 'action' => $action, 'version' => (int) $before['version'] ] + $extra, $this->ho );
			$this->assertSame( 409, $r->get_status(), "{$status} → {$action}: " . wp_json_encode( $r->get_data() ) );
			$this->assertSame( 'invalid_transition', $r->get_data()['code'], "{$status} → {$action}" );
			$this->assertSame( $status, $r->get_data()['data']['current']['status'] ?? null );
			$after = $this->row( $id );
			$this->assertSame( [ $before['status'], $before['version'] ], [ $after['status'], $after['version'] ], "{$status} → {$action}: no state change" );
			$this->assertSame( [], $this->notifs( $id ), "{$status} → {$action}: no email" );
		}
	}

	// ------------------------------------------------------------------ TC-RSA-013/014 concurrency (sequential form) + C-2

	public function test_tc_rsa_013_014_second_actor_with_stale_version_gets_409_and_only_one_e02(): void {
		$id = $this->submitted( 'QLA', '2026-12-03', '19:00' );
		$this->assertSame( 200, $this->transition( $id, [ 'action' => 'accept', 'version' => 1 ], $this->rs_a )->get_status() );
		// Replay of the same accept (double click / retried POST).
		$again = $this->transition( $id, [ 'action' => 'accept', 'version' => 1 ], $this->rs_a );
		$this->assertSame( 409, $again->get_status() );
		$this->assertContains( $again->get_data()['code'], [ 'invalid_transition', 'version_conflict' ] );
		// Another staff member declines from a stale page.
		$stale = $this->transition( $id, [ 'action' => 'decline', 'version' => 1, 'reason_code' => 'fully_booked' ], $this->bm_a );
		$this->assertSame( 409, $stale->get_status() );
		$this->assertSame( 'confirmed', $stale->get_data()['data']['current']['status'] ?? null, 'refreshed record in the 409 body' );
		$this->assertSame( 2, $stale->get_data()['data']['current']['version'] ?? null );
		$this->assertNotSame( '', (string) $stale->get_data()['message'], 'localised message' );
		$this->assertCount( 1, $this->notifs( $id, 'E02' ), 'exactly one E02' );
		$this->assertSame( [], $this->notifs( $id, 'E03' ) );
		$this->assertCount( 1, array_filter( $this->events( $id ), static fn( $e ) => 'status_changed' === $e['event_type'] ), 'one transition' );
		// Same status, newer version (a claim bumped it) → version_conflict.
		$p = $this->submitted( 'QLA', '2026-12-03', '20:00', [ 'email' => 'vc@example.org' ] );
		$this->assertSame( 201, $this->note( $p, [ 'type' => 'claim', 'version' => 1 ], $this->bm_a )->get_status() );
		$vc = $this->transition( $p, [ 'action' => 'accept', 'version' => 1 ], $this->rs_a );
		$this->assertSame( [ 409, 'version_conflict' ], [ $vc->get_status(), $vc->get_data()['code'] ] );
	}

	// ------------------------------------------------------------------ TC-RSA-025 / AC-RES-05-06 cutoff boundary

	public function test_tc_rsa_025_accept_one_minute_before_cutoff_ok_at_cutoff_lazy_expiry_e05_once(): void {
		$r1 = $this->submitted( 'QLA', '2026-12-01', '19:00', [ 'email' => 'cut1@example.org' ] ); // cutoff 18:00 JST
		$r2 = $this->submitted( 'QLA', '2026-12-01', '19:00', [ 'email' => 'cut2@example.org' ] );
		$this->freeze( '2026-12-01 17:59:00' );
		$this->assertSame( 200, $this->transition( $r1, [ 'action' => 'accept', 'version' => 1 ] )->get_status(), 'S − C − 1 min: still decidable' );
		$this->freeze( '2026-12-01 18:00:00' );
		$late = $this->transition( $r2, [ 'action' => 'accept', 'version' => 1 ] );
		$this->assertSame( [ 409, 'invalid_transition' ], [ $late->get_status(), $late->get_data()['code'] ] );
		$this->assertSame( 'staff.err.expired', $late->get_data()['data']['message_key'] );
		$this->assertSame( 'expired', $this->row( $r2 )['status'] );
		$this->assertCount( 1, $this->notifs( $r2, 'E05' ) );
		$this->assertSame( [], $this->notifs( $r2, 'E02' ) );
		$ev = array_values( array_filter( $this->events( $r2 ), static fn( $e ) => 'expired' === $e['to_status'] ) );
		$this->assertSame( 'system', $ev[0]['actor_type'] );
		// Job afterwards → nothing more.
		$this->freeze( '2026-12-01 18:05:00' );
		ExpiryJob::run();
		$this->assertCount( 1, $this->notifs( $r2, 'E05' ) );
		$this->assertSame( 'confirmed', $this->row( $r1 )['status'], 'confirmed R unaffected by expiry' );
		// allowed_actions never offers accept/decline at/after cutoff.
		$this->as( $this->rs_a );
		$d = $this->rest( 'GET', '/momos/v1/staff/reservations/' . $r2 )->get_data();
		$this->assertNotContains( 'accept', $d['allowed_actions'] );
		$this->assertNotContains( 'decline', $d['allowed_actions'] );
	}

	// ------------------------------------------------------------------ TC-RSA-022 expiry job timing

	public function test_tc_rsa_022_expiry_job_s_minus_c_minus_1_pending_then_expired_once(): void {
		$r = $this->submitted( 'QLA', '2026-12-01', '19:00', [ 'email' => 'job1@example.org' ] );
		$c = $this->submitted( 'QLA', '2026-12-01', '19:00', [ 'email' => 'job2@example.org' ] );
		$this->assertSame( 200, $this->transition( $c, [ 'action' => 'accept', 'version' => 1 ] )->get_status() );
		$this->freeze( '2026-12-01 17:59:00' );
		$this->assertSame( 0, ExpiryJob::run() );
		$this->assertSame( 'pending', $this->row( $r )['status'] );
		$this->freeze( '2026-12-01 18:00:00' );
		$this->assertSame( 1, ExpiryJob::run() );
		$this->assertSame( 'expired', $this->row( $r )['status'] );
		$this->assertNull( $this->row( $r )['cancelled_by'], 'cancelled_by stays NULL on expiry' );
		$this->freeze( '2026-12-01 18:05:00' );
		$this->assertSame( 0, ExpiryJob::run() );
		$this->assertCount( 1, $this->notifs( $r, 'E05' ) );
		$this->assertCount( 1, array_filter( $this->events( $r ), static fn( $e ) => 'expired' === $e['to_status'] ) );
		$this->assertSame( 'confirmed', $this->row( $c )['status'] );
		$this->assertSame( [], array_filter( $this->notifs( $r ), static fn( $n ) => in_array( $n['template_key'], [ 'E08', 'E09' ], true ) ), 'no staff email on expiry (listed in E10)' );
	}

	// ------------------------------------------------------------------ TC-RES-042 lazy expiry on API-03/04

	public function test_tc_res_042_lazy_expiry_on_status_and_cancel_without_job(): void {
		$r1 = $this->submitted( 'QLA', '2026-12-01', '19:00', [ 'email' => 'lz1@example.org' ] );
		$r2 = $this->submitted( 'QLA', '2026-12-01', '19:00', [ 'email' => 'lz2@example.org' ] );
		$this->freeze( '2026-12-01 18:02:00' );
		$s = $this->status_api( $r1 );
		$this->assertSame( 200, $s->get_status() );
		$this->assertSame( 'expired', $s->get_data()['status'] );
		$this->assertFalse( $s->get_data()['can_cancel'] );
		$c = $this->cancel_api( $r2 );
		$this->assertSame( [ 409, 'invalid_transition' ], [ $c->get_status(), $c->get_data()['code'] ] );
		$this->assertSame( 'expired', $this->row( $r2 )['status'] );
		ExpiryJob::run();
		foreach ( [ $r1, $r2 ] as $id ) {
			$this->assertCount( 1, $this->notifs( $id, 'E05' ), 'one E05' );
			$this->assertCount( 1, array_filter( $this->events( $id ), static fn( $e ) => 'expired' === $e['to_status'] ), 'one expiry event' );
			$this->assertSame( [], $this->notifs( $id, 'E04' ), 'not cancelled' );
		}
	}

	// ------------------------------------------------------------------ TC-RES-029 customer cancel cutoff

	public function test_tc_res_029_customer_cancel_before_and_at_the_customer_cutoff(): void {
		update_post_meta( $this->a, 'momo_res_customer_cancel_cutoff_minutes', '180' );
		$p  = $this->submitted( 'QLA', '2026-12-03', '19:00', [ 'email' => 'cc1@example.org' ] );
		$c1 = $this->submitted( 'QLA', '2026-12-03', '19:00', [ 'email' => 'cc2@example.org' ] );
		$c2 = $this->submitted( 'QLA', '2026-12-03', '19:00', [ 'email' => 'cc3@example.org' ] );
		$this->assertSame( 200, $this->transition( $c1, [ 'action' => 'accept', 'version' => 1 ] )->get_status() );
		$this->assertSame( 200, $this->transition( $c2, [ 'action' => 'accept', 'version' => 1 ] )->get_status() );
		$this->assertSame( 200, $this->cancel_api( $p )->get_status(), 'pending cancel (T4a)' );
		$this->freeze( '2026-12-03 15:59:00' ); // start − 181 min.
		$s = $this->status_api( $c1 )->get_data();
		$this->assertTrue( $s['can_cancel'] );
		$this->assertSame( '2026-12-03T16:00:00+09:00', $s['cancel_deadline'] );
		$ok = $this->cancel_api( $c1 );
		$this->assertSame( 200, $ok->get_status(), wp_json_encode( $ok->get_data() ) );
		$this->assertSame( 'cancelled', $ok->get_data()['status'] );
		$this->assertSame( 'customer', $ok->get_data()['cancelled_by'] );
		$this->assertFalse( $ok->get_data()['can_cancel'] );
		foreach ( [ $p, $c1 ] as $id ) {
			$row = $this->row( $id );
			$this->assertSame( [ 'cancelled', 'customer' ], [ $row['status'], $row['cancelled_by'] ] );
			$e04 = $this->notifs( $id, 'E04' );
			$this->assertCount( 1, $e04 );
			$this->assertSame( 'by_customer', $e04[0]['template_variant'] );
			$e09 = $this->notifs( $id, 'E09' );
			$this->assertCount( 2, $e09, 'E09 to each active branch recipient' );
			$this->assertSame( [ 'branch', 'branch' ], array_column( $e09, 'recipient_type' ) );
		}
		// At start − 180 min exactly → 409 cancel_not_allowed and no change.
		$this->freeze( '2026-12-03 16:00:00' );
		$this->assertFalse( $this->status_api( $c2 )->get_data()['can_cancel'] );
		$no = $this->cancel_api( $c2 );
		$this->assertSame( [ 409, 'cancel_not_allowed' ], [ $no->get_status(), $no->get_data()['code'] ] );
		$this->assertStringContainsString( '06-0000-0000', (string) $no->get_data()['message'], 'call route' );
		$this->assertSame( 'confirmed', $this->row( $c2 )['status'] );
		$this->assertSame( [], $this->notifs( $c2, 'E04' ) );
		// Already cancelled (terminal) → cancel_not_allowed, no second email [Ruling C-1].
		$again = $this->cancel_api( $c1 );
		$this->assertSame( [ 409, 'cancel_not_allowed' ], [ $again->get_status(), $again->get_data()['code'] ] );
		$this->assertCount( 1, $this->notifs( $c1, 'E04' ) );
	}

	// ------------------------------------------------------------------ TC-RES-028/030/040 token

	public function test_tc_res_028_030_040_token_rules(): void {
		$a  = $this->submitted( 'QLA', '2026-12-03', '19:00', [ 'email' => 'tk1@example.org' ] );
		$b  = $this->submitted( 'QLA', '2026-12-03', '19:30', [ 'email' => 'tk2@example.org' ] );
		$ta = $this->token( $a );
		$this->assertSame( hash( 'sha256', $ta ), $this->row( $a )['token_hash'], 'only the hash is stored' );
		// One altered character → 404, same body as an unknown reference.
		$alt     = substr( $ta, 0, -1 ) . ( 'A' === substr( $ta, -1 ) ? 'B' : 'A' );
		$wrong   = $this->status_api( $a, $alt );
		$unknown = $this->rest( 'GET', '/momos/v1/reservations/ZZZZZZZZ', null, [ 'X-Momo-Token' => $ta ], [ 'lang' => 'en' ] );
		$this->assertSame( [ 404, 'not_found' ], [ $wrong->get_status(), $wrong->get_data()['code'] ] );
		$this->assertSame( [ 404, 'not_found' ], [ $unknown->get_status(), $unknown->get_data()['code'] ] );
		$this->assertSame( $unknown->get_data()['message'], $wrong->get_data()['message'] );
		// A's token on B's reference → 404; cancel too.
		$this->assertSame( 404, $this->status_api( $b, $ta )->get_status() );
		$this->assertSame( 404, $this->cancel_api( $b, $ta )->get_status() );
		$this->assertSame( 'pending', $this->row( $b )['status'] );
		// ?t= → 400 before any lookup.
		$q = $this->status_api( $a, $ta, [ 't' => $ta, 'lang' => 'en' ] );
		$this->assertSame( [ 400, 'invalid_param' ], [ $q->get_status(), $q->get_data()['code'] ] );
		// Body t on API-04 → 400.
		$this->as( 0 );
		$bt = $this->rest( 'POST', '/momos/v1/reservations/' . $this->row( $a )['public_ref'] . '/cancel', [ 'lang' => 'en', 't' => $ta ], [ 'X-Momo-Token' => $ta ] );
		$this->assertSame( 400, $bt->get_status() );
		$this->assertSame( 'pending', $this->row( $a )['status'] );
		// Reference in lower case with hyphen is accepted (normalisation).
		$ref = strtolower( substr( $this->row( $a )['public_ref'], 0, 4 ) . '-' . substr( $this->row( $a )['public_ref'], 4 ) );
		$this->assertSame( 200, $this->rest( 'GET', '/momos/v1/reservations/' . $ref, null, [ 'X-Momo-Token' => $ta ] )->get_status() );
		// After start + 24 h → 410 link_expired.
		$this->freeze( '2026-12-04 19:00:00' );
		$exp = $this->status_api( $a, $ta );
		$this->assertSame( [ 410, 'link_expired' ], [ $exp->get_status(), $exp->get_data()['code'] ] );
		$this->freeze( '2026-12-04 18:59:00' );
		$this->assertSame( 200, $this->status_api( $a, $ta )->get_status(), 'still valid 1 min before start + 24 h' );
	}

	/** TC-RES-030: a 429 on API-03 must not reveal whether a reference exists. */
	public function test_tc_res_030_rate_limited_status_response_does_not_reveal_existence(): void {
		update_option( 'momo_rate_status_ip_per_hour', 1 );
		$a = $this->submitted( 'QLA', '2026-12-03', '19:00', [ 'email' => 'enum@example.org' ] );
		$fake = str_repeat( 'A', 43 );
		$this->rest( 'GET', '/momos/v1/reservations/ZZZZZZZZ', null, [ 'X-Momo-Token' => $fake ] ); // uses the single allowance
		$known   = $this->rest( 'GET', '/momos/v1/reservations/' . $this->row( $a )['public_ref'], null, [ 'X-Momo-Token' => $fake ] );
		$unknown = $this->rest( 'GET', '/momos/v1/reservations/YYYYYYYY', null, [ 'X-Momo-Token' => $fake ] );
		$this->assertSame( 429, $known->get_status() );
		$this->assertSame( 429, $unknown->get_status() );
		$this->assertSame( $unknown->get_data()['message'], $known->get_data()['message'], 'same 429 body whether or not the reference exists' );
	}

	/** API-04 Origin check (spec §5.2): a cross-site cancel is refused before any change. */
	public function test_api04_cross_origin_cancel_is_rejected(): void {
		$id = $this->submitted( 'QLA', '2026-12-03', '19:00', [ 'email' => 'origin@example.org' ] );
		$this->as( 0 );
		$r = $this->rest( 'POST', '/momos/v1/reservations/' . $this->row( $id )['public_ref'] . '/cancel', [ 'lang' => 'en' ], [ 'X-Momo-Token' => $this->token( $id ), 'origin' => 'https://evil.example' ] );
		$this->assertSame( [ 403, 'origin_rejected' ], [ $r->get_status(), $r->get_data()['code'] ] );
		$this->assertSame( 'pending', $this->row( $id )['status'] );
		$this->assertSame( [], $this->notifs( $id, 'E04' ) );
	}

	// ------------------------------------------------------------------ TC-RSA-010 attendance window

	public function test_tc_rsa_010_attendance_window_boundaries_rs_and_ho(): void {
		$mk = fn() => $this->make_reservation( $this->a, '2026-12-03', '19:00', [ 'status' => 'confirmed', 'confirmed_at' => Clock::mysql_utc() ] );
		$r1 = $mk();
		$r2 = $mk();
		$r3 = $mk();
		$this->freeze( '2026-12-03 18:59:00' );
		$r = $this->transition( $r1, [ 'action' => 'mark_no_show', 'version' => 1 ] );
		$this->assertSame( [ 422, 'attendance_window' ], [ $r->get_status(), $r->get_data()['code'] ], 'S − 1 min' );
		$this->freeze( '2026-12-03 19:00:00' );
		$this->assertSame( 200, $this->transition( $r1, [ 'action' => 'mark_completed', 'version' => 1 ] )->get_status(), 'at S' );
		$this->freeze( '2026-12-06 18:59:00' );
		$this->assertSame( 200, $this->transition( $r2, [ 'action' => 'mark_no_show', 'version' => 1 ] )->get_status(), 'S + 72 h − 1 min' );
		$this->freeze( '2026-12-06 19:01:00' );
		$r = $this->transition( $r3, [ 'action' => 'mark_no_show', 'version' => 1 ], $this->rs_a );
		$this->assertSame( [ 422, 'attendance_window' ], [ $r->get_status(), $r->get_data()['code'] ], 'RS after the window' );
		$this->assertSame( 200, $this->transition( $r3, [ 'action' => 'mark_no_show', 'version' => 1 ], $this->ho )->get_status(), 'HO any time after start' );
		foreach ( [ $r1, $r2, $r3 ] as $id ) {
			$this->assertSame( [], $this->notifs( $id ), 'attendance never emails' );
			$this->assertNotEmpty( $this->row( $id )['attended_at'] );
		}
		// The customer never sees attendance.
		$this->freeze( '2026-12-03 21:00:00' ); // within the link validity (start + 24 h)
		$sr = $this->status_api( $r1 );
		$s  = $sr->get_data();
		$this->assertSame( 200, $sr->get_status(), wp_json_encode( $s ) );
		$this->assertSame( 'confirmed', $s['status'] );
		$this->assertNotNull( $s['status_note'] );
	}

	// ------------------------------------------------------------------ TC-RSA-009 modification

	public function test_tc_rsa_009_modify_rules_override_and_e06(): void {
		$id = $this->submitted( 'QLA', '2026-12-05', '19:00' );
		$this->assertSame( 200, $this->transition( $id, [ 'action' => 'accept', 'version' => 1 ] )->get_status() );
		// Reason is required.
		$r = $this->patch( $id, [ 'version' => 2, 'requested_time' => '19:30' ], $this->bm_a );
		$this->assertSame( [ 400, 'reason_required' ], [ $r->get_status(), $r->get_data()['code'] ] );
		// 1) Change time with the guest's agreement → E06 (confirmed) + modified event with before/after.
		$r = $this->patch( $id, [ 'version' => 2, 'requested_time' => '19:30', 'party_size' => 5, 'reason' => 'agreed_with_guest' ], $this->bm_a );
		$this->assertSame( 200, $r->get_status(), wp_json_encode( $r->get_data() ) );
		$this->assertSame( [ 'E06' ], $r->get_data()['notifications_queued'] );
		$row = $this->row( $id );
		$this->assertSame( [ 'confirmed', '19:30:00', '5' ], [ $row['status'], $row['requested_time'], (string) $row['party_size'] ] );
		$this->assertSame( '2026-12-06 10:30:00', $row['token_expires_utc'], 'token expiry = new start + 24 h' );
		$e06 = $this->notifs( $id, 'E06' );
		$this->assertCount( 1, $e06 );
		$this->assertSame( 'confirmed', $e06[0]['template_variant'] );
		$mod = array_values( array_filter( $this->events( $id ), static fn( $e ) => 'modified' === $e['event_type'] ) );
		$p   = json_decode( (string) $mod[0]['payload'], true );
		$this->assertSame( '19:00', $p['before']['requested_time'] ?? $p['before']['time'] ?? null );
		$this->assertSame( '19:30', $p['after']['requested_time'] ?? $p['after']['time'] ?? null );
		// 2) A closed date → 409 slot_unavailable for BM and for HO with override.
		ClosureRepository::replace_dates( $this->a, [ '2026-12-10' ], 'closed_all_day', [], 'maintenance', '', AccessContext::system() );
		$v = (int) $this->row( $id )['version'];
		$r = $this->patch( $id, [ 'version' => $v, 'requested_date' => '2026-12-10', 'reason' => 'agreed_with_guest' ], $this->bm_a );
		$this->assertSame( [ 409, 'slot_unavailable' ], [ $r->get_status(), $r->get_data()['code'] ] );
		$r = $this->patch( $id, [ 'version' => $v, 'requested_date' => '2026-12-10', 'reason' => 'agreed_with_guest', 'override_rules' => true, 'override_reason' => 'Guest asked' ], $this->ho );
		$this->assertSame( [ 409, 'slot_unavailable' ], [ $r->get_status(), $r->get_data()['code'] ], 'closures are never overridable' );
		// 4) BM sends override_rules → 403 forbidden_field.
		$r = $this->patch( $id, [ 'version' => $v, 'requested_date' => '2026-12-01', 'requested_time' => '11:00', 'reason' => 'agreed_with_guest', 'override_rules' => true, 'override_reason' => 'x' ], $this->bm_a );
		$this->assertSame( [ 403, 'forbidden_field' ], [ $r->get_status(), $r->get_data()['code'] ] );
		// 3) HO moves it inside the lead time with a 200-char reason; 201 chars refused.
		$r = $this->patch( $id, [ 'version' => $v, 'requested_date' => '2026-12-01', 'requested_time' => '11:00', 'reason' => 'agreed_with_guest', 'override_rules' => true, 'override_reason' => str_repeat( 'r', 201 ) ], $this->ho );
		$this->assertSame( 400, $r->get_status() );
		$r = $this->patch( $id, [ 'version' => $v, 'requested_date' => '2026-12-01', 'requested_time' => '11:00', 'reason' => 'agreed_with_guest', 'override_rules' => true, 'override_reason' => str_repeat( 'r', 200 ) ], $this->ho );
		$this->assertSame( 200, $r->get_status(), wp_json_encode( $r->get_data() ) );
		$mod = array_values( array_filter( $this->events( $id ), static fn( $e ) => 'modified' === $e['event_type'] ) );
		$this->assertSame( str_repeat( 'r', 200 ), json_decode( (string) end( $mod )['payload'], true )['override_reason'] ?? null );
		// Same move without override by the BM → 422 lead_time_not_met.
		$p2 = $this->submitted( 'QLA', '2026-12-05', '19:00', [ 'email' => 'mod2@example.org' ] );
		$r  = $this->patch( $p2, [ 'version' => 1, 'requested_date' => '2026-12-01', 'requested_time' => '11:00', 'reason' => 'agreed_with_guest' ], $this->bm_a );
		$this->assertSame( [ 422, 'lead_time_not_met' ], [ $r->get_status(), $r->get_data()['code'] ] );
		// Pending → E06 variant pending.
		$this->assertSame( 200, $this->patch( $p2, [ 'version' => 1, 'party_size' => 3, 'reason' => 'agreed_with_guest' ], $this->bm_a )->get_status() );
		$this->assertSame( 'pending', $this->notifs( $p2, 'E06' )[0]['template_variant'] ?? null );
		// Other branch staff → 403 forbidden_branch.
		$this->assertSame( 'forbidden_branch', $this->patch( $p2, [ 'version' => 2, 'party_size' => 4, 'reason' => 'agreed_with_guest' ], $this->rs_b )->get_data()['code'] );
	}

	// ------------------------------------------------------------------ TC-RSA-007/008 claim and notes

	public function test_tc_rsa_007_008_claim_takeover_release_notes_and_flags(): void {
		$id = $this->submitted( 'QLA', '2026-12-03', '19:00' );
		$this->assertSame( 201, $this->note( $id, [ 'type' => 'claim', 'version' => 1 ], $this->rs_a )->get_status() );
		$stale = $this->note( $id, [ 'type' => 'claim', 'version' => 1 ], $this->bm_a );
		$this->assertSame( [ 409, 'version_conflict' ], [ $stale->get_status(), $stale->get_data()['code'] ] );
		$take = $this->note( $id, [ 'type' => 'claim', 'version' => 2 ], $this->bm_a );
		$this->assertSame( 201, $take->get_status() );
		$this->assertSame( (string) $this->bm_a, (string) $this->row( $id )['handled_by'] );
		$assigned = array_values( array_filter( $this->events( $id ), static fn( $e ) => 'assigned' === $e['event_type'] ) );
		$this->assertSame( $this->rs_a, json_decode( (string) end( $assigned )['payload'], true )['previous_handler_id'] ?? null, 'takeover recorded' );
		$this->assertSame( 201, $this->note( $id, [ 'type' => 'release', 'version' => 3 ], $this->bm_a )->get_status() );
		$this->assertNull( $this->row( $id )['handled_by'] );
		// Claiming never blocks other staff from deciding.
		$this->assertSame( 201, $this->note( $id, [ 'type' => 'claim', 'version' => 4 ], $this->bm_a )->get_status() );
		$this->assertSame( 200, $this->transition( $id, [ 'action' => 'accept', 'version' => 5 ], $this->rs_a )->get_status() );
		// Notes: 1,000 ok, 1,001 refused; allowed on terminal requests; never in customer email rows.
		$this->assertSame( 201, $this->note( $id, [ 'type' => 'note', 'text' => str_repeat( 'n', 1000 ) ] )->get_status() );
		$r = $this->note( $id, [ 'type' => 'note', 'text' => str_repeat( 'n', 1001 ) ] );
		$this->assertSame( [ 400, 'invalid_param' ], [ $r->get_status(), $r->get_data()['code'] ] );
		$this->assertSame( 'too_long', $r->get_data()['data']['fields']['text'] ?? null );
		$this->assertSame( 400, $this->note( $id, [ 'type' => 'note', 'text' => '' ] )->get_status() );
		$x = $this->make_reservation( $this->a, '2026-12-03', '19:00', [ 'status' => 'rejected', 'rejected_at' => Clock::mysql_utc() ] );
		$this->assertSame( 201, $this->note( $x, [ 'type' => 'note', 'text' => 'after the fact' ] )->get_status() );
		$this->assertSame( 409, $this->note( $x, [ 'type' => 'claim', 'version' => 1 ] )->get_status(), 'no claim on terminal' );
		// flag_resolved needs text; clears needs_action.
		global $wpdb;
		$wpdb->update( Tables::reservations(), [ 'needs_action_flag' => 1 ], [ 'id' => $id ] );
		$v = (int) $this->row( $id )['version'];
		$this->assertSame( 400, $this->note( $id, [ 'type' => 'flag_resolved', 'version' => $v ] )->get_status() );
		$this->assertSame( 201, $this->note( $id, [ 'type' => 'flag_resolved', 'version' => $v, 'text' => 'Guest called, ok' ] )->get_status() );
		$this->assertSame( '0', (string) $this->row( $id )['needs_action_flag'] );
	}

	// ------------------------------------------------------------------ TC-RSA-021 SLA reminder and escalation

	public function test_tc_rsa_021_sla_reminder_at_s_and_escalation_at_2s_exactly_once_with_recipients(): void {
		$this->freeze( '2026-12-01 10:00:00' );
		$r  = $this->submitted( 'QLA', '2026-12-05', '19:00', [ 'email' => 'sla1@example.org' ] );
		$r2 = $this->submitted( 'QLA', '2026-12-05', '19:30', [ 'email' => 'sla2@example.org' ] );
		$this->assertSame( 201, $this->note( $r, [ 'type' => 'claim', 'version' => 1 ], $this->rs_a )->get_status() );
		$this->freeze( '2026-12-01 11:30:00' );
		$this->assertSame( 200, $this->transition( $r2, [ 'action' => 'accept', 'version' => 1 ] )->get_status(), 'decided before S' );
		$this->freeze( '2026-12-01 11:59:00' );
		SlaJob::run();
		$this->assertSame( [], $this->notifs( $r, 'E08' ), 'nothing before S' );
		$this->freeze( '2026-12-01 12:00:00' );
		SlaJob::run();
		SlaJob::run();
		$rem = $this->notifs( $r, 'E08' );
		$this->assertSame( [ 'sla_reminder' ], array_values( array_unique( array_column( $rem, 'template_variant' ) ) ) );
		$emails = array_column( $rem, 'recipient_email' );
		sort( $emails );
		$rs_mail = strtolower( get_userdata( $this->rs_a )->user_email );
		$expect  = [ 'qla-inbox@momos.example', 'qla-manager@momos.example', $rs_mail ];
		sort( $expect );
		$this->assertSame( $expect, $emails, 'branch recipients + handled_by user, once each' );
		$this->assertNotContains( 'ho-desk@momos.example', $emails );
		$this->freeze( '2026-12-01 13:59:00' );
		SlaJob::run();
		$this->assertSame( [], array_filter( $this->notifs( $r, 'E08' ), static fn( $n ) => 'sla_escalation' === $n['template_variant'] ) );
		$this->freeze( '2026-12-01 14:00:00' );
		SlaJob::run();
		$this->freeze( '2026-12-01 14:05:00' );
		SlaJob::run();
		$esc = array_values( array_filter( $this->notifs( $r, 'E08' ), static fn( $n ) => 'sla_escalation' === $n['template_variant'] ) );
		$this->assertSame( [ 'ho-desk@momos.example' ], array_column( $esc, 'recipient_email' ), 'escalation to the HO list only, once' );
		$this->assertSame( 'head_office', $esc[0]['recipient_type'] );
		$this->assertCount( 1, array_filter( $this->events( $r ), static fn( $e ) => 'sla_reminder' === $e['event_type'] ) );
		$this->assertCount( 1, array_filter( $this->events( $r ), static fn( $e ) => 'sla_escalated' === $e['event_type'] ) );
		$this->assertSame( [], $this->notifs( $r2, 'E08' ), 'no reminder for a request decided before S' );
		// Inbox overdue badge.
		$this->as( $this->rs_a );
		$rows = $this->rest( 'GET', '/momos/v1/staff/reservations', null, [], [ 'flags' => 'overdue' ] )->get_data();
		$this->assertSame( [ $r ], array_column( $rows, 'id' ) );
		$this->assertSame( 'overdue', $rows[0]['sla_state'] );
	}

	/** SEC-012: a handler who is no longer assigned to the branch must not receive its E08 reminder. */
	public function test_sla_reminder_not_sent_to_a_former_handler_no_longer_assigned_to_the_branch(): void {
		$this->freeze( '2026-12-01 10:00:00' );
		$r = $this->submitted( 'QLA', '2026-12-05', '19:00', [ 'email' => 'slax@example.org' ] );
		$this->assertSame( 201, $this->note( $r, [ 'type' => 'claim', 'version' => 1 ], $this->rs_a )->get_status() );
		global $wpdb;
		$wpdb->delete( Tables::user_branches(), [ 'user_id' => $this->rs_a, 'branch_id' => $this->a ] ); // moved to another branch
		$wpdb->insert( Tables::user_branches(), [ 'user_id' => $this->rs_a, 'branch_id' => $this->b, 'assigned_at' => gmdate( 'Y-m-d H:i:s' ) ] );
		wp_cache_flush();
		$this->freeze( '2026-12-01 12:00:00' );
		SlaJob::run();
		$emails = array_column( $this->notifs( $r, 'E08' ), 'recipient_email' );
		$this->assertNotEmpty( $emails );
		$this->assertNotContains( strtolower( get_userdata( $this->rs_a )->user_email ), $emails, 'guest data of branch A must not reach a user of branch B' );
	}

	// ------------------------------------------------------------------ TC-RSA-016 closure conflicts

	public function test_tc_rsa_016_new_closure_flags_affected_active_reservations_only_without_email(): void {
		$p    = $this->submitted( 'QLA', '2026-12-10', '19:00', [ 'email' => 'cl1@example.org' ] );
		$c    = $this->submitted( 'QLA', '2026-12-10', '12:00', [ 'email' => 'cl2@example.org' ] );
		$safe = $this->submitted( 'QLA', '2026-12-11', '12:00', [ 'email' => 'cl3@example.org' ] );
		$x    = $this->submitted( 'QLA', '2026-12-10', '20:00', [ 'email' => 'cl4@example.org' ] );
		$o    = $this->submitted( 'QLB', '2026-12-10', '19:00', [ 'email' => 'cl5@example.org' ] );
		$this->assertSame( 200, $this->transition( $c, [ 'action' => 'accept', 'version' => 1 ] )->get_status() );
		$this->assertSame( 200, $this->transition( $x, [ 'action' => 'cancel', 'version' => 1, 'reason_code' => 'duplicate' ] )->get_status() );
		global $wpdb;
		$before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::notifications() ); // phpcs:ignore
		ClosureRepository::replace_dates( $this->a, [ '2026-12-10' ], 'closed_all_day', [], 'private_event', '', AccessContext::system() );
		foreach ( [ $p => 'pending', $c => 'confirmed' ] as $id => $status ) {
			$row = $this->row( $id );
			$this->assertSame( '1', (string) $row['needs_action_flag'], "{$status} flagged" );
			$this->assertSame( $status, $row['status'], 'status unchanged (never auto-cancelled)' );
			$this->assertCount( 1, array_filter( $this->events( $id ), static fn( $e ) => 'closure_conflict' === $e['event_type'] ) );
		}
		foreach ( [ $safe, $x, $o ] as $id ) {
			$this->assertSame( '0', (string) $this->row( $id )['needs_action_flag'] );
		}
		$this->assertSame( $before, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::notifications() ), 'no customer or staff email on a closure conflict' ); // phpcs:ignore
		// Inbox "Needs action" filter (both statuses).
		$this->as( $this->rs_a );
		$rows = $this->rest( 'GET', '/momos/v1/staff/reservations', null, [], [ 'flags' => 'needs_action', 'status' => 'pending,confirmed' ] )->get_data();
		$this->assertEqualsCanonicalizing( [ $p, $c ], array_column( $rows, 'id' ) );
		$this->assertContains( 'flag_resolved', $this->rest( 'GET', '/momos/v1/staff/reservations/' . $p )->get_data()['allowed_actions'] );
		// Re-saving the same closure does not flag twice; staff cancel clears the flag.
		ClosureRepository::replace_dates( $this->a, [ '2026-12-10' ], 'closed_all_day', [], 'private_event', '', AccessContext::system() );
		$this->assertCount( 1, array_filter( $this->events( $p ), static fn( $e ) => 'closure_conflict' === $e['event_type'] ) );
		$v = (int) $this->row( $p )['version'];
		$this->assertSame( 200, $this->transition( $p, [ 'action' => 'cancel', 'version' => $v, 'reason_code' => 'branch_unable_to_serve' ] )->get_status() );
		$this->assertSame( '0', (string) $this->row( $p )['needs_action_flag'] );
		// A valid modification clears it too.
		$v = (int) $this->row( $c )['version'];
		$this->assertSame( 200, $this->patch( $c, [ 'version' => $v, 'requested_date' => '2026-12-11', 'requested_time' => '12:30', 'reason' => 'agreed_with_guest' ], $this->rs_a )->get_status() );
		$this->assertSame( '0', (string) $this->row( $c )['needs_action_flag'] );
	}

	/** BR-RES-13 with after-midnight tails and special hours: a Friday closure flags the Saturday 00:30 request. */
	public function test_closure_on_business_day_flags_its_after_midnight_tail_and_special_hours_shortening(): void {
		$this->freeze( '2026-11-30 10:00:00' ); // Monday.
		$n = $this->make_branch( 'QLN', [], [ 5 => [ [ '18:00', '01:00', '00:30', 'dinner' ] ], 6 => [ [ '18:00', '23:00', '22:30', 'dinner' ] ] ] );
		$tail  = $this->submitted( 'QLN', '2026-12-05', '00:30', [ 'email' => 'tail@example.org' ] ); // Friday's tail.
		$sat   = $this->submitted( 'QLN', '2026-12-05', '22:00', [ 'email' => 'sat@example.org' ] );
		$fri   = $this->submitted( 'QLN', '2026-12-11', '23:30', [ 'email' => 'fri@example.org' ] );
		ClosureRepository::replace_dates( $n, [ '2026-12-04' ], 'closed_all_day', [], 'private_event', '', AccessContext::system() );
		$this->assertSame( '1', (string) $this->row( $tail )['needs_action_flag'], 'closure on Friday removes its Saturday 00:30 tail' );
		$this->assertSame( '0', (string) $this->row( $sat )['needs_action_flag'] );
		ClosureRepository::replace_dates( $n, [ '2026-12-11' ], 'special_hours', [ [ 'label' => 'dinner', 'opens_at' => '18:00', 'closes_at' => '22:00', 'last_order_at' => '21:30' ] ], 'other', '', AccessContext::system() );
		$this->assertSame( '1', (string) $this->row( $fri )['needs_action_flag'], 'special hours shorten the evening → flagged' );
	}

	/** TC-RES-020 / API-02 201 body: deadline = start − cutoff, masked email, JST summary, no PII in status_url. */
	public function test_tc_res_020_submission_body_contract(): void {
		$this->as( 0 );
		$r = $this->submit( $this->body( 'QLA', '2026-12-03', '19:00', [ 'email' => 'Aisha.R@Example.ORG' ] ) );
		$this->assertSame( 201, $r->get_status() );
		$d = $r->get_data();
		$this->assertMatchesRegularExpression( '/^[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$/', $d['reference_display'] );
		$this->assertSame( 'pending', $d['status'] );
		$this->assertSame( 'Request received — not yet confirmed', $d['status_label'] );
		$this->assertSame( '2026-12-03T18:00:00+09:00', $d['decision_deadline'] );
		$this->assertSame( 'A***@example.org', $d['email_masked'] );
		$this->assertSame( [ '2026-12-03', '19:00', 'Asia/Tokyo', 2 ], [ $d['summary']['date'], $d['summary']['time'], $d['summary']['timezone'], $d['summary']['party_size'] ] );
		$this->assertMatchesRegularExpression( '#/en/reservation/status/' . $d['reference'] . '/\#t=[A-Za-z0-9_-]{43}$#', $d['status_url'] );
		$this->assertStringNotContainsString( 'Aisha', (string) wp_parse_url( $d['status_url'], PHP_URL_PATH ) );
		$this->assertNull( $d['response_promise_minutes'], 'no promise unless configured (DEC-013)' );
		$this->assertFalse( $d['carrier_email'] );
	}

	// ------------------------------------------------------------------ TC-RSA-017 / BR-RES-12 notification failure

	public function test_tc_rsa_017_permanent_e02_failure_flags_guest_not_notified_and_alerts_branch(): void {
		$id = $this->submitted( 'QLA', '2026-12-03', '19:00', [ 'phone' => '090-0000-0101' ] );
		$this->assertSame( 200, $this->transition( $id, [ 'action' => 'accept', 'version' => 1 ] )->get_status() );
		$this->stub_transport( static fn( Message $m ) => SendResult::fail( 'smtp_rejected_recipient', '550 5.1.1 user unknown' ) );
		$e02 = $this->notifs( $id, 'E02' )[0];
		$this->assertSame( 'failed', Dispatcher::send( (int) $e02['id'] ) );
		$row = $this->row( $id );
		$this->assertSame( 'confirmed', $row['status'], 'email failure never changes the reservation' );
		$this->assertSame( '0', (string) $row['customer_notified_flag'] );
		$this->assertCount( 1, array_filter( $this->events( $id ), static fn( $e ) => 'notification_failed' === $e['event_type'] ) );
		$alerts = array_values( array_filter( $this->notifs( $id, 'E08' ), static fn( $n ) => 'notify_failed' === $n['template_variant'] ) );
		$this->assertEqualsCanonicalizing( [ 'qla-inbox@momos.example', 'qla-manager@momos.example' ], array_column( $alerts, 'recipient_email' ) );
		$this->as( $this->rs_a );
		$list = $this->rest( 'GET', '/momos/v1/staff/reservations', null, [], [ 'flags' => 'not_notified', 'status' => 'confirmed' ] )->get_data();
		$this->assertSame( [ $id ], array_column( $list, 'id' ) );
		$this->assertTrue( $list[0]['flags']['customer_not_notified'] );
		$detail = $this->rest( 'GET', '/momos/v1/staff/reservations/' . $id )->get_data();
		$this->assertSame( '+819000000101', $detail['phone_e164'] );
		$this->assertContains( 'mark_contacted', $detail['allowed_actions'] );
		$v = (int) $this->row( $id )['version'];
		$this->assertSame( 201, $this->note( $id, [ 'type' => 'customer_contacted', 'version' => $v ] )->get_status() );
		$this->assertSame( '1', (string) $this->row( $id )['customer_notified_flag'] );
	}

	/**
	 * reservation.md §3.8/§3.15 + notifications.md §4.1: E02 is queued in the SAME transaction as T2. If the
	 * queue insert fails, the accept must fail as a whole (no silent "confirmed" without a customer email
	 * and without a "customer not notified" flag).
	 */
	public function test_accept_is_never_committed_without_its_e02_row_or_a_not_notified_flag(): void {
		$id = $this->submitted( 'QLA', '2026-12-03', '19:00', [ 'email' => 'atomic2@example.org' ] );
		$t  = Tables::notifications();
		$br = static fn( string $sql ): string => str_starts_with( ltrim( $sql ), "INSERT INTO {$t}" ) ? 'INSERT INTO momos_qa_no_such_table VALUES (1)' : $sql;
		add_filter( 'query', $br );
		$r = $this->transition( $id, [ 'action' => 'accept', 'version' => 1 ] );
		remove_filter( 'query', $br );
		$row = $this->row( $id );
		if ( 'confirmed' === $row['status'] ) {
			$this->assertTrue( 1 === count( $this->notifs( $id, 'E02' ) ) || '0' === (string) $row['customer_notified_flag'], 'confirmed (HTTP ' . $r->get_status() . ') but no E02 row and customer_notified_flag = ' . $row['customer_notified_flag'] );
		} else {
			$this->assertSame( 'pending', $row['status'] );
			$this->assertGreaterThanOrEqual( 500, $r->get_status() );
		}
	}

	/** BR-RES-12 / FR-NOT-003: a transient failure is retried and does not flag the guest. */
	public function test_transient_failure_is_retried_and_does_not_flag(): void {
		$id = $this->submitted( 'QLA', '2026-12-03', '19:00' );
		$this->stub_transport( static fn( Message $m ) => SendResult::fail( 'smtp_tempfail', '451 try later' ) );
		$e01 = $this->notifs( $id, 'E01' )[0];
		$this->assertSame( 'queued', Dispatcher::send( (int) $e01['id'] ) );
		$this->assertSame( '1', (string) $this->row( $id )['customer_notified_flag'] );
		$n = $this->notifs( $id, 'E01' )[0];
		$this->assertSame( '2026-12-01 01:01:00', $n['next_attempt_at'], 'first retry after 1 min' );
	}

	// ------------------------------------------------------------------ Retention interplay

	public function test_purged_reservation_returns_404_on_status_and_cancel_and_queued_mail_is_cancelled(): void {
		$id    = $this->submitted( 'QLA', '2026-12-03', '19:00' );
		$token = $this->token( $id );
		$ref   = (string) $this->row( $id )['public_ref'];
		$this->assertTrue( \Momos\Privacy\Retention\Anonymizer::anonymize_reservation( $id, 'retention' ) );
		$this->as( 0 );
		$s = $this->rest( 'GET', '/momos/v1/reservations/' . $ref, null, [ 'X-Momo-Token' => $token ] );
		$this->assertSame( [ 404, 'not_found' ], [ $s->get_status(), $s->get_data()['code'] ] );
		$c = $this->rest( 'POST', '/momos/v1/reservations/' . $ref . '/cancel', [ 'lang' => 'en' ], [ 'X-Momo-Token' => $token ] );
		$this->assertSame( [ 404, 'not_found' ], [ $c->get_status(), $c->get_data()['code'] ] );
		$this->assertSame( 'pending', $this->row( $id )['status'] );
		$this->assertStringNotContainsString( 'Aisha', wp_json_encode( $s->get_data() ) . wp_json_encode( $c->get_data() ) );
		// The queued E01 is not sent to a purged address.
		$this->stub_transport( static fn( Message $m ) => SendResult::ok( '<x@test>' ) );
		$e01 = $this->notifs( $id, 'E01' )[0];
		Dispatcher::send( (int) $e01['id'] );
		$e01 = $this->notifs( $id, 'E01' )[0];
		$this->assertSame( 'cancelled', $e01['status'] );
		$this->assertNull( $e01['recipient_email'] );
		$this->assertSame( '[purged]', $e01['recipient_masked'] );
	}

	// ------------------------------------------------------------------ Dedupe on retried hooks

	public function test_retried_domain_hooks_never_queue_duplicate_emails(): void {
		$id = $this->submitted( 'QLA', '2026-12-03', '19:00' );
		do_action( 'momos/reservation/created', $id ); // a retried after-commit hook
		$this->assertCount( 1, $this->notifs( $id, 'E01' ) );
		$this->assertCount( 2, $this->notifs( $id, 'E07' ) );
		$this->assertSame( 200, $this->transition( $id, [ 'action' => 'accept', 'version' => 1 ] )->get_status() );
		do_action( 'momos/reservation/status_changed', $id, 'pending', 'confirmed', [ 'type' => 'staff', 'id' => $this->rs_a ] );
		$this->assertCount( 1, $this->notifs( $id, 'E02' ) );
		$this->assertSame( 200, $this->cancel_api( $id )->get_status() );
		do_action( 'momos/reservation/status_changed', $id, 'confirmed', 'cancelled', [ 'type' => 'customer' ] );
		$this->assertCount( 1, $this->notifs( $id, 'E04' ) );
		$this->assertCount( 2, $this->notifs( $id, 'E09' ) );
	}
}
