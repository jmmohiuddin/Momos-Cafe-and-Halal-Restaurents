<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

use Momos\Kernel\I18n\Lang;
use Momos\Kernel\I18n\Locale;
use Momos\Presentation\Components;
use Momos\Presentation\Format\DateFormatter;
use Momos\Presentation\Format\PhoneFormatter;
use Momos\Presentation\Format\PriceFormatter;
use Momos\Presentation\Helpers\Bdi;
use Momos\Presentation\Maintenance\MaintenancePage;
use Momos\Presentation\Migration\M0007_SystemPages;
use Momos\Presentation\PageContext;
use Momos\Presentation\PresentationModule;
use Momos\Presentation\SystemPages;
use Momos\Presentation\View\ActionBar;
use Momos\Presentation\View\LanguageSwitcher;
use Momos\Presentation\View\Navigation;
use Momos\Tests\TestCase;

/**
 * Presentation module and design-system frame (ui-design-system.md §3–§9, FR-CORP-008/009, FR-I18N-001/002/005/006,
 * DEC-060, TC-DS-03, TC-NAV-001/006, TC-I18N-001).
 */
final class PresentationTest extends TestCase {
	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/news/%postname%/' );
		SystemPages::flush();
		PageContext::reset();
		\Momos\Kernel\I18n\Strings::flush();
	}

	public function tear_down(): void {
		remove_all_filters( 'momos/presentation/offers_active' );
		PageContext::reset();
		parent::tear_down();
	}

	/** TC-DS-03: every presentation key exists, non-empty, in all six catalogs; drafts flagged (DEC-027). */
	public function test_presentation_catalogs_complete_in_six_languages(): void {
		$dir = MOMOS_CORE_DIR . '/languages/strings/';
		$en  = include $dir . 'en/presentation.php';
		$this->assertGreaterThan( 60, count( $en ) );
		foreach ( Lang::codes() as $lang ) {
			$file = $dir . $lang . '/presentation.php';
			$this->assertFileExists( $file );
			$cat = include $file;
			$this->assertSame( [], array_values( array_diff( array_keys( $en ), array_keys( $cat ) ) ), "missing keys in {$lang}" );
			foreach ( $cat as $key => $value ) {
				if ( 'ja' === $lang && 'common.tz_label' === $key ) {
					continue; // JST label is not shown in Japanese (DEC-062).
				}
				$this->assertNotSame( '', trim( (string) $value ), "{$lang}:{$key} empty" );
				preg_match_all( '/\{[a-z_]+\}/', (string) $en[ $key ], $want );
				preg_match_all( '/\{[a-z_]+\}/', (string) $value, $got );
				sort( $want[0] );
				sort( $got[0] );
				$this->assertSame( $want[0], $got[0], "{$lang}:{$key} placeholder set differs" );
			}
			if ( in_array( $lang, [ 'ar', 'ur', 'hi', 'bn' ], true ) ) {
				$this->assertStringContainsString( 'DRAFT – needs native review', (string) file_get_contents( $file ) );
			}
		}
	}

	/** Theme files use only keys that exist in the catalogs (no raw keys on screen). */
	public function test_theme_string_keys_exist(): void {
		$theme = dirname( MOMOS_CORE_DIR, 2 ) . '/themes/momos';
		$keys  = [];
		$it    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $theme . '/template-parts' ) );
		$files = array_merge( iterator_to_array( $it ), [ new \SplFileInfo( $theme . '/404.php' ), new \SplFileInfo( $theme . '/header.php' ) ] );
		foreach ( $files as $f ) {
			if ( $f->isFile() && str_ends_with( $f->getFilename(), '.php' ) ) {
				preg_match_all( "/momos_s\\(\\s*'([a-z0-9_.]+)'/", (string) file_get_contents( $f->getPathname() ), $m );
				$keys = array_merge( $keys, $m[1] );
			}
		}
		$keys = array_filter( array_unique( $keys ), static fn( $k ) => ! str_ends_with( $k, '.' ) ); // dynamic keys
		$this->assertNotEmpty( $keys );
		foreach ( $keys as $key ) {
			$this->assertTrue( \Momos\Kernel\I18n\Strings::has( $key, 'en' ) || str_starts_with( $key, 'status.' ), "key {$key} missing in en catalog" );
			$this->assertTrue( \Momos\Kernel\I18n\Strings::has( $key, 'ar' ), "key {$key} missing in ar catalog" );
		}
	}

	/** Template scan (Doc 09 §2.9.4): no prices, Japanese phones, time ranges or claim words in theme PHP. */
	public function test_theme_template_scan_has_no_literal_facts(): void {
		$theme = dirname( MOMOS_CORE_DIR, 2 ) . '/themes/momos';
		$it    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $theme ) );
		foreach ( $it as $f ) {
			if ( ! $f->isFile() || ! str_ends_with( $f->getFilename(), '.php' ) || str_contains( $f->getPathname(), '/static/' ) ) {
				continue;
			}
			$src = (string) file_get_contents( $f->getPathname() );
			$this->assertDoesNotMatchRegularExpression( '/¥\s?\d/u', $src, $f->getFilename() );
			$this->assertDoesNotMatchRegularExpression( '/0\d{1,4}-\d{1,4}-\d{4}/', $src, $f->getFilename() );
			$this->assertDoesNotMatchRegularExpression( '/\d{1,2}:\d{2}\s*[-–〜]/u', $src, $f->getFilename() );
			$this->assertDoesNotMatchRegularExpression( '/halal certified|100\s?%|ハラール認証/iu', $src, $f->getFilename() );
		}
	}

	/** FR-I18N-005: bidi isolation helpers escape and isolate. */
	public function test_bdi_helpers(): void {
		$this->assertSame( '<bdi dir="ltr">&lt;b&gt;</bdi>', Bdi::ltr( '<b>' ) );
		$this->assertSame( '<span lang="ja" dir="ltr">大阪</span>', Bdi::ja( '大阪' ) );
		$html = Bdi::t( 'maintenance.phone', [ 'phone' => '+81 6' ], [ 'phone' ], 'en' );
		$this->assertSame( 'Phone: <bdi dir="ltr">+81 6</bdi>', $html );
	}

	/** Doc 07 §6.10 formats: ja long/short literal patterns, en, Western digits for bn. */
	public function test_date_time_price_phone_formats(): void {
		$this->assertSame( '2026年12月3日（木）', DateFormatter::long( '2026-12-03', 'ja' ) );
		$this->assertSame( '12/3（木）', DateFormatter::short( '2026-12-03', 'ja' ) );
		if ( class_exists( \IntlDateFormatter::class ) ) {
			$this->assertSame( 'Thu 3 Dec 2026', DateFormatter::long( '2026-12-03', 'en' ) );
			$this->assertDoesNotMatchRegularExpression( '/[\x{09E6}-\x{09EF}\x{0660}-\x{0669}\x{06F0}-\x{06F9}]/u', DateFormatter::long( '2026-12-03', 'bn' ) . DateFormatter::long( '2026-12-03', 'ar' ) . DateFormatter::long( '2026-12-03', 'ur' ) );
		}
		$this->assertSame( '19:00', DateFormatter::time( '19:00:00' ) );
		$this->assertSame( '19:00', DateFormatter::time_with_zone( '19:00', 'ja' ) );
		$this->assertSame( '19:00 JST', DateFormatter::time_with_zone( '19:00', 'en' ) );
		$this->assertStringStartsWith( '<bdi dir="ltr">¥1,500</bdi>', PriceFormatter::html( 1500, 'en' ) );
		$this->assertSame( '06-6000-0000', PhoneFormatter::display( '+81660000000', '06-6000-0000', 'ja' ) );
		$this->assertSame( "+81\u{00A0}6-6000-0000", PhoneFormatter::display( '+81660000000', '06-6000-0000', 'ar' ) );
		$this->assertSame( 'tel:+81660000000', PhoneFormatter::tel_href( '+81 6 6000 0000' ) );
	}

	/** M0007: system pages, LCCS flags, front page; optional + legal pages start as drafts. */
	public function test_system_pages_migration_is_idempotent(): void {
		( new M0007_SystemPages() )->up();
		$map = SystemPages::map();
		foreach ( array_keys( SystemPages::ROLES ) as $role ) {
			$this->assertArrayHasKey( $role, $map );
		}
		( new M0007_SystemPages() )->up();
		SystemPages::flush();
		$this->assertSame( $map, SystemPages::map() );
		$this->assertSame( 'publish', get_post_status( $map['about'] ) );
		$this->assertSame( 'draft', get_post_status( $map['gallery'] ) );
		$this->assertSame( '1', get_post_meta( $map['home'], 'momo_lccs', true ) );
		$this->assertSame( 'page', get_option( 'show_on_front' ) );
		$this->assertSame( 'publish', get_post_status( (int) get_option( 'page_on_front' ) ) );
		$this->assertSame( 'reservation', get_post_field( 'post_name', (int) wp_get_post_parent_id( $map['submitted'] ) ) );
		$this->assertNull( SystemPages::url( 'gallery', 'ja' ) ); // optional, unpublished → no nav item
		$this->assertNull( SystemPages::legal_url( 'terms', 'ja' ) ); // draft legal page → no dead footer link
		// About is an editorial page: 404 in a language without an approved translation (DEC-027), so not linked
		// there (TC-NAV-005, QA-028); linked once the translation is approved.
		$this->assertNull( SystemPages::url( 'about', 'en' ) );
		update_post_meta( $map['about'], '_momos_i18n_en', wp_slash( wp_json_encode( [ 'title' => 'About us' ] ) ) );
		update_post_meta( $map['about'], '_momos_i18n_status_en', 'approved' );
		$this->assertStringEndsWith( '/en/about/', (string) SystemPages::url( 'about', 'en' ) );
	}

	/** FR-CORP-008 / DEC-060: header order; "Offers" only while an active offer exists (after Branches). */
	public function test_primary_navigation_and_offers_item(): void {
		( new M0007_SystemPages() )->up();
		add_filter( 'momos/presentation/offers_active', '__return_false' );
		$keys = array_column( Navigation::primary( 'ja' ), 'key' );
		$this->assertSame( [ 'menu', 'branches', 'halal', 'about', 'reservation' ], $keys );
		remove_all_filters( 'momos/presentation/offers_active' );
		add_filter( 'momos/presentation/offers_active', '__return_true' );
		$keys = array_column( Navigation::primary( 'ja' ), 'key' );
		$this->assertSame( [ 'menu', 'branches', 'offers', 'halal', 'about', 'reservation' ], $keys );
		// Footer "Explore" always links Offers.
		add_filter( 'momos/presentation/offers_active', '__return_false' );
		$this->assertContains( 'offers', array_column( Navigation::explore( 'en' ), 'key' ) );
	}

	/** §8.6: action bar per page type. */
	public function test_action_bar_items_by_page_type(): void {
		( new M0007_SystemPages() )->up();
		$this->assertSame( [], ActionBar::items( 'reservation', 0, 'ja' ) );
		$this->assertSame( [], ActionBar::items( 'reservation_submitted', 0, 'ja' ) );
		$items = ActionBar::items( 'home', 0, 'en' );
		$this->assertSame( [ 'request', 'branches' ], array_column( $items, 'key' ) );
		$this->assertSame( 'click_reservation', $items[0]['event'] );
		$this->assertStringContainsString( '/en/reservation/', $items[0]['url'] );
	}

	/** FR-I18N-001: body classes carry language and direction. */
	public function test_body_classes_language_and_direction(): void {
		Locale::set( 'ar' );
		$classes = PresentationModule::body_class( [] );
		$this->assertContains( 'momo-lang-ar', $classes );
		$this->assertContains( 'momo-dir-rtl', $classes );
		$this->assertContains( 'momo-page', $classes );
	}

	/** FR-I18N-002: switcher lists enabled languages in fixed order with native names; 404 → target home. */
	public function test_language_switcher_options(): void {
		update_option( 'momos_languages_enabled', [ 'en', 'ar' ] );
		$this->go_to( home_url( '/no-such-page-xyz/' ) );
		Locale::set( 'ja' );
		$opts = LanguageSwitcher::options();
		$this->assertSame( [ 'ja', 'en', 'ar' ], array_column( $opts, 'code' ) );
		$this->assertSame( 'العربية', $opts[2]['native'] );
		$this->assertSame( 'rtl', $opts[2]['dir'] );
		$this->assertTrue( $opts[0]['current'] );
		$this->assertStringEndsWith( '/ar/', $opts[2]['url'] );
		delete_option( 'momos_languages_enabled' );
	}

	/** Components: unknown slug renders nothing; output is escaped. */
	public function test_component_renderer_escapes(): void {
		$this->assertSame( '', Components::render( 'does-not-exist' ) );
		$html = Components::render( 'button', [ 'label' => '<script>x</script>', 'url' => 'https://example.org/a', 'variant' => 'primary' ] );
		$this->assertStringContainsString( 'momo-btn--primary', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$badge = Components::render( 'badge', [ 'status' => 'pending' ] );
		$this->assertStringContainsString( 'i-hourglass', $badge );
		$this->assertStringNotContainsString( 'check', $badge ); // pending never uses a check icon (DEC-011)
	}

	/** SCR-C20: static drop-in, 503 + Retry-After, one block per language with own lang/dir. */
	public function test_maintenance_page_is_static_and_multilingual(): void {
		$html = MaintenancePage::html( [ 'ja', 'en', 'ar' ], '+81600000000' );
		$this->assertStringContainsString( 'http_response_code( 503 )', $html );
		$this->assertStringContainsString( 'Retry-After: 600', $html );
		$this->assertStringContainsString( '<section lang="ar" dir="rtl"', $html );
		$this->assertStringContainsString( '<section lang="ja" dir="ltr"', $html );
		$this->assertStringContainsString( 'href="tel:+81600000000"', $html );
		$this->assertStringNotContainsString( '<link', $html ); // no external resources
	}
}
