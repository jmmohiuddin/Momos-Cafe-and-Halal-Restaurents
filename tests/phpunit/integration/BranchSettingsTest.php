<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/BranchTestCaseTest.php';

use Momos\Branch\Repository\BranchSettingsRepository;
use Momos\Branch\Service\SettingsValidator;

/**
 * G6 reservation settings (FR-RES-023/024, BR-RES-15, PRV-010, FR-RES-026): TC-BR-008, TC-RES-038,
 * TC-ADM-010, AC-RES-06-01/04.
 */
final class BranchSettingsTest extends BranchTestCaseTest {
	/** @return array<string,mixed> */
	private function values( array $over = [] ): array {
		return array_merge( BranchSettingsRepository::DEFAULTS, [ 'momo_res_notification_recipients' => [ [ 'email' => 'shop@momos.example', 'language' => 'ja', 'active' => '1' ] ] ], $over );
	}

	public function test_br_res_15_cutoff_vs_lead_time_ac_res_06_01(): void {
		update_option( 'momo_min_decision_window_minutes', 30 );
		$errors = SettingsValidator::validate( $this->values( [ 'momo_res_lead_time_minutes' => '60', 'momo_res_decision_cutoff_minutes' => '40' ] ), 0 );
		$this->assertContains( 'The decision cutoff must be at least 30 minutes shorter than the minimum lead time.', $errors );
		$this->assertSame( [], SettingsValidator::validate( $this->values( [ 'momo_res_lead_time_minutes' => '60', 'momo_res_decision_cutoff_minutes' => '30' ] ), 0 ) );
	}

	public function test_ranges_party_and_slot_interval(): void {
		$this->assertNotEmpty( SettingsValidator::validate( $this->values( [ 'momo_res_party_min' => '0' ] ), 0 ) );
		$this->assertNotEmpty( SettingsValidator::validate( $this->values( [ 'momo_res_party_max' => '51' ] ), 0 ) );
		$this->assertContains( 'Minimum party size cannot be larger than the maximum.', SettingsValidator::validate( $this->values( [ 'momo_res_party_min' => '6', 'momo_res_party_max' => '4' ] ), 0 ) );
		$this->assertNotEmpty( SettingsValidator::validate( $this->values( [ 'momo_res_slot_interval_minutes' => '20' ] ), 0 ) );
		$this->assertNotEmpty( SettingsValidator::validate( $this->values( [ 'momo_res_response_sla_minutes' => '10' ] ), 0 ) );
		$this->assertSame( [], SettingsValidator::validate( $this->values( [ 'momo_res_response_sla_minutes' => '0' ] ), 0 ) );
		$this->assertNotEmpty( SettingsValidator::validate( $this->values( [ 'momo_res_booking_window_days' => '366' ] ), 0 ) );
	}

	public function test_recipient_domain_rules_ac_res_06_04(): void {
		$errors = SettingsValidator::validate( $this->values( [ 'momo_res_notification_recipients' => [ [ 'email' => 'branch.a@gmail.com', 'language' => 'ja', 'active' => '1' ] ] ] ), 0 );
		$this->assertContains( 'Use a business-controlled address (personal webmail is not allowed).', $errors );
		update_option( 'momo_allowed_recipient_domains', [ 'momos.example' ] );
		$this->assertNotEmpty( SettingsValidator::validate( $this->values( [ 'momo_res_notification_recipients' => [ [ 'email' => 'x@other.example', 'language' => 'ja', 'active' => '1' ] ] ] ), 0 ) );
		$this->assertSame( [], SettingsValidator::validate( $this->values(), 0 ) );
		$dupes = [ [ 'email' => 'A@momos.example', 'language' => 'ja', 'active' => '1' ], [ 'email' => 'a@momos.example', 'language' => 'en', 'active' => '1' ] ];
		$this->assertContains( 'Each recipient email can be listed only once.', SettingsValidator::validate( $this->values( [ 'momo_res_notification_recipients' => $dupes ] ), 0 ) );
	}

	public function test_enable_preconditions(): void {
		$b      = $this->make_branch( 'QAA' );
		$errors = SettingsValidator::validate( $this->values( [ 'momo_res_enabled' => '1' ] ), $b );
		$this->assertNotEmpty( $errors, 'no weekly periods yet' );
		$this->set_week( $b, [ 1 => [ [ '11:00', '15:00', '14:30' ] ] ] );
		$this->assertSame( [], SettingsValidator::validate( $this->values( [ 'momo_res_enabled' => '1' ] ), $b ) );
		$this->set_week( $b, [ 1 => [ [ '11:00', '15:00', null ] ] ] );
		$this->assertNotEmpty( SettingsValidator::validate( $this->values( [ 'momo_res_enabled' => '1' ] ), $b ), 'period without last order' );
		update_post_meta( $b, 'momo_operating_status', 'temporarily_closed' );
		$this->set_week( $b, [ 1 => [ [ '11:00', '15:00', '14:30' ] ] ] );
		$this->assertNotEmpty( SettingsValidator::validate( $this->values( [ 'momo_res_enabled' => '1' ] ), $b ), 'not open' );
	}

	public function test_test_branch_must_not_reuse_real_recipients(): void {
		$real = $this->make_branch( 'QAA' );
		update_post_meta( $real, 'momo_res_notification_recipients', [ [ 'email' => 'shop@momos.example', 'language' => 'ja', 'active' => '1' ] ] );
		$tst = $this->make_branch( 'TST', [ 'momo_is_test_branch' => '1' ] );
		$this->assertNotEmpty( SettingsValidator::validate( $this->values(), $tst ) );
		$this->assertSame( [], SettingsValidator::validate( $this->values( [ 'momo_res_notification_recipients' => [ [ 'email' => 'qa@momos.example', 'language' => 'en', 'active' => '1' ] ] ] ), $tst ) );
	}

	public function test_repository_defaults_and_invalid_values_disable(): void {
		$b = $this->make_branch( 'QAA' );
		$s = BranchSettingsRepository::get( $b );
		$this->assertFalse( $s['enabled'] );
		$this->assertSame( 1, $s['party_min'] );
		$this->assertSame( 8, $s['party_max'] );
		$this->assertSame( 120, $s['lead_time_minutes'] );
		$this->assertSame( 30, $s['slot_interval_minutes'] );
		$this->assertSame( 'wall_clock', $s['sla_clock'] );
		$this->assertNull( $s['policy_page'] );
		update_post_meta( $b, 'momo_res_enabled', '1' );
		$this->assertTrue( BranchSettingsRepository::get( $b )['enabled'] );
		update_post_meta( $b, 'momo_res_decision_cutoff_minutes', '115' ); // Violates BR-RES-15 with lead 120.
		$this->assertFalse( BranchSettingsRepository::get( $b )['enabled'], 'invalid stored rules refuse requests' );
	}

	public function test_active_recipients(): void {
		$b = $this->make_branch( 'QAA' );
		update_post_meta( $b, 'momo_res_notification_recipients', [ [ 'email' => 'Shop@Momos.example', 'language' => 'en', 'active' => '1' ], [ 'email' => 'old@momos.example', 'language' => 'ja', 'active' => '0' ] ] );
		$this->assertSame( [ [ 'email' => 'shop@momos.example', 'language' => 'en' ] ], BranchSettingsRepository::active_recipients( $b ) );
	}
}
