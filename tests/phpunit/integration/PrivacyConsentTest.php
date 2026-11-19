<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/PrivacyTestCaseTest.php';

use Momos\Privacy\Consent\ConsentRegistry;

/**
 * Consent version registry (FR-PRV-002, PRV-003, TC-PRV-001/002, AC-PRV-01-02, Access spec §10.2).
 */
final class PrivacyConsentTest extends PrivacyTestCaseTest {
	public function set_up(): void {
		parent::set_up();
		delete_option( ConsentRegistry::PIN_OPTION );
		update_option(
			ConsentRegistry::OPTION,
			[ [ 'consent_key' => 'PP1.0-RP1.0', 'privacy_policy_version' => '1.0', 'reservation_policy_version' => '1.0', 'privacy_page_revision_id' => '', 'reservation_policy_revision_id' => '', 'effective_from' => '2026-11-01' ] ],
			false
		);
	}

	public function test_key_format_and_length(): void {
		$this->assertTrue( ConsentRegistry::is_valid_key( 'PP1.0-RP1.0' ) );
		$this->assertFalse( ConsentRegistry::is_valid_key( 'PP-2026-11-v1|RP-2026-11-v1' ), 'TRD example is 27 characters' );
		$this->assertFalse( ConsentRegistry::is_valid_key( 'PP1234.5678-RP1234.5678' ), 'longer than varchar(20)' );
		$this->expectException( \InvalidArgumentException::class );
		ConsentRegistry::add_version( '1.0', '1.0', '2026-12-01' ); // duplicate key
	}

	public function test_registry_version_in_force_follows_effective_from(): void {
		$this->assertSame( 'PP1.0-RP1.0', ConsentRegistry::current() );
		$this->assertSame( 'unversioned', ConsentRegistry::stored_key(), 'Reservation stores the pin; none selected yet (M0005)' );

		$row = ConsentRegistry::add_version( '1.1', '1.0', '2026-12-01 09:00' );
		$this->assertSame( 'PP1.1-RP1.0', $row['consent_key'] );
		$this->assertCount( count( \Momos\Kernel\I18n\Lang::enabled() ), $row['text_sha256'] );
		$this->assertSame( hash( 'sha256', momos_t( 'res.label.consent', [], 'ja' ) ), $row['text_sha256']['ja'] );
		$this->assertSame( 'PP1.0-RP1.0', ConsentRegistry::current(), 'not yet effective' );
		$this->assertCount( 1, $this->audits_of( 'settings.updated' ) );
		$this->freeze( '2026-12-01 08:59:00' );
		$this->assertSame( 'PP1.0-RP1.0', ConsentRegistry::current() );
		$this->freeze( '2026-12-01 09:00:00' );
		$this->assertSame( 'PP1.1-RP1.0', ConsentRegistry::current() );
	}

	public function test_v1_to_v2_new_rows_store_v2_old_rows_keep_v1(): void {
		$this->make_branch( 'QPS' );
		ConsentRegistry::activate( 'PP1.0-RP1.0' );
		$this->assertSame( 'PP1.0-RP1.0', ConsentRegistry::stored_key() );
		$first = $this->submit( $this->body( 'QPS', '2026-11-25', '19:00' ) );
		$this->assertSame( 201, $first->get_status(), (string) wp_json_encode( $first->get_data() ) );

		ConsentRegistry::add_version( '2.0', '1.0', '2026-11-19' );
		try {
			ConsentRegistry::add_version( '3.0', '1.0', '2027-01-01' );
			ConsentRegistry::activate( 'PP3.0-RP1.0' );
			$this->fail( 'future version activated' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( 'PP1.0-RP1.0', ConsentRegistry::stored_key() );
		}
		ConsentRegistry::activate( 'PP2.0-RP1.0' );
		$second = $this->submit( $this->body( 'QPS', '2026-11-26', '19:00', [ 'email' => 'second@example.org' ] ) );
		$this->assertSame( 201, $second->get_status() );

		global $wpdb;
		$keys = $wpdb->get_col( 'SELECT consent_version FROM ' . \Momos\Kernel\Db\Tables::reservations() . ' ORDER BY id ASC' ); // phpcs:ignore
		$this->assertSame( [ 'PP1.0-RP1.0', 'PP2.0-RP1.0' ], $keys );
		$changes = array_column( array_column( $this->audits_of( 'settings.updated' ), 4 ), 'changes' );
		$this->assertContains( [ ConsentRegistry::PIN_OPTION => [ 'from' => 'PP1.0-RP1.0', 'to' => 'PP2.0-RP1.0' ] ], $changes );

		$history = ConsentRegistry::history();
		$this->assertSame( [ 'PP1.0-RP1.0', 'PP2.0-RP1.0', 'PP3.0-RP1.0' ], array_column( $history, 'consent_key' ) );
		$this->assertSame( [ 1, 1, 0 ], array_column( $history, 'reservations' ) );
		$this->assertSame( [ false, true, false ], array_column( $history, 'active' ) );
	}

	public function test_explicit_pin_wins_while_valid_and_effective(): void {
		ConsentRegistry::add_version( '1.1', '1.0', '2026-11-10' );
		update_option( ConsentRegistry::PIN_OPTION, 'PP1.0-RP1.0' );
		$this->assertSame( 'PP1.0-RP1.0', ConsentRegistry::current() );
		update_option( ConsentRegistry::PIN_OPTION, 'PP9.9-RP9.9' ); // not in the registry → registry decides
		$this->assertSame( 'PP1.1-RP1.0', ConsentRegistry::current() );
		update_option( ConsentRegistry::OPTION, [], false );
		update_option( ConsentRegistry::PIN_OPTION, '' );
		$this->assertSame( ConsentRegistry::UNVERSIONED, ConsentRegistry::current() );
		$this->expectException( \InvalidArgumentException::class );
		ConsentRegistry::activate( 'PP1.0-RP1.0' );
	}

	public function test_settings_save_keeps_hashes_and_creator(): void {
		$uid = $this->staff( 'momo_head_office' );
		wp_set_current_user( $uid );
		ConsentRegistry::add_version( '1.1', '1.0', '2026-12-01' );
		$stored = get_option( ConsentRegistry::OPTION );
		// The settings screen saves only its sub-fields (no hashes, no creator).
		$trimmed = array_map( static fn( $r ) => array_diff_key( $r, [ 'text_sha256' => 1, 'created_by' => 1 ] ), $stored );
		update_option( ConsentRegistry::OPTION, $trimmed, false );
		$after = get_option( ConsentRegistry::OPTION );
		$this->assertSame( $stored[1]['text_sha256'], $after[1]['text_sha256'] );
		$this->assertSame( $uid, (int) $after[1]['created_by'] );
		$this->assertNotEmpty( $after[0]['text_sha256'], 'filled for rows created without hashes' );
	}

	public function test_invalid_input_rejected(): void {
		foreach ( [ [ '1', '1.0', '2026-12-01' ], [ '1.0', '1.0b', '2026-12-01' ], [ '1.2', '1.0', '01/12/2026' ] ] as [ $pp, $rp, $eff ] ) {
			try {
				ConsentRegistry::add_version( $pp, $rp, $eff );
				$this->fail( "accepted {$pp} {$rp} {$eff}" );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
		$this->assertCount( 1, get_option( ConsentRegistry::OPTION ) );
	}
}
