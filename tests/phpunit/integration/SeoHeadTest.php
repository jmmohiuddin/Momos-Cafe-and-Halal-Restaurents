<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/SeoTestCaseTest.php';

use Momos\Presentation\SystemPages;
use Momos\Seo\Breadcrumbs;
use Momos\Seo\Context;
use Momos\Seo\Head;
use Momos\Seo\Meta;

/**
 * SEO head (FR-SEO-002/003/005/007; i18n-seo-analytics.md §9.1–§9.4, §9.7): canonical, hreflang +
 * x-default, noindex rules (query variants, TEST branch, C10/C11, hidden languages, previews, 404),
 * per-language titles/descriptions with overrides, OG, breadcrumbs (AC-SEO-01-01/02/06/07).
 */
final class SeoHeadTest extends SeoTestCaseTest {
	public function test_branch_canonical_and_reciprocal_hreflang_ac_seo_01_01(): void {
		$b = $this->branch( 'QHA', [ 'en' => [ 'title' => 'Alpha', 'momo_area_label' => 'Namba' ], 'ar' => [ 'title' => 'ألفا' ] ] );
		$this->visit( $b, 'en' );
		$this->assertSame( 'branch', Context::current()['kind'] );
		$this->assertSame( '', Context::noindex_reason() );
		$home = untrailingslashit( (string) get_option( 'home' ) );
		$this->assertSame( $home . '/en/branches/qha-branch/', Context::canonical() );
		$alts = Context::alternates();
		$this->assertSame( [ 'ja', 'en', 'ar' ], array_keys( $alts ), 'only languages with an approved translation' );
		$this->assertSame( $home . '/ar/branches/qha-branch/', $alts['ar'] );
		$html = Head::markup();
		$this->assertStringContainsString( '<link rel="alternate" hreflang="x-default" href="' . $home . '/en/branches/qha-branch/">', $html );
		$this->assertStringNotContainsString( 'hreflang="hi"', $html );
		// Reciprocal: the ar version lists the same set.
		$this->visit( $b, 'ar' );
		$this->assertSame( $alts, Context::alternates() );
		$this->assertSame( $home . '/ar/branches/qha-branch/', Context::canonical() );
	}

	public function test_hidden_language_is_never_an_alternate_and_x_default_omitted_without_en(): void {
		$b = $this->branch( 'QHB', [ 'en' => [ 'title' => 'Beta' ], 'ar' => [ 'title' => 'بيتا' ] ] );
		update_option( 'momos_languages_enabled', [ 'ja', 'ar' ] );
		$this->visit( $b, 'ja' );
		$alts = Context::alternates();
		$this->assertSame( [ 'ja', 'ar' ], array_keys( $alts ) );
		$this->assertStringNotContainsString( 'x-default', Head::markup() );
		// Visiting the hidden language itself (staff preview) → noindex, nofollow, no canonical/hreflang.
		$this->visit( $b, 'en' );
		$this->assertSame( 'hidden_language', Context::noindex_reason() );
		$this->assertNull( Context::canonical() );
		$this->assertSame( [], Context::alternates() );
		$robots = Head::robots( [] );
		$this->assertTrue( $robots['noindex'] );
		$this->assertTrue( $robots['nofollow'] );
	}

	public function test_query_variant_is_noindex_follow_with_clean_canonical_ac_seo_01_02(): void {
		$cat = $this->category( 'qcurry', [ 'en' => [ 'name' => 'Curry' ] ] );
		$this->dish( 'D7001', $cat, [ 'en' => [ 'title' => 'Chicken' ] ] );
		$this->visit( home_url( '/menu/qcurry/?branch=SHN' ), 'ja' );
		$this->assertSame( 'category', Context::current()['kind'] );
		$this->assertSame( 'query', Context::noindex_reason() );
		$this->assertSame( untrailingslashit( (string) get_option( 'home' ) ) . '/menu/qcurry/', Context::canonical() );
		$this->assertSame( [], Context::alternates(), 'noindex pages print no hreflang' );
		$robots = Head::robots( [ 'max-image-preview' => 'large' ] );
		$this->assertTrue( $robots['noindex'] );
		$this->assertTrue( $robots['follow'] );
		$this->assertArrayNotHasKey( 'max-image-preview', $robots );
		// Same URL without the query is indexable.
		$this->visit( home_url( '/menu/qcurry/' ), 'ja' );
		$this->assertSame( '', Context::noindex_reason() );
		$this->assertSame( [], Head::robots( [] ) );
	}

	public function test_private_pages_test_branch_and_404_are_noindex_without_canonical(): void {
		$sub = SystemPages::id( 'submitted' );
		$this->visit( $sub, 'en' );
		$this->assertSame( 'private', Context::noindex_reason() );
		$this->assertNull( Context::canonical() );
		$this->assertStringNotContainsString( 'og:title', Head::markup() );
		$this->assertStringNotContainsString( 'ld+json', Head::markup() );

		$test = $this->branch( 'TSQ', [ 'en' => [ 'title' => 'Test' ] ], [ 'momo_is_test_branch' => '1' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->visit( $test, 'ja' );
		$this->assertSame( 'test_branch', Context::noindex_reason() );
		$this->assertTrue( Head::robots( [] )['nofollow'] );
		$this->assertSame( [], \Momos\Seo\Schema\JsonLd::graph() );
		wp_set_current_user( 0 );

		$this->visit( home_url( '/en/does-not-exist-xyz/' ), 'en' );
		$this->assertSame( '404', Context::noindex_reason() );
		$this->assertNull( Context::canonical() );
		$this->assertSame( 'Page not found | Momo\'s', Meta::title( 'en' ) );
	}

	public function test_untranslated_page_preview_is_noindex_and_offers_noindex_while_empty(): void {
		$about = SystemPages::id( 'about' );
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );
		$this->visit( $about, 'hi' );
		$this->assertSame( 'preview', Context::noindex_reason() );
		wp_set_current_user( 0 );

		add_filter( 'momos/presentation/offers_active', '__return_false' );
		$this->visit( SystemPages::id( 'offers' ), 'ja' );
		$this->assertSame( 'offers_empty', Context::noindex_reason() );
		$this->assertNotNull( Context::canonical(), 'self canonical kept on the empty offers page' );
		remove_all_filters( 'momos/presentation/offers_active' );
	}

	public function test_titles_per_language_templates_and_overrides_ac_seo_01_06(): void {
		$b = $this->branch( 'QHT', [ 'en' => [ 'title' => 'Tenma', 'momo_area_label' => 'Tenma' ], 'ar' => [ 'title' => 'تنما' ] ] );
		$this->visit( $b, 'en' );
		$this->assertSame( 'Tenma – Halal restaurant near Tenma | Momo\'s', Meta::title( 'en' ) );
		$this->visit( $b, 'ar' );
		$this->assertSame( 'تنما | Momo\'s', Meta::title( 'ar' ), 'no area in ar → no-area template, never JA text' );
		$this->visit( $b, 'ja' );
		$this->assertSame( '店舗QHT｜Momo\'s', Meta::title( 'ja' ) );

		// Overrides: JA meta and approved translated keys; a draft translation is ignored.
		update_post_meta( $b, 'momo_seo_title', '心斎橋のハラール' );
		update_post_meta( $b, 'momo_seo_description', '駅から徒歩1分。' );
		$this->translate( 'post', $b, 'ar', [ 'title' => 'تنما', 'seo_title' => 'مطعم حلال تنما', 'og_title' => 'تنما' ] );
		$this->visit( $b, 'ja' );
		$this->assertSame( '心斎橋のハラール', Meta::title( 'ja' ) );
		$this->assertSame( '駅から徒歩1分。', Meta::description( 'ja' ) );
		$this->visit( $b, 'ar' );
		$this->assertSame( 'مطعم حلال تنما', Meta::title( 'ar' ) );
		$html = Head::markup();
		$this->assertStringContainsString( '<meta property="og:title" content="تنما">', $html );
		$this->assertStringContainsString( '<meta property="og:locale" content="ar_AR">', $html );
		update_post_meta( $b, '_momos_i18n_status_ar', 'draft' );
		$this->visit( $b, 'en' );
		$this->assertStringNotContainsString( 'مطعم', Meta::title( 'en' ) );
	}

	public function test_dish_title_description_and_breadcrumbs_ac_seo_01_07(): void {
		$cat  = $this->category( 'qbiryani', [ 'ar' => [ 'name' => 'برياني' ] ] );
		$dish = $this->dish( 'D7002', $cat, [ 'ar' => [ 'title' => 'برياني الدجاج', 'momo_short_description' => 'أرز بسمتي متبل.' ] ] );
		$this->visit( $dish, 'ar' );
		$this->assertSame( 'dish', Context::current()['kind'] );
		$this->assertSame( 'برياني الدجاج – برياني | Momo\'s', Meta::title( 'ar' ) );
		$this->assertSame( 'أرز بسمتي متبل.', Meta::description( 'ar' ) );
		$home  = untrailingslashit( (string) get_option( 'home' ) );
		$items = Breadcrumbs::items( 'ar' );
		$this->assertSame( [ momos_t( 'bc.home', [], 'ar' ), momos_t( 'nav.menu', [], 'ar' ), 'برياني', 'برياني الدجاج' ], array_column( $items, 'name' ) );
		$this->assertSame( $home . '/ar/', $items[0]['url'] );
		$this->assertSame( $home . '/ar/menu/qbiryani/', $items[2]['url'] );
		$this->assertSame( $home . '/ar/menu/qbiryani/d7002/', $items[3]['url'] );
		// BreadcrumbList = the same trail, positions 1…n.
		$bc = \Momos\Seo\Schema\JsonLd::breadcrumb_list( 'ar' );
		$this->assertSame( [ 1, 2, 3, 4 ], array_column( $bc['itemListElement'], 'position' ) );
		$this->assertSame( array_column( $items, 'url' ), array_column( $bc['itemListElement'], 'item' ) );
	}

	public function test_description_truncation_and_no_breadcrumbs_on_home_or_reservation(): void {
		$long = str_repeat( 'Lovely halal food near the station. ', 12 );
		$out  = Meta::truncate( $long, 'en' );
		$this->assertLessThanOrEqual( Meta::DESC_MAX, mb_strlen( $out ) );
		$this->assertStringEndsWith( '.', $out );
		$ja = Meta::truncate( str_repeat( 'あ', 200 ), 'ja' );
		$this->assertLessThanOrEqual( Meta::DESC_MAX_JA, mb_strlen( $ja ) );

		$this->visit( home_url( '/' ), 'ja' );
		$this->assertSame( 'home', Context::current()['kind'] );
		$this->assertSame( [], Breadcrumbs::items() );
		$this->visit( SystemPages::id( 'reservation' ), 'en' );
		$this->assertSame( [], Breadcrumbs::items() );
		$this->assertSame( '', Context::noindex_reason(), 'C09 is indexable' );
	}

	public function test_core_canonical_removed_and_document_title_filtered(): void {
		$this->assertFalse( has_action( 'wp_head', 'rel_canonical' ) );
		$b = $this->branch( 'QHD', [ 'en' => [ 'title' => 'Delta' ] ] );
		$this->visit( $b, 'en' );
		$this->assertSame( 'Delta | Momo\'s', Head::document_title( 'fallback' ) );
	}
}
