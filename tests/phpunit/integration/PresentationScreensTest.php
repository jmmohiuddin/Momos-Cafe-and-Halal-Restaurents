<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

use Momos\Kernel\I18n\Lang;
use Momos\Presentation\Contact\ContactForm;
use Momos\Presentation\Migration\M0007_SystemPages;
use Momos\Presentation\Migration\M0007_SystemPagesR2;
use Momos\Presentation\PageContext;
use Momos\Presentation\SystemPages;
use Momos\Presentation\Templates\TemplateRouter;
use Momos\Presentation\View\BranchView;
use Momos\Presentation\View\Content;
use Momos\Tests\TestCase;

/**
 * Customer screen templates and their Presentation view models (TASK 2; screens.md §0.1, §3, §7;
 * content-strings.md §3.4/§8; FR-CNT-001 / BR-CNT-01; BR-I18N-02; i18n-seo-analytics.md §9.3).
 */
final class PresentationScreensTest extends TestCase {
	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/news/%postname%/' );
		SystemPages::flush();
		PageContext::reset();
		\Momos\Kernel\I18n\Strings::flush();
	}

	public function tear_down(): void {
		PageContext::reset();
		parent::tear_down();
	}

	/** Every literal `momos_s('key')` in any theme file exists, non-empty, in all six catalogs (DEC-027). */
	public function test_every_theme_string_key_exists_in_six_catalogs(): void {
		$theme = dirname( MOMOS_CORE_DIR, 2 ) . '/themes/momos';
		$keys  = [];
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $theme ) ) as $f ) {
			if ( $f->isFile() && str_ends_with( $f->getFilename(), '.php' ) && ! str_contains( $f->getPathname(), '/static/' ) ) {
				preg_match_all( "/momos_s\\(\\s*'([a-z0-9_.]+)'/", (string) file_get_contents( $f->getPathname() ), $m );
				$keys = array_merge( $keys, $m[1] );
			}
		}
		$keys = array_filter( array_unique( $keys ), static fn( $k ) => ! str_ends_with( $k, '.' ) );
		$this->assertGreaterThan( 100, count( $keys ) );
		foreach ( $keys as $key ) {
			foreach ( Lang::codes() as $lang ) {
				$this->assertTrue( \Momos\Kernel\I18n\Strings::has( $key, $lang ), "key {$key} missing in {$lang}" );
			}
		}
		// bn: Western digits only (DEC-062).
		$bn = (string) file_get_contents( MOMOS_CORE_DIR . '/languages/strings/bn/presentation.php' );
		$this->assertDoesNotMatchRegularExpression( '/[০-৯]/u', $bn );
	}

	/** Every system role routes to an existing theme template (screens.md §0.1). */
	public function test_role_templates_exist(): void {
		foreach ( TemplateRouter::ROLE_TEMPLATES as $rel ) {
			$this->assertNotSame( '', locate_template( [ $rel ] ), $rel );
		}
		foreach ( [ 'front-page.php', 'home.php', 'single.php', 'single-momo_branch.php', 'single-momo_dish.php', 'taxonomy-momo_menu_category.php' ] as $f ) {
			$this->assertNotSame( '', locate_template( [ $f ] ), $f );
		}
	}

	/** M0007 r2: status page under reservation, C10/C11 noindex flags, idempotent. */
	public function test_migration_r2_adds_status_page_and_noindex_flags(): void {
		( new M0007_SystemPages() )->up();
		( new M0007_SystemPagesR2() )->up();
		SystemPages::flush();
		$map = SystemPages::map();
		$this->assertArrayHasKey( 'status', $map );
		$this->assertSame( 'reservation', get_post_field( 'post_name', (int) wp_get_post_parent_id( $map['status'] ) ) );
		$this->assertSame( '1', get_post_meta( $map['submitted'], 'momo_noindex', true ) );
		$this->assertSame( '1', get_post_meta( $map['status'], 'momo_noindex', true ) );
		$this->assertSame( 'reservation_status', PageContext::type_of_role( 'status' ) );
		( new M0007_SystemPagesR2() )->up();
		SystemPages::flush();
		$this->assertSame( $map, SystemPages::map() );
	}

	/** BR-I18N-02: an editorial page without an approved translation is a 404 in that language; string-driven pages are not. */
	public function test_untranslated_editorial_page_is_not_found(): void {
		( new M0007_SystemPages() )->up();
		SystemPages::flush();
		$about = SystemPages::id( 'about' );
		$halal = SystemPages::id( 'halal' );
		$visit = function ( int $id ): void {
			\Momos\Kernel\I18n\Locale::set( 'ja' );
			$this->go_to( get_permalink( $id ) );
			\Momos\Kernel\I18n\Locale::set( 'en' );
		};
		$visit( $about );
		$this->assertTrue( is_page( $about ) );
		$this->assertTrue( TemplateRouter::is_untranslated_singular() );
		$visit( $halal );
		$this->assertFalse( TemplateRouter::is_untranslated_singular() );
		update_post_meta( $about, '_momos_i18n_status_en', 'approved' );
		update_post_meta( $about, '_momos_i18n_en', wp_slash( wp_json_encode( [ 'title' => 'About us' ] ) ) );
		$visit( $about );
		$this->assertFalse( TemplateRouter::is_untranslated_singular() );
		$this->assertSame( 'About us', Content::title( $about, 'en' ) );
		\Momos\Kernel\I18n\Locale::set( 'ja' );
	}

	/** Contact (FR-CNT-001): server validation codes; nothing is stored by validation. */
	public function test_contact_validation(): void {
		$ok = ContactForm::input( [ 'name' => ' Aiko ', 'email' => 'aiko@example.com', 'topic' => 'general', 'branch' => '', 'message' => 'Hello', 'consent' => '1' ] );
		$this->assertSame( [], ContactForm::validate( $ok ) );
		$this->assertSame( 'Aiko', $ok['name'] );
		$bad = ContactForm::validate( ContactForm::input( [ 'name' => '', 'email' => 'nope', 'topic' => 'order', 'branch' => 'ZZZ', 'message' => str_repeat( 'x', 2001 ) ] ) );
		$this->assertSame( [ 'name', 'email', 'topic', 'branch', 'message', 'consent' ], array_keys( $bad ) );
		$this->assertSame( 'message_length', $bad['message'] );
		$before = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts}" );
		ContactForm::validate( $ok );
		$this->assertSame( $before, (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts}" ) );
	}

	/** Contact mail: HO recipient, Reply-To sender, no IP / UA in the body (TC-PRV-015). */
	public function test_contact_mail_has_no_ip_or_user_agent(): void {
		$_SERVER['REMOTE_ADDR']     = '203.0.113.7';
		$_SERVER['HTTP_USER_AGENT'] = 'TestAgent/1.0';
		$stub = new class() implements \Momos\Notification\Transport\TransportInterface {
			public ?\Momos\Notification\Transport\Message $last = null;
			public function name(): string {
				return 'stub';
			}
			public function send( \Momos\Notification\Transport\Message $m ): \Momos\Notification\Transport\SendResult {
				$this->last = $m;
				return \Momos\Notification\Transport\SendResult::ok( $m->message_id );
			}
		};
		add_filter( 'momos/notification/transport', static fn() => $stub );
		$sent = ContactForm::send( ContactForm::input( [ 'name' => 'Aiko', 'email' => 'aiko@example.com', 'topic' => 'feedback', 'branch' => '', 'message' => 'Thanks!', 'consent' => '1' ] ), 'en' );
		remove_all_filters( 'momos/notification/transport' );
		$this->assertTrue( $sent );
		$this->assertNotNull( $stub->last );
		$this->assertStringContainsString( '[Contact] Feedback — All', $stub->last->subject );
		$this->assertSame( 'aiko@example.com', $stub->last->reply_to );
		$this->assertStringContainsString( 'Thanks!', $stub->last->text );
		$this->assertStringNotContainsString( '203.0.113', $stub->last->text . $stub->last->html );
		$this->assertStringNotContainsString( 'TestAgent', $stub->last->text . $stub->last->html );
	}

	/** Legal TOC only above 5 headings; ids added; legal meta written out. */
	public function test_legal_toc_and_meta(): void {
		$five = str_repeat( '<h2>A</h2><p>x</p>', 5 );
		$this->assertSame( [], Content::with_toc( $five )['toc'] );
		$six = Content::with_toc( str_repeat( '<h2>Sec</h2><p>x</p>', 5 ) . '<h2 id="last">Last</h2>' );
		$this->assertCount( 6, $six['toc'] );
		$this->assertStringContainsString( 'id="section-1"', $six['html'] );
		$this->assertSame( 'last', $six['toc'][5]['id'] );
		$p = self::factory()->post->create( [ 'post_type' => 'page' ] );
		update_post_meta( $p, 'momo_legal_version', 'PP-1.0' );
		update_post_meta( $p, 'momo_legal_last_updated', '2026-11-01' );
		$meta = Content::legal_meta( $p, 'ja' );
		$this->assertSame( 'PP-1.0', $meta['version'] );
		$this->assertSame( '2026年11月1日（日）', $meta['updated'] );
		$this->assertFalse( $meta['ja_note'] );
		$this->assertTrue( Content::legal_meta( $p, 'en' )['ja_note'] );
	}

	/** FAQ: fixed group order, untranslated items hidden, unknown groups dropped. */
	public function test_faq_items_grouped_and_language_gated(): void {
		$p = self::factory()->post->create( [ 'post_type' => 'page' ] );
		update_post_meta( $p, 'momo_faq_items', wp_slash( wp_json_encode( [
			[ 'id' => 'b', 'group' => 'allergens', 'question' => 'Q-b', 'answer' => '<p>A-b</p>', 'order' => 2 ],
			[ 'id' => 'a', 'group' => 'reservations', 'question' => 'Q-a', 'answer' => '<p>A-a</p><script>x</script>', 'order' => 1 ],
			[ 'id' => 'c', 'group' => 'bogus', 'question' => 'Q-c', 'answer' => 'A-c', 'order' => 3 ],
		] ) ) );
		$ja = Content::faq( $p, 'ja' );
		$this->assertSame( [ 'reservations', 'allergens' ], array_keys( $ja ) );
		$this->assertStringNotContainsString( '<script>', $ja['reservations'][0]['answer'] );
		update_post_meta( $p, '_momos_i18n_status_en', 'approved' );
		update_post_meta( $p, '_momos_i18n_en', wp_slash( wp_json_encode( [ 'faq_items' => wp_json_encode( [ [ 'id' => 'a', 'question' => 'EN-a', 'answer' => 'EN' ] ] ) ] ) ) );
		$en = Content::faq( $p, 'en' );
		$this->assertSame( [ 'reservations' ], array_keys( $en ) );
		$this->assertSame( 'EN-a', $en['reservations'][0]['question'] );
	}

	/** FR-BR-003: unknown facilities omitted; confirmed values in the language. */
	public function test_branch_facilities_omit_unknown(): void {
		$b = self::factory()->post->create( [ 'post_type' => 'momo_branch' ] );
		update_post_meta( $b, 'momo_prayer_space', 'unknown' );
		update_post_meta( $b, 'momo_wifi', 'yes' );
		update_post_meta( $b, 'momo_payment_methods', [ 'cash', 'credit_card' ] );
		$rows = BranchView::facilities( $b, 'en' );
		$this->assertSame( [ 'Payment', 'Wi-Fi' ], array_column( $rows, 'label' ) );
		$this->assertSame( 'Cash, Credit cards', $rows[0]['value'] );
		$this->assertSame( [], BranchView::facilities( self::factory()->post->create( [ 'post_type' => 'momo_branch' ] ), 'en' ) );
	}

	/** Maps links only (DEC-029): search URL from coordinates, '' without location. */
	public function test_maps_url(): void {
		$this->assertStringStartsWith( 'https://www.google.com/maps/search/?api=1&query=34.6%2C135.5', BranchView::maps_url( [ 'lat' => 34.6, 'lng' => 135.5, 'place_id' => '' ] ) );
		$this->assertSame( '', BranchView::maps_url( [ 'lat' => null, 'lng' => null, 'address_ja' => '' ] ) );
	}

	/** Robots: query variants and C10/C11 are noindex. */
	public function test_variant_urls_are_noindex(): void {
		$_GET['branch'] = 'ABC';
		$this->assertTrue( TemplateRouter::is_variant() );
		$this->assertTrue( (bool) ( TemplateRouter::robots( [] )['noindex'] ?? false ) );
		unset( $_GET['branch'] );
		$this->assertFalse( TemplateRouter::is_variant() );
	}
}
