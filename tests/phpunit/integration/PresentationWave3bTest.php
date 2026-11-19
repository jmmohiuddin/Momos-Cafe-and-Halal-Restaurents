<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

use Momos\Presentation\Migration\M0007_SystemPages;
use Momos\Presentation\Migration\M0008_UnpublishWpDefaults;
use Momos\Presentation\SystemPages;
use Momos\Tests\TestCase;

/**
 * Wave 3b presentation fixes: QA-030 for real installs (M0008 unpublishes the untouched WordPress install
 * defaults, TC-I18N-013) and the batched system/legal page lookup (NFR-PERF-02) keeping its semantics.
 */
final class PresentationWave3bTest extends TestCase {
	public function set_up(): void {
		parent::set_up();
		SystemPages::flush();
		// Start from a known state: remove whatever defaults the test install created.
		foreach ( M0008_UnpublishWpDefaults::DEFAULTS as $type => $slug ) {
			foreach ( get_posts( [ 'post_type' => $type, 'name' => $slug, 'post_status' => 'any', 'numberposts' => -1 ] ) as $p ) {
				wp_delete_post( $p->ID, true );
			}
		}
	}

	private function make_default( string $type, string $slug, string $title ): int {
		return (int) self::factory()->post->create(
			[
				'post_type'     => $type,
				'post_name'     => $slug,
				'post_title'    => $title,
				'post_status'   => 'publish',
				'post_date'     => '2026-01-05 10:00:00',
				'post_date_gmt' => '2026-01-05 01:00:00',
			]
		);
	}

	public function test_m0008_unpublishes_untouched_wordpress_defaults_only(): void {
		$hello  = $this->make_default( 'post', 'hello-world', 'Hello world!' );
		$sample = $this->make_default( 'page', 'sample-page', 'Sample Page' );
		$this->assertSame( get_post_field( 'post_date_gmt', $hello ), get_post_field( 'post_modified_gmt', $hello ), 'fixture is unedited' );

		( new M0008_UnpublishWpDefaults() )->up();
		$this->assertSame( 'draft', get_post_status( $hello ) );
		$this->assertSame( 'draft', get_post_status( $sample ) );
		$this->assertSame( [], wp_get_post_revisions( $hello ), 'a status change writes no revision (post IDs stay stable)' );
		$this->assertSame( 10, has_action( 'post_updated', 'wp_save_post_revision' ), 'revisions are re-enabled afterwards' );

		// Idempotent: a second run changes nothing.
		( new M0008_UnpublishWpDefaults() )->up();
		$this->assertSame( 'draft', get_post_status( $hello ) );
		$this->assertSame( 'draft', get_post_status( $sample ) );
	}

	public function test_m0008_keeps_edited_or_system_pages_published(): void {
		$sample = $this->make_default( 'page', 'sample-page', 'Sample Page' );
		// An editor reworked the page: it is no longer the install default.
		global $wpdb;
		$wpdb->update( $wpdb->posts, [ 'post_modified_gmt' => '2026-02-01 00:00:00', 'post_modified' => '2026-02-01 09:00:00' ], [ 'ID' => $sample ] );
		clean_post_cache( $sample );
		$hello = $this->make_default( 'post', 'hello-world', 'Hello world!' );
		add_post_meta( $hello, '_momo_seed_key', 'post:hello' ); // Seeded objects are never touched.

		( new M0008_UnpublishWpDefaults() )->up();
		$this->assertSame( 'publish', get_post_status( $sample ) );
		$this->assertSame( 'publish', get_post_status( $hello ) );
	}

	public function test_m0008_is_registered_after_m0007(): void {
		$ids = array_map( static fn( $m ) => $m->id(), (array) apply_filters( 'momos/migrations', [] ) );
		$this->assertContains( 'M0008_UnpublishWpDefaults', $ids );
		$this->assertGreaterThan( 0, strcmp( 'M0008_UnpublishWpDefaults', 'M0007_SystemPages_r2' ) );
	}

	/** The batched lookup keeps the documented fallbacks: brand option → documented slug → 0. */
	public function test_legal_id_resolves_slug_and_option_and_sees_new_pages(): void {
		( new M0007_SystemPages() )->up();
		SystemPages::flush();
		$terms = SystemPages::legal_id( 'terms' );
		$this->assertGreaterThan( 0, $terms );
		$this->assertSame( 'terms', get_post_field( 'post_name', $terms ) );
		$this->assertSame( 'legal', SystemPages::role_of( $terms ) );

		// A page created later in the same request is found (cache keyed on the posts' last_changed).
		wp_delete_post( SystemPages::legal_id( 'accessibility' ), true );
		$this->assertSame( 0, SystemPages::legal_id( 'accessibility' ) );
		$new = (int) self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'accessibility', 'post_status' => 'draft' ] );
		$this->assertSame( $new, SystemPages::legal_id( 'accessibility' ) );

		// A child page with the slug is not a legal page (top-level only, like get_page_by_path()).
		wp_delete_post( $new, true );
		self::factory()->post->create( [ 'post_type' => 'page', 'post_name' => 'accessibility', 'post_parent' => SystemPages::id( 'about' ) ] );
		$this->assertSame( 0, SystemPages::legal_id( 'accessibility' ) );
	}

	public function test_system_page_ids_are_loaded_with_one_query_pair(): void {
		( new M0007_SystemPages() )->up();
		SystemPages::flush();
		wp_cache_flush();
		$n     = 0;
		$count = static function ( $q ) use ( &$n ) {
			++$n;
			return $q;
		};
		add_filter( 'query', $count );
		foreach ( array_keys( SystemPages::ROLES ) as $role ) {
			SystemPages::id( $role );
		}
		foreach ( array_keys( SystemPages::LEGAL ) as $key ) {
			SystemPages::legal_id( $key );
		}
		$after_ids = $n;
		foreach ( array_keys( SystemPages::LEGAL ) as $key ) {
			SystemPages::legal_url( $key, 'en' );
		}
		remove_filter( 'query', $count );
		// option map (alloptions after the flush) + legal slugs + posts + postmeta; nothing per page.
		$this->assertLessThanOrEqual( 6, $after_ids, 'system/legal page lookups are batched' );
		$this->assertSame( $after_ids, $n, 'legal_url() needs no further queries once primed' );
	}
}
