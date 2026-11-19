<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

use Momos\Kernel\Db\Tables;
use Momos\Seed\SampleGuard;
use Momos\Seed\Writer;
use Momos\Tests\TestCase;

/**
 * BR-CORP-03 / content-strings.md §10.1 D2 (DEF-012, QA-024): in production no sample-flagged branch, offer,
 * notice, page or post can be public, whichever channel publishes it; each block shows the admin error of the
 * dish/offer rules and is audited. The rule is switched on here with the `momos/seed/block_sample_publish`
 * filter (the test install is not production) and checked against a production environment once.
 */
final class SeedSampleGuardTest extends TestCase {
	public function set_up(): void {
		parent::set_up();
		SampleGuard::reset();
		$GLOBALS['momos_last_field_errors'] = [];
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function tear_down(): void {
		remove_all_filters( 'momos/seed/block_sample_publish' );
		SampleGuard::reset();
		parent::tear_down();
	}

	private function block_on(): void {
		add_filter( 'momos/seed/block_sample_publish', '__return_true' );
	}

	private function audits( int $post_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Tables::audit() . ' WHERE action = %s AND object_id = %d', SampleGuard::AUDIT_ACTION, $post_id ) ); // phpcs:ignore
	}

	private function sample_draft( string $type ): int {
		$id = self::factory()->post->create( [ 'post_type' => $type, 'post_title' => 'QA sample ' . $type, 'post_status' => 'draft' ] );
		update_post_meta( $id, Writer::SAMPLE_META, '1' );
		return $id;
	}

	public function test_off_outside_production_so_demo_sites_show_the_seed(): void {
		if ( \Momos\Kernel\Env::is_production() ) {
			$this->markTestSkipped( 'Test install runs as production.' );
		}
		$this->assertFalse( SampleGuard::applies() );
		$page = $this->sample_draft( 'page' );
		wp_update_post( [ 'ID' => $page, 'post_status' => 'publish' ] );
		$this->assertSame( 'publish', get_post_status( $page ) );
		$this->assertSame( 0, $this->audits( $page ) );
	}

	public function test_insert_with_sample_meta_input_is_stored_as_draft_with_error_and_audit(): void {
		$this->block_on();
		$id = wp_insert_post( [ 'post_type' => 'page', 'post_title' => 'QA sample page', 'post_status' => 'publish', 'meta_input' => [ Writer::SAMPLE_META => '1' ] ] );
		$this->assertIsInt( $id );
		$this->assertSame( 'draft', get_post_status( $id ) );
		$this->assertContains( SampleGuard::message(), \Momos\Kernel\Fields\FieldRegistry::last_errors() );
		$this->assertSame( 1, $this->audits( $id ) );
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT object_type, changes FROM ' . Tables::audit() . ' WHERE action = %s AND object_id = %d', SampleGuard::AUDIT_ACTION, $id ), ARRAY_A ); // phpcs:ignore
		$this->assertSame( 'page', $row['object_type'] );
		$this->assertStringContainsString( '"channel":"save"', (string) $row['changes'] );
	}

	public function test_update_to_publish_and_schedule_are_refused_for_every_guarded_type(): void {
		$this->block_on();
		foreach ( [ 'page', 'post', 'momo_notice', 'momo_offer', 'momo_branch' ] as $type ) {
			$id = $this->sample_draft( $type );
			wp_update_post( [ 'ID' => $id, 'post_status' => 'publish' ] );
			$this->assertNotSame( 'publish', get_post_status( $id ), "{$type}: publish refused" );
			$this->assertGreaterThanOrEqual( 1, $this->audits( $id ), "{$type}: audited" );
		}
		$id = $this->sample_draft( 'page' );
		wp_update_post( [ 'ID' => $id, 'post_status' => 'future', 'post_date' => gmdate( 'Y-m-d H:i:s', time() + 86400 ) ] );
		$this->assertSame( 'draft', get_post_status( $id ), 'scheduling refused' );
	}

	public function test_wp_publish_post_and_cron_publication_are_reverted(): void {
		$this->block_on();
		foreach ( [ 'page', 'momo_notice', 'momo_branch' ] as $type ) {
			$id = $this->sample_draft( $type );
			wp_publish_post( $id ); // Bypasses wp_insert_post_data (also the future → publish cron path).
			$this->assertSame( 'draft', get_post_status( $id ), "{$type}: reverted to draft" );
			$this->assertSame( 1, $this->audits( $id ), "{$type}: audited once" );
		}
	}

	public function test_flagging_a_public_post_as_sample_unpublishes_it(): void {
		$this->block_on();
		$id = self::factory()->post->create( [ 'post_type' => 'post', 'post_title' => 'QA real post', 'post_status' => 'publish' ] );
		$this->assertSame( 'publish', get_post_status( $id ), 'Real content publishes.' );
		update_post_meta( $id, Writer::SAMPLE_META, '1' );
		$this->assertSame( 'draft', get_post_status( $id ) );
		$this->assertSame( 1, $this->audits( $id ) );
	}

	public function test_real_content_and_other_post_types_are_not_affected(): void {
		$this->block_on();
		$page = self::factory()->post->create( [ 'post_type' => 'page', 'post_title' => 'QA real page', 'post_status' => 'publish' ] );
		update_post_meta( $page, Writer::SAMPLE_META, '0' );
		wp_update_post( [ 'ID' => $page, 'post_title' => 'QA real page (edited)' ] );
		$this->assertSame( 'publish', get_post_status( $page ) );
		$this->assertSame( 0, $this->audits( $page ) );
		$this->assertSame( [], \Momos\Kernel\Fields\FieldRegistry::last_errors() );
	}

	public function test_production_always_blocks_and_the_filter_cannot_lift_it(): void {
		if ( defined( 'MOMOS_ENV' ) || defined( 'WP_ENVIRONMENT_TYPE' ) ) {
			$this->markTestSkipped( 'The environment is a constant in this install.' );
		}
		putenv( 'WP_ENVIRONMENT_TYPE=production' );
		try {
			if ( ! \Momos\Kernel\Env::is_production() ) {
				$this->markTestSkipped( 'wp_get_environment_type() is cached in this install.' );
			}
			add_filter( 'momos/seed/block_sample_publish', '__return_false' );
			$this->assertTrue( SampleGuard::applies() );
			$id = $this->sample_draft( 'page' );
			wp_update_post( [ 'ID' => $id, 'post_status' => 'publish' ] );
			$this->assertSame( 'draft', get_post_status( $id ) );
		} finally {
			putenv( 'WP_ENVIRONMENT_TYPE' );
		}
	}

	public function test_audit_action_is_catalogued_and_labelled_in_six_languages(): void {
		$this->assertTrue( \Momos\Audit\Catalogue::is_known( SampleGuard::AUDIT_ACTION ) );
		$key = 'admin.audit.action.' . SampleGuard::AUDIT_ACTION;
		foreach ( [ 'ja', 'en', 'ar', 'ur', 'hi', 'bn' ] as $lang ) {
			$this->assertNotSame( $key, momos_t( $key, [], $lang ), "label in {$lang}" );
		}
	}

	public function test_purge_restores_system_pages_as_published_while_the_block_is_on(): void {
		( new \Momos\Presentation\Migration\M0007_SystemPages() )->up();
		\Momos\Presentation\SystemPages::flush();
		( new \Momos\Dietary\Migration\M0003_SeedAllergens() )->up();
		( new \Momos\Dietary\Migration\M0003_SeedStatements() )->up();
		$home = \Momos\Presentation\SystemPages::id( 'home' );
		$this->assertGreaterThan( 0, $home );
		$before = get_post_status( $home );
		( new \Momos\Seed\DemoSeeder( null, false ) )->run();
		$this->assertTrue( SampleGuard::is_sample( $home ), 'The seed flags the pages it edits.' );

		$this->block_on();
		( new \Momos\Seed\Resetter() )->run();
		$this->assertFalse( SampleGuard::is_sample( $home ), 'The flag is removed before the page is restored.' );
		$this->assertSame( $before, get_post_status( $home ), 'The restored page keeps its status.' );
		$this->assertSame( 0, $this->audits( $home ) );
	}
}
