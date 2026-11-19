<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/ReservationTestCaseTest.php';

use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Db\Tables;
use Momos\Kernel\Settings;
use Momos\Kernel\Support\Hash;
use Momos\Privacy\Retention\RetentionJob;

/**
 * Shared fixtures for the Privacy module tests (fictional data only: branches QPA/QPB, guests at
 * example.org). Builds on the Reservation fixtures (branches, direct reservation inserts, audit capture).
 */
abstract class PrivacyTestCaseTest extends ReservationTestCaseTest {
	public function set_up(): void {
		parent::set_up();
		delete_option( RetentionJob::LAST_OPTION );
		Settings::flush();
		$this->freeze( '2026-11-19 10:00:00' );
	}

	public function tear_down(): void {
		Settings::flush();
		parent::tear_down();
	}

	/** Reservation with full PII, a staff note event (with a free-text payload) and a customer notification. */
	protected function rich_reservation( int $branch_id, string $date, string $time = '19:00', array $over = [] ): int {
		$id = $this->make_reservation(
			$branch_id,
			$date,
			$time,
			array_merge(
				[
					'guest_name'          => 'Aisha Rahman',
					'email'               => 'aisha@example.org',
					'phone_e164'          => '+601234567890',
					'special_request'     => 'MARKER-SR peanut allergy',
					'message_to_customer' => 'MARKER-MSG see you soon',
					'ip_hash'             => str_repeat( 'b', 64 ),
					'user_agent_hash'     => str_repeat( 'c', 64 ),
					'status'              => 'completed',
					'consent_version'     => 'PP1.0-RP1.0',
				],
				$over
			)
		);
		global $wpdb;
		$now = Clock::mysql_utc();
		$wpdb->insert( Tables::events(), [ 'reservation_id' => $id, 'event_type' => 'created', 'to_status' => 'pending', 'actor_type' => 'customer', 'payload' => '{"captcha":"verified","source_lang":"en"}', 'created_at' => $now ] );
		$wpdb->insert( Tables::events(), [ 'reservation_id' => $id, 'event_type' => 'note', 'actor_type' => 'staff', 'actor_id' => 1, 'actor_display' => 'Staff One', 'note' => 'MARKER-NOTE guest called', 'created_at' => $now ] );
		$wpdb->insert( Tables::events(), [ 'reservation_id' => $id, 'event_type' => 'modified', 'actor_type' => 'staff', 'reason_code' => 'agreed_with_guest', 'payload' => '{"before":{"requested_date":"2025-01-01","requested_time":"18:00","party_size":2},"after":{"requested_date":"2025-01-02","requested_time":"19:00","party_size":3},"override_reason":"MARKER-OVR Aisha asked by phone"}', 'created_at' => $now ] );
		$this->notification( $id, (string) $this->row( $id )['email'] );
		return $id;
	}

	protected function notification( int $reservation_id, string $email, string $status = 'sent', ?string $created = null ): int {
		global $wpdb;
		$created = $created ?? Clock::mysql_utc();
		$wpdb->insert(
			Tables::notifications(),
			[
				'uuid'             => wp_generate_uuid4(),
				'dedupe_key'       => 'test-' . wp_generate_uuid4(),
				'template_key'     => 'E01',
				'template_variant' => 'received',
				'lang'             => 'en',
				'recipient_type'   => 'customer',
				'recipient_email'  => $email,
				'recipient_hash'   => \Momos\Notification\Recipient\RecipientResolver::hash( $email ),
				'recipient_masked' => Hash::mask_email( $email ),
				'reservation_id'   => $reservation_id,
				'status'           => $status,
				'created_at'       => $created,
				'updated_at'       => $created,
				'sent_at'          => 'sent' === $status ? $created : null,
			]
		);
		return (int) $wpdb->insert_id;
	}

	/** @return array<string,mixed> */
	protected function notif_row( int $id ): array {
		global $wpdb;
		return (array) $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Tables::notifications() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore
	}

	/** @return list<array<int,mixed>> captured audit calls with this action */
	protected function audits_of( string $action ): array {
		return array_values( array_filter( $this->audits, static fn( $a ) => $a[0] === $action ) );
	}
}
