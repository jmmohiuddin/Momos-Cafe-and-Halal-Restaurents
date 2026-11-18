<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/AccessTestCaseTest.php';

use Momos\Audit\Admin\AuditLogScreen;
use Momos\Audit\AuditLogger;
use Momos\Audit\AuditRepository;
use Momos\Audit\Job\AuditRetentionJob;
use Momos\Kernel\Db\Tables;

/**
 * Audit log (FR-ADM-004, SEC-080…084, DEC-034/059; TC-ADM-002/011, TC-ACL-018, TC-PRV-008/017,
 * AC-ADM-03-01…04).
 */
final class AuditLoggerTest extends AccessTestCaseTest {
	public function test_entry_has_actor_branch_masked_changes_truncated_ip_tc_adm_002(): void {
		$bm = $this->user_with_role( 'momo_branch_manager' );
		wp_set_current_user( $bm );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.45';
		momos_audit( 'menu_item.price_changed', 'branch_menu_item', 12, 7, [ 'price' => [ 'from' => 1500, 'to' => 1700 ], 'email' => 'guest@example.org', 'note' => 'call 090-1234-5678' ] );
		$rows = $this->audit_rows( 'menu_item.price_changed' );
		$this->assertCount( 1, $rows );
		$r = $rows[0];
		$this->assertSame( 'staff', $r['actor_type'] );
		$this->assertSame( (string) $bm, $r['actor_id'] );
		$this->assertSame( 'momo_branch_manager', $r['actor_role'] );
		$this->assertSame( '7', $r['branch_id'] );
		$this->assertSame( '203.0.113.0/24', $r['ip_truncated'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/', $r['request_id'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $r['row_hmac'] );
		$c = json_decode( $r['changes'], true );
		$this->assertSame( [ 'from' => 1500, 'to' => 1700 ], $c['price'] );
		$this->assertSame( '[masked]', $c['email'] );
		$this->assertSame( '[masked]', $c['note'] );
		$this->assertStringNotContainsString( 'guest@example.org', $r['changes'] );
	}

	public function test_masking_keeps_structure_and_scrubs_free_text_tc_prv_008(): void {
		$m = AuditLogger::mask( [ 'guest_name' => [ 'from' => 'Taro', 'to' => 'Jiro' ], 'reason' => 'mail x@y.jp or +819012345678 on 2026-11-18 10:00:00', 'nested' => [ 'phone_e164' => '+81312345678' ] ] );
		$this->assertSame( [ 'from' => '[masked]', 'to' => '[masked]' ], $m['guest_name'] );
		$this->assertSame( 'mail [email] or [phone] on 2026-11-18 10:00:00', $m['reason'] );
		$this->assertSame( '[masked]', $m['nested']['phone_e164'] );
	}

	public function test_ipv6_truncated_to_48_and_system_actor(): void {
		$_SERVER['REMOTE_ADDR'] = '2001:db8:1:2:3::9';
		wp_set_current_user( 0 );
		momos_audit( 'retention.run', 'audit', null, null, [] );
		$r = $this->audit_rows( 'retention.run' )[0];
		$this->assertSame( '2001:db8:1::/48', $r['ip_truncated'] );
		$this->assertSame( 'system', $r['actor_type'] );
		$this->assertNull( $r['actor_id'] );
	}

	public function test_unknown_action_logged_but_recorded_outside_production(): void {
		$id = AuditLogger::record( 'something.unknown', 'x' );
		$this->assertNotNull( $id, 'development records so parallel modules are not blocked' );
		$this->assertSame( 'user.role_changed', \Momos\Audit\Catalogue::normalize( 'user.role_assigned' ) );
	}

	public function test_hmac_chain_detects_tampering_and_deletion(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			momos_audit( 'settings.updated', 'settings', null, null, [ 'i' => $i ] );
		}
		$first = (int) $this->audit_rows()[0]['id'];
		$_GET  = [];
		$ok    = AuditRepository::verify( $first );
		$this->assertSame( [], $ok['failed'] );
		global $wpdb;
		$rows = $this->audit_rows( 'settings.updated' );
		$wpdb->update( Tables::audit(), [ 'changes' => '{"i":99}' ], [ 'id' => $rows[2]['id'] ] );
		$this->assertContains( (int) $rows[2]['id'], AuditRepository::verify( $first )['failed'] );
		$wpdb->update( Tables::audit(), [ 'changes' => $rows[2]['changes'] ], [ 'id' => $rows[2]['id'] ] );
		$this->assertSame( [], AuditRepository::verify( $first )['failed'] );
		$wpdb->delete( Tables::audit(), [ 'id' => $rows[2]['id'] ] );
		$this->assertContains( (int) $rows[3]['id'], AuditRepository::verify( $first )['failed'], 'deleted row breaks the chain' );
	}

	public function test_access_denied_deduplicated_per_request(): void {
		momos_audit( 'access.denied', 'reservation', 55, 3, [ 'reason' => 'forbidden_branch' ] );
		do_action( 'momos/access/denied', [ 'object_type' => 'reservation', 'object_id' => 55, 'capability' => 'momo_view_reservation' ] );
		$this->assertCount( 1, $this->audit_rows( 'access.denied' ) );
		do_action( 'momos/access/denied', [ 'object_type' => 'notification', 'object_id' => 77, 'capability' => 'x', 'email' => 'leak@example.org' ] );
		$rows = $this->audit_rows( 'access.denied' );
		$this->assertCount( 2, $rows );
		$this->assertStringNotContainsString( 'leak@', (string) $rows[1]['changes'] );
		do_action( 'momos/fields/denied', 'option', 'momo-settings', 'momo_settings_security', [ 'momo_csp_mode' ], 0 );
		$this->assertCount( 3, $this->audit_rows( 'access.denied' ) );
	}

	public function test_option_saves_become_settings_updated(): void {
		do_action( 'momos/fields/saved', 'option', 'momo-settings', 'momo_settings_privacy', [ 'momo_retention_pii_months' => [ '12', '18' ] ], 1 );
		do_action( 'momos/fields/saved', 'option', 'momo-brand', 'group_momo_brand', [ 'momo_contact_email' => [ 'a@momos.example', 'b@momos.example' ] ], 1 );
		$s = json_decode( $this->audit_rows( 'settings.updated' )[0]['changes'], true );
		$this->assertSame( [ 'from' => '12', 'to' => '18' ], $s['changes']['momo_retention_pii_months'] );
		$b = $this->audit_rows( 'brand.updated' )[0]['changes'];
		$this->assertStringNotContainsString( 'momos.example', $b );
	}

	public function test_translation_saved_hook(): void {
		$page = self::factory()->post->create( [ 'post_type' => 'page' ] );
		do_action( 'momos/i18n/translation_saved', 'post', $page, 'en', 'needs_review', 'approved', [ 'title' ], 1 );
		$this->assertCount( 1, $this->audit_rows( 'translation.approved' ) );
	}

	public function test_detail_viewed_deduplicated_30_minutes(): void {
		wp_set_current_user( $this->user_with_role( 'momo_head_office' ) );
		momos_audit( 'reservation.detail_viewed', 'reservation', 9, 1 );
		momos_audit( 'reservation.detail_viewed', 'reservation', 9, 1 );
		$this->assertCount( 1, $this->audit_rows( 'reservation.detail_viewed' ) );
	}

	public function test_retention_purge_24_and_12_months_dec_034(): void {
		global $wpdb;
		$this->freeze( '2026-11-18 12:00:00' );
		$ins = static function ( string $action, string $when ) use ( $wpdb ): int {
			$wpdb->insert( Tables::audit(), [ 'occurred_at' => $when, 'actor_type' => 'system', 'action' => $action, 'object_type' => 'x' ] );
			return (int) $wpdb->insert_id;
		};
		$old_domain = $ins( 'branch.hours_updated', '2024-11-18 02:59:00' ); // > 24 months → purged
		$new_domain = $ins( 'branch.hours_updated', '2024-11-18 03:01:00' ); // < 24 months → kept
		$old_act    = $ins( 'auth.login_failed', '2025-11-18 02:00:00' );    // activity > 12 months → purged
		$new_act    = $ins( 'auth.login_failed', '2025-11-18 04:00:00' );    // kept
		$out = AuditRetentionJob::run();
		$this->assertGreaterThanOrEqual( 1, $out['domain'] );
		$this->assertGreaterThanOrEqual( 1, $out['activity'] );
		$exists = static fn( int $id ): bool => (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . Tables::audit() . ' WHERE id = %d', $id ) ); // phpcs:ignore
		$this->assertFalse( $exists( $old_domain ) );
		$this->assertTrue( $exists( $new_domain ) );
		$this->assertFalse( $exists( $old_act ) );
		$this->assertTrue( $exists( $new_act ) );
		$this->assertCount( 1, $this->audit_rows( 'retention.run' ) );
		\Momos\Kernel\Settings::set( 'momo_retention_audit_months', '6' );
		$this->assertSame( 12, AuditRetentionJob::domain_months(), 'lower bound 12 months' );
	}

	public function test_viewer_forbidden_for_bm_tc_acl_018_and_filters(): void {
		$bm = $this->user_with_role( 'momo_branch_manager' );
		wp_set_current_user( $bm );
		try {
			AuditLogScreen::render();
			$this->fail( 'BM reached the audit log' );
		} catch ( \WPDieException $e ) {
			$denied = $this->audit_rows( 'access.denied' );
			$this->assertSame( 'audit', end( $denied )['object_type'] );
		}
		$ho = $this->user_with_role( 'momo_head_office' );
		wp_set_current_user( $ho );
		$this->freeze( '2031-03-03 12:00:00' );
		momos_audit( 'branch.hours_updated', 'branch', 5, 5, [ 'x' => 1 ] );
		momos_audit( 'user.disabled', 'user', 6, null, [] );
		$res = AuditRepository::search( [ 'from' => '2031-03-03', 'to' => '2031-03-03', 'action' => 'branch.*', 'actor' => $ho ] );
		$this->assertSame( 1, $res['total'] );
		$this->assertSame( 0, AuditRepository::search( [ 'from' => '2031-03-04', 'to' => '2031-03-04', 'actor' => $ho ] )['total'] );
		$this->assertSame( 1, AuditRepository::search( [ 'from' => '2031-03-03', 'to' => '2031-03-03', 'branch_id' => 5, 'actor' => $ho ] )['total'] );
		$this->assertSame( 2, AuditRepository::search( [ 'from' => '2031-03-03', 'to' => '2031-03-03', 'actor' => $ho ] )['total'] );
		ob_start();
		AuditLogScreen::render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'branch.hours_updated', $html );
		$this->assertStringNotContainsString( 'type="submit" name="delete"', $html );
		$this->assertSame( [ 'x: 1' ], AuditLogScreen::change_lines( '{"x":1}' ) );
	}
}
