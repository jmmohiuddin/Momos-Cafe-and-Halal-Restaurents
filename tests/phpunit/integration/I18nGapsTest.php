<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/AccessTestCaseTest.php';

use Momos\Admin\Settings\LanguageGate;
use Momos\Kernel\I18n\Admin\GapsPage;
use Momos\Kernel\I18n\Admin\TranslationGaps;
use Momos\Kernel\I18n\Lang;
use Momos\Kernel\I18n\Strings;
use Momos\Kernel\I18n\Translations;

/**
 * SCR-A11 translation gaps + language gate (FR-I18N-008/009, i18n-seo-analytics.md §6.3–§6.5,
 * TC-I18N-008, TC-I18N-009, TC-I18N-019, AC-I18N-03-02/03).
 */
final class I18nGapsTest extends AccessTestCaseTest {
	public function set_up(): void {
		parent::set_up();
		Strings::flush();
		TranslationGaps::flush();
	}

	public function tear_down(): void {
		$_GET  = [];
		$_POST = [];
		Strings::flush();
		TranslationGaps::flush();
		parent::tear_down();
	}

	/** @return array<string,mixed>|null */
	private function row_for( int|string $id, string $lang, array $filters = [], string $type = 'post' ): ?array {
		foreach ( TranslationGaps::rows( $filters + [ 'lang' => $lang ] ) as $r ) {
			if ( (string) $r['id'] === (string) $id && $r['type'] === $type ) {
				return $r;
			}
		}
		return null;
	}

	public function test_states_counts_filters_and_deep_links(): void {
		$this->freeze( '2026-11-19 10:00:00' );
		$home = self::factory()->post->create( [ 'post_type' => 'page', 'post_title' => 'ホームQA', 'post_status' => 'publish', 'post_date' => '2026-11-15 10:00:00', 'post_date_gmt' => '2026-11-15 01:00:00' ] );
		update_post_meta( $home, 'momo_lccs', '1' );
		$about = self::factory()->post->create( [ 'post_type' => 'page', 'post_title' => '私たちについてQA', 'post_status' => 'publish' ] );
		$draft = self::factory()->post->create( [ 'post_type' => 'page', 'post_title' => '下書きQA', 'post_status' => 'draft' ] );
		$cat   = (int) wp_insert_term( 'カレーQA', 'momo_menu_category', [ 'slug' => 'curry-qa' ] )['term_id'];
		TranslationGaps::flush();

		$r = $this->row_for( $home, 'ar' );
		$this->assertNotNull( $r );
		$this->assertSame( 'missing', $r['state'] );
		$this->assertTrue( $r['lccs'] );
		$this->assertSame( 4, $r['age'], 'JST days since JA publish' );
		$this->assertStringContainsString( 'page=momos-translate', TranslationGaps::edit_url( $r, 'ar' ) );
		$this->assertStringContainsString( 'id=' . $home, TranslationGaps::edit_url( $r, 'ar' ) );
		$this->assertNull( $this->row_for( $draft, 'ar' ), 'drafts are not translation items' );
		$this->assertFalse( $this->row_for( $about, 'ar' )['lccs'] );
		$this->assertTrue( $this->row_for( $cat, 'ar', [], 'term' )['lccs'] );

		Translations::save( 'post', $about, 'ar', [ 'title' => 'من نحن' ], 'draft' );
		Translations::save( 'post', $home, 'ar', [ 'title' => 'الرئيسية' ], 'needs_review' );
		Translations::save( 'post', $home, 'ur', [ 'title' => 'ہوم' ], 'approved' );
		TranslationGaps::flush();
		$this->assertSame( 'draft', $this->row_for( $about, 'ar' )['state'] );
		$this->assertSame( 'needs_review', $this->row_for( $home, 'ar' )['state'] );
		$this->assertNull( $this->row_for( $home, 'ur' ), 'approved & current = no gap' );

		// Source change → outdated (needs update), age since last change.
		wp_update_post( [ 'ID' => $home, 'post_title' => 'ホームQA2' ] );
		TranslationGaps::flush();
		$this->assertSame( 'outdated', $this->row_for( $home, 'ur' )['state'] );
		$this->assertSame( 0, $this->row_for( $home, 'ur' )['age'] );

		// Filters.
		$this->assertNotNull( $this->row_for( $home, 'ar', [ 'type' => 'page', 'lccs' => true ] ) );
		$this->assertNull( $this->row_for( $about, 'ar', [ 'lccs' => true ] ) );
		$this->assertNull( $this->row_for( $home, 'ar', [ 'state' => 'draft' ] ) );
		$this->assertNull( $this->row_for( $cat, 'ar', [ 'type' => 'page' ], 'term' ) );

		// Counts per language × type × state.
		$counts = TranslationGaps::counts( [ 'ar', 'ur' ] );
		$this->assertGreaterThanOrEqual( 1, $counts['ar']['page']['draft'] );
		$this->assertGreaterThanOrEqual( 1, $counts['ar']['page']['needs_review'] );
		$this->assertGreaterThanOrEqual( 1, $counts['ur']['page']['outdated'] );
		$this->assertGreaterThan( 0, $counts['ar']['strings']['draft'], 'string keys counted per key' );
		$this->assertLessThan( 100, TranslationGaps::lccs_percent( 'ar' ) );
	}

	public function test_bulk_mark_needs_review_only_touches_outdated(): void {
		$page = self::factory()->post->create( [ 'post_type' => 'page', 'post_title' => '店舗QA', 'post_status' => 'publish' ] );
		$fine = self::factory()->post->create( [ 'post_type' => 'page', 'post_title' => '変更なしQA', 'post_status' => 'publish' ] );
		Translations::save( 'post', $page, 'en', [ 'title' => 'Branches' ], 'approved' );
		Translations::save( 'post', $fine, 'en', [ 'title' => 'Unchanged' ], 'approved' );
		Strings::set_status( 'en', [ 'nav.menu' ], 'approved', 1 );
		Strings::save_override( Lang::SOURCE, 'nav.menu', 'メニュー一覧' ); // reference for en changed
		wp_update_post( [ 'ID' => $page, 'post_title' => '店舗一覧QA' ] );
		TranslationGaps::flush();
		$this->assertTrue( Strings::is_outdated( 'nav.menu', 'en' ) );

		$hr = $this->user_with_role( 'momo_head_office' );
		$n  = TranslationGaps::mark_outdated_needs_review( [ 'en' ], $hr, [ [ 'type' => 'post', 'id' => $page ], [ 'type' => 'post', 'id' => $fine ] ] );
		$this->assertSame( 1, $n );
		$this->assertSame( 'needs_review', Translations::status( 'post', $page, 'en' ) );
		$this->assertSame( 'approved', Translations::status( 'post', $fine, 'en' ) );
		$this->assertSame( 'Branches', Translations::data( 'post', $page, 'en' )['title'], 'text kept' );
		$this->assertSame( 'approved', Strings::status( 'nav.menu', 'en' ), 'not selected' );

		TranslationGaps::mark_outdated_needs_review( [ 'en' ], $hr );
		$this->assertSame( 'needs_review', Strings::status( 'nav.menu', 'en' ) );

		// Handler: translators without the approve cap are refused.
		wp_set_current_user( $this->user_with_role( 'momo_content_editor' ) );
		$_POST = [ '_wpnonce' => wp_create_nonce( 'momos_gaps_mark_review' ), 'mark_all' => '1' ];
		try {
			GapsPage::handle_mark_review();
			$this->fail( 'content editor changed review status' );
		} catch ( \WPDieException $e ) {
			$this->assertNotEmpty( $this->audit_rows( 'access.denied' ) );
		}
	}

	public function test_gaps_screen_permissions_and_translator_queue(): void {
		$page = self::factory()->post->create( [ 'post_type' => 'page', 'post_title' => 'キューQA', 'post_status' => 'publish' ] );
		$tr   = $this->user_with_role( 'momo_translator' );
		update_user_meta( $tr, 'momo_translator_langs', [ 'ur' ] );
		wp_set_current_user( $tr );
		$this->assertSame( [ 'ur' ], GapsPage::filters( [ 'lang' => 'ar' ] )['langs'] );
		$this->assertSame( '', GapsPage::filters( [ 'lang' => 'ar' ] )['lang'], 'other languages not selectable' );
		ob_start();
		GapsPage::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'キューQA', $html );
		$this->assertStringContainsString( 'lang=ur', $html );
		$this->assertStringNotContainsString( 'lang=ar&', str_replace( '&amp;', '&', $html ) );
		$this->assertStringContainsString( 'LCCS', $html );

		wp_set_current_user( $this->user_with_role( 'momo_reservation_staff' ) );
		try {
			GapsPage::render();
			$this->fail( 'reservation staff opened the gaps view' );
		} catch ( \WPDieException $e ) {
			$this->assertNotEmpty( $this->audit_rows( 'access.denied' ) );
		}
		$this->assertSame( "'=SUM(A1)", GapsPage::csv_cell( '=SUM(A1)' ) );
		$this->assertSame( 'Home', GapsPage::csv_cell( 'Home' ) );
	}

	/** TC-I18N-009: enabling a language is refused while LCCS items or strings are unapproved. */
	public function test_language_gate_blocks_until_lccs_complete(): void {
		$this->freeze( '2026-11-18 10:00:00' );
		update_option( 'momos_languages_enabled', [ 'ja', 'en' ] );
		$ho   = $this->user_with_role( 'momo_head_office' );
		$tr   = $this->user_with_role( 'momo_translator' );
		$home = self::factory()->post->create( [ 'post_type' => 'page', 'post_title' => 'ホームGATE', 'post_status' => 'publish' ] );
		update_post_meta( $home, 'momo_lccs', '1' );
		$non  = self::factory()->post->create( [ 'post_type' => 'page', 'post_title' => 'ニュースGATE', 'post_status' => 'publish' ] );
		TranslationGaps::flush();
		$sign = [ 'by' => 'Native Reviewer', 'at' => '2026-11-17', 'ref' => 'R-AR-1' ];

		$this->assertContains( 'Page “ホームGATE”: Missing', LanguageGate::gaps( 'ar' ), 'gate filter wired to the gaps service' );
		$this->assertNotContains( 'Page “ニュースGATE”: Missing', LanguageGate::gaps( 'ar' ), 'non-LCCS items do not block' );
		$err = LanguageGate::enable( 'ar', $sign, $ho );
		$this->assertNotEmpty( $err );
		$this->assertStringContainsString( (string) count( LanguageGate::gaps( 'ar' ) ), $err[0] );
		$this->assertNotContains( 'ar', Lang::enabled() );

		// Approve every LCCS gap and all customer-facing strings for ar (non-LCCS content stays missing).
		foreach ( TranslationGaps::items() as $item ) {
			$state = TranslationGaps::state( $item, 'ar' )['state'];
			if ( '' === $state || ( ! $item['lccs'] && 'strings' !== $item['type'] ) ) {
				continue;
			}
			if ( 'strings' === $item['type'] ) {
				foreach ( (array) $item['keys'] as $k ) {
					if ( null === Strings::raw( $k, 'ar' ) ) {
						Strings::save_override( 'ar', $k, (string) Strings::raw( $k, 'en' ) );
					}
				}
				Strings::set_status( 'ar', (array) $item['keys'], 'approved', $tr );
			} elseif ( in_array( $item['type'], [ 'post', 'term' ], true ) ) {
				$vals = [];
				foreach ( array_keys( Translations::fields( Translations::kind_of( $item['type'], $item['id'] ) ) ) as $f ) {
					$vals[ $f ] = 'AR ' . $f;
				}
				Translations::save( $item['type'], $item['id'], 'ar', $vals, 'approved', $tr );
			} elseif ( 'option' === $item['type'] ) {
				Translations::save_option( (string) $item['id'], 'ar', [], 'approved', $tr );
			} elseif ( 'statement' === $item['type'] ) {
				$raw = (array) get_option( 'momos_dietary_statements', [] );
				foreach ( $raw as $i => $row ) {
					if ( ( $row['key'] ?? '' ) === $item['id'] ) {
						$raw[ $i ]['i18n']['ar'] = [ 'text' => 'AR', 'status' => 'approved' ];
					}
				}
				update_option( 'momos_dietary_statements', $raw );
			}
		}
		TranslationGaps::flush();
		$this->assertSame( [], LanguageGate::gaps( 'ar' ) );
		$this->assertSame( 100, TranslationGaps::lccs_percent( 'ar' ) );
		$this->assertNotEmpty( LanguageGate::enable( 'ar', [ 'by' => '', 'at' => '2026-11-17', 'ref' => '' ], $ho ), 'sign-off still required' );
		$this->assertSame( [], LanguageGate::enable( 'ar', $sign, $ho ) );
		$this->assertContains( 'ar', Lang::enabled() );
		$this->assertNotEmpty( $this->audit_rows( 'language.enabled' ) );

		// A later JA change makes the page outdated → re-enabling after hide is blocked again.
		$this->assertSame( [], LanguageGate::disable( 'ar', $ho ) );
		wp_update_post( [ 'ID' => $home, 'post_title' => 'ホームGATE改' ] );
		TranslationGaps::flush();
		$this->assertContains( 'Page “ホームGATE改”: Needs update', LanguageGate::gaps( 'ar' ) );
		$this->assertNotEmpty( LanguageGate::enable( 'ar', $sign, $ho ) );
		$this->assertNotContains( 'ar', Lang::enabled() );
		$this->assertSame( 'missing', Translations::status( 'post', $non, 'ar' ), 'non-LCCS content never blocked the gate' );
	}
}
