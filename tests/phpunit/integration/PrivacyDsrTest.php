<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/PrivacyTestCaseTest.php';

use Momos\Kernel\Db\Tables;
use Momos\Kernel\Support\Crockford;
use Momos\Privacy\Admin\DsrScreen;
use Momos\Privacy\Dsr\DsrService;
use Momos\Privacy\Dsr\PersonalData;
use Momos\Privacy\Retention\RetentionJob;

/**
 * DSR tool SCR-A19 and core exporter/eraser (FR-PRV-004, TC-PRV-006, AC-PRV-03-01…03, SEC-008).
 */
final class PrivacyDsrTest extends PrivacyTestCaseTest {
	private int $qpa = 0;

	private int $qpb = 0;

	public function set_up(): void {
		parent::set_up();
		$this->qpa = $this->make_branch( 'QPA' );
		$this->qpb = $this->make_branch( 'QPB' );
	}

	public function tear_down(): void {
		$_POST = [];
		$_GET  = [];
		$_REQUEST = [];
		parent::tear_down();
	}

	/** @return array{0:int,1:int,2:int,3:int} three of aisha's reservations over two branches + one of another guest */
	private function seed(): array {
		$a = $this->rich_reservation( $this->qpa, '2026-06-01' );
		$b = $this->rich_reservation( $this->qpb, '2026-07-01', '18:00', [ 'status' => 'cancelled' ] );
		$c = $this->rich_reservation( $this->qpa, '2026-12-05', '19:00', [ 'status' => 'confirmed' ] ); // active future
		$o = $this->rich_reservation( $this->qpb, '2026-06-02', '19:00', [ 'guest_name' => 'Kenji Sato', 'email' => 'kenji@example.org' ] );
		return [ $a, $b, $c, $o ];
	}

	private function as_role( string $role ): int {
		$uid = $this->staff( $role, 'momo_head_office' === $role || 'administrator' === $role ? [] : [ $this->qpa ] );
		wp_set_current_user( $uid );
		return $uid;
	}

	public function test_search_by_email_is_exact_case_insensitive_and_across_branches(): void {
		[ $a, $b, $c, $o ] = $this->seed();
		$this->as_role( 'momo_head_office' );
		foreach ( [ 'aisha@example.org', '  Aisha@EXAMPLE.org ', 'AISHA@example.org' ] as $q ) {
			$r = DsrService::search( $q );
			$this->assertSame( 'email', $r['by'] );
			$this->assertEqualsCanonicalizing( [ $a, $b, $c ], $r['ids'], $q );
		}
		$this->assertSame( 'invalid', DsrService::search( 'aisha' )['error'], 'no partial match' );
		$this->assertSame( 'invalid', DsrService::search( 'aisha@' )['error'] );
		$this->assertSame( 'empty', DsrService::search( '   ' )['error'] );
		$this->assertSame( [], DsrService::search( 'isha@example.org' )['ids'] );

		$searched = $this->audits_of( 'dsr.searched' );
		$this->assertSame( [ 'by' => 'email', 'results' => 3 ], $searched[0][4] );
		$this->assertStringNotContainsString( 'aisha', strtolower( (string) wp_json_encode( $this->audits ) ) );
	}

	public function test_search_by_reference_and_purged_rows_are_hidden(): void {
		[ $a ] = $this->seed();
		$ref   = (string) $this->row( $a )['public_ref'];
		$this->as_role( 'momo_head_office' );
		$this->assertSame( [ $a ], DsrService::search( strtolower( Crockford::display( $ref ) ) )['ids'] );
		$this->assertSame( 'reference', DsrService::search( $ref )['by'] );
		\Momos\Privacy\Retention\Anonymizer::anonymize_reservation( $a, 'dsr' );
		$this->assertSame( [], DsrService::search( $ref )['ids'] );
		$this->assertNotContains( $a, DsrService::search( 'aisha@example.org' )['ids'] );
	}

	public function test_export_bundle_is_complete_and_audited(): void {
		[ $a, $b, $c ] = $this->seed();
		$this->as_role( 'momo_head_office' );
		$bundle = DsrService::bundle( [ $a, $b, $c ], 'email' );
		$this->assertSame( 'momos-dsr-export', $bundle['format'] );
		$this->assertCount( 3, $bundle['reservations'] );
		$r = $bundle['reservations'][0];
		$this->assertSame( Crockford::display( (string) $this->row( $a )['public_ref'] ), $r['reference'] );
		$this->assertSame( [ 'code' => 'QPA', 'name' => '支店QPA' ], $r['branch'] );
		$this->assertSame( 'Aisha Rahman', $r['name'] );
		$this->assertSame( 'aisha@example.org', $r['email'] );
		$this->assertSame( '+601234567890', $r['phone'] );
		$this->assertSame( 'MARKER-SR peanut allergy', $r['special_request'] );
		$this->assertSame( 'MARKER-MSG see you soon', $r['message_to_customer'] );
		$this->assertSame( 'PP1.0-RP1.0', $r['consent']['version'] );
		$this->assertSame( '19:00', $r['requested_time_jst'] );
		$this->assertSame( [ 'created', 'modified' ], array_column( $r['events'], 'type' ) );
		$this->assertSame( 'E01', $r['notifications'][0]['template'] );
		$this->assertArrayNotHasKey( 'recipient_email', $r['notifications'][0] );
		$json = (string) wp_json_encode( $bundle );
		$this->assertStringNotContainsString( 'MARKER-NOTE', $json, 'staff internal notes excluded' );
		$this->assertStringNotContainsString( 'token_hash', $json );
		$this->assertStringNotContainsString( str_repeat( 'b', 64 ), $json, 'no abuse hashes' );
		$this->assertMatchesRegularExpression( '/^momo-dsr-export-\d{8}-\d{6}\.json$/', DsrService::export_filename() );
		$this->assertSame( [ 'by' => 'email', 'count' => 3 ], $this->audits_of( 'dsr.exported' )[0][4] );
	}

	public function test_correct_validates_and_audits_masked(): void {
		[ $a, $b ] = $this->seed();
		$this->as_role( 'momo_head_office' );
		$bad = DsrService::correct( [ $a ], [ 'email' => 'not-an-email', 'name' => "Bad\nName" ], 'subject_request' );
		$this->assertSame( [ 'name', 'email' ], array_keys( $bad['errors'] ) );
		$this->assertSame( 'reason', array_key_first( DsrService::correct( [ $a ], [ 'name' => 'Aisha R.' ], '' )['errors'] ) );
		$this->assertArrayHasKey( 'request_ref', DsrService::correct( [ $a ], [ 'name' => 'Aisha R.' ], 'other', 'has space' )['errors'] );
		$this->assertArrayHasKey( 'fields', DsrService::correct( [ $a ], [], 'other' )['errors'] );
		$this->assertSame( 'Aisha Rahman', $this->row( $a )['guest_name'] );

		$v   = (int) $this->row( $a )['version'];
		$out = DsrService::correct( [ $a, $b ], [ 'name' => 'Aisha R. Khan', 'email' => 'Aisha.New@Example.ORG', 'phone' => '090-1234-5678', 'preferred_lang' => 'ja' ], 'subject_request', 'DSR-2026-014' );
		$this->assertSame( [], $out['errors'] );
		$this->assertSame( 2, $out['updated'] );
		$row = $this->row( $a );
		$this->assertSame( 'Aisha R. Khan', $row['guest_name'] );
		$this->assertSame( 'Aisha.New@example.org', $row['email'] );
		$this->assertSame( '+819012345678', $row['phone_e164'] );
		$this->assertSame( 'ja', $row['preferred_lang'] );
		$this->assertSame( $v + 1, (int) $row['version'] );

		$aud = $this->audits_of( 'dsr.corrected' );
		$this->assertCount( 2, $aud );
		$this->assertSame( 'reservation', $aud[0][1] );
		$this->assertSame( [ 'guest_name', 'email', 'phone_e164', 'preferred_lang', 'reason', 'request_ref' ], array_keys( $aud[0][4] ) );
		// Stored audit row: personal values masked by the audit writer.
		global $wpdb;
		$stored = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT changes FROM ' . Tables::audit() . " WHERE action = 'dsr.corrected' AND object_id = %d", $a ) ); // phpcs:ignore
		$this->assertStringContainsString( '[masked]', $stored );
		$this->assertStringNotContainsString( 'Aisha', $stored );
		$this->assertStringNotContainsString( '9012345678', $stored );
	}

	public function test_anonymize_requires_typed_reference_and_blocks_active_future(): void {
		[ $a, $b, $c ] = $this->seed();
		$this->as_role( 'momo_head_office' );
		$ref_a = (string) $this->row( $a )['public_ref'];
		$this->assertSame( 'confirm_mismatch', DsrService::anonymize( $a, 'WRONG123', 'erasure_request' ) );
		$this->assertSame( 'invalid_reason', DsrService::anonymize( $a, $ref_a, 'because' ) );
		$this->assertSame( 'blocked_active', DsrService::anonymize( $c, (string) $this->row( $c )['public_ref'], 'erasure_request' ) );
		$this->assertNull( $this->row( $c )['pii_purged_at'] );
		$this->assertNull( $this->row( $a )['pii_purged_at'] );

		$this->assertSame( 'anonymized', DsrService::anonymize( $a, strtolower( Crockford::display( $ref_a ) ), 'erasure_request', 'DSR-7' ) );
		$row = $this->row( $a );
		$this->assertNotNull( $row['pii_purged_at'] );
		$this->assertNull( $row['email'] );
		$this->assertNull( $row['token_hash'], 'status link no longer works' );
		$purged = array_values( array_filter( $this->events( $a ), static fn( $e ) => 'pii_purged' === $e['event_type'] ) );
		$this->assertSame( 'dsr', $purged[0]['reason_code'] );
		$this->assertSame( [ 'reason' => 'erasure_request', 'request_ref' => 'DSR-7', 'channel' => 'dsr_tool' ], $this->audits_of( 'dsr.anonymized' )[0][4] );
		$this->assertSame( 'not_found', DsrService::anonymize( $a, $ref_a, 'erasure_request' ) );
		$this->assertSame( 'Aisha Rahman', $this->row( $b )['guest_name'], 'one reservation per confirmation' );
	}

	public function test_core_exporter_and_eraser(): void {
		[ $a, $b, $c, $o ] = $this->seed();
		wp_set_current_user( $this->staff( 'administrator' ) );
		$this->assertArrayHasKey( PersonalData::ID, apply_filters( 'wp_privacy_personal_data_exporters', [] ) );
		$this->assertArrayHasKey( 'momos-notifications', apply_filters( 'wp_privacy_personal_data_exporters', [] ) );
		$this->assertArrayHasKey( PersonalData::ID, apply_filters( 'wp_privacy_personal_data_erasers', [] ) );

		$export = PersonalData::export( 'AISHA@example.org', 1 );
		$this->assertTrue( $export['done'] );
		$this->assertCount( 3, $export['data'] );
		$values = (string) wp_json_encode( $export['data'] );
		$this->assertStringContainsString( 'MARKER-SR', $values );
		$this->assertStringNotContainsString( 'Kenji', $values );
		$this->assertStringNotContainsString( 'MARKER-NOTE', $values );

		$erase = PersonalData::erase( 'aisha@example.org', 1 );
		$this->assertTrue( $erase['items_removed'] );
		$this->assertTrue( $erase['items_retained'] );
		$this->assertCount( 1, $erase['messages'] );
		$this->assertStringContainsString( Crockford::display( (string) $this->row( $c )['public_ref'] ), $erase['messages'][0] );
		$this->assertNotNull( $this->row( $a )['pii_purged_at'] );
		$this->assertNotNull( $this->row( $b )['pii_purged_at'] );
		$this->assertNull( $this->row( $c )['pii_purged_at'] );
		$this->assertSame( 'Kenji Sato', $this->row( $o )['guest_name'] );
		$this->assertCount( 2, $this->audits_of( 'dsr.anonymized' ) );
		global $wpdb;
		$this->assertSame( '1', (string) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::reservations() . " WHERE email = 'aisha@example.org'" ) ); // phpcs:ignore
	}

	public function test_permissions_and_vendor_denial(): void {
		$this->seed();
		$ho = $this->staff( 'momo_head_office' );
		$this->assertTrue( user_can( $ho, 'momo_manage_dsr' ) );
		$this->assertFalse( user_can( $ho, 'export_others_personal_data' ), 'core tools stay SA-only' );

		$sa = $this->staff( 'administrator' );
		$this->assertTrue( user_can( $sa, 'momo_manage_dsr' ) );
		$this->assertTrue( user_can( $sa, 'export_others_personal_data' ) );
		$this->assertTrue( user_can( $sa, 'erase_others_personal_data' ) );

		$vendor = $this->staff( 'administrator' );
		update_user_meta( $vendor, 'momo_vendor', '1' );
		\Momos\Access\Roles\RoleInstaller::apply_user_denies( $vendor );
		clean_user_cache( $vendor );
		$this->assertFalse( user_can( $vendor, 'momo_manage_dsr' ) );
		$this->assertFalse( user_can( $vendor, 'export_others_personal_data' ) );
		$this->assertFalse( user_can( $vendor, 'erase_others_personal_data' ) );
		wp_set_current_user( $vendor );
		$this->assertSame( [], PersonalData::export( 'aisha@example.org' )['data'] );
		$this->assertFalse( PersonalData::erase( 'aisha@example.org' )['items_removed'] );

		foreach ( [ 'momo_branch_manager', 'momo_reservation_staff', 'momo_menu_manager' ] as $role ) {
			$uid = $this->staff( $role, [ $this->qpa ] );
			$this->assertFalse( user_can( $uid, 'momo_manage_dsr' ), $role );
		}
	}

	public function test_screen_and_handlers_deny_without_capability(): void {
		$this->seed();
		$this->as_role( 'momo_branch_manager' );
		$this->audits = [];
		foreach ( [ [ DsrScreen::class, 'render' ], [ DsrScreen::class, 'handle_search' ], [ DsrScreen::class, 'handle_export' ], [ DsrScreen::class, 'handle_anonymize' ] ] as $cb ) {
			try {
				$cb();
				$this->fail( 'expected wp_die' );
			} catch ( \WPDieException $e ) {
				$this->assertStringContainsString( 'permission', $e->getMessage() );
			}
		}
		$denied = $this->audits_of( 'access.denied' );
		$this->assertCount( 4, $denied );
		$this->assertSame( 'momo_manage_dsr', $denied[0][4]['cap'] );
		$this->assertSame( [], $this->audits_of( 'dsr.searched' ) );
	}

	public function test_search_handler_requires_nonce_and_keeps_pii_out_of_url(): void {
		[ $a, $b, $c ] = $this->seed();
		$this->as_role( 'momo_head_office' );
		$_POST    = [ 'q' => 'aisha@example.org' ];
		$_REQUEST = $_POST;
		try {
			DsrScreen::handle_search();
			$this->fail( 'expected nonce failure' );
		} catch ( \WPDieException $e ) {
			$this->assertSame( [], $this->audits_of( 'dsr.searched' ) );
		}

		$_POST['_momos_dsr_nonce'] = wp_create_nonce( DsrScreen::NONCE );
		$_REQUEST                  = $_POST;
		$location                  = '';
		$trap                      = static function ( $loc ) use ( &$location ) {
			$location = (string) $loc;
			throw new \RuntimeException( 'redirect' );
		};
		add_filter( 'wp_redirect', $trap );
		try {
			DsrScreen::handle_search();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirect', $e->getMessage() );
		} finally {
			remove_filter( 'wp_redirect', $trap );
		}
		$this->assertStringNotContainsString( 'aisha', strtolower( $location ) );
		$this->assertStringNotContainsString( '%40', (string) wp_parse_url( $location, PHP_URL_QUERY ) );
		$this->assertStringNotContainsString( '@', (string) wp_parse_url( $location, PHP_URL_QUERY ) );
		parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $q );
		$this->assertSame( 'searched', $q['m'] );
		$stored = DsrScreen::load( (string) $q['r'] );
		$this->assertEqualsCanonicalizing( [ $a, $b, $c ], $stored['ids'] );
		$this->assertSame( 'email', $stored['by'] );
		// Another user cannot use the token.
		wp_set_current_user( $this->staff( 'momo_head_office' ) );
		$this->assertNull( DsrScreen::load( (string) $q['r'] ) );
	}

	public function test_retention_and_dsr_share_one_anonymizer_and_screen_renders(): void {
		[ $a ] = $this->seed();
		$this->as_role( 'momo_head_office' );
		$token   = DsrScreen::store( DsrService::search( 'aisha@example.org' ) );
		$_GET    = [ 'page' => DsrScreen::SLUG, 'r' => $token ];
		ob_start();
		DsrScreen::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( Crockford::display( (string) $this->row( $a )['public_ref'] ), $html );
		$this->assertStringContainsString( 'momos_dsr_export', $html );
		$this->assertStringContainsString( 'momos_dsr_anonymize', $html );
		$this->assertStringNotContainsString( 'aisha@example.org', $html, 'result table shows no contact data' );
		$this->assertStringNotContainsString( 'Aisha Rahman', $html );
		$this->assertStringContainsString( 'PP1.0-RP1.0', $html );
		$this->assertNotNull( RetentionJob::run( true ) );
	}
}
