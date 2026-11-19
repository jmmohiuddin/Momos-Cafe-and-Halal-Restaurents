<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/BranchTestCaseTest.php';

use Momos\Branch\Domain\AccessContext;
use Momos\Branch\Repository\ClosureRepository;
use Momos\Presentation\Contact\ContactForm;
use Momos\Presentation\Format\DateFormatter;
use Momos\Presentation\Migration\M0007_SystemPages;
use Momos\Presentation\SystemPages;
use Momos\Presentation\View\BranchView;
use Momos\Presentation\View\Cards;
use Momos\Presentation\View\LanguageSwitcher;
use Momos\Presentation\View\Navigation;
use Momos\Presentation\View\Samples;

/**
 * Regression tests for the Wave 3 QA fixes of the presentation module (QA-004/005/026/028/029/036/061/073).
 */
final class PresentationQaFixesTest extends BranchTestCaseTest {
	/** @var array<string,mixed> */
	private array $server = [];
	/** @var array<string,mixed> */
	private array $get = [];

	public function set_up(): void {
		parent::set_up();
		$this->server = $_SERVER;
		$this->get    = $_GET;
		SystemPages::flush();
	}

	public function tear_down(): void {
		$_SERVER = $this->server;
		$_GET    = $this->get;
		\Momos\Kernel\I18n\Locale::set( 'ja' );
		parent::tear_down();
	}

	/** QA-005 (FR-BR-007, TC-BR-010): the "Today" line follows closures and special hours, not the weekly row. */
	public function test_today_uses_closures_and_special_hours(): void {
		$this->freeze( '2026-12-01 12:00:00' ); // Tuesday JST.
		$b = $this->make_branch( 'QTD' );
		$this->set_week( $b, [ 2 => [ [ '11:30', '23:00', '22:30', 'dinner' ] ], 3 => [ [ '11:30', '23:00', '22:30', 'dinner' ] ] ] );
		ClosureRepository::replace_dates( $b, [ '2026-12-01' ], 'special_hours', [ [ 'label' => 'dinner', 'opens_at' => '18:00', 'closes_at' => '21:00', 'last_order_at' => '20:30' ] ], 'other', '', AccessContext::system() );
		$t = BranchView::today( $b, 'en' );
		$this->assertIsArray( $t );
		$this->assertFalse( $t['closed'] );
		$this->assertSame( '18:00–21:00', wp_strip_all_tags( $t['hours_html'] ) );
		$this->assertSame( '20:30', wp_strip_all_tags( $t['lo_html'] ) );

		ClosureRepository::replace_dates( $b, [ '2026-12-01' ], 'closed_all_day', [], 'private_event', '', AccessContext::system() );
		$t = BranchView::today( $b, 'en' );
		$this->assertTrue( $t['closed'] );
		$this->assertSame( '', $t['hours_html'] );
		$this->assertStringContainsString( 'Closed today', $t['closed_html'] );
	}

	/** QA-004 (AC-BR-04-01, DEF-010): two-digit h23 times and a localised next-day label on an after-midnight close. */
	public function test_after_midnight_range_has_next_day_label_in_every_language(): void {
		foreach ( [ 'ja', 'en', 'ar', 'ur', 'hi', 'bn' ] as $lang ) {
			$html = BranchView::range_html( [ 'opens_at' => '17:00', 'closes_at' => '02:00' ], $lang );
			$this->assertStringContainsString( '<bdi dir="ltr">17:00–02:00</bdi>', $html, $lang );
			$this->assertNotSame( '<bdi dir="ltr">17:00–02:00</bdi>', $html, "{$lang}: next-day label missing" );
			$this->assertSame( '<bdi dir="ltr">11:30–15:00</bdi>', BranchView::range_html( [ 'opens_at' => '11:30:00', 'closes_at' => '15:00:00' ], $lang ) );
		}
	}

	/** QA-036 (BR-BR-05, Doc 07 §5 C04): the open-now badge carries an escaped client schedule for cached pages. */
	public function test_open_status_client_schedule_is_escaped_html_templates(): void {
		$this->freeze( '2026-12-07 12:00:00' );
		$b = $this->make_branch( 'QOS' );
		$this->set_week( $b, [ 1 => [ [ '11:00', '22:00', '21:30', 'dinner' ] ] ] );
		$c = Cards::open_status_client( $b, 'en' );
		$this->assertIsArray( $c );
		$this->assertNotNull( $c['now'], 'frozen clock is passed to the browser' );
		$this->assertNotEmpty( $c['days'] );
		$this->assertStringContainsString( '<bdi dir="ltr">{time}</bdi>', $c['t']['open'] );
		$this->assertStringContainsString( '<bdi dir="ltr">{lo}</bdi>', $c['t']['open'] );
		$this->assertStringContainsString( '{day}', $c['t']['closed'] );
		foreach ( $c['dates'] as $label ) {
			$this->assertSame( esc_html( wp_specialchars_decode( $label ) ), $label );
		}
		update_post_meta( $b, 'momo_operating_status', 'temporarily_closed' );
		$this->assertNull( Cards::open_status_client( $b, 'en' ) );
	}

	/** QA-073 (DEC-062): offer validity ranges without weekdays. */
	public function test_date_range_follows_dec_062(): void {
		$this->assertSame( '2026年12月1日〜12月31日', DateFormatter::range( '2026-12-01', '2026-12-31', 'ja' ) );
		$this->assertSame( '2026年12月1日〜2027年1月18日', DateFormatter::range( '2026-12-01', '2027-01-18', 'ja' ) );
		$this->assertSame( '1 Dec – 31 Dec 2026', DateFormatter::range( '2026-12-01', '2026-12-31', 'en' ) );
		$this->assertSame( '18 Nov 2026 – 18 Jan 2027', DateFormatter::range( '2026-11-18', '2027-01-18', 'en' ) );
		foreach ( [ 'ar', 'ur', 'hi', 'bn' ] as $lang ) {
			$r = DateFormatter::range( '2026-12-01', '2026-12-31', $lang );
			$this->assertMatchesRegularExpression( '/^[^0-9]*1\D.* – .*31\D.*2026/u', $r, $lang );
			$this->assertDoesNotMatchRegularExpression( '/[٠-٩۰-۹०-९০-৯]/u', $r, "{$lang}: Western digits" );
		}
	}

	/** QA-029 (FR-I18N-002): the switcher keeps branch + menu filters (incl. arrays) and drops everything else. */
	public function test_language_switcher_keeps_filters_and_drops_tracking(): void {
		$_SERVER['REQUEST_URI'] = '/en/menu/curry/?branch=DNB&diet%5B%5D=vegetarian&diet%5B%5D=halal&max_spice=1&utm_source=qa&foo=bar';
		$_GET                   = [ 'branch' => 'DNB', 'diet' => [ 'vegetarian', 'halal' ], 'max_spice' => '1', 'utm_source' => 'qa', 'foo' => 'bar' ];
		$uri                    = new \ReflectionProperty( \Momos\Kernel\I18n\Router::class, 'original_uri' );
		$old                    = $uri->getValue();
		$uri->setValue( null, null ); // read REQUEST_URI of this simulated request
		$url = LanguageSwitcher::current_url( 'ar' );
		$this->assertStringContainsString( '/ar/menu/curry/?', $url );
		$this->assertStringContainsString( 'branch=DNB', $url );
		$this->assertStringContainsString( 'diet%5B%5D=vegetarian&diet%5B%5D=halal', $url );
		$this->assertStringContainsString( 'max_spice=1', $url );
		$this->assertStringNotContainsString( 'utm_source', $url );
		$this->assertStringNotContainsString( 'foo', $url );
		$_GET = [ 'diet' => [ '<script>' ], 'branch' => 'a b' ];
		$this->assertStringNotContainsString( '?', LanguageSwitcher::current_url( 'ar' ), 'invalid values are dropped' );
		$uri->setValue( null, $old );
	}

	/** QA-028 (TC-NAV-005): a page hidden in a language is not linked from that language's navigation. */
	public function test_hidden_page_is_not_linked_in_navigation(): void {
		( new M0007_SystemPages() )->up();
		SystemPages::flush();
		$about = SystemPages::id( 'about' );
		update_post_meta( $about, '_momos_i18n_bn', wp_slash( wp_json_encode( [ 'title' => 'আমাদের সম্পর্কে' ] ) ) );
		update_post_meta( $about, '_momos_i18n_status_bn', 'approved' );
		$this->assertNotNull( SystemPages::url( 'about', 'bn' ) );
		update_post_meta( $about, '_momos_i18n_status_bn', 'draft' );
		SystemPages::flush();
		$this->assertNull( SystemPages::url( 'about', 'bn' ) );
		foreach ( [ Navigation::primary( 'bn' ), Navigation::explore( 'bn' ), Navigation::mobile( 'bn' ) ] as $items ) {
			foreach ( $items as $i ) {
				$this->assertNotSame( '', (string) $i['url'] );
				$this->assertStringNotContainsString( '/bn/about/', (string) $i['url'] );
			}
		}
		$this->assertNotNull( SystemPages::url( 'halal', 'bn' ), 'string-driven pages stay linked' );
	}

	/** QA-061 (FR-SEC-006, SEC-022): contact posts must come from the site origin and carry a Turnstile token. */
	public function test_contact_requires_same_origin_and_captcha_token(): void {
		$site = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		unset( $_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_REFERER'] );
		$this->assertFalse( ContactForm::same_origin() );
		$_SERVER['HTTP_ORIGIN'] = 'https://attacker.example';
		$this->assertFalse( ContactForm::same_origin() );
		$_SERVER['HTTP_ORIGIN']  = 'null';
		$_SERVER['HTTP_REFERER'] = 'http://' . $site . '/contact/';
		$this->assertTrue( ContactForm::same_origin() );
		$_SERVER['HTTP_ORIGIN'] = 'http://' . $site;
		$this->assertTrue( ContactForm::same_origin() );
		$this->assertFalse( ContactForm::captcha_ok( '' ), 'missing token is refused' );
	}

	/** QA-026 (content-strings §10.1 D5, DEF-011): sample items are marked outside production only. */
	public function test_sample_marking(): void {
		$p = self::factory()->post->create();
		$this->assertFalse( Samples::is_marked( $p ) );
		update_post_meta( $p, 'momo_is_sample', '1' );
		$this->assertTrue( Samples::is_marked( $p ) );
		$this->assertFalse( Samples::is_marked( 0 ) );
	}
}
