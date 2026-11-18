<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/NotificationTestCaseTest.php';

use Momos\Notification\Notifier;
use Momos\Notification\Queue\NotificationQueue;
use Momos\Notification\Queue\NotificationRequest;
use Momos\Notification\Recipient\RecipientResolver;

/**
 * Queue, routing and dedupe (F-NOT-03, FR-NOT-003/004, DEC-049, GATE-03): AC-RES-01-01, TC-NOT-004,
 * TC-NOT-006, TC-RES-029, AC-NOT-02-02, TC-RSA-021, TC-NOT-017, dedupe idempotency.
 */
final class NotificationQueueTest extends NotificationTestCaseTest {
	public function test_t1_queues_e01_and_e07_per_active_branch_recipient_and_dedupes(): void {
		$a = $this->branch( 'QAA', array_merge( self::recipients( 'qaa-1@momos.example', 'qaa-2@momos.example' ), [ [ 'email' => 'qaa-off@momos.example', 'language' => 'ja', 'active' => '0' ] ] ) );
		$r = $this->reservation( $a );
		do_action( 'momos/reservation/created', $r );
		do_action( 'momos/reservation/created', $r ); // T1 replay → no duplicates.

		$e01 = $this->rows( 'E01' );
		$e07 = $this->rows( 'E07' );
		$this->assertCount( 1, $e01, 'double T1 → one E01' );
		$this->assertSame( 'res:' . $r . ':E01', $e01[0]['dedupe_key'] );
		$this->assertSame( 'customer', $e01[0]['recipient_type'] );
		$this->assertSame( 'en', $e01[0]['lang'] );
		$this->assertCount( 2, $e07, 'one E07 per ACTIVE recipient' );
		foreach ( $e07 as $row ) {
			$this->assertStringStartsWith( 'res:' . $r . ':E07:', $row['dedupe_key'] );
			$this->assertSame( 'branch', $row['recipient_type'] );
			$this->assertSame( (string) $a, (string) $row['branch_id'] );
		}
	}

	public function test_tc_not_004_branch_isolation_on_the_notification_channel(): void {
		$a = $this->branch( 'QAA', self::recipients( 'qaa@momos.example' ) );
		$b = $this->branch( 'QAB', self::recipients( 'qab@momos.example' ) );
		$r = $this->reservation( $b );
		do_action( 'momos/reservation/created', $r );
		do_action( 'momos/reservation/status_changed', $r, 'pending', 'cancelled', [ 'type' => 'customer' ] );
		$this->update_reservation( $r, [ 'status' => 'pending' ] );
		do_action( 'momos/reservation/sla_reminder', $r );
		$emails = array_column( array_filter( $this->rows(), static fn( $x ) => 'customer' !== $x['recipient_type'] ), 'recipient_email' );
		$this->assertNotEmpty( $emails );
		$this->assertNotContains( 'qaa@momos.example', $emails, 'branch A never receives branch B mail' );
		$this->assertSame( [ 'qab@momos.example' ], array_values( array_unique( $emails ) ) );
		unset( $a );
	}

	public function test_tc_not_006_row_metadata_masked_hash_and_no_body(): void {
		$a = $this->branch( 'QAA', self::recipients( 'qaa@momos.example' ) );
		$r = $this->reservation( $a );
		do_action( 'momos/reservation/created', $r );
		$row = $this->rows( 'E01' )[0];
		$this->assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/', $row['uuid'] );
		$this->assertSame( 'a***@e***.test', $row['recipient_masked'] );
		$this->assertSame( RecipientResolver::hash( 'aisha.guest@example.test' ), $row['recipient_hash'] );
		$this->assertSame( 64, strlen( $row['recipient_hash'] ) );
		$this->assertSame( 'queued', $row['status'] );
		$this->assertSame( '0', (string) $row['attempts'] );
		$this->assertSame( 'reservation', $row['object_type'] );
		global $wpdb;
		$cols = $wpdb->get_col( 'SHOW COLUMNS FROM ' . \Momos\Kernel\Db\Tables::notifications() ); // phpcs:ignore
		$this->assertEmpty( array_intersect( $cols, [ 'subject', 'body', 'html', 'text' ] ), 'no body/subject stored (PRV-011)' );
	}

	public function test_transition_matrix_t2_to_t6_and_spam_suppression(): void {
		$a = $this->branch( 'QAA', self::recipients( 'qaa@momos.example' ) );

		$r2 = $this->reservation( $a, [ 'status' => 'confirmed', 'version' => 2 ] );
		do_action( 'momos/reservation/status_changed', $r2, 'pending', 'confirmed', [ 'type' => 'staff', 'id' => 1 ] );
		$this->assertSame( [ 'res:' . $r2 . ':E02:2' ], array_column( $this->rows( 'E02' ), 'dedupe_key' ) );

		$r3 = $this->reservation( $a, [ 'status' => 'rejected', 'version' => 2, 'decision_reason_code' => 'fully_booked' ] );
		do_action( 'momos/reservation/status_changed', $r3, 'pending', 'rejected', [ 'type' => 'staff' ] );
		$this->assertCount( 1, $this->rows( 'E03' ) );

		$r5 = $this->reservation( $a, [ 'status' => 'expired', 'version' => 2 ] );
		do_action( 'momos/reservation/status_changed', $r5, 'pending', 'expired', [ 'type' => 'system' ] );
		do_action( 'momos/reservation/status_changed', $r5, 'pending', 'expired', [ 'type' => 'system' ] );
		$this->assertCount( 1, $this->rows( 'E05' ), 're-run → one E05' );

		// T4a customer cancel → E04 by_customer + E09 to the branch (US-NOT-006, TC-RES-029).
		$r4 = $this->reservation( $a, [ 'status' => 'cancelled', 'cancelled_by' => 'customer', 'version' => 2 ] );
		do_action( 'momos/reservation/status_changed', $r4, 'pending', 'cancelled', [ 'type' => 'customer' ] );
		$e04 = $this->rows( 'E04' );
		$this->assertCount( 1, $e04 );
		$this->assertSame( 'by_customer', $e04[0]['template_variant'] );
		$this->assertCount( 1, $this->rows( 'E09' ) );

		// T4b staff cancel `suspected_spam` → no E04 (FR-RES-012); `duplicate` → E04 by_branch.
		$spam = $this->reservation( $a, [ 'status' => 'cancelled', 'cancelled_by' => 'staff', 'decision_reason_code' => 'suspected_spam', 'version' => 2 ] );
		do_action( 'momos/reservation/status_changed', $spam, 'pending', 'cancelled', [ 'type' => 'staff' ] );
		$dup = $this->reservation( $a, [ 'status' => 'cancelled', 'cancelled_by' => 'staff', 'decision_reason_code' => 'duplicate', 'version' => 2 ] );
		do_action( 'momos/reservation/status_changed', $dup, 'pending', 'cancelled', [ 'type' => 'staff' ] );
		$by_res = array_column( $this->rows( 'E04' ), 'template_variant', 'reservation_id' );
		$this->assertArrayNotHasKey( $spam, $by_res );
		$this->assertSame( 'by_branch', $by_res[ $dup ] );
		$this->assertCount( 1, $this->rows( 'E09' ), 'no E09 for staff cancellations' );

		// T7/T8 attendance → nothing.
		$before = count( $this->rows() );
		do_action( 'momos/reservation/status_changed', $r2, 'confirmed', 'completed', [ 'type' => 'staff' ] );
		$this->assertCount( $before, $this->rows() );
	}

	public function test_n3_modified_sends_e06_variant_by_status(): void {
		$a = $this->branch( 'QAA', self::recipients( 'qaa@momos.example' ) );
		$r = $this->reservation( $a, [ 'status' => 'confirmed', 'version' => 3 ] );
		do_action( 'momos/reservation/modified', $r, [ 'party_size' => 4 ], [ 'party_size' => 5 ], [ 'type' => 'staff' ] );
		$row = $this->rows( 'E06' )[0];
		$this->assertSame( 'confirmed', $row['template_variant'] );
		$this->assertSame( 'res:' . $r . ':E06:3', $row['dedupe_key'] );
		do_action( 'momos/reservation/token_reissued', $r );
		$this->assertSame( [ 'confirmed', 'new_link' ], array_column( $this->rows( 'E06' ), 'template_variant' ) );
	}

	public function test_sla_reminder_adds_handled_by_and_escalation_goes_to_ho_list(): void {
		$a     = $this->branch( 'QAA', self::recipients( 'qaa@momos.example' ) );
		$staff = self::factory()->user->create( [ 'role' => 'momo_reservation_staff', 'user_email' => 'staff.a@momos.example' ] );
		$r     = $this->reservation( $a, [ 'handled_by' => $staff ] );
		update_option( 'momo_ho_escalation_recipients', [ [ 'email' => 'ho@momos.example', 'language' => 'en', 'active' => '1' ] ] );
		\Momos\Kernel\Settings::set( 'momo_ho_escalation_recipients', [ [ 'email' => 'ho@momos.example', 'language' => 'en', 'active' => '1' ] ] );
		do_action( 'momos/reservation/sla_reminder', $r );
		do_action( 'momos/reservation/sla_reminder', $r );
		do_action( 'momos/reservation/sla_escalated', $r );
		$rows = $this->rows( 'E08' );
		$rem  = array_values( array_filter( $rows, static fn( $x ) => 'sla_reminder' === $x['template_variant'] ) );
		$esc  = array_values( array_filter( $rows, static fn( $x ) => 'sla_escalation' === $x['template_variant'] ) );
		$this->assertEqualsCanonicalizing( [ 'qaa@momos.example', 'staff.a@momos.example' ], array_column( $rem, 'recipient_email' ), 'reminder once to branch + handled_by' );
		$this->assertSame( 'user', $rem[1]['recipient_type'] );
		$this->assertSame( [ 'ho@momos.example' ], array_column( $esc, 'recipient_email' ) );
		$this->assertSame( 'head_office', $esc[0]['recipient_type'] );
		$this->assertSame( 'en', $esc[0]['lang'] );
	}

	public function test_ac_not_02_02_staff_language_from_user_locale_else_row_language(): void {
		$uid = self::factory()->user->create( [ 'role' => 'momo_branch_manager', 'user_email' => 'manager@momos.example' ] );
		update_user_meta( $uid, 'locale', 'en_US' );
		$a = $this->branch( 'QAA', [ [ 'email' => 'manager@momos.example', 'language' => 'ja', 'active' => '1' ], [ 'email' => 'inbox@momos.example', 'language' => 'ja', 'active' => '1' ] ] );
		$r = $this->reservation( $a );
		do_action( 'momos/reservation/created', $r );
		$langs = array_column( $this->rows( 'E07' ), 'lang', 'recipient_email' );
		$this->assertSame( 'en', $langs['manager@momos.example'], 'WP user → admin locale' );
		$this->assertSame( 'ja', $langs['inbox@momos.example'], 'shared inbox → row language' );
		$users = array_column( $this->rows( 'E07' ), 'recipient_user_id', 'recipient_email' );
		$this->assertSame( (string) $uid, (string) $users['manager@momos.example'] );
	}

	public function test_notifier_contract_dedupes_e11_and_refuses_customer_type_for_staff_templates(): void {
		$recipient = [ 'type' => 'head_office', 'email' => 'ho@momos.example', 'lang' => 'en', 'user_id' => null ];
		$cert      = self::factory()->post->create( [ 'post_type' => 'post' ] );
		$id        = Notifier::queue( 'E11', $recipient, [ 'certificate_id' => $cert, 'variant' => 'cert_30' ], 'cert:' . $cert . ':30:abc' );
		$this->assertIsInt( $id );
		$this->assertNull( Notifier::queue( 'E11', $recipient, [ 'certificate_id' => $cert, 'variant' => 'cert_30' ], 'cert:' . $cert . ':30:abc' ), '2nd run sends nothing' );
		$row = $this->rows( 'E11' )[0];
		$this->assertSame( 'certificate', $row['object_type'] );
		$this->assertSame( (string) $cert, (string) $row['object_id'] );
		$this->assertSame( 'cert_30', $row['template_variant'] );
		$this->assertNull( Notifier::queue( 'E07', [ 'type' => 'customer', 'email' => 'x@example.test' ], [], 'k1' ) );
		$this->assertNull( Notifier::queue( 'E99', $recipient, [], 'k2' ) );
		$this->assertNull( Notifier::queue( 'E11', [ 'type' => 'head_office', 'email' => 'not-an-email' ], [], 'k3' ) );
	}

	public function test_recipients_filter_may_only_remove(): void {
		$a = $this->branch( 'QAA', self::recipients( 'one@momos.example', 'two@momos.example' ) );
		$r = $this->reservation( $a );
		add_filter(
			'momos/notification/recipients',
			static function ( array $list ) {
				$list   = array_values( array_filter( $list, static fn( $x ) => 'two@momos.example' !== $x['email'] ) );
				$list[] = [ 'type' => 'branch', 'email' => 'personal@gmail.com', 'lang' => 'ja', 'user_id' => null ];
				return $list;
			}
		);
		NotificationQueue::enqueue( new NotificationRequest( 'E07', 'branch_recipients', 'res:' . $r . ':E07', null, $r, $a ) );
		$this->assertSame( [ 'one@momos.example' ], array_column( $this->rows( 'E07' ), 'recipient_email' ) );
	}

	public function test_no_active_recipients_queues_nothing(): void {
		$a = $this->branch( 'QAA', [] );
		$r = $this->reservation( $a );
		do_action( 'momos/reservation/created', $r );
		$this->assertCount( 0, $this->rows( 'E07' ) );
		$this->assertCount( 1, $this->rows( 'E01' ) );
	}
}
