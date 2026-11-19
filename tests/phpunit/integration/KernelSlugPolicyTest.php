<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

use Momos\Kernel\Fields\FieldRegistry;
use Momos\Kernel\I18n\SlugPolicy;
use Momos\Tests\TestCase;

/**
 * FR-I18N-012 / i18n-seo-analytics §8.1 rule 1: Latin `[a-z0-9-]` slugs for dishes, offers, notices, pages and
 * posts (categories, branches and FAQ ids have their own guards). No percent-encoded slug is ever stored.
 */
final class KernelSlugPolicyTest extends TestCase {
	public function set_up(): void {
		parent::set_up();
		$GLOBALS['momos_last_field_errors'] = [];
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function tear_down(): void {
		unset( $GLOBALS['momos_last_field_errors'] );
		parent::tear_down();
	}

	private function errors(): string {
		return implode( ' ', FieldRegistry::last_errors() );
	}

	public function test_guard_is_registered_for_the_five_types(): void {
		$this->assertSame( 8, has_filter( 'wp_insert_post_data', [ SlugPolicy::class, 'guard' ] ) );
		foreach ( [ 'page', 'post', 'momo_dish', 'momo_offer', 'momo_notice' ] as $type ) {
			$this->assertNotFalse( has_filter( 'rest_pre_insert_' . $type, [ SlugPolicy::class, 'rest_guard' ] ), $type );
		}
	}

	public function test_japanese_title_without_slug_gets_a_neutral_latin_slug(): void {
		$a = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'アクセス案内' ] );
		$b = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => '会社概要' ] );
		$this->assertSame( 'publish', get_post_status( $a ), 'publishing is not blocked' );
		$this->assertMatchesRegularExpression( '/^page(-\d+)?$/', get_post( $a )->post_name );
		$this->assertMatchesRegularExpression( '/^page-\d+$/', get_post( $b )->post_name, 'unique' );
		$this->assertStringContainsString( 'Enter a descriptive slug', $this->errors(), 'editor is told to set a real slug' );
		$this->assertStringNotContainsString( '%', (string) get_permalink( $a ) );

		$dish = wp_insert_post( [ 'post_type' => 'momo_dish', 'post_status' => 'draft', 'post_title' => 'チキンモモ' ] );
		wp_update_post( [ 'ID' => $dish, 'post_status' => 'publish' ] );
		$name = get_post( $dish )->post_name;
		if ( '' !== $name ) { // The dish validators may keep it a draft; any slug WordPress stores is Latin.
			$this->assertMatchesRegularExpression( '/^dish(-\d+)?$/', $name );
		}
		$news = wp_insert_post( [ 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'お知らせ' ] );
		$this->assertMatchesRegularExpression( '/^post(-\d+)?$/', get_post( $news )->post_name );
	}

	public function test_typed_non_latin_slug_on_a_first_publication_is_rejected(): void {
		$id   = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'アクセス案内', 'post_name' => 'アクセス' ] );
		$post = get_post( $id );
		$this->assertSame( 'draft', $post->post_status, 'first publication refused' );
		$this->assertSame( '', $post->post_name, 'no percent-encoded slug stored' );
		$this->assertStringContainsString( 'lowercase Latin letters', $this->errors() );
		$this->assertStringContainsString( 'saved as a draft', $this->errors() );
	}

	public function test_japanese_title_with_latin_slug_publishes(): void {
		$id   = wp_insert_post( [ 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => '新店舗オープンのお知らせ', 'post_name' => 'new-branch-opening' ] );
		$post = get_post( $id );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( 'new-branch-opening', $post->post_name );
		$this->assertSame( '', $this->errors() );
	}

	public function test_latin_accents_are_transliterated(): void {
		$id = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Crème Brûlée Café' ] );
		$this->assertSame( 'creme-brulee-cafe', get_post( $id )->post_name );
		$this->assertSame( 'publish', get_post_status( $id ) );
	}

	public function test_typed_non_latin_slug_on_a_published_page_keeps_the_previous_slug(): void {
		$id = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'About', 'post_name' => 'about-us' ] );
		wp_update_post( [ 'ID' => $id, 'post_name' => 'ハラール' ] );
		$post = get_post( $id );
		$this->assertSame( 'about-us', $post->post_name );
		$this->assertSame( 'publish', $post->post_status, 'other edits still save' );
		$this->assertStringContainsString( 'The previous slug "about-us" was kept.', $this->errors() );
	}

	public function test_typed_non_latin_slug_on_a_new_dish_is_rejected(): void {
		$id   = wp_insert_post( [ 'post_type' => 'momo_dish', 'post_status' => 'draft', 'post_title' => 'チキンモモ', 'post_name' => 'チキンモモ' ] );
		$post = get_post( $id );
		$this->assertSame( '', $post->post_name );
		$this->assertStringNotContainsString( '%', $post->post_name );
		$this->assertStringContainsString( 'lowercase Latin letters', $this->errors() );
		$latin = wp_insert_post( [ 'post_type' => 'momo_dish', 'post_status' => 'draft', 'post_title' => 'チキンモモ', 'post_name' => 'Chicken Momo' ] );
		$this->assertSame( 'chicken-momo', get_post( $latin )->post_name, 'sanitize_title output accepted' );
	}

	public function test_offers_and_notices_get_a_neutral_latin_slug(): void {
		foreach ( [ 'momo_offer' => 'offer', 'momo_notice' => 'notice' ] as $type => $base ) {
			$a = wp_insert_post( [ 'post_type' => $type, 'post_status' => 'publish', 'post_title' => '年末年始の営業' ] );
			$b = wp_insert_post( [ 'post_type' => $type, 'post_status' => 'publish', 'post_title' => '年末年始の営業' ] );
			$sa = get_post( $a )->post_name;
			$sb = get_post( $b )->post_name;
			if ( '' !== $sa ) { // A module validator may keep the item as a draft; a slug, if any, is Latin.
				$this->assertMatchesRegularExpression( '/^' . $base . '(-\d+)?$/', $sa, $type );
			}
			if ( '' !== $sa && '' !== $sb ) {
				$this->assertNotSame( $sa, $sb, 'unique' );
			}
		}
		$this->assertMatchesRegularExpression( '/^notice(-\d+)?$/', SlugPolicy::guard( [ 'post_type' => 'momo_notice', 'post_status' => 'publish', 'post_name' => '%e5%b9%b4%e6%9c%ab', 'post_parent' => 0 ], [] )['post_name'] );
		$this->assertStringNotContainsString( 'Latin', $this->errors(), 'auto-derived slug of a type without URLs is replaced silently' );
	}

	public function test_trash_and_untrash_still_work(): void {
		$id = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Trash me', 'post_name' => 'trash-me' ] );
		wp_trash_post( $id );
		$this->assertSame( 'trash', get_post_status( $id ) );
		$this->assertSame( 'trash-me__trashed', get_post( $id )->post_name );
		wp_untrash_post( $id );
		$this->assertSame( 'trash-me', get_post( $id )->post_name );
	}

	public function test_block_editor_rest_save_answers_400_for_a_typed_non_latin_slug(): void {
		$req = new \WP_REST_Request( 'POST', '/wp/v2/pages' );
		$req->set_body_params( [ 'title' => 'Shops', 'status' => 'publish', 'slug' => '店舗' ] );
		$res = rest_do_request( $req );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'momos_invalid_slug', $res->as_error()->get_error_code() );

		$req = new \WP_REST_Request( 'POST', '/wp/v2/pages' );
		$req->set_body_params( [ 'title' => '店舗一覧', 'status' => 'publish', 'slug' => 'shops' ] );
		$res = rest_do_request( $req );
		$this->assertSame( 201, $res->get_status() );
		$this->assertSame( 'shops', $res->get_data()['slug'] );

		$req = new \WP_REST_Request( 'POST', '/wp/v2/pages' );
		$req->set_body_params( [ 'title' => '店舗一覧', 'status' => 'publish' ] );
		$res = rest_do_request( $req );
		$this->assertSame( 201, $res->get_status(), 'no slug typed: neutral Latin slug' );
		$this->assertMatchesRegularExpression( '/^page(-\d+)?$/', $res->get_data()['slug'] );
	}
}
