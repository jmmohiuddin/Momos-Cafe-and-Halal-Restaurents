<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/AccessTestCaseTest.php';

use Momos\Kernel\I18n\Admin\StringsPage;
use Momos\Kernel\I18n\Strings;

/**
 * SCR-A11 Strings tab: overrides with placeholder validation, per-key status, review rules, audit
 * (content-strings.md §2.1/§12/§13, i18n-seo-analytics.md §6.2).
 */
final class I18nStringsPageTest extends AccessTestCaseTest {
	public function set_up(): void {
		parent::set_up();
		Strings::flush();
	}

	public function tear_down(): void {
		$_GET  = [];
		$_POST = [];
		Strings::flush();
		parent::tear_down();
	}

	public function test_placeholder_mismatch_is_refused_per_key(): void {
		$tr = $this->user_with_role( 'momo_translator' );
		wp_set_current_user( $tr );
		$key = 'admin.translation.gaps_none';
		[ $saved, $errors ] = StringsPage::apply( 'ar', [ $key => 'لا شيء', 'nav.menu' => 'القائمة الكاملة' ], [], $tr );
		$this->assertSame( [ 'nav.menu' ], $saved );
		$this->assertCount( 1, $errors );
		$this->assertStringContainsString( '{language}', $errors[0] );
		$this->assertNull( Strings::overrides()['ar'][ $key ] ?? null, 'not saved' );
		$this->assertSame( 'القائمة الكاملة', Strings::t( 'nav.menu', [], 'ar' ) );
		$this->assertSame( 'draft', Strings::status( 'nav.menu', 'ar' ) );
		$this->assertSame( $tr, (int) Strings::record( 'nav.menu', 'ar' )['translated_by'] );
		$this->assertSame( 'human', Strings::record( 'nav.menu', 'ar' )['source'] );

		// Unknown keys and over-long values are ignored / refused.
		[ $saved ] = StringsPage::apply( 'ar', [ 'no.such.key' => 'x' ], [], $tr );
		$this->assertSame( [], $saved );
		[ , $errors ] = StringsPage::apply( 'ar', [ 'nav.menu' => str_repeat( 'x', StringsPage::MAX_LEN + 1 ) ], [], $tr );
		$this->assertNotEmpty( $errors );

		// Restoring the shipped text removes the override.
		$file = (string) Strings::file_value( 'nav.menu', 'ar' );
		StringsPage::apply( 'ar', [ 'nav.menu' => $file ], [], $tr );
		$this->assertArrayNotHasKey( 'nav.menu', Strings::overrides()['ar'] ?? [] );
	}

	public function test_review_rules_and_audit(): void {
		$tr1 = $this->user_with_role( 'momo_translator' );
		$tr2 = $this->user_with_role( 'momo_translator' );
		$ce  = $this->user_with_role( 'momo_content_editor' );
		$key = 'res.title';
		$val = (string) Strings::raw( $key, 'ar' ) . ' ';
		wp_set_current_user( $tr1 );
		[ , $errors ] = StringsPage::apply( 'ar', [ $key => trim( $val ) . '!' ], [ $key => 'approved' ], $tr1 );
		$this->assertNotEmpty( $errors, 'own review-class translation cannot be approved' );
		$this->assertSame( 'needs_review', Strings::status( $key, 'ar' ) );

		wp_set_current_user( $ce );
		[ , $errors ] = StringsPage::apply( 'ar', [], [ $key => 'approved' ], $ce );
		$this->assertNotEmpty( $errors, 'no approve cap' );
		$this->assertSame( 'needs_review', Strings::status( $key, 'ar' ) );

		wp_set_current_user( $tr2 );
		[ $saved, $errors ] = StringsPage::apply( 'ar', [], [ $key => 'approved' ], $tr2 );
		$this->assertSame( [], $errors );
		$this->assertSame( [ $key ], $saved );
		$this->assertTrue( Strings::is_approved( $key, 'ar' ) );

		// Non-review-class keys may be approved by their translator.
		wp_set_current_user( $tr1 );
		[ , $errors ] = StringsPage::apply( 'ar', [ 'nav.faq' => 'الأسئلة الشائعة' ], [ 'nav.faq' => 'approved' ], $tr1 );
		$this->assertSame( [], $errors );
		$this->assertTrue( Strings::is_approved( 'nav.faq', 'ar' ) );

		$rows = $this->audit_rows( 'translation.approved' );
		$this->assertNotEmpty( $rows );
		$last = (string) end( $rows )['changes'];
		$this->assertStringContainsString( 'nav.faq', $last );
		$this->assertStringNotContainsString( 'الأسئلة', $last, 'no values in audit' );
		$this->assertSame( 'strings', end( $rows )['object_type'] );
	}

	public function test_language_scope_and_handler_permissions(): void {
		$tr = $this->user_with_role( 'momo_translator' );
		update_user_meta( $tr, 'momo_translator_langs', [ 'hi' ] );
		$this->assertSame( [ 'hi' ], StringsPage::langs( $tr ) );
		[ $saved, $errors ] = StringsPage::apply( 'ar', [ 'nav.menu' => 'x' ], [], $tr );
		$this->assertSame( [], $saved );
		$this->assertNotEmpty( $errors );

		wp_set_current_user( $tr );
		$_GET = [ 'lang' => 'hi', 'q' => 'nav.menu' ];
		ob_start();
		StringsPage::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'name="values[nav.menu]"', $html );
		$this->assertStringContainsString( 'lang="hi"', $html );
		$this->assertStringNotContainsString( 'value="ar"', $html );

		$ur = $this->user_with_role( 'momo_head_office' );
		wp_set_current_user( $ur );
		$_GET = [ 'lang' => 'ur', 'group' => 'presentation', 'q' => 'nav.menu' ];
		ob_start();
		StringsPage::render();
		$this->assertStringContainsString( 'dir="rtl"', (string) ob_get_clean() );

		wp_set_current_user( $this->user_with_role( 'momo_branch_manager' ) );
		$_POST = [ '_wpnonce' => wp_create_nonce( StringsPage::ACTION ), 'lang' => 'en', 'values' => [ 'nav.menu' => 'X' ] ];
		try {
			StringsPage::handle();
			$this->fail( 'branch manager edited strings' );
		} catch ( \WPDieException $e ) {
			$this->assertNotEmpty( $this->audit_rows( 'access.denied' ) );
		}
		$this->assertArrayNotHasKey( 'nav.menu', Strings::overrides()['en'] ?? [] );
	}

	public function test_search_filters(): void {
		$keys = StringsPage::search( 'en', [ 'q' => 'nav.' ] );
		$this->assertContains( 'nav.menu', $keys );
		$this->assertContains( 'nav.menu', StringsPage::search( 'en', [ 'group' => 'presentation', 'lccs' => true ] ) );
		$this->assertNotContains( 'res.title', StringsPage::search( 'en', [ 'group' => 'presentation' ] ) );
		$this->assertContains( 'res.title', StringsPage::search( 'ar', [ 'status' => 'draft', 'q' => 'res.title' ] ) );
		$this->assertContains( 'presentation', StringsPage::groups() );
	}
}
