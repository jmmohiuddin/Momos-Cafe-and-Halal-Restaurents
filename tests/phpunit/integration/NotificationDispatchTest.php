<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/NotificationTestCaseTest.php';

use Momos\Kernel\Clock\Clock;
use Momos\Notification\Queue\Dispatcher;
use Momos\Notification\Queue\QueueSweeper;
use Momos\Notification\Queue\RetryPolicy;
use Momos\Notification\Repository\NotificationRepository;
use Momos\Notification\Transport\LogTransport;
use Momos\Notification\Transport\Message;
use Momos\Notification\Transport\WpMailTransport;

/**
 * Worker, retry/backoff, final-failure effects, sweeper, guards and transports (FR-NOT-003,
 * FR-NOT-006, NFR-REL-01, SEC-033): TC-NOT-005, AC-NOT-03-01/02, TC-NOT-013, TC-NOT-012 (headers),
 * TC-RSA-021 guard, sweeper recovery, SEC-033 header injection.
 */
final class NotificationDispatchTest extends NotificationTestCaseTest {
	private function e01( ?int $branch = null ): array {
		$a = $branch ?? $this->branch( 'QAA', self::recipients( 'qaa@momos.example' ) );
		$r = $this->reservation( $a );
		do_action( 'momos/reservation/created', $r );
		return [ $a, $r, (int) $this->rows( 'E01' )[0]['id'] ];
	}

	public function test_send_marks_sent_with_message_id_and_one_to_reply_to_branch(): void {
		[ , , $id ] = $this->e01();
		$this->assertSame( 'sent', Dispatcher::send( $id ) );
		$row = NotificationRepository::find( $id );
		$this->assertSame( 'sent', $row['status'] );
		$this->assertSame( '1', (string) $row['attempts'] );
		$this->assertMatchesRegularExpression( '/^<' . preg_quote( $row['uuid'], '/' ) . '@[a-z0-9.-]+>$/', $row['provider_message_id'] );
		$this->assertNotNull( $row['sent_at'] );
		$this->assertNull( $row['locked_at'] );
		$m = $this->sent[0];
		$this->assertSame( 'aisha.guest@example.test', $m->to );
		$this->assertSame( 'qaa@momos.example', $m->reply_to );
		$this->assertStringStartsWith( '[STAGING] ', $m->subject, 'non-production subject prefix' );
		$this->assertSame( 'skipped', Dispatcher::send( $id ), 'a sent row is never claimed again' );
	}

	public function test_tc_not_005_backoff_1_5_15_60_then_failed_with_effects_and_reservation_untouched(): void {
		[ $a, $r, $id ] = $this->e01();
		$this->fail_queue = array_fill( 0, 5, [ 'smtp_connect_failed', 'SMTP connect() failed.' ] );
		$expected = [ 1, 5, 15, 60 ];
		foreach ( $expected as $i => $minutes ) {
			$this->assertSame( 'queued', Dispatcher::send( $id ), 'attempt ' . ( $i + 1 ) );
			$row = NotificationRepository::find( $id );
			$this->assertSame( (string) ( $i + 1 ), (string) $row['attempts'] );
			$this->assertSame( Clock::mysql_utc( Clock::utc()->modify( '+' . $minutes . ' minutes' ) ), $row['next_attempt_at'] );
			$this->assertSame( 'smtp_connect_failed', $row['last_error_code'] );
			$this->assertSame( 'skipped', Dispatcher::send( $id ), 'not due yet' );
			$this->advance( '+' . $minutes . ' minutes' );
		}
		$this->assertSame( 'failed', Dispatcher::send( $id ) );
		$row = NotificationRepository::find( $id );
		$this->assertSame( 'failed', $row['status'] );
		$this->assertSame( '5', (string) $row['attempts'] );
		$this->assertNotNull( $row['failed_at'] );
		$this->assertSame( [ [ $id, $r ] ], $this->failed_events, 'momos/notification/failed (id, reservation id)' );
		$e08 = array_values( array_filter( $this->rows( 'E08' ), static fn( $x ) => 'notify_failed' === $x['template_variant'] ) );
		$this->assertCount( 1, $e08, 'E08 notify_failed to the branch recipients' );
		$this->assertSame( 'qaa@momos.example', $e08[0]['recipient_email'] );
		$this->assertSame( 'notification', $e08[0]['object_type'] );
		$this->assertSame( (string) $id, (string) $e08[0]['object_id'] );
		$res = \Momos\Notification\Support\ReservationReader::find( $r );
		$this->assertSame( 'pending', $res['status'], 'email failure never changes the reservation (BR-RES-12)' );
		// The cascade E08 renders the failed template and next step, no guest contact data.
		$this->assertSame( 'sent', Dispatcher::send( (int) $e08[0]['id'] ) );
		$m = end( $this->sent );
		$this->assertStringContainsString( 'お客様へのメール未達', $m->subject, 'shared inbox → ja' );
		$this->assertStringNotContainsString( '+60123456789', $m->html );
		$this->assertStringNotContainsString( 'aisha.guest', $m->html );
		unset( $a );
	}

	public function test_second_request_succeeds_on_attempt_3_and_staff_failure_has_no_cascade(): void {
		[ $a, , $id ] = $this->e01();
		$this->fail_queue = [ [ 'smtp_tempfail', '421 try later' ], [ 'smtp_timeout', 'Connection timed out' ] ];
		Dispatcher::send( $id );
		$this->advance( '+1 minute' );
		Dispatcher::send( $id );
		$this->advance( '+5 minutes' );
		$this->assertSame( 'sent', Dispatcher::send( $id ) );
		$this->assertSame( '3', (string) NotificationRepository::find( $id )['attempts'] );

		$e07 = (int) $this->rows( 'E07' )[0]['id'];
		$this->fail_queue = [ [ 'smtp_rejected_recipient', '550 5.1.1 user unknown' ] ];
		$this->assertSame( 'failed', Dispatcher::send( $e07 ) );
		$this->assertCount( 0, array_filter( $this->rows( 'E08' ), static fn( $x ) => 'notify_failed' === $x['template_variant'] ), 'no cascade for staff templates' );
		unset( $a );
	}

	public function test_tc_not_013_permanent_rejection_fails_immediately_and_error_is_redacted(): void {
		[ , $r, $id ] = $this->e01();
		$this->fail_queue = [ [ 'smtp_rejected_recipient', '550 5.1.1 <aisha.guest@example.test>: Recipient address rejected' ] ];
		$this->assertSame( 'failed', Dispatcher::send( $id ) );
		$row = NotificationRepository::find( $id );
		$this->assertSame( '1', (string) $row['attempts'] );
		$this->assertSame( 'smtp_rejected_recipient', $row['last_error_code'] );
		$this->assertStringNotContainsString( 'aisha.guest@example.test', (string) $row['last_error'], 'no address in last_error (SEC-081)' );
		$this->assertSame( [ [ $id, $r ] ], $this->failed_events );
	}

	public function test_ac_not_03_02_recovery_sends_in_creation_order(): void {
		$a   = $this->branch( 'QAA', [] );
		$ids = [];
		for ( $i = 0; $i < 3; $i++ ) {
			$r = $this->reservation( $a );
			do_action( 'momos/reservation/created', $r );
			$ids[] = (int) $this->rows( 'E01' )[ $i ]['id'];
			$this->advance( '+1 second' );
		}
		$this->assertSame( $ids, NotificationRepository::due_ids( 10 ) );
		$this->assertSame( 3, Dispatcher::run_due() );
		$this->assertSame( array_map( static fn( $id ) => NotificationRepository::find( $id )['provider_message_id'], $ids ), array_map( static fn( Message $m ) => $m->message_id, $this->sent ) );
		$this->assertNotEmpty( get_option( 'momos_job_heartbeat_queue' ) );
	}

	public function test_sweeper_recovers_stale_sending_and_fails_at_attempt_5(): void {
		[ , , $id ] = $this->e01();
		global $wpdb;
		$t = \Momos\Kernel\Db\Tables::notifications();
		$wpdb->update( $t, [ 'status' => 'sending', 'attempts' => 2, 'locked_at' => Clock::mysql_utc( Clock::utc()->modify( '-11 minutes' ) ) ], [ 'id' => $id ] );
		$out = QueueSweeper::run();
		$this->assertSame( 1, $out['recovered'] );
		$row = NotificationRepository::find( $id );
		$this->assertSame( 'queued', $row['status'] );
		$this->assertSame( 'worker_crash', $row['last_error_code'] );
		$this->assertSame( 'sent', Dispatcher::send( $id ) );

		$e07 = (int) $this->rows( 'E07' )[0]['id'];
		$wpdb->update( $t, [ 'status' => 'sending', 'attempts' => 5, 'locked_at' => Clock::mysql_utc( Clock::utc()->modify( '-30 minutes' ) ) ], [ 'id' => $e07 ] );
		$this->assertSame( 1, QueueSweeper::run()['failed'] );
		$this->assertSame( 'failed', NotificationRepository::find( $e07 )['status'] );
		$this->assertNotEmpty( get_option( 'momos_job_heartbeat_queue' ) );
	}

	public function test_guards_cancel_sla_reminder_after_accept_purged_recipient_and_customer_address(): void {
		$a = $this->branch( 'QAA', self::recipients( 'qaa@momos.example', 'aisha.guest@example.test' ) );
		$r = $this->reservation( $a );
		do_action( 'momos/reservation/sla_reminder', $r );
		$rows = $this->rows( 'E08' );
		$this->update_reservation( $r, [ 'status' => 'confirmed' ] );
		foreach ( $rows as $row ) {
			$this->assertSame( 'cancelled', Dispatcher::send( (int) $row['id'] ) );
			$this->assertSame( 'not_applicable', NotificationRepository::find( (int) $row['id'] )['last_error_code'] );
		}
		// Staff template addressed to the reservation's own customer → cancelled.
		$this->update_reservation( $r, [ 'status' => 'pending' ] );
		do_action( 'momos/reservation/created', $r );
		$to_customer = array_values( array_filter( $this->rows( 'E07' ), static fn( $x ) => 'aisha.guest@example.test' === $x['recipient_email'] ) );
		$this->assertSame( 'cancelled', Dispatcher::send( (int) $to_customer[0]['id'] ) );
		// Purged recipient → cancelled (recipient_purged).
		$e01 = (int) $this->rows( 'E01' )[0]['id'];
		NotificationRepository::purge_recipients_for_reservation( $r );
		$this->assertSame( 'cancelled', NotificationRepository::find( $e01 )['status'] );
		$this->assertSame( '[purged]', NotificationRepository::find( $e01 )['recipient_masked'] );
		$this->assertCount( 0, $this->sent );
	}

	public function test_retry_policy_classification(): void {
		$this->assertSame( 'smtp_auth_failed', RetryPolicy::classify( 535, 'SMTP Error: Could not authenticate.' ) );
		$this->assertSame( 'smtp_connect_failed', RetryPolicy::classify( null, 'SMTP Error: Could not connect to SMTP host. SMTP connect() failed.' ) );
		$this->assertSame( 'smtp_tempfail', RetryPolicy::classify( 451, 'temporary' ) );
		$this->assertSame( 'smtp_rejected_recipient', RetryPolicy::classify( 550, 'SMTP Error: The following recipients failed: x' ) );
		$this->assertSame( 'smtp_rejected_message', RetryPolicy::classify( 554, 'SMTP Error: data not accepted.' ) );
		$this->assertNull( RetryPolicy::next_attempt( 1, 'render_failed', Clock::utc() ) );
		$this->assertNull( RetryPolicy::next_attempt( 5, 'smtp_timeout', Clock::utc() ) );
	}

	public function test_wp_mail_transport_headers_multipart_no_cc_bcc_and_header_injection(): void {
		reset_phpmailer_instance();
		$m   = new Message( 'guest@example.test', "Subject\r\nBcc: evil@example.test", '<p>Hello</p>', 'Hello', 'reservations@momos.example', "Momo's\r\nBcc: evil@example.test", 'qaa@momos.example', '<uuid-1@momos.example>', 'en' );
		$res = ( new WpMailTransport() )->send( $m );
		$this->assertTrue( $res->ok );
		$mailer = tests_retrieve_phpmailer_instance();
		$raw    = $mailer->get_sent()->header;
		$this->assertStringContainsString( 'Reply-To: qaa@momos.example', $raw );
		$this->assertStringContainsString( 'Auto-Submitted: auto-generated', $raw );
		$this->assertStringContainsString( 'Content-Language: en', $raw );
		$this->assertStringContainsString( 'Message-ID: <uuid-1@momos.example>', $raw );
		$this->assertDoesNotMatchRegularExpression( '/^Bcc:/mi', $raw, 'CR/LF + Bcc adds no header (SEC-033)' );
		$this->assertSame( [], $mailer->get_sent()->bcc ?? [] );
		$this->assertStringNotContainsStringIgnoringCase( "\nCc:", $raw );
		$this->assertSame( [ 'guest@example.test' ], array_column( $mailer->get_sent()->to, 0 ) );
		$this->assertSame( 'Hello', $mailer->AltBody );
	}

	public function test_log_transport_writes_eml_and_json(): void {
		$written = [];
		add_action( 'momos/notification/log_transport_written', function ( $base, $json ) use ( &$written ): void {
			$written = [ $base, $json ];
		}, 10, 2 );
		$m   = new Message( 'guest@example.test', 'Subject ✓', '<p>本文</p>', '本文', 'reservations@momos.example', "Momo's", null, '<uuid-2@momos.example>', 'ja' );
		$res = ( new LogTransport() )->send( $m );
		$this->assertTrue( $res->ok );
		$this->assertFileExists( $written[0] . '.eml' );
		$this->assertFileExists( $written[0] . '.json' );
		$eml = (string) file_get_contents( $written[0] . '.eml' );
		$this->assertStringContainsString( 'multipart/alternative', $eml );
		$this->assertStringContainsString( 'Message-ID: <uuid-2@momos.example>', $eml );
		$json = json_decode( (string) file_get_contents( $written[0] . '.json' ), true );
		$this->assertSame( '本文', $json['text'] );
		unlink( $written[0] . '.eml' );
		unlink( $written[0] . '.json' );
	}
}
