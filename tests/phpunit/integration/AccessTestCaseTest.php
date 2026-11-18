<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

use Momos\Audit\AuditLogger;
use Momos\Kernel\Db\Tables;
use Momos\Tests\TestCase;

/**
 * Shared helpers for the Access / Audit / Admin tests.
 */
abstract class AccessTestCaseTest extends TestCase {
	/** Audit rows written before this test (custom-table rows can survive committed transactions). */
	protected int $audit_floor = 0;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->audit_floor = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id),0) FROM ' . Tables::audit() ); // phpcs:ignore
		// Assignment rows of users that do not exist (left by earlier runs whose user IDs get reused).
		$wpdb->query( 'DELETE FROM ' . Tables::user_branches() . " WHERE user_id NOT IN (SELECT ID FROM {$wpdb->users})" ); // phpcs:ignore
		AuditLogger::reset_request_state();
		\Momos\Access\Login\LoginThrottle::reset_state();
		\Momos\Access\Admin\UserAccessFields::reset_state();
		\Momos\Kernel\Settings::flush();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.77';
	}

	public function tear_down(): void {
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		parent::tear_down();
	}

	protected function branch( string $code = 'QAA', string $status = 'publish' ): int {
		$id = wp_insert_post( [ 'post_type' => 'momo_branch', 'post_title' => 'Branch ' . $code, 'post_status' => $status ] );
		update_post_meta( $id, 'momo_branch_code', $code );
		return (int) $id;
	}

	protected function reservation( int $branch_id ): int {
		global $wpdb;
		$wpdb->insert( Tables::reservations(), [
			'public_ref' => strtoupper( substr( md5( uniqid( '', true ) ), 0, 8 ) ), 'idempotency_key' => wp_generate_uuid4(), 'branch_id' => $branch_id, 'status' => 'pending',
			'requested_date' => '2026-12-01', 'requested_time' => '18:00:00', 'requested_start_utc' => '2026-12-01 09:00:00',
			'party_size' => 2, 'guest_name' => 'A', 'email' => 'a@example.org', 'preferred_lang' => 'en', 'source_lang' => 'en',
			'consent_version' => 'v1', 'consent_at' => '2026-11-01 00:00:00', 'token_hash' => str_repeat( 'a', 64 ),
			'token_expires_utc' => '2026-12-02 00:00:00', 'created_at' => '2026-11-01 00:00:00', 'updated_at' => '2026-11-01 00:00:00',
		] );
		return (int) $wpdb->insert_id;
	}

	/** @return list<array<string,mixed>> */
	protected function audit_rows( ?string $action = null ): array {
		global $wpdb;
		$sql = 'SELECT * FROM ' . Tables::audit() . $wpdb->prepare( ' WHERE id > %d', $this->audit_floor ) . ( $action ? $wpdb->prepare( ' AND action = %s', $action ) : '' ) . ' ORDER BY id ASC';
		return (array) $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore
	}

	/** Start a cookie session for a user (token in the logged-in cookie). */
	protected function login_session( int $user_id, array $extra = [] ): string {
		$manager = \WP_Session_Tokens::get_instance( $user_id );
		$token   = $manager->create( time() + HOUR_IN_SECONDS );
		if ( $extra ) {
			$manager->update( $token, array_merge( (array) $manager->get( $token ), $extra ) );
		}
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user_id, time() + HOUR_IN_SECONDS, 'logged_in', $token );
		wp_set_current_user( $user_id );
		return $token;
	}
}
