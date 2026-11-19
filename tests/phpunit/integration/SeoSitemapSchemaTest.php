<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/SeoTestCaseTest.php';

use Momos\Branch\Domain\AccessContext;
use Momos\Branch\Repository\ClosureRepository;
use Momos\Presentation\SystemPages;
use Momos\Seo\Schema\JsonLd;
use Momos\Seo\Schema\OpeningHours;
use Momos\Seo\Sitemap;

/**
 * XML sitemaps (FR-SEO-004, AC-SEO-01-03, TC-SEO-004) and JSON-LD (FR-SEO-006, BR-SEO-01, AC-SEO-02-01…04,
 * TC-SEO-006/007; i18n-seo-analytics.md §9.5, §10).
 */
final class SeoSitemapSchemaTest extends SeoTestCaseTest {
	/** @return list<string> */
	private function locs( string $type, string $lang ): array {
		return array_column( Sitemap::urls( $type, $lang ), 'loc' );
	}

	public function test_sitemaps_list_only_visible_indexable_items_per_language(): void {
		$home   = untrailingslashit( (string) get_option( 'home' ) );
		$open   = $this->branch( 'QSA', [ 'en' => [ 'title' => 'Open' ] ] );
		$this->branch( 'QSB', [ 'en' => [ 'title' => 'Closed' ] ], [ 'momo_operating_status' => 'closed_permanently' ] );
		$this->branch( 'QSC', [ 'en' => [ 'title' => 'Test' ] ], [ 'momo_is_test_branch' => '1' ] );
		$ja_only = $this->branch( 'QSD' );
		$cat     = $this->category( 'qsoup', [ 'en' => [ 'name' => 'Soup' ] ] );
		$dish    = $this->dish( 'D7101', $cat, [ 'en' => [ 'title' => 'Lentil soup' ] ] );
		$this->dish( 'D7102', $cat, [ 'en' => [ 'title' => 'Retired' ] ], [ 'momo_retired' => '1' ] );
		$this->dish( 'D7103', $cat ); // JA only.
		Sitemap::bump();

		$en_branches = $this->locs( 'branches', 'en' );
		$this->assertSame( [ $home . '/en/branches/qsa-branch/' ], $en_branches, 'no TEST, closed or untranslated branch' );
		$ja_branches = $this->locs( 'branches', 'ja' );
		$this->assertContains( $home . '/branches/qsd-branch/', $ja_branches );
		$this->assertNotContains( $home . '/branches/qsc-branch/', $ja_branches );
		$this->assertNotContains( $home . '/branches/qsb-branch/', $ja_branches );

		$this->assertSame( [ $home . '/en/menu/qsoup/d7101/' ], $this->locs( 'dishes', 'en' ) );
		$this->assertCount( 2, $this->locs( 'dishes', 'ja' ), 'retired dish excluded' );
		$this->assertSame( [ $home . '/en/menu/qsoup/' ], $this->locs( 'menu-categories', 'en' ) );

		$pages = $this->locs( 'pages', 'en' );
		$this->assertContains( $home . '/en/', $pages );
		$this->assertContains( $home . '/en/halal/', $pages, 'string-driven system pages are in every enabled language' );
		$this->assertNotContains( Sitemap::class, $pages );
		foreach ( $pages as $loc ) {
			$this->assertStringNotContainsString( '/reservation/submitted/', $loc );
			$this->assertStringNotContainsString( '/reservation/status/', $loc );
			$this->assertStringNotContainsString( '?', $loc );
			$this->assertStringEndsWith( '/', $loc );
		}
		$this->assertNotContains( $home . '/en/about/', $pages, 'untranslated About is not listed in en' );
		$this->assertContains( $home . '/about/', $this->locs( 'pages', 'ja' ) );
		unset( $open, $ja_only, $dish );
	}

	public function test_sitemap_index_and_files_follow_language_gate(): void {
		$this->branch( 'QSE', [ 'en' => [ 'title' => 'E' ] ] );
		update_option( 'momos_languages_enabled', [ 'ja', 'en' ] );
		Sitemap::bump();
		$index = Sitemap::render_index();
		$this->assertStringContainsString( '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', $index );
		$this->assertStringContainsString( '/sitemaps/en-branches.xml', $index );
		$this->assertStringNotContainsString( '/sitemaps/ar-', $index, 'hidden language never listed' );
		$this->assertNull( Sitemap::render_file( 'ar', 'pages' ), 'hidden language → 404' );
		$this->assertNull( Sitemap::render_file( 'en', 'offers' ), 'unknown type → 404' );
		$this->assertNull( Sitemap::render_file( 'en', 'branches', 2 ), 'empty chunk → 404' );
		$xml = (string) Sitemap::render_file( 'en', 'branches' );
		$doc = simplexml_load_string( $xml );
		$this->assertNotFalse( $doc, 'well-formed XML' );
		$this->assertSame( 'urlset', $doc->getName() );
		$this->assertMatchesRegularExpression( '#<lastmod>\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z</lastmod>#', $xml );
		$this->assertFalse( apply_filters( 'wp_sitemaps_enabled', true ), 'core sitemaps disabled' );
	}

	public function test_restaurant_node_with_opening_hours_closure_and_special_hours(): void {
		$this->freeze( '2026-12-07 10:00:00' ); // Monday JST.
		$b = $this->branch( 'QSF', [ 'en' => [ 'title' => 'Fukushima', 'momo_city' => 'Osaka', 'momo_address_line1' => '1-1 Fukushima' ] ] );
		$this->hours( $b, [ [ 1, '11:00', '15:00' ], [ 1, '17:00', '22:00' ], [ 5, '18:00', '02:00', true ] ] );
		ClosureRepository::replace_dates( $b, [ '2026-12-11' ], 'closed_all_day', [], 'private_event', 'internal note', AccessContext::system() );
		ClosureRepository::replace_dates( $b, [ '2026-12-14' ], 'special_hours', [ [ 'label' => 'dinner', 'opens_at' => '17:00', 'closes_at' => '21:00', 'last_order_at' => '20:30' ] ], 'other', '', AccessContext::system() );
		\Momos\Branch\Service\ScheduleService::flush();
		$hours = OpeningHours::for_branch( $b );
		$regular = array_values( array_filter( $hours, static fn( $h ) => ! isset( $h['validFrom'] ) ) );
		$this->assertCount( 3, $regular );
		$this->assertSame( [ 'https://schema.org/Monday', '11:00', '15:00' ], [ $regular[0]['dayOfWeek'], $regular[0]['opens'], $regular[0]['closes'] ] );
		$this->assertSame( [ 'https://schema.org/Friday', '18:00', '02:00' ], [ $regular[2]['dayOfWeek'], $regular[2]['opens'], $regular[2]['closes'] ], 'cross-midnight close kept on the opening day' );
		$closed = array_values( array_filter( $hours, static fn( $h ) => '2026-12-11' === ( $h['validFrom'] ?? '' ) ) );
		$this->assertCount( 1, $closed );
		$this->assertSame( [ 'https://schema.org/Friday', '00:00', '00:00', '2026-12-11' ], [ $closed[0]['dayOfWeek'], $closed[0]['opens'], $closed[0]['closes'], $closed[0]['validThrough'] ] );
		$special = array_values( array_filter( $hours, static fn( $h ) => '2026-12-14' === ( $h['validFrom'] ?? '' ) ) );
		$this->assertSame( [ [ '17:00', '21:00' ] ], array_map( static fn( $h ) => [ $h['opens'], $h['closes'] ], $special ) );
		$this->assertStringNotContainsString( 'internal note', (string) wp_json_encode( $hours ) );

		$this->visit( $b, 'en' );
		$node = JsonLd::restaurant( $b, 'en' );
		$this->assertSame( 'Restaurant', $node['@type'] );
		$this->assertSame( 'Fukushima', $node['name'] );
		$this->assertSame( '+81600000000', $node['telephone'] );
		$this->assertSame( [ '@type' => 'GeoCoordinates', 'latitude' => 34.67251, 'longitude' => 135.50123 ], $node['geo'] );
		$this->assertSame( 'JP', $node['address']['addressCountry'] );
		$this->assertSame( '1-1 Fukushima', $node['address']['streetAddress'] );
		$this->assertTrue( $node['acceptsReservations'] );
		$this->assertArrayNotHasKey( 'aggregateRating', $node );
		$this->assertArrayNotHasKey( 'priceRange', $node, 'Q-415: omitted until supplied' );
		$graph = JsonLd::graph( 'en' );
		$this->assertSame( [ 'Restaurant', 'BreadcrumbList' ], array_column( $graph, '@type' ), 'exactly one Restaurant node' );
		$json = json_decode( JsonLd::encode( $graph ), true );
		$this->assertSame( 'https://schema.org', $json['@context'] );
	}

	public function test_temporarily_closed_branch_emits_closed_dates_until_reopening(): void {
		$this->freeze( '2026-12-07 10:00:00' );
		$b = $this->branch( 'QSG', [], [ 'momo_operating_status' => 'temporarily_closed', 'momo_reopen_date' => '2026-12-10', 'momo_res_enabled' => '1' ] );
		$this->hours( $b, [ [ 1, '11:00', '15:00' ] ] );
		$closed = array_values( array_filter( OpeningHours::for_branch( $b ), static fn( $h ) => isset( $h['validFrom'] ) ) );
		$this->assertSame( [ '2026-12-07', '2026-12-08', '2026-12-09' ], array_column( $closed, 'validFrom' ) );
		$this->visit( $b, 'ja' );
		$this->assertArrayNotHasKey( 'acceptsReservations', (array) JsonLd::restaurant( $b, 'ja' ) );
	}

	public function test_no_restaurant_for_test_closed_or_untranslated_branches(): void {
		$test = $this->branch( 'QSH', [ 'en' => [ 'title' => 'T' ] ], [ 'momo_is_test_branch' => '1' ] );
		$gone = $this->branch( 'QSI', [], [ 'momo_operating_status' => 'closed_permanently' ] );
		$ja   = $this->branch( 'QSJ' );
		$this->assertNull( JsonLd::restaurant( $test, 'ja' ) );
		$this->assertNull( JsonLd::restaurant( $gone, 'ja' ) );
		$this->assertNull( JsonLd::restaurant( $ja, 'en' ) );
		$this->assertNotNull( JsonLd::restaurant( $ja, 'ja' ) );
	}

	public function test_menu_item_offer_in_jpy_and_halal_diet_only_with_certified_claim(): void {
		$cat  = $this->category( 'qgrill', [ 'en' => [ 'name' => 'Grill' ] ] );
		$dish = $this->dish( 'D7201', $cat, [ 'en' => [ 'title' => 'Seekh kebab', 'content' => '<p>Minced lamb skewers.</p>' ] ], [ 'momo_reference_price_jpy' => '980' ] );
		$this->visit( $dish, 'en' );
		$node = JsonLd::menu_item( $dish, 'en' );
		$this->assertSame( 'MenuItem', $node['@type'] );
		$this->assertSame( 'Seekh kebab', $node['name'] );
		$this->assertSame( 'Minced lamb skewers.', $node['description'] );
		$this->assertSame( [ '@type' => 'Offer', 'price' => '980', 'priceCurrency' => 'JPY' ], $node['offers'] );
		$this->assertArrayNotHasKey( 'suitableForDiet', $node, 'no certified_item claim → no HalalDiet' );
		add_filter( 'momos/schema/graph', static fn( $g ) => $g );
		$section = JsonLd::menu_section( $cat, 'en' );
		$this->assertSame( 'MenuSection', $section['@type'] );
		$this->assertSame( [ 'Seekh kebab' ], array_column( $section['hasMenuItem'], 'name' ) );
		$this->visit( SystemPages::id( 'menu' ), 'en' );
		$menu = JsonLd::menu( 'en' );
		$this->assertSame( 'Menu', $menu['@type'] );
		$this->assertContains( 'Grill', array_column( $menu['hasMenuSection'], 'name' ) );
	}

	public function test_home_has_organization_and_website_without_search_action(): void {
		$this->visit( home_url( '/' ), 'en' );
		$graph = JsonLd::graph( 'en' );
		$this->assertSame( [ 'Organization', 'WebSite' ], array_column( $graph, '@type' ) );
		$home = untrailingslashit( (string) get_option( 'home' ) );
		$this->assertSame( $home . '/#organization', $graph[0]['@id'] );
		$this->assertSame( $home . '/en/', $graph[0]['url'] );
		$this->assertArrayNotHasKey( 'potentialAction', $graph[1] );
		$this->assertSame( 'en', $graph[1]['inLanguage'] );
	}

	public function test_faq_page_markup_equals_visible_questions(): void {
		$faq = SystemPages::id( 'faq' );
		wp_update_post( [ 'ID' => $faq, 'post_status' => 'publish' ] );
		update_post_meta( $faq, 'momo_faq_items', wp_slash( (string) wp_json_encode( [
			[ 'id' => 'q1', 'group' => 'reservations', 'order' => 1, 'question' => '予約は必要ですか？', 'answer' => '<p>おすすめします。</p>' ],
			[ 'id' => 'q2', 'group' => 'halal_dietary', 'order' => 2, 'question' => 'ハラールですか？', 'answer' => '<p>店舗ごとに表示しています。</p>' ],
		], JSON_UNESCAPED_UNICODE ) ) );
		$this->visit( $faq, 'ja' );
		$node = JsonLd::faq_page( 'faq', $faq, 'ja' );
		$this->assertSame( 'FAQPage', $node['@type'] );
		$this->assertSame( [ '予約は必要ですか？', 'ハラールですか？' ], array_column( $node['mainEntity'], 'name' ) );
		$this->assertSame( 'おすすめします。', $node['mainEntity'][0]['acceptedAnswer']['text'] );
		// Halal page: only the halal & dietary excerpt it shows.
		$this->visit( SystemPages::id( 'halal' ), 'ja' );
		$halal = JsonLd::faq_page( 'halal', SystemPages::id( 'halal' ), 'ja' );
		$this->assertSame( [ 'ハラールですか？' ], array_column( $halal['mainEntity'], 'name' ) );
		// Untranslated FAQ in en → no FAQPage; switched off → none.
		$this->visit( $faq, 'en' );
		$this->assertNull( JsonLd::faq_page( 'halal', SystemPages::id( 'halal' ), 'en' ) );
		\Momos\Seo\SeoSettings::save( [ 'faq_schema' => false ] );
		$this->assertNull( JsonLd::faq_page( 'faq', $faq, 'ja' ) );
	}
}
