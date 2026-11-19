<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/SeoTestCaseTest.php';

use Momos\Presentation\SystemPages;
use Momos\Seo\Analytics\TagLoader;
use Momos\Seo\Redirects\NotFoundLog;
use Momos\Seo\Redirects\RedirectMatcher;
use Momos\Seo\Redirects\RedirectStore;
use Momos\Seo\Routing;
use Momos\Seo\SeoSettings;

/**
 * Redirects & 404 log (FR-SEO-008, FR-SEO-001 normalisation, FR-CORP-007(b), FR-PRV-005; AC-SEO-01-04,
 * TC-SEO-001/008), robots.txt (FR-SEO-009, TC-SEO-009) and the analytics loader rules (FR-ANL-001/002,
 * DEC-066, AC-ANL-01-02/03, TC-ANL-004/005/009).
 */
final class SeoRedirectsAnalyticsTest extends SeoTestCaseTest {
	public function tear_down(): void {
		delete_option( RedirectStore::OPTION );
		RedirectStore::flush();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_redirect_validation_refuses_chains_loops_external_and_pii(): void {
		$ok = RedirectStore::save( [ 'from' => '/Old-Menu.html', 'to' => '/menu/', 'status' => 301, 'owner' => 'SEO' ] );
		$this->assertSame( [], $ok['errors'] );
		$this->assertSame( '/old-menu.html', $ok['rule']['from'], 'stored lower-case, no trailing slash' );
		$this->assertArrayHasKey( 'to', RedirectStore::save( [ 'from' => '/menu', 'to' => '/old-menu.html' ] )['errors'], 'target is itself a source (chain/loop)' );
		$this->assertArrayHasKey( 'to', RedirectStore::save( [ 'from' => '/legacy/', 'to' => '/old-menu.html/' ] )['errors'], 'target is a redirected source' );
		$this->assertArrayHasKey( 'to', RedirectStore::save( [ 'from' => '/a', 'to' => 'https://evil.example.com/' ] )['errors'] );
		$this->assertArrayHasKey( 'to', RedirectStore::save( [ 'from' => '/a', 'to' => '/a/' ] )['errors'], 'target = source' );
		$this->assertArrayHasKey( 'from', RedirectStore::save( [ 'from' => '/old-menu.html/', 'to' => '/branches/' ] )['errors'], 'duplicate source' );
		$this->assertArrayHasKey( 'from', RedirectStore::save( [ 'from' => '/r?email=a@b.c', 'to' => '/menu/' ] )['errors'], 'PII in source' );
		$this->assertArrayHasKey( 'from', RedirectStore::save( [ 'from' => '/wp-admin/x', 'to' => '/menu/' ] )['errors'] );
		$this->assertArrayHasKey( 'to', RedirectStore::save( [ 'from' => '/x', 'to' => '' ] )['errors'], '301 needs a target' );
		$this->assertArrayHasKey( 'status', RedirectStore::save( [ 'from' => '/x', 'to' => '/menu/', 'status' => 307 ] )['errors'] );
		$this->assertSame( [], RedirectStore::save( [ 'from' => '/gone', 'status' => 410 ] )['errors'] );
		$this->assertSame( [], RedirectStore::save( [ 'from' => '/promo', 'to' => '/offers/', 'status' => 302 ] )['errors'] );
		// Existing rule pointing at a new source → refused (would build a chain).
		$this->assertSame( [], RedirectStore::save( [ 'from' => '/x1', 'to' => '/x2/' ] )['errors'] );
		$this->assertArrayHasKey( 'from', RedirectStore::save( [ 'from' => '/x2/', 'to' => '/menu/' ] )['errors'] );
		$this->assertCount( 4, RedirectStore::all() );
	}

	public function test_matching_is_exact_case_insensitive_single_hop_with_loop_guard(): void {
		RedirectStore::save( [ 'from' => '/old-menu.html', 'to' => '/en/menu/', 'status' => 301 ] );
		RedirectStore::save( [ 'from' => '/promo', 'to' => '/offers/', 'status' => 302 ] );
		RedirectStore::save( [ 'from' => '/gone', 'status' => 410 ] );
		RedirectStore::save( [ 'from' => '/campaign?id=7', 'to' => '/offers/', 'status' => 301 ] );
		$home = untrailingslashit( (string) get_option( 'home' ) );
		$this->assertSame( [ 'status' => 301, 'url' => $home . '/en/menu/' ], array_slice( (array) RedirectMatcher::decide( '/OLD-MENU.HTML' ), 0, 2 ) );
		$this->assertSame( 301, RedirectMatcher::decide( '/old-menu.html/' )['status'] );
		$this->assertSame( 302, RedirectMatcher::decide( '/promo/' )['status'] );
		$this->assertSame( 410, RedirectMatcher::decide( '/gone' )['status'] );
		$this->assertSame( $home . '/offers/', RedirectMatcher::decide( '/campaign?id=7' )['url'] );
		$this->assertNull( RedirectStore::match( '/old-menu' ), 'no partial/regex matching' );
		// Loop guard: a stored rule whose target equals the request is never followed.
		update_option( RedirectStore::OPTION, [ [ 'id' => 'loop', 'from' => '/self', 'to' => '/self/', 'status' => 301, 'owner' => '', 'verified' => false, 'source' => 'manual', 'created_at' => '', 'updated_at' => '' ] ], false );
		RedirectStore::flush();
		$this->assertNull( RedirectMatcher::decide( '/self/' ) );
	}

	public function test_url_normalisation_single_301_ac_seo_01_04(): void {
		$home = untrailingslashit( (string) get_option( 'home' ) );
		$this->assertSame( [ 'status' => 301, 'url' => $home . '/branches/', 'rule' => null ], RedirectMatcher::decide( '/Branches' ) );
		$this->assertSame( $home . '/en/menu/?branch=SHN', RedirectMatcher::decide( '/EN/menu?branch=SHN' )['url'], 'query kept, prefix lower-cased' );
		$this->assertNull( RedirectMatcher::decide( '/branches/' ) );
		$this->assertNull( RedirectMatcher::decide( '/reservation/status/ABCD1234/' ), 'C-8: status REF keeps its case' );
		$this->assertNull( RedirectMatcher::decide( '/en/reservation/status/ABCD1234' ) );
		foreach ( [ '/sitemap.xml', '/robots.txt', '/wp-json/momos/v1/x', '/wp-admin/', '/wp-login.php', '/wp-content/themes/momos/style.css', '/feed.rss' ] as $exempt ) {
			$this->assertNull( RedirectMatcher::decide( $exempt ), $exempt );
		}
	}

	public function test_404_log_is_aggregated_redacted_and_bounded(): void {
		NotFoundLog::clear();
		NotFoundLog::record( '/en/old/page?email=a@b.c&branch=SHN&utm_source=x', 'en', 'https://www.example.org/path?q=secret' );
		NotFoundLog::record( '/en/old/page?branch=SHN', 'en', '' );
		NotFoundLog::record( '/contact/someone@example.com/', 'ja', '' );
		NotFoundLog::record( '/reservation/status/ABCD2345/x', 'ja', '' );
		NotFoundLog::record( '/call/09012345678/', 'ja', '' );
		$rows = NotFoundLog::top();
		$this->assertCount( 4, $rows );
		$this->assertSame( '/en/old/page?branch=SHN', $rows[0]['path'] );
		$this->assertSame( 2, $rows[0]['hits'] );
		$this->assertSame( 'www.example.org', $rows[0]['referrer_host'] );
		$all = (string) wp_json_encode( NotFoundLog::rows() );
		foreach ( [ 'someone', 'example.com', 'a@b.c', 'secret', 'ABCD2345', '09012345678', 'utm_source' ] as $pii ) {
			$this->assertStringNotContainsString( $pii, $all );
		}
		$this->assertSame( [ 'path', 'lang', 'referrer_host', 'hits', 'first_seen_utc', 'last_seen_utc' ], array_keys( $rows[0] ), 'no IP / UA columns' );

		// Ring buffer keeps the most recently seen MAX rows; retention purges stale rows.
		$rows = [];
		for ( $i = 0; $i < NotFoundLog::MAX; $i++ ) {
			$rows[ md5( (string) $i ) ] = [ 'path' => '/p' . $i . '/', 'lang' => 'ja', 'referrer_host' => '', 'hits' => 1, 'first_seen_utc' => '2026-01-01 00:00:00', 'last_seen_utc' => '2026-01-01 00:00:00' ];
		}
		update_option( NotFoundLog::OPTION, $rows, false );
		NotFoundLog::record( '/newest/', 'ja' );
		$this->assertCount( NotFoundLog::MAX, NotFoundLog::rows() );
		$this->assertSame( '/newest/', NotFoundLog::top()[0]['path'] );
		$this->freeze( '2026-06-01 00:00:00' );
		$this->assertSame( NotFoundLog::MAX - 1, NotFoundLog::purge() );
		$this->assertCount( 1, NotFoundLog::rows() );
		wp_cache_delete( 'alloptions', 'options' );
		$this->assertArrayNotHasKey( NotFoundLog::OPTION, wp_load_alloptions(), 'not autoloaded' );
	}

	public function test_weekly_review_validation(): void {
		$this->freeze( '2026-12-07 10:00:00' );
		$this->assertArrayHasKey( 'week_of', NotFoundLog::add_review( '2026-13-01', 'x', 1 ) );
		$this->assertArrayHasKey( 'week_of', NotFoundLog::add_review( '2026-12-20', 'x', 1 ), 'future week refused' );
		$this->assertSame( [], NotFoundLog::add_review( '2026-11-30', str_repeat( 'a', 600 ), 1 ) );
		$this->assertSame( 500, mb_strlen( NotFoundLog::reviews()[0]['actions'] ) );
	}

	public function test_robots_txt_per_environment(): void {
		$this->assertSame( "User-agent: *\nDisallow: /\n", Routing::robots_txt( '' ), 'non-production blocks everything' );
		$this->assertTrue( has_filter( 'robots_txt', [ Routing::class, 'robots_txt' ] ) > 0 );
	}

	public function test_capabilities_for_redirects_and_seo_meta(): void {
		$ho = self::factory()->user->create( [ 'role' => 'momo_head_office' ] );
		$mm = self::factory()->user->create( [ 'role' => 'momo_menu_manager' ] );
		$bm = self::factory()->user->create( [ 'role' => 'momo_branch_manager' ] );
		$tr = self::factory()->user->create( [ 'role' => 'momo_translator' ] );
		$this->assertTrue( user_can( $ho, 'momo_manage_redirects' ) );
		$this->assertFalse( user_can( $mm, 'momo_manage_redirects' ) );
		$this->assertTrue( user_can( $mm, 'momo_edit_seo_meta' ) );
		$this->assertFalse( user_can( $bm, 'momo_edit_seo_meta' ) );
		$this->assertFalse( user_can( $tr, 'momo_edit_seo_meta' ) );
	}

	public function test_admin_handler_denies_without_capability(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'momo_content_editor' ] ) );
		$denied = [];
		add_action( 'momos/audit/log', static function ( $action ) use ( &$denied ): void {
			$denied[] = $action;
		} );
		try {
			\Momos\Seo\Admin\SeoAdmin::handle_redirect_save();
			$this->fail( 'expected wp_die' );
		} catch ( \WPDieException $e ) {
			$this->assertContains( 'access.denied', $denied );
		}
		$this->assertSame( [], RedirectStore::all() );
	}

	public function test_seo_settings_and_gtm_id_validation(): void {
		$this->assertTrue( SeoSettings::faq_schema() );
		$this->assertSame( 90, SeoSettings::retention_days() );
		SeoSettings::save( [ 'faq_schema' => '', 'log_retention_days' => 5000 ] );
		$this->assertFalse( SeoSettings::faq_schema() );
		$this->assertSame( 730, SeoSettings::retention_days() );
		\Momos\Kernel\Settings::set( 'momo_gtm_container_id', 'GTM-ABC123' );
		$this->assertSame( 'GTM-ABC123', SeoSettings::gtm_id() );
		\Momos\Kernel\Settings::set( 'momo_gtm_container_id', 'GTM-"><script>' );
		$this->assertSame( '', SeoSettings::gtm_id() );
		\Momos\Kernel\Settings::set( 'momo_gtm_container_id', '' );
		$this->assertSame( '', SeoSettings::gtm_id() );
	}

	public function test_tag_loader_never_on_c10_c11_test_branch_or_for_logged_in_users_dec_066(): void {
		$this->visit( home_url( '/' ), 'en' );
		$this->assertTrue( TagLoader::allowed() );
		$this->visit( SystemPages::id( 'submitted' ), 'en' );
		$this->assertFalse( TagLoader::allowed(), 'C10' );
		$this->visit( SystemPages::id( 'status' ), 'ja' );
		$this->assertFalse( TagLoader::allowed(), 'C11' );
		$test = $this->branch( 'TSA', [], [ 'momo_is_test_branch' => '1' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->visit( $test, 'ja' );
		$this->assertFalse( TagLoader::allowed(), 'logged-in staff / TEST branch' );
		wp_set_current_user( 0 );
		$this->visit( $test, 'ja' );
		$this->assertFalse( TagLoader::allowed(), 'TEST branch (404 for the public)' );
		update_option( 'momos_languages_enabled', [ 'ja' ] );
		$this->visit( home_url( '/' ), 'en' );
		$this->assertFalse( TagLoader::allowed(), 'hidden language' );

		// The script tag carries only a validated container ID; no noscript iframe is ever printed.
		\Momos\Kernel\Settings::set( 'momo_gtm_container_id', 'GTM-ABC123' );
		$tag = TagLoader::tag( "<script src='x.js' id='momos-datalayer-js'></script>", TagLoader::HANDLE );
		$this->assertStringContainsString( 'data-momo-datalayer data-gtm="GTM-ABC123"', $tag );
		$this->assertSame( '<script src="y.js"></script>', TagLoader::tag( '<script src="y.js"></script>', 'other' ) );
		$js = (string) file_get_contents( get_theme_file_path( TagLoader::SCRIPT ) );
		$this->assertStringContainsString( 'globalPrivacyControl', $js );
		$this->assertStringContainsString( "'gtm.blocklist'", $js );
		$this->assertStringNotContainsString( 'noscript', $js );
		\Momos\Kernel\Settings::set( 'momo_gtm_container_id', '' );
	}

	public function test_seo_strings_exist_in_six_catalogs_with_same_placeholders(): void {
		$dir = MOMOS_CORE_DIR . '/languages/strings/';
		$en  = include $dir . 'en/seo.php';
		foreach ( \Momos\Kernel\I18n\Lang::codes() as $lang ) {
			$file = $dir . $lang . '/seo.php';
			$cat  = include $file;
			$this->assertSame( array_keys( $en ), array_keys( $cat ), $lang );
			foreach ( $cat as $k => $v ) {
				preg_match_all( '/\{[a-z_]+\}/', (string) $en[ $k ], $want );
				preg_match_all( '/\{[a-z_]+\}/', (string) $v, $got );
				sort( $want[0] );
				sort( $got[0] );
				$this->assertSame( $want[0], $got[0], "{$lang}:{$k}" );
			}
			if ( ! in_array( $lang, [ 'en' ], true ) ) {
				$this->assertStringContainsString( 'DRAFT', (string) file_get_contents( $file ), $lang );
			}
		}
	}
}
