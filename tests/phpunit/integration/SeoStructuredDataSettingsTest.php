<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/SeoTestCaseTest.php';

use Momos\Seo\Admin\SeoAdmin;
use Momos\Seo\Schema\JsonLd;
use Momos\Seo\SeoSettings;

/**
 * FR-SEO-006(b) / F-SEO-02 / Q-415: Restaurant `servesCuisine` (per language) and `priceRange` are SEO settings
 * edited by SA/HO, each < 100 characters, and appear in the branch JSON-LD only when set.
 */
final class SeoStructuredDataSettingsTest extends SeoTestCaseTest {
	public function tear_down(): void {
		unset( $_POST['serves_cuisine'], $_POST['price_range'], $_REQUEST['_wpnonce'], $_GET['tab'] );
		parent::tear_down();
	}

	public function test_restaurant_emits_cuisine_per_language_and_price_range_only_when_set(): void {
		$b = $this->branch( 'QSX', [ 'en' => [ 'title' => 'Fukushima' ] ] );
		$this->visit( $b, 'en' );
		$node = JsonLd::restaurant( $b, 'en' );
		$this->assertArrayNotHasKey( 'servesCuisine', $node, 'nothing until Head Office supplies it (Q-415)' );
		$this->assertArrayNotHasKey( 'priceRange', $node );

		$res = SeoSettings::save_schema( [ 'serves_cuisine' => [ 'ja' => 'カレー', 'en' => 'Curry' ], 'price_range' => 'QA-PRICE' ] );
		$this->assertSame( [], $res['errors'] );
		$en = JsonLd::restaurant( $b, 'en' );
		$this->assertSame( 'Curry', $en['servesCuisine'] );
		$this->assertSame( 'QA-PRICE', $en['priceRange'] );
		$this->visit( $b, 'ja' );
		$this->assertSame( 'カレー', JsonLd::restaurant( $b, 'ja' )['servesCuisine'] );

		// A language without a value: no servesCuisine there (no other-language fallback), priceRange still shown.
		SeoSettings::save_schema( [ 'serves_cuisine' => [ 'ja' => 'カレー' ], 'price_range' => 'QA-PRICE' ] );
		$this->visit( $b, 'en' );
		$en = JsonLd::restaurant( $b, 'en' );
		$this->assertArrayNotHasKey( 'servesCuisine', $en );
		$this->assertSame( 'QA-PRICE', $en['priceRange'] );
		$graph = json_decode( JsonLd::encode( JsonLd::graph( 'en' ) ), true );
		$this->assertSame( 'QA-PRICE', $graph['@graph'][0]['priceRange'] );

		// Cleared → omitted again.
		SeoSettings::save_schema( [] );
		$this->assertArrayNotHasKey( 'priceRange', JsonLd::restaurant( $b, 'en' ) );
	}

	public function test_values_are_plain_text_under_100_characters(): void {
		$ok = SeoSettings::save_schema( [ 'serves_cuisine' => [ 'ar' => str_repeat( 'ع', 99 ) ], 'price_range' => '<b>QA</b> range' ] );
		$this->assertSame( [], $ok['errors'] );
		$this->assertSame( 99, mb_strlen( SeoSettings::serves_cuisine( 'ar' ) ) );
		$this->assertSame( 'QA range', SeoSettings::price_range(), 'tags stripped' );

		$bad = SeoSettings::save_schema( [ 'serves_cuisine' => [ 'en' => str_repeat( 'x', 100 ) ], 'price_range' => str_repeat( 'y', 100 ) ] );
		$this->assertArrayHasKey( 'serves_cuisine_en', $bad['errors'] );
		$this->assertArrayHasKey( 'price_range', $bad['errors'] );
		$this->assertSame( 'QA range', SeoSettings::price_range(), 'nothing stored when a value is too long' );
		$this->assertSame( 99, mb_strlen( SeoSettings::serves_cuisine( 'ar' ) ) );
	}

	public function test_head_office_saves_through_the_settings_tab_and_others_are_denied(): void {
		$redirect = static function ( string $location ): string {
			throw new \RuntimeException( $location );
		};
		add_filter( 'wp_redirect', $redirect );
		$audit = [];
		$spy   = static function ( $action, $object_type = '' ) use ( &$audit ): void {
			$audit[] = $action . ':' . $object_type;
		};
		add_action( 'momos/audit/log', $spy, 1, 2 );

		$ho = self::factory()->user->create( [ 'role' => 'momo_head_office' ] );
		wp_set_current_user( $ho );
		$_GET['tab'] = 'schema';
		ob_start();
		SeoAdmin::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'name="serves_cuisine[ar]"', $html );
		$this->assertMatchesRegularExpression( '/name="serves_cuisine\[ar\]"[^>]*maxlength="99" lang="ar" dir="rtl"/', $html );
		$this->assertStringContainsString( 'name="price_range"', $html );
		$this->assertStringContainsString( 'for="momo-seo-price"', $html, 'labelled field' );

		$_POST['serves_cuisine'] = [ 'en' => 'Curry', 'bn' => 'কারি' ];
		$_POST['price_range']    = 'QA-PRICE';
		$_REQUEST['_wpnonce']    = wp_create_nonce( 'momos_seo_schema' );
		try {
			SeoAdmin::handle_seo_schema();
			$this->fail( 'expected a redirect' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'tab=schema', $e->getMessage() );
			$this->assertStringContainsString( 'momos_notice=saved', $e->getMessage() );
		}
		$this->assertSame( 'Curry', SeoSettings::serves_cuisine( 'en' ) );
		$this->assertSame( 'কারি', SeoSettings::serves_cuisine( 'bn' ) );
		$this->assertSame( 'QA-PRICE', SeoSettings::price_range() );
		$this->assertContains( 'settings.updated:seo_schema', $audit );

		// A Content Editor (no momo_manage_redirects) is refused and nothing changes.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'momo_content_editor' ] ) );
		$_POST['price_range'] = 'changed';
		try {
			SeoAdmin::handle_seo_schema();
			$this->fail( 'expected wp_die' );
		} catch ( \WPDieException $e ) {
			$this->assertContains( 'access.denied:redirect', $audit );
		}
		$this->assertSame( 'QA-PRICE', SeoSettings::price_range() );
		remove_filter( 'wp_redirect', $redirect );
		remove_action( 'momos/audit/log', $spy, 1 );
	}
}
