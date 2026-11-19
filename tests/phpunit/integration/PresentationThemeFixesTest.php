<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/MenuTest.php';

use Momos\Kernel\Fields\FieldRegistry;
use Momos\Kernel\I18n\Strings;
use Momos\Kernel\I18n\Translations;
use Momos\Kernel\Settings;
use Momos\Presentation\Admin\BrandSettings;
use Momos\Presentation\Components;
use Momos\Presentation\Maintenance\MaintenanceMode;
use Momos\Presentation\Maintenance\MaintenancePage;
use Momos\Presentation\Migration\M0007_SystemPages;
use Momos\Presentation\Migration\M0007_SystemPagesR2;
use Momos\Presentation\Migration\M0008_NewsCategory;
use Momos\Presentation\SystemPages;
use Momos\Presentation\View\ActionBar;
use Momos\Presentation\View\BranchView;
use Momos\Presentation\View\Content;
use Momos\Presentation\View\HalalPage;
use Momos\Presentation\View\Home;
use Momos\Presentation\View\MenuView;
use Momos\Tests\TestCase;

/**
 * Theme/presentation gap fixes from the PRD audit: FR-BR-002 A3 "Show map", Directions fallback, FR-MENU-002(a)(b)
 * (spice 0, "Prices may differ by branch"), FR-CORP-011(e) offer validity data, FR-CORP-001(e)(f) / AC-CORP-01-04/05,
 * AC-CORP-03-03 halal per-language gate, FR-CORP-005(b) / AC-CORP-05-01 news, FR-CORP-007 A1 / FR-FND-005
 * maintenance + brand settings, AC-MENU-02-05 single-branch prefill.
 */
final class PresentationThemeFixesTest extends TestCase {
	use MenuDietaryFixtures;

	public function set_up(): void {
		parent::set_up();
		$this->fixtures_up();
		Settings::flush();
		Strings::flush();
		SystemPages::flush();
		putenv( 'MOMOS_GMAPS_EMBED_KEY' );
	}

	public function tear_down(): void {
		putenv( 'MOMOS_GMAPS_EMBED_KEY' );
		$this->fixtures_down();
		Settings::flush();
		parent::tear_down();
	}

	/** FR-BR-002 A3 / TRD §4.6: "Show map" only with the G3 setting on and the key defined; iframe created on click only. */
	public function test_show_map_needs_setting_and_key(): void {
		$branch = [ 'lat' => 34.69, 'lng' => 135.5, 'place_id' => '', 'address_ja' => '大阪府大阪市中央区北浜サンプル1-2-3' ];
		$this->assertSame( '', BranchView::map_embed_url( $branch, 'en' ), 'no key → links only' );
		$off = Components::render( 'map-facade', [ 'name' => 'Demo', 'maps_url' => 'https://www.google.com/maps/search/?api=1', 'directions_url' => 'https://www.google.com/maps/dir/?api=1', 'embed_url' => BranchView::map_embed_url( $branch, 'en' ) ] );
		$this->assertStringNotContainsString( 'data-momo-map-embed', $off );

		putenv( 'MOMOS_GMAPS_EMBED_KEY=test-embed-key' );
		$url = BranchView::map_embed_url( $branch, 'en' );
		$this->assertStringStartsWith( 'https://www.google.com/maps/embed/v1/place?', $url );
		$this->assertStringContainsString( 'key=test-embed-key', $url );
		$this->assertStringContainsString( 'q=34.69%2C135.5', $url );
		$this->assertStringContainsString( 'q=place_id%3AChIJ123', BranchView::map_embed_url( [ 'place_id' => 'ChIJ123' ] + $branch, 'en' ) );
		$this->assertStringContainsString( 'q=' . rawurlencode( $branch['address_ja'] ), BranchView::map_embed_url( [ 'lat' => null, 'lng' => null ] + $branch, 'en' ) );
		$on = \Momos\Kernel\I18n\Locale::with( 'en', static fn() => Components::render( 'map-facade', [ 'name' => 'Demo', 'maps_url' => 'https://www.google.com/maps/search/?api=1', 'directions_url' => 'https://www.google.com/maps/dir/?api=1', 'embed_url' => $url ] ) );
		$this->assertStringContainsString( 'data-momo-map-embed="https://www.google.com/maps/embed/v1/place?', $on );
		$this->assertStringContainsString( '<span>Show map</span>', $on );
		$this->assertStringContainsString( 'data-momo-map-title="Map of Demo (Google Maps)"', $on );
		$this->assertStringNotContainsString( '<iframe', $on, 'no third-party request before the click' );

		Settings::set( 'momo_map_embed_on_click', '0' );
		Settings::flush();
		$this->assertSame( '', BranchView::map_embed_url( $branch, 'en' ), 'setting off → links only' );
		$this->assertStringContainsString( 'https://www.google.com', \Momos\Access\Http\SecurityHeaders::CSP );
		$this->assertMatchesRegularExpression( '#frame-src[^;]*https://www\.google\.com#', \Momos\Access\Http\SecurityHeaders::CSP );
	}

	/** F-BR-01 error handling: Directions uses coordinates, else the Japanese address. */
	public function test_directions_fall_back_to_the_japanese_address(): void {
		$ja = '大阪府大阪市中央区北浜サンプル1-2-3';
		$this->assertSame( 'https://www.google.com/maps/dir/?api=1&destination=34.69%2C135.5', ActionBar::directions_url( [ 'lat' => 34.69, 'lng' => 135.5, 'address_ja' => $ja ] ) );
		$url = ActionBar::directions_url( [ 'lat' => null, 'lng' => null, 'place_id' => '', 'address_ja' => $ja ] );
		$this->assertStringStartsWith( 'https://www.google.com/maps/dir/?api=1&destination=', $url );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		$this->assertSame( $ja, rawurldecode( (string) $q['destination'] ) );
		$this->assertSame( '', ActionBar::directions_url( [ 'lat' => null, 'lng' => null, 'address_ja' => '' ] ) );
	}

	/** FR-MENU-002(a)(b), AC-MENU-01-01: price note on the card; spice 0 shows icons + "Not spicy". */
	public function test_dish_card_price_note_and_spice_zero(): void {
		$card = [ 'id' => 1, 'name' => 'Dal', 'url' => 'https://example.test/d', 'price_html' => '<bdi dir="ltr">¥1,500</bdi> (tax incl.)', 'prices_may_differ' => true, 'spice' => 0, 'spice_label' => momos_t( 'spice.0', [], 'en' ), 'tags' => [] ];
		$html = \Momos\Kernel\I18n\Locale::with( 'en', static fn() => Components::render( 'dish-card', [ 'card' => $card ] ) );
		$this->assertStringContainsString( 'Prices may differ by branch', $html );
		$this->assertStringContainsString( 'Not spicy', $html );
		$this->assertSame( 3, substr_count( $html, '#i-chili-outline' ) );
		$this->assertSame( 0, substr_count( $html, '#i-chili-filled' ) );
		$plain = \Momos\Kernel\I18n\Locale::with( 'en', static fn() => Components::render( 'dish-card', [ 'card' => [ 'prices_may_differ' => false ] + $card ] ) );
		$this->assertStringNotContainsString( 'Prices may differ by branch', $plain );
	}

	/** AC-MENU-01-01 end to end: without branch the card carries the reference price and the note. */
	public function test_card_data_prices_may_differ_without_branch_only(): void {
		$cat = $this->category( 'pmd' );
		$a   = $this->branch( 'PMA' );
		$this->branch( 'PMB' );
		$x = $this->dish( 'D0900', $cat, 1500 );
		$this->row( $a, $x, [ 'price_override_jpy' => 1600 ] );
		$none = \Momos\Menu\Service\MenuQueryService::card( $x, 'ja' );
		$this->assertSame( 1500, $none['price'] );
		$this->assertTrue( $none['prices_may_differ'] );
		$this->assertFalse( \Momos\Menu\Service\MenuQueryService::card( $x, 'ja', $a )['prices_may_differ'] );
	}

	/** AC-MENU-02-05: dish offered only at C (no branch context) → Request a table preselects C. */
	public function test_single_offering_branch_is_preselected(): void {
		( new M0007_SystemPages() )->up();
		( new M0007_SystemPagesR2() )->up();
		SystemPages::flush();
		$cat = $this->category( 'one' );
		$a   = $this->branch( 'ONA' );
		$c   = $this->branch( 'ONC' );
		foreach ( [ $a, $c ] as $b ) {
			update_post_meta( $b, 'momo_res_enabled', '1' );
		}
		$x = $this->dish( 'D0910', $cat );
		$this->row( $c, $x );
		$url = (string) MenuView::dish( $x, null, 'ja' )['request_url'];
		$this->assertStringContainsString( 'branch=ONC', $url );
		$this->row( $a, $x );
		$this->assertStringNotContainsString( 'branch=', (string) MenuView::dish( $x, null, 'ja' )['request_url'], 'two offering branches → no preselection' );
	}

	/** FR-CORP-011(e): offer cards carry their UTC window for the client-side re-check. */
	public function test_offer_card_embeds_validity(): void {
		$html = Components::render( 'offer-card', [ 'offer' => [ 'id' => 7, 'title' => 'Lunch', 'start_ms' => 1795000000000, 'end_ms' => 1796000000000 ] ] );
		$this->assertStringContainsString( 'data-momo-offer', $html );
		$this->assertStringContainsString( 'data-start="1795000000000"', $html );
		$this->assertStringContainsString( 'data-end="1796000000000"', $html );
	}

	/** FR-CORP-001(e), AC-CORP-01-04: no approved brand statement in a language → neutral text, never empty, no claim. */
	public function test_home_halal_section_never_disappears(): void {
		update_option( \Momos\Dietary\Service\StatementRepository::OPTION, [] );
		foreach ( [ 'ja', 'en', 'ur' ] as $lang ) {
			$claim = Home::halal_claim( $lang );
			$this->assertTrue( $claim['fallback'] );
			$this->assertSame( momos_t( Home::NEUTRAL_KEY, [], $lang ), $claim['statement'] );
		}
		$this->assertSame( 'Halal information is shown for each branch.', Home::halal_claim( 'en' )['statement'] );
		$html = \Momos\Kernel\I18n\Locale::with( 'en', static fn() => Components::render( 'claim-statement', [ 'context' => 'brand', 'h' => 2, 'link' => true ] ) );
		$this->assertStringContainsString( 'Halal information is shown for each branch.', $html );
		$this->assertStringNotContainsString( 'ertified', $html );
		$this->assertStringNotContainsString( 'momo-halal__mark', $html );

		update_option(
			\Momos\Dietary\Service\StatementRepository::OPTION,
			[ [ 'key' => 'brand.neutral', 'claim_type' => 'neutral', 'text_ja' => 'ハラール情報は店舗ごとです。', 'approved' => true, 'i18n' => [ 'en' => [ 'text' => 'Approved neutral EN.', 'status' => 'approved' ] ] ] ]
		);
		$this->assertSame( 'Approved neutral EN.', Home::halal_claim( 'en' )['statement'], 'approved template wins' );
		$this->assertFalse( Home::halal_claim( 'en' )['fallback'] );
		$this->assertTrue( Home::halal_claim( 'ur' )['fallback'], 'not approved in ur → documented neutral sentence' );
	}

	/** FR-CORP-001(f), AC-CORP-01-05, DEC-013: the promise needs approved copy and every accepting branch configured. */
	public function test_home_response_promise_only_when_approved(): void {
		$a = $this->branch( 'RPA' );
		foreach ( [ 'momo_res_enabled' => '1', 'momo_res_show_response_promise' => '1', 'momo_res_response_sla_minutes' => '120' ] as $k => $v ) {
			update_post_meta( $a, $k, $v );
		}
		Strings::set_status( 'en', [ 'res.submitted.promise' ], 'draft' );
		$this->assertSame( '', Home::response_promise( 'en' ), 'copy not approved → no promise' );
		Strings::set_status( 'en', [ 'res.submitted.promise' ], 'approved' );
		$this->assertSame( 'The branch usually replies within 2 hours during opening hours.', Home::response_promise( 'en' ) );
		update_post_meta( $a, 'momo_res_show_response_promise', '0' );
		$this->assertSame( '', Home::response_promise( 'en' ), 'promise switched off at an accepting branch' );
	}

	/** AC-CORP-03-03: /ur/halal/ is not published while a needed statement template has no approved Urdu text. */
	public function test_halal_page_language_gate(): void {
		$b = $this->branch( 'HLG' );
		Translations::save( 'post', $b, 'ur', [ 'title' => 'ڈیمو برانچ' ], 'approved' );
		$row = [ 'key' => 'no_claim', 'claim_type' => 'no_claim', 'text_ja' => '当店はハラールに関する表示を行っていません。', 'approved' => true, 'i18n' => [] ];
		update_option( \Momos\Dietary\Service\StatementRepository::OPTION, [ $row ] );
		$this->assertTrue( HalalPage::published( 'ja' ), 'source page always published' );
		$this->assertFalse( HalalPage::published( 'ur' ) );
		$row['i18n']['ur'] = [ 'text' => 'یہ برانچ حلال سے متعلق کوئی دعویٰ نہیں کرتی۔', 'status' => 'draft' ];
		update_option( \Momos\Dietary\Service\StatementRepository::OPTION, [ $row ] );
		$this->assertFalse( HalalPage::published( 'ur' ), 'draft translation is not reviewed' );
		// hreflang alternates and the sitemap use the same predicate (Seo\Context::entity_visible).
		$halal = \Momos\Presentation\SystemPages::id( 'halal' );
		if ( $halal ) {
			$this->assertFalse( \Momos\Seo\Context::entity_visible( \Momos\Seo\Context::PAGE, $halal, 'ur', 'halal' ), 'unpublished /ur/halal/ is not advertised' );
		}
		$row['i18n']['ur']['status'] = 'approved';
		update_option( \Momos\Dietary\Service\StatementRepository::OPTION, [ $row ] );
		$this->assertTrue( HalalPage::published( 'ur' ) );
	}

	/** FR-CORP-005(b), AC-CORP-05-01: only the News category, 10 per page, newest first (12 → 10 + 2). */
	public function test_news_list_category_and_pagination(): void {
		( new M0008_NewsCategory() )->up();
		( new M0008_NewsCategory() )->up();
		$news = get_term_by( 'slug', 'news', 'category' );
		$this->assertInstanceOf( \WP_Term::class, $news );
		$ids = [];
		for ( $i = 1; $i <= 12; $i++ ) {
			$ids[ $i ] = self::factory()->post->create( [ 'post_title' => 'ニュース' . $i, 'post_date' => sprintf( '2026-10-%02d 10:00:00', $i ), 'post_category' => [ $news->term_id ] ] );
		}
		$other = self::factory()->post->create( [ 'post_title' => '他のカテゴリー', 'post_date' => '2026-10-30 10:00:00' ] );
		$p1    = Content::news( 1, 'ja' );
		$p2    = Content::news( 2, 'ja' );
		$this->assertCount( 10, $p1['items'] );
		$this->assertCount( 2, $p2['items'] );
		$this->assertSame( 2, $p1['pages'] );
		$this->assertSame( $ids[12], $p1['items'][0]['id'], 'newest first' );
		$this->assertSame( [ $ids[2], $ids[1] ], array_column( $p2['items'], 'id' ) );
		$this->assertNotContains( $other, array_merge( array_column( $p1['items'], 'id' ), array_column( $p2['items'], 'id' ) ) );
		$this->assertContains( 'M0008_NewsCategory', array_map( static fn( $m ) => $m->id(), (array) apply_filters( 'momos/migrations', [] ) ) );
	}

	/** FR-CORP-007 / FR-FND-005: the maintenance page reads the registered brand phone (E.164 + display form). */
	public function test_maintenance_page_uses_brand_phone(): void {
		Settings::set( 'momo_contact_phone_e164', '+81612345678' );
		Settings::set( 'momo_contact_phone_display', '06-1234-5678' );
		Settings::flush();
		$doc = MaintenancePage::document( [ 'ja', 'en', 'ar', 'ur' ] );
		$this->assertStringContainsString( 'href="tel:+81612345678"', $doc );
		$this->assertStringContainsString( '06-1234-5678', $doc );
		$this->assertStringContainsString( '<section lang="ur" dir="rtl"', $doc );
		$this->assertStringNotContainsString( '<?php', $doc );
		Settings::set( 'momo_contact_phone_e164', '' );
		Settings::flush();
		$this->assertStringNotContainsString( 'tel:', MaintenancePage::document( [ 'ja' ] ), 'no phone configured → omitted' );
	}

	/** FR-CORP-007 A1: ROLE-01 toggle; anonymous (and non-ROLE-01) blocked, logged-in ROLE-01 sees the site. */
	public function test_maintenance_toggle_and_role01_bypass(): void {
		$this->assertSame( 'momo-settings', FieldRegistry::option_page_of( MaintenanceMode::OPTION ) );
		$keys = array_column( \Momos\Admin\Settings\SettingsScreen::groups_of( 'monitoring' ), 'key' );
		$this->assertContains( 'momo_settings_maintenance', $keys );
		$this->assertFalse( MaintenanceMode::should_block(), 'off by default' );
		Settings::set( MaintenanceMode::OPTION, '1' );
		Settings::flush();
		wp_set_current_user( 0 );
		$this->assertTrue( MaintenanceMode::should_block() );
		$req = new \WP_REST_Request( 'POST', '/momos/v1/reservations' );
		$err = MaintenanceMode::rest( null, rest_get_server(), $req );
		$this->assertWPError( $err );
		$this->assertSame( 503, $err->get_error_data()['status'] );
		wp_set_current_user( $this->user( 'momo_head_office' ) );
		$this->assertTrue( MaintenanceMode::should_block(), 'Head Office is not ROLE-01' );
		$this->assertNull( MaintenanceMode::rest( null, rest_get_server(), $req ), 'staff REST calls are untouched' );
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );
		$this->assertFalse( MaintenanceMode::should_block(), 'logged-in ROLE-01 sees the site' );
		Settings::set( MaintenanceMode::OPTION, '0' );
	}

	/** FR-FND-005: registered address (translatable), incomplete notice for ROLE-01/02, save purges momo-global. */
	public function test_brand_settings_address_notice_and_purge(): void {
		$this->assertSame( 'momo-brand', FieldRegistry::option_page_of( BrandSettings::ADDRESS ) );
		$group = FieldRegistry::groups_for( 'option:momo-brand' );
		$field = null;
		foreach ( $group as $g ) {
			foreach ( $g['fields'] as $f ) {
				if ( BrandSettings::ADDRESS === $f['name'] ) {
					$field = $f;
				}
			}
		}
		$this->assertNotNull( $field );
		$this->assertTrue( ! empty( $field['translatable'] ) );

		update_option( 'momos_opt_momo-brand', [ 'momo_contact_email' => 'info@example.co.jp' ] );
		Settings::flush();
		$missing = BrandSettings::missing();
		$this->assertArrayHasKey( 'momo_legal_operator_name', $missing );
		$this->assertArrayHasKey( 'momo_logo_primary', $missing );
		$this->assertArrayHasKey( 'momo_contact_phone_e164', $missing );
		$this->assertArrayHasKey( 'momo_privacy_page', $missing );
		$this->assertArrayNotHasKey( 'momo_contact_email', $missing );

		wp_set_current_user( $this->user( 'momo_head_office' ) );
		ob_start();
		BrandSettings::notice();
		$out = (string) ob_get_clean();
		$this->assertStringContainsString( 'Required brand settings are incomplete', $out );
		$this->assertStringContainsString( 'Legal operator name', $out );
		wp_set_current_user( $this->user( 'momo_menu_manager' ) );
		ob_start();
		BrandSettings::notice();
		$this->assertSame( '', (string) ob_get_clean(), 'only ROLE-01/02 see it' );

		$tags = [];
		add_action(
			'momos/cache/purge',
			static function ( $t ) use ( &$tags ): void {
				$tags = array_merge( $tags, (array) $t );
			},
			0
		);
		do_action( 'momos/options_page/saved', 'momo-brand' );
		$this->assertContains( 'momo-global', $tags );
	}
}
