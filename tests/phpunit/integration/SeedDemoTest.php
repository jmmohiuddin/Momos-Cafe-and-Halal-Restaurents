<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

use Momos\Kernel\Db\Tables;
use Momos\Kernel\I18n\Translations;
use Momos\Seed\Data\Content;
use Momos\Seed\Data\People;
use Momos\Seed\DemoSeeder;
use Momos\Seed\Resetter;
use Momos\Seed\Writer;
use Momos\Tests\TestCase;

/**
 * `wp momos seed --demo` / `--reset` (NFR-MNT-02, DEC-047, GATE-02): seeding twice changes nothing,
 * the demo data is complete (every reservation status, six languages approved, no halal claim), and
 * reset removes only sample content while restoring the pages and settings the seed edited.
 *
 * One test method on purpose: a full seed takes several seconds.
 */
final class SeedDemoTest extends TestCase {
	public function test_seed_twice_is_idempotent_and_reset_removes_only_sample_content(): void {
		// System and legal pages as installed by the Presentation migration (the test suite empties posts).
		( new \Momos\Presentation\Migration\M0007_SystemPages() )->up();
		\Momos\Presentation\SystemPages::flush();
		( new \Momos\Dietary\Migration\M0003_SeedAllergens() )->up(); // The WP test suite deletes terms between classes.

		// ---- Real (non-sample) content that must survive.
		$real_page   = self::factory()->post->create( [ 'post_type' => 'page', 'post_title' => 'Real page', 'post_status' => 'publish' ] );
		$real_dish   = self::factory()->post->create( [ 'post_type' => 'momo_dish', 'post_title' => 'Real draft dish', 'post_status' => 'draft' ] );
		$real_branch = self::factory()->post->create( [ 'post_type' => 'momo_branch', 'post_title' => 'Real draft branch', 'post_status' => 'draft' ] );
		$real_cat    = self::factory()->term->create( [ 'taxonomy' => 'momo_menu_category', 'name' => 'Real category', 'slug' => 'real-category' ] );
		$real_user   = self::factory()->user->create( [ 'role' => 'momo_menu_manager', 'user_login' => 'real_mm' ] );
		$home        = \Momos\Presentation\SystemPages::id( 'home' );
		$faq         = \Momos\Presentation\SystemPages::id( 'faq' );
		$this->assertGreaterThan( 0, $home );
		$home_before = get_post( $home );
		$faq_status  = get_post_status( $faq );
		$tagline     = \Momos\Kernel\Settings::get( 'momo_tagline' );

		// ---- First seed.
		$counts = ( new DemoSeeder( null, false ) )->run();
		$this->assertSame( 4, $counts['branches'] );
		$this->assertSame( 24, $counts['dishes'] );
		$this->assertSame( 6, $counts['categories'] );
		$this->assertSame( 2, $counts['offers'] );
		$this->assertSame( 2, $counts['notices'] );
		$this->assertSame( 8, $counts['users'] );
		$this->assertSame( 10, $counts['reservations'] );

		$this->assert_demo_content();
		$first = $this->fingerprint();

		// ---- Second seed: nothing changes (no new rows, no new audit entries, same meta).
		( new DemoSeeder( null, false ) )->run();
		$this->assertSame( $first, $this->fingerprint(), 'Seeding twice must be idempotent.' );

		// ---- Reset: only sample content goes, edited pages/settings come back.
		( new Resetter() )->run();
		global $wpdb;
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", Writer::KEY_META ) ) );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Tables::reservations() . ' WHERE idempotency_key LIKE %s', People::RES_KEY_PREFIX . '%' ) ) ); // phpcs:ignore
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE meta_key = '_momo_seed_key'" ) ); // phpcs:ignore
		$this->assertSame( [], get_users( [ 'meta_key' => Writer::KEY_META, 'meta_compare' => 'EXISTS', 'fields' => 'ID' ] ) ); // phpcs:ignore
		$this->assertFalse( get_user_by( 'login', 'demo_ho' ) );

		foreach ( [ $real_page, $real_dish, $real_branch ] as $id ) {
			$this->assertInstanceOf( \WP_Post::class, get_post( $id ), "Real post {$id} must survive the reset." );
		}
		$this->assertInstanceOf( \WP_Term::class, get_term( $real_cat ) );
		$this->assertInstanceOf( \WP_User::class, get_user_by( 'id', $real_user ) );

		$home_after = get_post( $home );
		$this->assertSame( $home_before->post_excerpt, $home_after->post_excerpt );
		$this->assertSame( $home_before->post_content, $home_after->post_content );
		$this->assertSame( '', (string) get_post_meta( $home, Writer::SAMPLE_META, true ) );
		$this->assertSame( 'missing', Translations::status( 'post', $home, 'en' ) );
		$this->assertSame( $faq_status, get_post_status( $faq ) );
		\Momos\Kernel\Settings::flush();
		$this->assertSame( $tagline, \Momos\Kernel\Settings::get( 'momo_tagline' ) );
		$this->assertSame( [], get_option( DemoSeeder::MANIFEST, [] ) );
		$milk = \Momos\Dietary\Allergens::term_by_code( 'milk' );
		$this->assertSame( 'missing', Translations::status( 'term', $milk->term_id, 'ar' ), 'Draft allergen names added by the seed are removed.' );
	}

	public function test_seed_and_reset_refuse_in_production(): void {
		if ( defined( 'WP_ENVIRONMENT_TYPE' ) ) {
			$this->markTestSkipped( 'WP_ENVIRONMENT_TYPE is a constant in this install.' );
		}
		putenv( 'WP_ENVIRONMENT_TYPE=production' );
		try {
			foreach ( [ static fn() => ( new DemoSeeder( null, false ) )->run(), static fn() => ( new Resetter() )->run() ] as $fn ) {
				try {
					$fn();
					$this->fail( 'Expected a refusal in production.' );
				} catch ( \Momos\Seed\SeedException $e ) {
					$this->assertStringContainsString( 'production', $e->getMessage() );
				}
			}
			$this->assertFalse( get_user_by( 'login', 'demo_ho' ), 'Nothing is written when refused.' );
		} finally {
			putenv( 'WP_ENVIRONMENT_TYPE' );
		}
	}

	private function assert_demo_content(): void {
		global $wpdb;
		// Every reservation status exists, all on example.com.
		$statuses = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT status FROM ' . Tables::reservations() . ' WHERE idempotency_key LIKE %s', People::RES_KEY_PREFIX . '%' ) ); // phpcs:ignore
		sort( $statuses );
		$this->assertSame( [ 'cancelled', 'completed', 'confirmed', 'expired', 'no_show', 'pending', 'rejected' ], $statuses );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Tables::reservations() . " WHERE idempotency_key LIKE %s AND email NOT LIKE '%%@example.com'", People::RES_KEY_PREFIX . '%' ) ) ); // phpcs:ignore
		$this->assertGreaterThanOrEqual( 17, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Tables::events() . ' e JOIN ' . Tables::reservations() . ' r ON r.id = e.reservation_id WHERE r.idempotency_key LIKE %s', People::RES_KEY_PREFIX . '%' ) ) ); // phpcs:ignore

		// Three public branches + one hidden TEST branch; no halal claim anywhere (GATE-02).
		$public = \Momos\Branch\Repository\BranchRepository::public_ids( 'ja' );
		$this->assertCount( 3, $public );
		$test = \Momos\Branch\Repository\BranchRepository::find_by_code( 'TST', true );
		$this->assertNotNull( $test );
		$this->assertTrue( $test['is_test'] );
		foreach ( array_merge( $public, [ (int) $test['id'] ] ) as $b ) {
			$this->assertContains( \Momos\Dietary\Service\ClaimResolver::branch_claim( $b, 'en' )['type'], [ 'no_claim', 'none' ] );
			$this->assertSame( '1', (string) get_post_meta( $b, Writer::SAMPLE_META, true ) );
			$this->assertTrue( \Momos\Branch\Repository\BranchSettingsRepository::get( $b )['enabled'] );
			foreach ( \Momos\Branch\Repository\BranchSettingsRepository::active_recipients( $b ) as $r ) {
				$this->assertStringEndsWith( '@example.com', $r['email'] );
			}
		}
		$kitahama = (int) \Momos\Branch\Repository\BranchRepository::find_by_code( 'DKH' )['id'];
		$this->assertCount( 2, \Momos\Branch\Service\ScheduleService::week( $kitahama )[1], 'Split lunch/dinner hours.' );
		$namba = (int) \Momos\Branch\Repository\BranchRepository::find_by_code( 'DNB' )['id'];
		$this->assertTrue( \Momos\Branch\Service\ScheduleService::week( $namba )[5][1]['closes_next_day'], 'After-midnight period.' );

		// Dishes: published, sample, translated + approved in every language, illustrative images.
		$dishes = get_posts( [ 'post_type' => 'momo_dish', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => Writer::KEY_META, 'meta_compare' => 'EXISTS' ] ); // phpcs:ignore
		$this->assertCount( 24, $dishes );
		$not_verified = 0;
		foreach ( $dishes as $d ) {
			$this->assertSame( '1', (string) get_post_meta( $d, 'momo_is_sample', true ) );
			$this->assertSame( '1', (string) get_post_meta( (int) get_post_thumbnail_id( $d ), 'momo_image_illustrative', true ) );
			foreach ( [ 'en', 'ar', 'ur', 'hi', 'bn' ] as $lang ) {
				$this->assertTrue( Translations::post_visible( $d, $lang ), "Dish {$d} visible in {$lang}" );
				$this->assertNotSame( '', Translations::post( $d, 'title', $lang ) );
			}
			if ( ! \Momos\Dietary\Service\AllergenPresenter::for_dish( $d, 'ja' )['complete'] ) {
				++$not_verified;
			}
		}
		$this->assertSame( 1, $not_verified, 'Exactly one dish keeps unverified allergen data.' );
		$this->assertNotEmpty( \Momos\Menu\Service\MenuQueryService::featured( 'ar' ) );
		$this->assertCount( 6, \Momos\Menu\Service\MenuQueryService::categories( 'bn' ) );

		// Offers (one featured) and legal placeholders.
		$this->assertCount( 1, \Momos\Branch\Query\OfferQuery::home_featured( 'en' ) );
		$privacy = \Momos\Presentation\SystemPages::legal_id( 'privacy' );
		$this->assertSame( 'publish', get_post_status( $privacy ) );
		$this->assertStringContainsString( Content::LEGAL_PLACEHOLDER, get_post( $privacy )->post_content );
		$this->assertStringContainsString( Content::LEGAL_PLACEHOLDER, Translations::post( $privacy, 'content', 'ur' ) );

		// Staff accounts for every role, branch roles scoped to two different branches.
		$this->assertSame( [ $kitahama ], momos_user_branch_ids( get_user_by( 'login', 'demo_rs_kitahama' )->ID ) );
		$this->assertSame( [ $namba ], momos_user_branch_ids( get_user_by( 'login', 'demo_bm_namba' )->ID ) );
		$this->assertTrue( user_can( get_user_by( 'login', 'demo_ho' )->ID, 'momo_edit_dietary_claims' ) );
	}

	/** @return array<string,mixed> */
	private function fingerprint(): array {
		global $wpdb;
		$out = [];
		foreach ( [ 'posts' => $wpdb->posts, 'postmeta' => $wpdb->postmeta, 'terms' => $wpdb->terms, 'termmeta' => $wpdb->termmeta, 'users' => $wpdb->users, 'usermeta' => $wpdb->usermeta ] as $k => $t ) {
			$out[ $k ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ); // phpcs:ignore
		}
		foreach ( [ 'reservations', 'events', 'notifications', 'menu_items', 'hours', 'closures', 'user_branches', 'audit' ] as $k ) {
			$out[ $k ] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::$k() ); // phpcs:ignore
		}
		$wpdb->query( 'SET SESSION group_concat_max_len = 100000000' );
		$out['meta_hash'] = md5( (string) $wpdb->get_var( "SELECT GROUP_CONCAT(CONCAT(post_id, ':', meta_key, '=', MD5(meta_value)) ORDER BY meta_id SEPARATOR '|') FROM {$wpdb->postmeta} WHERE meta_key NOT LIKE '\\_edit\\_%'" ) ); // phpcs:ignore
		return $out;
	}
}
