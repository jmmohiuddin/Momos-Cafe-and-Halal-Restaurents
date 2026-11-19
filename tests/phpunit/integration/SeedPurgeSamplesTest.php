<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

use Momos\Kernel\Db\Tables;
use Momos\Seed\Data\Branches;
use Momos\Seed\Data\Content;
use Momos\Seed\Data\Menu;
use Momos\Seed\DemoSeeder;
use Momos\Seed\PurgeSamples;
use Momos\Seed\SampleCheck;
use Momos\Seed\Writer;
use Momos\Tests\TestCase;

/**
 * `wp momos purge-samples` (content-strings.md §10.1 D8, DEF-012 / QA-024) and the demo seed data rules
 * (DEC-062 Western digits, QA-050; neutral statement templates only, QA-006).
 */
final class SeedPurgeSamplesTest extends TestCase {
	public function test_purge_removes_every_sample_item_and_keeps_real_content(): void {
		( new \Momos\Presentation\Migration\M0007_SystemPages() )->up();
		\Momos\Presentation\SystemPages::flush();
		( new \Momos\Dietary\Migration\M0003_SeedAllergens() )->up();
		( new \Momos\Dietary\Migration\M0003_SeedStatements() )->up();

		$real_dish = self::factory()->post->create( [ 'post_type' => 'momo_dish', 'post_title' => 'Real draft dish', 'post_status' => 'draft' ] );
		$real_cat  = self::factory()->term->create( [ 'taxonomy' => 'momo_menu_category', 'name' => 'Real category', 'slug' => 'real-category' ] );
		( new DemoSeeder( null, false ) )->run();

		// Sample items flagged by staff (not created by the seed): a dish, an offer, a notice, a category and an image.
		$dish   = self::factory()->post->create( [ 'post_type' => 'momo_dish', 'post_title' => 'Sample dish', 'post_status' => 'draft' ] );
		$offer  = self::factory()->post->create( [ 'post_type' => 'momo_offer', 'post_title' => 'Sample offer', 'post_status' => 'draft' ] );
		$notice = self::factory()->post->create( [ 'post_type' => 'momo_notice', 'post_title' => 'Sample notice', 'post_status' => 'draft' ] );
		$image  = self::factory()->attachment->create( [ 'post_title' => 'Sample image', 'post_mime_type' => 'image/jpeg' ] );
		$cat    = self::factory()->term->create( [ 'taxonomy' => 'momo_menu_category', 'name' => 'Sample category', 'slug' => 'sample-category' ] );
		foreach ( [ $dish, $offer, $notice, $image ] as $id ) {
			update_post_meta( $id, Writer::SAMPLE_META, '1' );
		}
		update_term_meta( $cat, Writer::SAMPLE_META, '1' );
		$this->assertNotEmpty( SampleCheck::findings() );

		$counts = ( new PurgeSamples() )->run();

		$this->assertSame( [], SampleCheck::findings(), 'No sample item, category or demo image is left.' );
		foreach ( [ $dish, $offer, $notice, $image ] as $id ) {
			$this->assertNull( get_post( $id ), "Sample post {$id} deleted." );
		}
		$this->assertNull( get_term( $cat ) );
		// Seeded objects (Resetter: 24 dishes, 2 offers, 2 notices) + the staff-flagged ones.
		$this->assertSame( 25, $counts['momo_dish'] );
		$this->assertSame( 3, $counts['momo_offer'] );
		$this->assertSame( 3, $counts['momo_notice'] );
		$this->assertGreaterThanOrEqual( 1, $counts['terms'] );
		$this->assertFalse( get_user_by( 'login', 'demo_ho' ), 'Seeded demo content went through the Resetter.' );
		$this->assertSame( [], get_option( DemoSeeder::MANIFEST, [] ) );
		$this->assertInstanceOf( \WP_Post::class, get_post( $real_dish ), 'Real content survives.' );
		$this->assertInstanceOf( \WP_Term::class, get_term( $real_cat ) );
		$this->assertNull( \Momos\Dietary\Service\StatementRepository::text( 'brand.neutral', 'en' ), 'Demo statement approvals are reverted.' );

		global $wpdb;
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Tables::audit() . ' WHERE action = %s', PurgeSamples::AUDIT_ACTION ) ), 'The purge is audited.' ); // phpcs:ignore
		$this->assertTrue( \Momos\Audit\Catalogue::is_known( PurgeSamples::AUDIT_ACTION ) );
	}

	public function test_production_requires_yes_and_a_super_administrator(): void {
		if ( defined( 'WP_ENVIRONMENT_TYPE' ) ) {
			$this->markTestSkipped( 'WP_ENVIRONMENT_TYPE is a constant in this install.' );
		}
		$this->assertNull( PurgeSamples::refusal( false ), 'Non-production: no confirmation needed.' );
		putenv( 'WP_ENVIRONMENT_TYPE=production' );
		try {
			$this->assertTrue( PurgeSamples::is_production() );
			$this->assertStringContainsString( '--yes', (string) PurgeSamples::refusal( false ) );
			wp_set_current_user( self::factory()->user->create( [ 'role' => 'momo_head_office' ] ) );
			$this->assertStringContainsString( 'Super Administrator', (string) PurgeSamples::refusal( true ) );
			wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
			$this->assertNull( PurgeSamples::refusal( true ) );
		} finally {
			putenv( 'WP_ENVIRONMENT_TYPE' );
			wp_set_current_user( 0 );
		}
	}

	/** DEC-062 / TC-I18N-005: seeded text uses Western digits in every language (QA-050). */
	public function test_seed_data_uses_western_digits_only(): void {
		$data = [ Branches::all(), Content::notices(), Content::offers(), Content::faq(), Content::pages(), Content::legal_titles(), Content::brand(), Content::statements(), Menu::categories(), Menu::dishes(), Menu::ingredients(), Menu::tags(), Menu::allergen_names() ];
		$bad  = [];
		array_walk_recursive(
			$data,
			static function ( $v ) use ( &$bad ): void {
				if ( is_string( $v ) && preg_match( '/[\x{0660}-\x{0669}\x{06F0}-\x{06F9}\x{0966}-\x{096F}\x{09E6}-\x{09EF}\x{FF10}-\x{FF19}]/u', $v ) ) {
					$bad[] = mb_substr( $v, 0, 60 );
				}
			}
		);
		$this->assertSame( [], $bad );
	}

	/** QA-006 / GATE-02: the seed approves neutral templates only, and every draft passes the wording guard. */
	public function test_seed_statements_are_neutral_and_pass_the_wording_guard(): void {
		$types = [];
		foreach ( \Momos\Dietary\Migration\M0003_SeedStatements::rows() as $r ) {
			$types[ $r['key'] ] = $r['claim_type'];
		}
		foreach ( Content::statements() as $key => $texts ) {
			$this->assertArrayHasKey( $key, $types );
			$this->assertContains( $types[ $key ], [ 'neutral', 'no_claim', 'alcohol' ], "{$key} is not a claim template" );
			$this->assertSame( \Momos\Kernel\I18n\Lang::codes(), array_keys( $texts ) );
			foreach ( $texts as $lang => $text ) {
				$this->assertSame( [], \Momos\Dietary\Guard\WordingGuard::statement_violations( $text, $types[ $key ], $lang ), "{$key} {$lang}" );
				$this->assertSame( str_contains( $texts['en'], '{branch_links}' ), str_contains( $text, '{branch_links}' ), "{$key} {$lang} keeps its placeholder" );
			}
		}
	}
}
