<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/AccessTestCaseTest.php';

use Momos\Admin\Content\FaqEditor;
use Momos\Admin\Content\LegalPageBox;
use Momos\Admin\Content\MediaFields;
use Momos\Kernel\Fields\FieldRegistry;
use Momos\Kernel\I18n\Translations;
use Momos\Presentation\View\Content;

/**
 * Content admin editors (SCR-A14/A18): FAQ items, legal page meta, gallery attachment fields
 * (content-strings.md §2.3/§3.4/§8.4/§8.7, screens.md §3.13/§3.15/§3.18; AC-CORP-04-01, AC-CORP-06-02).
 */
final class ContentAdminTest extends AccessTestCaseTest {
	public function tear_down(): void {
		$_POST = [];
		parent::tear_down();
	}

	public function test_faq_items_validation(): void {
		[ $rows, $errors ] = FaqEditor::sanitize(
			[
				[ 'group' => 'halal_dietary', 'question' => 'アルコールは提供していますか？', 'answer' => '<p>店舗ごとの<a href="/halal/">ハラール情報</a>をご覧ください。</p><script>alert(1)</script>', 'order' => '20', 'id' => 'alcohol' ],
				[ 'group' => 'reservations', 'question' => '予約方法は？', 'answer' => 'フォームから。', 'order' => '10', 'id' => '' ],
				[ 'group' => 'reservations', 'question' => '', 'answer' => '', 'id' => 'empty-row-dropped' ],
			]
		);
		$this->assertSame( [], $errors );
		$this->assertCount( 2, $rows );
		$this->assertSame( 'reservations', $rows[0]['group'], 'sorted by order' );
		$this->assertMatchesRegularExpression( '/^[a-z0-9-]{3,60}$/', $rows[0]['id'], 'generated id' );
		$this->assertStringNotContainsString( '<script>', $rows[1]['answer'] );
		$this->assertStringContainsString( '<a href="/halal/">', $rows[1]['answer'] );

		[ , $errors ] = FaqEditor::sanitize(
			[
				[ 'group' => 'nope', 'question' => 'Q', 'answer' => 'A', 'id' => 'ok-id' ],
				[ 'group' => 'allergens', 'question' => 'Q2', 'answer' => '', 'id' => 'other' ],
				[ 'group' => 'allergens', 'question' => 'Q3', 'answer' => 'A3', 'id' => 'Bad ID!' ],
				[ 'group' => 'allergens', 'question' => 'Q4', 'answer' => 'A4', 'id' => 'ok-id' ],
				[ 'group' => 'allergens', 'question' => str_repeat( 'あ', 201 ), 'answer' => 'A5', 'id' => 'long-q' ],
			]
		);
		$this->assertCount( 5, $errors, implode( "\n", $errors ) );
	}

	public function test_faq_save_and_theme_reader(): void {
		$page = self::factory()->post->create( [ 'post_type' => 'page', 'post_title' => 'よくある質問', 'post_status' => 'publish' ] );
		$this->assertSame( [], FaqEditor::save_items( $page, [ [ 'group' => 'allergens', 'question' => 'アレルゲン情報は？', 'answer' => '<p>各料理のページをご覧ください。</p>', 'order' => 1, 'id' => 'allergen-info' ] ] ) );
		$raw = (string) get_post_meta( $page, 'momo_faq_items', true );
		$this->assertJson( $raw );
		$this->assertSame( 'allergen-info', json_decode( $raw, true )[0]['id'] );
		$this->assertSame( 'アレルゲン情報は？', Content::faq( $page, 'ja' )['allergens'][0]['question'] );
		$this->assertTrue( FaqEditor::applies_to( get_post( $page ) ), 'pages with items show the editor' );

		// Invalid list → nothing stored.
		$this->assertNotEmpty( FaqEditor::save_items( $page, [ [ 'group' => 'allergens', 'question' => 'Q', 'answer' => '', 'id' => 'x-1' ] ] ) );
		$this->assertSame( $raw, (string) get_post_meta( $page, 'momo_faq_items', true ) );

		// Post-screen save needs nonce + edit right.
		wp_set_current_user( $this->user_with_role( 'momo_content_editor' ) );
		$_POST = [ 'momos_faq_present' => '1', '_momos_faq_nonce' => wp_create_nonce( 'momos_faq_' . $page ), 'momos_faq' => [ [ 'group' => 'reservations', 'question' => '質問', 'answer' => '回答', 'order' => 5, 'id' => 'q-new' ] ] ];
		FaqEditor::save_from_post_screen( $page, get_post( $page ) );
		$this->assertSame( 'q-new', FaqEditor::items( $page )[0]['id'] );
		wp_set_current_user( $this->user_with_role( 'momo_reservation_staff' ) );
		$_POST['_momos_faq_nonce'] = wp_create_nonce( 'momos_faq_' . $page );
		$_POST['momos_faq']        = [];
		FaqEditor::save_from_post_screen( $page, get_post( $page ) );
		$this->assertCount( 1, FaqEditor::items( $page ), 'no edit right → unchanged' );
		$this->assertNotEmpty( $this->audit_rows( 'access.denied' ) );
	}

	public function test_legal_page_box_fields_and_publish_rules(): void {
		$group = FieldRegistry::get_group( LegalPageBox::GROUP );
		$this->assertNotNull( $group );
		$this->assertSame( [ 'momo_page_type', 'momo_legal_version', 'momo_legal_last_updated' ], array_column( $group['fields'], 'name' ) );
		$this->assertArrayNotHasKey( 'momo_legal_version', Translations::fields( 'post:page' ), 'copy field — never translated' );
		$this->freeze( '2026-11-18 10:00:00' );
		$this->assertNull( LegalPageBox::validate_version( '1.0' ) );
		$this->assertNull( LegalPageBox::validate_version( 'PP-1.0' ) );
		$this->assertNotNull( LegalPageBox::validate_version( '1.0 final!' ) );
		$this->assertNotNull( LegalPageBox::validate_version( str_repeat( '1', 21 ) ) );
		$this->assertNull( LegalPageBox::validate_date( '2026-11-18' ) );
		$this->assertNotNull( LegalPageBox::validate_date( '2026-11-19' ), 'not in the future (JST)' );
		$this->assertNotNull( LegalPageBox::validate_date( '2026-02-30' ) );

		$page = self::factory()->post->create( [ 'post_type' => 'page', 'post_title' => '利用規約', 'post_status' => 'draft' ] );
		update_post_meta( $page, 'momo_page_type', 'legal' );
		$ce = $this->user_with_role( 'momo_content_editor' );
		wp_set_current_user( $ce );
		$fields = array_column( $group['fields'], null, 'name' );
		$this->assertFalse( FieldRegistry::can_edit( $fields['momo_legal_version'], $page ), 'CE cannot set version/date' );
		$this->assertTrue( current_user_can( 'edit_post', $page ), 'CE drafts legal pages' );
		$this->assertFalse( current_user_can( 'publish_post', $page ), 'CE cannot publish legal pages (M5)' );
		wp_set_current_user( $this->user_with_role( 'momo_head_office' ) );
		$this->assertTrue( FieldRegistry::can_edit( $fields['momo_legal_version'], $page ) );
		$this->assertTrue( current_user_can( 'publish_post', $page ) );

		// Version + date required to publish a legal page.
		$data = FieldRegistry::validate_post_before_save( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => '利用規約' ], [ 'ID' => $page ] );
		$this->assertSame( 'draft', $data['post_status'] );
		update_post_meta( $page, 'momo_legal_version', '1.0' );
		update_post_meta( $page, 'momo_legal_last_updated', '2026-11-01' );
		$data = FieldRegistry::validate_post_before_save( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => '利用規約' ], [ 'ID' => $page ] );
		$this->assertSame( 'publish', $data['post_status'] );
		$meta = Content::legal_meta( $page, 'en' );
		$this->assertSame( '1.0', $meta['version'], 'translations show the JA master version' );
		$this->assertNotSame( '', $meta['updated'] );
		$this->assertSame( 'settings.updated', LegalPageBox::audit_action( null, 'post', 'page', LegalPageBox::GROUP ) );
	}

	private function image(): int {
		$aid = self::factory()->attachment->create( [ 'post_title' => 'interior', 'post_mime_type' => 'image/jpeg', 'file' => 'interior.jpg' ] );
		update_post_meta( $aid, '_wp_attached_file', 'interior.jpg' );
		return (int) $aid;
	}

	public function test_gallery_fields_validation_and_scope(): void {
		$a   = $this->branch( 'QGA' );
		$b   = $this->branch( 'QGB' );
		$cat = (int) wp_insert_term( '前菜QA', 'momo_menu_category', [ 'slug' => 'starters-qa' ] )['term_id'];
		$img = $this->image();
		$ho  = $this->user_with_role( 'momo_head_office' );
		wp_set_current_user( $ho );
		$this->assertSame( [], MediaFields::apply( $img, [ MediaFields::INCLUDE . '_present' => '1', MediaFields::INCLUDE => '1', MediaFields::BRANCH => (string) $a, MediaFields::CATEGORY => (string) $cat ] ) );
		$this->assertSame( '1', get_post_meta( $img, MediaFields::INCLUDE, true ) );
		$this->assertSame( $a, (int) get_post_meta( $img, MediaFields::BRANCH, true ) );
		$this->assertSame( $cat, (int) get_post_meta( $img, MediaFields::CATEGORY, true ) );

		$errors = MediaFields::apply( $img, [ MediaFields::BRANCH => '999999', MediaFields::CATEGORY => '999999' ] );
		$this->assertArrayHasKey( MediaFields::BRANCH, $errors );
		$this->assertArrayHasKey( MediaFields::CATEGORY, $errors );
		$this->assertSame( $a, (int) get_post_meta( $img, MediaFields::BRANCH, true ), 'unchanged on error' );

		// Unchecked box clears the flag.
		MediaFields::apply( $img, [ MediaFields::INCLUDE . '_present' => '1' ] );
		$this->assertSame( '', get_post_meta( $img, MediaFields::INCLUDE, true ) );

		// DEC-047: illustrative images never on the gallery.
		update_post_meta( $img, 'momo_image_illustrative', '1' );
		$errors = MediaFields::apply( $img, [ MediaFields::INCLUDE . '_present' => '1', MediaFields::INCLUDE => '1' ] );
		$this->assertArrayHasKey( MediaFields::INCLUDE, $errors );
		$this->assertSame( '', get_post_meta( $img, MediaFields::INCLUDE, true ) );
		delete_post_meta( $img, 'momo_image_illustrative' );

		// Branch managers: own branches only.
		$bm = $this->user_with_role( 'momo_branch_manager', [ $b ] );
		wp_set_current_user( $bm );
		$this->assertArrayHasKey( MediaFields::BRANCH, MediaFields::apply( $img, [ MediaFields::BRANCH => (string) $b ] ), 'cannot retag an image of another branch' );
		$this->assertNotEmpty( $this->audit_rows( 'access.denied' ) );
		$own = $this->image();
		$this->assertSame( [], MediaFields::apply( $own, [ MediaFields::BRANCH => (string) $b ] ) );
		$this->assertArrayHasKey( MediaFields::BRANCH, MediaFields::apply( $own, [ MediaFields::BRANCH => (string) $a ] ) );
		$this->assertSame( [ $b ], array_keys( MediaFields::branch_choices() ) );
	}

	public function test_attachment_ui_and_gallery_reader(): void {
		$img = $this->image();
		wp_set_current_user( $this->user_with_role( 'momo_head_office' ) );
		$fields = MediaFields::fields_to_edit( [], get_post( $img ) );
		foreach ( [ MediaFields::INCLUDE, MediaFields::BRANCH, MediaFields::CATEGORY, 'momos_translations' ] as $k ) {
			$this->assertArrayHasKey( $k, $fields );
		}
		$this->assertStringContainsString( 'page=momos-translate', $fields['momos_translations']['html'] );
		wp_set_current_user( $this->user_with_role( 'momo_reservation_staff' ) );
		$this->assertSame( [], MediaFields::fields_to_edit( [], get_post( $img ) ), 'no fields without edit rights' );
		$saved = MediaFields::fields_to_save( [ 'ID' => $img ], [ MediaFields::INCLUDE . '_present' => '1', MediaFields::INCLUDE => '1' ] );
		$this->assertArrayNotHasKey( 'errors', $saved );
		$this->assertSame( '', get_post_meta( $img, MediaFields::INCLUDE, true ), 'no edit right → ignored' );

		// Gallery shows the image in a language only with an approved alt/caption translation.
		update_post_meta( $img, MediaFields::INCLUDE, '1' );
		wp_update_post( [ 'ID' => $img, 'post_excerpt' => '店内' ] );
		update_post_meta( $img, '_wp_attachment_image_alt', '店内の写真' );
		$ids = static fn( string $lang ) => array_column( Content::gallery( $lang ), 'id' );
		$this->assertContains( $img, $ids( 'ja' ) );
		$this->assertNotContains( $img, $ids( 'en' ) );
		Translations::save( 'post', $img, 'en', [ 'alt' => 'Dining room', 'caption' => 'Inside' ], 'approved' );
		$en = array_values( array_filter( Content::gallery( 'en' ), static fn( $g ) => $g['id'] === $img ) );
		$this->assertSame( 'Dining room', $en[0]['alt'] );
		$this->assertSame( 'Inside', $en[0]['caption'] );
	}
}
