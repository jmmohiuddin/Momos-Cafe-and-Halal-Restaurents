<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/AccessTestCaseTest.php';

use Momos\Kernel\I18n\Admin\TranslationEditor;
use Momos\Kernel\I18n\Translations;
use Momos\Kernel\Settings;
use Momos\Presentation\View\Content;

/**
 * Translations kernel extensions: repeater values (FAQ items), attachment alt/caption, option texts,
 * translator ≠ reviewer for review-class content (IMPL-002, i18n-seo-analytics.md §3.1/§6.1/§6.2).
 */
final class I18nTranslationsTest extends AccessTestCaseTest {
	public function set_up(): void {
		parent::set_up();
		Settings::flush();
	}

	public function tear_down(): void {
		$_POST = [];
		Settings::flush();
		parent::tear_down();
	}

	private function faq_page(): int {
		$id    = self::factory()->post->create( [ 'post_type' => 'page', 'post_title' => 'よくある質問', 'post_status' => 'publish', 'post_content' => '', 'post_excerpt' => '' ] );
		$items = [
			[ 'id' => 'how-to-request', 'group' => 'reservations', 'question' => '予約方法は？', 'answer' => '<p>フォームから送信します。</p>', 'order' => 10 ],
			[ 'id' => 'is-it-halal', 'group' => 'halal_dietary', 'question' => 'ハラールですか？', 'answer' => '<p>ハラール情報のページをご覧ください。</p>', 'order' => 20 ],
		];
		update_post_meta( $id, 'momo_faq_items', wp_slash( (string) wp_json_encode( $items, JSON_UNESCAPED_UNICODE ) ) );
		return $id;
	}

	public function test_repeater_translation_round_trip_and_theme_reader(): void {
		$id = $this->faq_page();
		$this->assertSame( 'repeater', Translations::fields( 'post:page' )['faq_items']['type'] );
		Translations::save(
			'post',
			$id,
			'en',
			[
				'title'     => 'FAQ',
				'faq_items' => [
					[ 'id' => 'how-to-request', 'question' => 'How do I request a table?', 'answer' => '<p>Use the form.</p><script>x</script>', 'group' => 'hacked' ],
					[ 'id' => 'not-in-source', 'question' => 'Injected', 'answer' => 'Nope' ],
				],
			],
			'draft'
		);
		$rows = Translations::data( 'post', $id, 'en' )['faq_items'];
		$this->assertCount( 1, $rows, 'rows unknown to the source are dropped' );
		$this->assertSame( [ 'id', 'question', 'answer' ], array_keys( $rows[0] ), 'only registered sub fields are stored' );
		$this->assertStringNotContainsString( '<script>', $rows[0]['answer'] );
		$this->assertSame( [ 'faq_items' ], Translations::missing_fields( 'post', $id, 'en' ), 'second item untranslated' );
		$this->assertSame( '', Translations::post( $id, 'faq_items', 'en' ), 'not public before approval' );
		$this->assertSame( [], Content::faq( $id, 'en' ) );

		Translations::save( 'post', $id, 'en', [ 'faq_items' => [ $rows[0], [ 'id' => 'is-it-halal', 'question' => 'Is it halal?', 'answer' => '<p>See the halal page.</p>' ] ] ], 'approved', 1 );
		$this->assertJson( Translations::post( $id, 'faq_items', 'en' ), 'string API keeps working for list values' );
		$this->assertCount( 2, Translations::post_rows( $id, 'faq_items', 'en' ) );
		$this->assertCount( 2, Translations::post_rows( $id, 'faq_items', 'ja' ), 'source rows for ja' );
		$faq = Content::faq( $id, 'en' );
		$this->assertSame( [ 'reservations', 'halal_dietary' ], array_keys( $faq ), 'group comes from the source, not the translation' );
		$this->assertSame( 'How do I request a table?', $faq['reservations'][0]['question'] );
		$this->assertSame( [], Translations::missing_fields( 'post', $id, 'en' ) );

		// Re-ordering / regrouping the source does not outdate; changing a question does.
		$src                 = json_decode( (string) get_post_meta( $id, 'momo_faq_items', true ), true );
		$src[0]['order']     = 99;
		$src[0]['group']     = 'allergens';
		update_post_meta( $id, 'momo_faq_items', wp_slash( (string) wp_json_encode( $src, JSON_UNESCAPED_UNICODE ) ) );
		$this->assertFalse( Translations::is_outdated( 'post', $id, 'en' ) );
		$src[1]['question'] = 'ハラール認証はありますか？';
		update_post_meta( $id, 'momo_faq_items', wp_slash( (string) wp_json_encode( $src, JSON_UNESCAPED_UNICODE ) ) );
		$this->assertTrue( Translations::is_outdated( 'post', $id, 'en' ) );
	}

	public function test_pages_without_faq_keep_their_source_hash(): void {
		$id = self::factory()->post->create( [ 'post_type' => 'page', 'post_title' => '会社概要', 'post_status' => 'publish' ] );
		Translations::save( 'post', $id, 'en', [ 'title' => 'About' ], 'approved' );
		$stored = (string) get_post_meta( $id, '_momos_i18n_srchash_en', true );
		$fields = Translations::fields( 'post:page' );
		unset( $fields['faq_items'] );
		$buf = '';
		foreach ( array_keys( $fields ) as $k ) {
			$v = Translations::source_value( 'post', $id, $k );
			if ( '' !== $v ) { // Empty source fields are not part of the hash (adding an optional field never outdates approvals).
				$buf .= $k . '=' . $v . "\n";
			}
		}
		$this->assertSame( hash( 'sha256', $buf ), $stored, 'the empty faq_items field does not change existing hashes' );
		// Adding a new, still-empty optional field (e.g. an SEO description) keeps the stored hash.
		$registry = new \ReflectionProperty( Translations::class, 'fields' );
		$registry->setAccessible( true );
		$saved = $registry->getValue();
		Translations::register( 'post:page', [ 'momos_test_new_field' => [ 'label' => 'New', 'type' => 'text' ] ] );
		try {
			$this->assertFalse( Translations::is_outdated( 'post', $id, 'en' ) );
		} finally {
			$registry->setValue( null, $saved );
		}
	}

	public function test_attachment_alt_and_caption_are_translatable(): void {
		$fields = Translations::fields( 'post:attachment' );
		$this->assertSame( 'meta:_wp_attachment_image_alt', $fields['alt']['source'] );
		$this->assertSame( 'post_excerpt', $fields['caption']['source'] );
		$aid = self::factory()->attachment->create( [ 'post_title' => 'photo', 'post_excerpt' => '店内の様子', 'post_mime_type' => 'image/jpeg', 'file' => 'photo.jpg' ] );
		update_post_meta( $aid, '_wp_attachment_image_alt', '店内' );
		$this->assertSame( [ 'alt', 'caption' ], Translations::missing_fields( 'post', $aid, 'ar' ) );
		Translations::save( 'post', $aid, 'ar', [ 'alt' => 'داخل المطعم', 'caption' => 'المطعم من الداخل' ], 'approved' );
		$this->assertSame( 'داخل المطعم', \Momos\Presentation\View\MenuView::alt( $aid, 'ar' ) );
		$this->assertSame( '店内', \Momos\Presentation\View\MenuView::alt( $aid, 'ja' ) );
		update_post_meta( $aid, '_wp_attachment_image_alt', '店内の写真' );
		$this->assertTrue( Translations::is_outdated( 'post', $aid, 'ar' ) );
	}

	public function test_option_texts_translate_with_status(): void {
		Settings::set( 'momo_trading_name', 'モモズ' );
		$this->assertSame( 'missing', Translations::status( 'option', 'momo-brand', 'en' ) );
		Translations::save( 'option', 'momo-brand', 'en', [ 'momo_trading_name' => "Momo's", 'unknown' => 'x' ], 'needs_review', 3 );
		$this->assertSame( '', Translations::option( 'momo-brand', 'momo_trading_name', 'en' ), 'hidden until approved' );
		$this->assertArrayNotHasKey( 'unknown', Translations::option_data( 'momo-brand', 'en' ) );
		Translations::save_option( 'momo-brand', 'en', [], 'approved', 4 );
		$this->assertSame( "Momo's", Translations::option( 'momo-brand', 'momo_trading_name', 'en' ) );
		$this->assertSame( 3, Translations::translated_by( 'option', 'momo-brand', 'en' ) );
		$this->assertFalse( Translations::is_outdated( 'option', 'momo-brand', 'en' ) );
		Settings::set( 'momo_trading_name', 'モモズ・カフェ' );
		$this->assertTrue( Translations::is_outdated( 'option', 'momo-brand', 'en' ) );
		$rows = $this->audit_rows( 'translation.approved' );
		$this->assertNotEmpty( $rows );
		$this->assertStringContainsString( 'momo-brand', (string) end( $rows )['changes'] );
	}

	/** QA-069 / DEF-016: persist() verifies the nonce itself instead of trusting its callers. */
	public function test_persist_refuses_a_request_without_a_valid_nonce(): void {
		$page = self::factory()->post->create( [ 'post_type' => 'page', 'post_title' => 'メニュー' ] );
		$tr   = $this->user_with_role( 'momo_translator' );
		wp_set_current_user( $tr );
		$_POST = [ 'momos_tr' => [ 'en' => [ 'title' => 'Menu' ] ], 'momos_tr_status' => [ 'en' => 'draft' ] ];
		TranslationEditor::persist( 'post', $page );
		$this->assertSame( 'missing', Translations::status( 'post', $page, 'en' ), 'no nonce: nothing saved' );

		$_POST['_momos_tr_nonce'] = 'not-a-nonce';
		TranslationEditor::persist( 'post', $page );
		$this->assertSame( 'missing', Translations::status( 'post', $page, 'en' ), 'invalid nonce: nothing saved' );

		$other                    = self::factory()->post->create( [ 'post_type' => 'page', 'post_title' => 'アクセス' ] );
		$_POST['_momos_tr_nonce'] = wp_create_nonce( TranslationEditor::nonce_action( 'post', $other ) );
		TranslationEditor::persist( 'post', $page );
		$this->assertSame( 'missing', Translations::status( 'post', $page, 'en' ), 'nonce of another item: nothing saved' );

		$_POST['_momos_tr_nonce'] = wp_create_nonce( TranslationEditor::nonce_action( 'post', $page ) );
		TranslationEditor::persist( 'post', $page );
		$this->assertSame( 'draft', Translations::status( 'post', $page, 'en' ), 'valid nonce: saved' );
		$_POST = [];
	}

	/** The editor page and the meta box issue the nonce persist() verifies (DEF-016). */
	public function test_editor_forms_issue_the_nonce_persist_verifies(): void {
		$page = self::factory()->post->create( [ 'post_type' => 'page', 'post_title' => 'メニュー' ] );
		wp_set_current_user( $this->user_with_role( 'momo_head_office' ) );
		$_GET = [ 'type' => 'post', 'id' => (string) $page, 'lang' => 'en' ];
		ob_start();
		TranslationEditor::page();
		$html = (string) ob_get_clean();
		$_GET = [];
		$this->assertMatchesRegularExpression( '/name="_momos_tr_nonce" value="([^"]+)"/', $html );
		preg_match( '/name="_momos_tr_nonce" value="([^"]+)"/', $html, $m );
		$this->assertNotFalse( wp_verify_nonce( $m[1], TranslationEditor::nonce_action( 'post', $page ) ) );

		$_POST = [ 'momos_tr' => [ 'en' => [ 'title' => 'Menu' ] ], 'momos_tr_status' => [ 'en' => 'draft' ], '_momos_tr_nonce' => $m[1] ];
		TranslationEditor::save_from_post_screen( $page, get_post( $page ) );
		$this->assertSame( 'draft', Translations::status( 'post', $page, 'en' ) );
		$_POST = [];
	}

	public function test_translator_cannot_approve_own_legal_translation(): void {
		$page = self::factory()->post->create( [ 'post_type' => 'page', 'post_title' => 'プライバシーポリシー', 'post_status' => 'publish', 'post_content' => '<p>本文</p>' ] );
		update_post_meta( $page, 'momo_page_type', 'legal' );
		$this->assertTrue( TranslationEditor::is_review_class( 'post', $page ) );
		$tr1 = $this->user_with_role( 'momo_translator' );
		$tr2 = $this->user_with_role( 'momo_translator' );

		wp_set_current_user( $tr1 );
		$_POST  = [ 'momos_tr' => [ 'en' => [ 'title' => 'Privacy Policy', 'content' => '<p>Body</p>' ] ], 'momos_tr_status' => [ 'en' => 'approved' ] ];
		$_POST['_momos_tr_nonce'] = wp_create_nonce( TranslationEditor::nonce_action( 'post', $page ) );
		$errors = TranslationEditor::persist( 'post', $page );
		$this->assertNotEmpty( $errors, 'self-approval refused' );
		$this->assertSame( 'needs_review', Translations::status( 'post', $page, 'en' ) );
		$this->assertSame( $tr1, Translations::translated_by( 'post', $page, 'en' ) );

		wp_set_current_user( $tr2 );
		$_POST = [ 'momos_tr' => [ 'en' => [ 'title' => 'Privacy Policy', 'content' => '<p>Body</p>' ] ], 'momos_tr_status' => [ 'en' => 'approved' ] ];
		$_POST['_momos_tr_nonce'] = wp_create_nonce( TranslationEditor::nonce_action( 'post', $page ) );
		$this->assertSame( [], TranslationEditor::persist( 'post', $page ) );
		$this->assertSame( 'approved', Translations::status( 'post', $page, 'en' ) );
		$this->assertSame( $tr2, (int) get_post_meta( $page, '_momos_i18n_approved_by_en', true ) );

		// Content editors (no approve cap) are downgraded to needs_review.
		$news = self::factory()->post->create( [ 'post_title' => 'お知らせ' ] );
		wp_set_current_user( $this->user_with_role( 'momo_content_editor' ) );
		$_POST = [ 'momos_tr' => [ 'en' => [ 'title' => 'News' ] ], 'momos_tr_status' => [ 'en' => 'approved' ] ];
		$_POST['_momos_tr_nonce'] = wp_create_nonce( TranslationEditor::nonce_action( 'post', $news ) );
		TranslationEditor::persist( 'post', $news );
		$this->assertSame( 'needs_review', Translations::status( 'post', $news, 'en' ) );
	}

	public function test_translator_languages_limit_the_editor(): void {
		$tr = $this->user_with_role( 'momo_translator' );
		update_user_meta( $tr, 'momo_translator_langs', [ 'ur' ] );
		$this->assertSame( [ 'ur' ], TranslationEditor::user_langs( $tr ) );
		$page = self::factory()->post->create( [ 'post_type' => 'page', 'post_title' => 'メニュー' ] );
		wp_set_current_user( $tr );
		$_POST = [ 'momos_tr' => [ 'ar' => [ 'title' => 'قائمة' ], 'ur' => [ 'title' => 'مینو' ] ], 'momos_tr_status' => [ 'ar' => 'draft', 'ur' => 'draft' ] ];
		$_POST['_momos_tr_nonce'] = wp_create_nonce( TranslationEditor::nonce_action( 'post', $page ) );
		TranslationEditor::persist( 'post', $page );
		$this->assertSame( 'missing', Translations::status( 'post', $page, 'ar' ), 'other languages ignored' );
		$this->assertSame( 'draft', Translations::status( 'post', $page, 'ur' ) );
		$_GET = [ 'type' => 'post', 'id' => (string) $page, 'lang' => 'ar' ];
		try {
			TranslationEditor::page();
			$this->fail( 'editor opened for a language outside the translator languages' );
		} catch ( \WPDieException $e ) {
			$this->assertNotEmpty( $this->audit_rows( 'access.denied' ) );
		} finally {
			$_GET = [];
		}
		$this->assertSame( \Momos\Kernel\I18n\Lang::targets(), TranslationEditor::user_langs( $this->user_with_role( 'momo_head_office' ) ) );
	}
}
