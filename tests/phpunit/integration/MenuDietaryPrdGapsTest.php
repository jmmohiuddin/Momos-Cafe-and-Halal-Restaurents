<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/MenuTest.php';

use Momos\Dietary\Fields\DietaryFields;
use Momos\Dietary\Fields\ProhibitedTermsFields;
use Momos\Dietary\Guard\WordingGuard;
use Momos\Dietary\Service\AllergenPresenter;
use Momos\Dietary\Service\ClaimResolver;
use Momos\Dietary\Service\StatementRepository;
use Momos\Kernel\Fields\FieldRegistry;
use Momos\Kernel\I18n\Translations;
use Momos\Kernel\Settings;
use Momos\Menu\Admin\CategoryOrderPage;
use Momos\Menu\ContentTypes;
use Momos\Menu\Rewrite\MenuRewrite;
use Momos\Menu\Service\DescriptionLimit;
use Momos\Menu\Service\DishPublishValidator;
use Momos\Menu\Service\MenuQueryService;
use Momos\Tests\TestCase;

/**
 * PRD audit gaps of the Menu & Dietary slice: FR-MENU-004(a) description limit, FR-MENU-005(a) category
 * reorder (buttons + drag-and-drop AJAX), FR-DIET-002(f) prohibited-wording list in Settings, FR-CORP-001(e) /
 * FR-CORP-003(a) brand statement selection, and AC-MENU-02-04, AC-DIET-01-07, AC-DIET-01-08, AC-DIET-02-06.
 */
final class MenuDietaryPrdGapsTest extends TestCase {
	use MenuDietaryFixtures;

	private int $dca;
	private int $floor = 0;

	public function set_up(): void {
		parent::set_up();
		$this->fixtures_up();
		global $wpdb;
		$this->floor = (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . \Momos\Kernel\Db\Tables::audit() ); // phpcs:ignore
		Settings::flush();
		WordingGuard::flush();
		$this->dca = $this->user( 'momo_head_office', [], true );
	}

	public function tear_down(): void {
		$_REQUEST = [];
		$_GET     = [];
		remove_all_filters( 'wp_doing_ajax' );
		remove_all_filters( 'wp_die_ajax_handler' );
		Settings::flush();
		WordingGuard::flush();
		$this->fixtures_down();
		parent::tear_down();
	}

	/** Audit rows written by the Audit module itself (field layer), newest last. @return list<array<string,mixed>> */
	private function db_audit( string $action ): array {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . \Momos\Kernel\Db\Tables::audit() . ' WHERE id > %d AND action = %s ORDER BY id ASC', $this->floor, $action ), ARRAY_A ); // phpcs:ignore
	}

	private function approve_statement( string $key, string $type = '', string $ja = '', string $en = '' ): void {
		$row = StatementRepository::get( $key );
		$this->assertSame( [], StatementRepository::save_row( [ 'key' => $key, 'claim_type' => $row['claim_type'] ?? $type, 'text_ja' => $row['text_ja'] ?? $ja, 'approved' => true ], $this->dca, $row ? $key : null ) );
		$text = $row['i18n']['en']['text'] ?? $en;
		if ( ( $row['i18n']['en']['text'] ?? '' ) !== $text ) {
			// Translator ≠ reviewer (FR-I18N-007(b)): a translator writes new text, Head Office approves it.
			StatementRepository::save_translation( $key, 'en', $text, 'needs_review', $this->user_with_role( 'momo_translator' ) );
		}
		$this->assertSame( [], StatementRepository::save_translation( $key, 'en', $text, 'approved', $this->dca ) );
	}

	// ------------------------------------------------------------------ FR-MENU-004(a)

	/** FR-MENU-004(a): Japanese long description ≤ 1,500 visible characters — publish validation with a clear message. */
	public function test_long_description_limit_blocks_publication(): void {
		$cat   = $this->category( 'desc' );
		$base  = [ 'momo_short_description' => 'x', 'momo_primary_category' => (string) $cat, 'momo_reference_price_jpy' => '1000', 'momo_spice_level' => '0', 'momo_dish_sku' => 'D0901' ];
		$thumb = $this->image();
		$check = fn( string $content ) => DishPublishValidator::errors( $base, 0, 'Name', [ $cat ], $thumb, $content );

		$this->assertSame( 1500, DescriptionLimit::MAX );
		$this->assertSame( [], $check( str_repeat( 'あ', 1500 ) ) );
		$this->assertSame( [], $check( '<p><strong>' . str_repeat( 'あ', 1500 ) . '</strong></p>' ), 'markup does not count' );
		$this->assertSame( [ 'The long description can have at most 1,500 characters (currently 1,501). Shorten it before publishing.' ], $check( str_repeat( 'あ', 1501 ) ) );

		// A new publication is saved as a draft and the stored dish reports the error.
		$id = self::factory()->post->create( [ 'post_type' => ContentTypes::DISH, 'post_title' => '長い説明', 'post_status' => 'draft', 'post_content' => str_repeat( 'い', 1600 ) ] );
		wp_set_object_terms( $id, [ $cat ], ContentTypes::CATEGORY );
		foreach ( array_merge( $base, [ 'momo_dish_sku' => 'D0902' ] ) as $k => $v ) {
			update_post_meta( $id, $k, $v );
		}
		set_post_thumbnail( $id, $this->image() );
		wp_update_post( [ 'ID' => $id, 'post_status' => 'publish' ] );
		$this->assertSame( 'draft', get_post_status( $id ) );
		$this->assertContains( 'The long description can have at most 1,500 characters (currently 1,600). Shorten it before publishing.', DishPublishValidator::errors_for_post( $id ) );

		// A published dish keeps its previous description when an update is too long.
		$pub = $this->dish( 'D0903', $cat );
		wp_update_post( [ 'ID' => $pub, 'post_content' => '短い説明' ] );
		$GLOBALS['momos_last_field_errors'] = [];
		wp_update_post( [ 'ID' => $pub, 'post_content' => str_repeat( 'う', 1501 ) ] );
		$this->assertSame( 'publish', get_post_status( $pub ) );
		$this->assertSame( '短い説明', get_post_field( 'post_content', $pub ) );
		$this->assertContains( 'The long description can have at most 1,500 characters (currently 1,501). Shorten it before publishing.', FieldRegistry::last_errors() );
		$this->assertContains( 'The previous long description was kept.', FieldRegistry::last_errors() );
		unset( $GLOBALS['momos_fields_reject_invalid'] );
	}

	/** FR-MENU-004(a): every translation ≤ 1,500 characters — an over-length translation cannot be approved. */
	public function test_long_description_limit_applies_to_every_translation(): void {
		$this->assertStringContainsString( '1,500', (string) Translations::fields( 'post:' . ContentTypes::DISH )['content']['label'] );
		$dish = $this->dish( 'D0910', $this->category( 'desc-tr' ) );
		$user = $this->user( 'momo_head_office' );
		wp_set_current_user( $user );

		Translations::save( 'post', $dish, 'en', [ 'title' => 'Dish', 'content' => '<p>' . str_repeat( 'a', 1500 ) . '</p>' ], 'approved', $user );
		$this->assertSame( 'approved', Translations::status( 'post', $dish, 'en' ), '1,500 characters are allowed' );

		foreach ( [ 'en', 'ar', 'ur', 'hi', 'bn' ] as $lang ) {
			delete_transient( DescriptionLimit::NOTICE . $user );
			Translations::save( 'post', $dish, $lang, [ 'title' => 'T', 'content' => str_repeat( 'b', 1501 ) ], 'approved', $user );
			$this->assertSame( 'draft', Translations::status( 'post', $dish, $lang ), "{$lang}: over-length translation returned to draft" );
			$this->assertFalse( Translations::post_visible( $dish, $lang ) );
			$notice = (array) get_transient( DescriptionLimit::NOTICE . $user );
			$this->assertSame( [ "The {$lang} description can have at most 1,500 characters (currently 1,501). The translation was kept as a draft and cannot be approved until it is shortened." ], $notice );
		}
		ob_start();
		DescriptionLimit::notices();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'role="alert"', $html );
		$this->assertStringContainsString( 'at most 1,500 characters', $html );
	}

	// ------------------------------------------------------------------ FR-MENU-005(a)

	/** FR-MENU-005(a): up/down buttons (move_term) renumber the order and are audited. */
	public function test_category_move_term_reorders_and_audits(): void {
		$a = $this->category( 'ord-a', 'A' );
		$b = $this->category( 'ord-b', 'B' );
		$c = $this->category( 'ord-c', 'C' );
		foreach ( [ $a => '10', $b => '20', $c => '30' ] as $id => $o ) {
			update_term_meta( $id, 'momo_cat_order', $o );
		}
		$this->assertSame( [ $a, $b, $c ], CategoryOrderPage::ordered_ids() );

		CategoryOrderPage::move_term( $c, 'up' );
		$this->assertSame( [ $a, $c, $b ], CategoryOrderPage::ordered_ids() );
		$this->assertSame( [ '10', '20', '30' ], [ get_term_meta( $a, 'momo_cat_order', true ), get_term_meta( $c, 'momo_cat_order', true ), get_term_meta( $b, 'momo_cat_order', true ) ] );
		$audit = $this->audits_of( CategoryOrderPage::AUDIT );
		$this->assertCount( 1, $audit );
		$this->assertSame( [ 'before' => [ $a, $b, $c ], 'after' => [ $a, $c, $b ], 'via' => 'buttons' ], $audit[0][4] );

		CategoryOrderPage::move_term( $a, 'up' ); // already first
		CategoryOrderPage::move_term( $b, 'down' ); // already last
		$this->assertSame( [ $a, $c, $b ], CategoryOrderPage::ordered_ids() );
		$this->assertCount( 1, $this->audits_of( CategoryOrderPage::AUDIT ), 'no change, no audit' );

		$this->assertTrue( CategoryOrderPage::save_order( [ $b, $a, $c ] ) );
		$this->assertSame( [ $b, $a, $c ], CategoryOrderPage::ordered_ids() );
		$this->assertSame( 'drag', $this->audits_of( CategoryOrderPage::AUDIT )[1][4]['via'] );
		$this->assertFalse( CategoryOrderPage::save_order( [ $a, $c ] ), 'incomplete list refused' );
		$this->assertFalse( CategoryOrderPage::save_order( [ $a, $a, $c ] ), 'duplicates refused' );
		$this->assertFalse( CategoryOrderPage::save_order( [ $a, $b, $c, 999999 ] ), 'unknown term refused' );
		$this->assertSame( [ $b, $a, $c ], CategoryOrderPage::ordered_ids() );

		// The screen offers the drag handle and keeps the keyboard buttons.
		wp_set_current_user( $this->user( 'momo_menu_manager' ) );
		ob_start();
		CategoryOrderPage::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'momos-cat-handle', $html );
		$this->assertStringContainsString( 'id="momos-cat-order"', $html );
		$this->assertSame( 3, substr_count( $html, 'data-dir="up"' ) );
		$this->assertStringContainsString( 'data-term-id="' . $b . '"', $html );
	}

	/** Runs the AJAX handler and returns [status, decoded JSON]. */
	private function ajax( array $post ): array {
		$_POST    = $post;
		$_REQUEST = $post;
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter(
			'wp_die_ajax_handler',
			static fn() => static function ( $message ) {
				throw new \WPDieException( is_string( $message ) ? $message : '' );
			}
		);
		$code = null;
		$cb   = static function ( $header, $status ) use ( &$code ) {
			$code = $status;
			return $header;
		};
		add_filter( 'status_header', $cb, 10, 2 );
		ob_start();
		try {
			CategoryOrderPage::ajax_reorder();
		} catch ( \WPDieException $e ) {
			unset( $e );
		}
		$out = (string) ob_get_clean();
		remove_filter( 'status_header', $cb, 10 );
		return [ $code, json_decode( $out, true ) ];
	}

	/** FR-MENU-005(a): drag-and-drop save handler — nonce, capability, complete order, audit. */
	public function test_sortable_ajax_save_handler(): void {
		$a = $this->category( 'drag-a', 'A' );
		$b = $this->category( 'drag-b', 'B' );
		update_term_meta( $a, 'momo_cat_order', '10' );
		update_term_meta( $b, 'momo_cat_order', '20' );

		$mm = $this->user( 'momo_menu_manager' );
		wp_set_current_user( $mm );
		[ , $res ] = $this->ajax( [ 'action' => CategoryOrderPage::AJAX, '_ajax_nonce' => 'bad', 'order' => [ (string) $b, (string) $a ] ] );
		$this->assertFalse( $res['success'] );
		$this->assertSame( [ $a, $b ], CategoryOrderPage::ordered_ids(), 'invalid nonce: nothing saved' );

		[ , $res ] = $this->ajax( [ 'action' => CategoryOrderPage::AJAX, '_ajax_nonce' => wp_create_nonce( CategoryOrderPage::AJAX ), 'order' => [ (string) $b, (string) $a ] ] );
		$this->assertTrue( $res['success'] );
		$this->assertSame( [ $b, $a ], $res['data']['order'] );
		$this->assertSame( [ $b, $a ], CategoryOrderPage::ordered_ids() );
		$audit = $this->audits_of( CategoryOrderPage::AUDIT );
		$this->assertCount( 1, $audit );
		$this->assertSame( [ 'before' => [ $a, $b ], 'after' => [ $b, $a ], 'via' => 'drag' ], $audit[0][4] );

		[ , $res ] = $this->ajax( [ 'action' => CategoryOrderPage::AJAX, '_ajax_nonce' => wp_create_nonce( CategoryOrderPage::AJAX ), 'order' => [ (string) $a ] ] );
		$this->assertFalse( $res['success'] );
		$this->assertStringContainsString( 'Reload the page', $res['data']['message'] );

		$ce = $this->user( 'momo_content_editor' );
		wp_set_current_user( $ce );
		$this->assertFalse( current_user_can( CategoryOrderPage::CAP ) );
		[ , $res ] = $this->ajax( [ 'action' => CategoryOrderPage::AJAX, '_ajax_nonce' => wp_create_nonce( CategoryOrderPage::AJAX ), 'order' => [ (string) $a, (string) $b ] ] );
		$this->assertFalse( $res['success'] );
		$this->assertSame( [ $b, $a ], CategoryOrderPage::ordered_ids(), 'no capability: nothing saved' );
		$denied = $this->audits_of( 'access.denied' );
		$this->assertSame( 'menu_category', end( $denied )[1] );
	}

	// ------------------------------------------------------------------ FR-DIET-002(f)

	/** FR-DIET-002(f): HO maintains the prohibited-wording list per language in Settings; WordingGuard reads it. */
	public function test_prohibited_wording_list_is_maintained_in_settings(): void {
		$field = ProhibitedTermsFields::field( 'en' );
		$group = FieldRegistry::get_group( ProhibitedTermsFields::GROUP );
		$this->assertSame( 'momo-settings', FieldRegistry::option_page_of( $field ) );
		$this->assertSame( 'other', $group['tab'] );
		foreach ( [ 'all', 'ja', 'en', 'ar', 'ur', 'hi', 'bn' ] as $lang ) {
			$this->assertNotNull( FieldRegistry::field_def( 'option:momo-settings', ProhibitedTermsFields::PREFIX . $lang ), $lang );
		}
		// Seeded from config/claim-keywords.php.
		$this->assertContains( [ 'class' => 'allergen', 'term' => 'gluten-free' ], Settings::get( $field ) );
		$this->assertContains( [ 'class' => 'absolute', 'term' => '100% halal' ], Settings::get( ProhibitedTermsFields::field( '*' ) ) );
		$this->assertContains( 'gluten-free', array_column( WordingGuard::check( 'Gluten-free naan', 'en' ), 'term' ) );

		// Menu Manager (no momo_manage_brand) cannot change it.
		wp_set_current_user( $this->user( 'momo_menu_manager' ) );
		$rows = [ [ 'class' => 'allergen', 'term' => 'guaranteed safe' ] ];
		FieldRegistry::save_group_values( 'option', 'momo-settings', $group, [ $field => $rows ] );
		$this->assertSame( [], WordingGuard::check( 'guaranteed safe', 'en' ) );
		$this->assertNotEmpty( $this->db_audit( 'access.denied' ), 'denied field change is audited' );

		// Head Office replaces the English list: adds a term, drops one.
		wp_set_current_user( $this->user( 'momo_head_office' ) );
		$new = array_values( array_filter( ProhibitedTermsFields::seed_rows( 'en' ), static fn( $r ) => 'gluten-free' !== $r['term'] ) );
		$new[] = [ 'class' => 'allergen', 'term' => 'guaranteed safe' ];
		FieldRegistry::save_group_values( 'option', 'momo-settings', $group, [ $field => $new ] );
		$this->assertSame( [ [ 'term' => 'guaranteed safe', 'class' => 'allergen', 'lang' => 'en' ] ], WordingGuard::check( 'Guaranteed SAFE for everyone', 'en' ) );
		$this->assertNotContains( 'gluten-free', array_column( WordingGuard::check( 'Gluten-free naan', 'en' ), 'term' ), 'removed from the en list' );
		$this->assertContains( 'グルテンフリー', array_column( WordingGuard::check( 'グルテンフリーのナン', 'ja' ), 'term' ), 'other languages keep the master list' );
		$this->assertNotEmpty( WordingGuard::blocking_violations( 'guaranteed safe', 'en' ) );

		$audit = $this->audits_of( 'dietary.prohibited_terms_updated' );
		$this->assertCount( 1, $audit );
		$this->assertSame( [ 'allergen:guaranteed safe' ], $audit[0][4]['languages']['en']['added'] );
		$this->assertSame( [ 'allergen:gluten-free' ], $audit[0][4]['languages']['en']['removed'] );
		$settings = $this->db_audit( 'settings.updated' );
		$this->assertStringContainsString( $field, (string) end( $settings )['changes'] );

		// Native-reviewer equivalents for a draft language; the statement validator uses them.
		FieldRegistry::save_group_values( 'option', 'momo-settings', $group, [ ProhibitedTermsFields::field( 'ja' ) => array_merge( ProhibitedTermsFields::seed_rows( 'ja' ), [ [ 'class' => 'absolute', 'term' => '絶対ハラール' ] ] ) ] );
		$errors = StatementRepository::save_row( [ 'key' => 'brand.test', 'claim_type' => 'neutral', 'text_ja' => '当店は絶対ハラールです。', 'approved' => false ], $this->dca );
		$this->assertContains( 'Prohibited wording: “絶対ハラール”.', $errors );

		// Duplicate terms in one list are refused with a message.
		$this->assertNotNull( ProhibitedTermsFields::validate_rows( [ [ 'class' => 'allergen', 'term' => 'X' ], [ 'class' => 'absolute', 'term' => 'x' ] ] ) );
	}

	// ------------------------------------------------------------------ FR-CORP-001(e) / FR-CORP-003(a)

	/** FR-CORP-001(e) / FR-CORP-003(a): brand_claim() honours momo_brand_halal_statement_key, then brand.*, then brand.neutral. */
	public function test_brand_claim_uses_the_selected_brand_statement(): void {
		$this->approve_statement( 'brand.neutral' );
		$this->assertSame( 'brand.neutral', ClaimResolver::brand_claim( 'en' )['template_key'] );

		$this->approve_statement( 'brand.story', 'neutral', 'ハラール情報は各店舗のページでご確認ください。', 'See each branch page for halal information.' );
		$this->assertSame( 'brand.story', ClaimResolver::brand_claim( 'en' )['template_key'], 'first approved brand.* template' );

		$this->approve_statement( 'no_claim' );
		Settings::set( 'momo_brand_halal_statement_key', 'no_claim' );
		$en = ClaimResolver::brand_claim( 'en' );
		$this->assertSame( [ 'template_key' => 'no_claim', 'statement' => 'Please ask our staff at this branch about ingredients and preparation.', 'statement_available' => true ], $en );
		$this->assertSame( '食材や調理方法については、この店舗のスタッフにお尋ねください。', ClaimResolver::brand_claim( 'ja' )['statement'] );
		$ar = ClaimResolver::brand_claim( 'ar' );
		$this->assertSame( 'brand.neutral', $ar['template_key'], 'no approved ar translation → neutral, never mixed languages' );
		$this->assertFalse( $ar['statement_available'] );

		// Unapproved selection, or a certified template whose register variables cannot be filled → fallback.
		Settings::set( 'momo_brand_halal_statement_key', 'halal_ingredients' );
		$this->assertSame( 'brand.story', ClaimResolver::brand_claim( 'en' )['template_key'] );
		$this->approve_statement( 'certified.whole_kitchen' );
		Settings::set( 'momo_brand_halal_statement_key', 'certified.whole_kitchen' );
		$this->assertSame( 'brand.story', ClaimResolver::brand_claim( 'en' )['template_key'] );
		$this->assertStringNotContainsString( 'certified', ClaimResolver::brand_claim( 'en' )['statement'] );
	}

	// ------------------------------------------------------------------ untested ACs

	/** AC-MENU-02-04: a draft dish is not returned anonymously via preview URL or /wp-json/wp/v2/momo_dish. */
	public function test_draft_dish_is_not_public_by_preview_or_rest(): void {
		$cat   = $this->category( 'drafts' );
		$pub   = $this->dish( 'D0920', $cat );
		$draft = self::factory()->post->create( [ 'post_type' => ContentTypes::DISH, 'post_title' => '下書き料理', 'post_status' => 'draft', 'post_name' => 'draft-dish' ] );
		wp_set_object_terms( $draft, [ $cat ], ContentTypes::CATEGORY );
		update_post_meta( $draft, 'momo_primary_category', (string) $cat );
		wp_set_current_user( 0 );

		$list = rest_do_request( new \WP_REST_Request( 'GET', '/wp/v2/momo_dish' ) );
		$this->assertSame( 200, $list->get_status() );
		$ids = array_column( $list->get_data(), 'id' );
		$this->assertContains( $pub, $ids );
		$this->assertNotContains( $draft, $ids );
		$this->assertContains( rest_do_request( new \WP_REST_Request( 'GET', '/wp/v2/momo_dish/' . $draft ) )->get_status(), [ 401, 403, 404 ] );
		$req = new \WP_REST_Request( 'GET', '/wp/v2/momo_dish' );
		$req->set_param( 'status', 'draft' );
		$res = rest_do_request( $req );
		$this->assertNotSame( 200, $res->get_status() );
		$req = new \WP_REST_Request( 'GET', '/wp/v2/momo_dish/' . $draft );
		$req->set_param( 'context', 'edit' );
		$this->assertContains( rest_do_request( $req )->get_status(), [ 401, 403, 404 ] );

		// Preview URLs and the dish route.
		foreach ( [ [ 'p' => $draft, 'preview' => 'true' ], [ 'post_type' => ContentTypes::DISH, 'p' => $draft, 'preview' => 'true' ], [ 'momo_dish' => 'draft-dish', 'preview' => 'true' ] ] as $args ) {
			$this->go_to( add_query_arg( $args, home_url( '/' ) ) );
			MenuRewrite::template_redirect();
			$this->assertTrue( is_404(), 'preview ' . wp_json_encode( $args ) );
			$this->assertNotContains( $draft, wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) );
		}
		$this->go_to( add_query_arg( [ 'momo_dish' => 'draft-dish', MenuRewrite::QV_CATEGORY_REQ => 'drafts' ], home_url( '/' ) ) );
		MenuRewrite::template_redirect();
		$this->assertTrue( is_404(), '/menu/drafts/draft-dish/ → 404' );
		$this->go_to( add_query_arg( [ 'momo_dish' => 'd0920', MenuRewrite::QV_CATEGORY_REQ => 'drafts' ], home_url( '/' ) ) );
		MenuRewrite::template_redirect();
		$this->assertFalse( is_404(), 'control: the published dish is served' );
	}

	/** AC-DIET-01-07: alcohol_served unknown → no is audited with before/after; the translated statement shows. */
	public function test_alcohol_served_change_is_audited_and_translated_statement_shows(): void {
		$this->approve_statement( 'alcohol.served.no' );
		$this->approve_statement( 'no_claim' );
		$branch = $this->branch( 'ALC' );
		update_post_meta( $branch, 'momo_alcohol_served', 'unknown' );
		$this->assertSame( [], ClaimResolver::branch_claim( $branch, 'en' )['alcohol_lines'], 'unknown is not displayed' );

		wp_set_current_user( $this->dca );
		$group = FieldRegistry::get_group( DietaryFields::G7 );
		$_POST['_momos_rendered'] = array_map( static fn( $f ) => $f['name'], $group['fields'] );
		$values = array_merge( FieldRegistry::post_values( $branch, $group ), [ 'momo_alcohol_served' => 'no' ] );
		FieldRegistry::save_group_values( 'post', $branch, $group, $values, get_post( $branch ) );

		$this->assertSame( 'no', get_post_meta( $branch, 'momo_alcohol_served', true ), implode( ' | ', FieldRegistry::last_errors() ) );
		$audit = $this->audits_of( 'dietary.branch_claims_updated' );
		$this->assertCount( 1, $audit );
		$this->assertSame( $branch, $audit[0][2] );
		$this->assertSame( 'unknown', $audit[0][4]['before']['momo_alcohol_served'] );
		$this->assertSame( 'no', $audit[0][4]['after']['momo_alcohol_served'] );
		$this->assertSame( [ 'Alcoholic drinks are not served at this branch.' ], ClaimResolver::branch_claim( $branch, 'en' )['alcohol_lines'] );
		$this->assertSame( [ 'この店舗ではアルコール飲料を提供していません。' ], ClaimResolver::branch_claim( $branch, 'ja' )['alcohol_lines'] );
	}

	/** AC-DIET-01-08 / FR-DIET-003(c): no free-text halal field on the branch (or dish) data model. */
	public function test_no_free_text_halal_field_on_branch_model(): void {
		$free_text = [ 'text', 'textarea', 'wysiwyg' ];
		$halal     = '/halal|ハラール|ハラル|claim|statement/i';
		$checked   = 0;
		foreach ( [ 'post:momo_branch', 'post:' . ContentTypes::DISH ] as $kind ) {
			foreach ( FieldRegistry::groups_for( $kind ) as $g ) {
				foreach ( $g['fields'] as $f ) {
					$subs = 'repeater' === $f['type'] ? (array) ( $f['sub_fields'] ?? [] ) : [ $f ];
					foreach ( $subs as $sf ) {
						if ( preg_match( $halal, (string) $sf['name'] . ' ' . (string) ( $sf['label'] ?? '' ) ) ) {
							++$checked;
							$this->assertTrue( ! in_array( $sf['type'], $free_text, true ) || ! empty( $sf['stamp'] ), "{$kind}: {$sf['name']} is free text" );
						}
					}
				}
			}
			foreach ( array_keys( Translations::fields( $kind ) ) as $key ) {
				$this->assertDoesNotMatchRegularExpression( $halal, (string) $key, "{$kind}: translatable {$key}" );
			}
		}
		$this->assertGreaterThan( 3, $checked, 'the claim fields were inspected' );
		$this->assertSame( 'select', FieldRegistry::field_def( 'post:momo_branch', 'momo_halal_claim_type' )['type'] );
		$this->assertSame( 'select', FieldRegistry::field_def( 'post:momo_branch', 'momo_halal_statement_key' )['type'] );
	}

	/** AC-DIET-02-06: a variant for branch B shows its own allergen states; the base dish is unchanged. */
	public function test_variant_dish_has_own_allergen_states(): void {
		$cat     = $this->category( 'variants' );
		$a       = $this->branch( 'VRA' );
		$b       = $this->branch( 'VRB' );
		$base    = $this->dish( 'D0930', $cat, 1500, [ 'momo_alg_wheat' => 'contains', 'momo_alg_milk' => 'not_contained' ] );
		$variant = $this->dish( 'D0931', $cat, 1500, [ 'momo_alg_wheat' => 'not_contained', 'momo_alg_milk' => 'contains' ] );
		wp_update_post( [ 'ID' => $variant, 'post_parent' => $base ] );
		update_post_meta( $variant, 'momo_variant_of', (string) $base );
		$this->row( $a, $base );
		$this->row( $b, $variant );

		$this->assertSame( [ $variant ], MenuQueryService::dishes_in_category( $cat, 'ja', $b ), 'branch B lists its variant' );
		$this->assertSame( [ $base ], MenuQueryService::dishes_in_category( $cat, 'ja', $a ) );
		$state = static function ( int $dish, string $code ): string {
			foreach ( AllergenPresenter::for_dish( $dish, 'en' )['groups'] as $g ) {
				foreach ( $g['rows'] as $r ) {
					if ( $r['code'] === $code ) {
						return (string) $r['state'];
					}
				}
			}
			return '';
		};
		$this->assertSame( 'not_contained', $state( $variant, 'wheat' ) );
		$this->assertSame( 'contains', $state( $variant, 'milk' ) );
		$this->assertSame( 'contains', $state( $base, 'wheat' ), 'base unchanged' );
		$this->assertSame( 'not_contained', $state( $base, 'milk' ) );

		// Editing the variant's states later leaves the base untouched.
		wp_set_current_user( $this->dca );
		update_post_meta( $variant, 'momo_alg_egg', 'may_contain' );
		$this->assertSame( 'may_contain', AllergenPresenter::states( $variant )['egg'] );
		$this->assertSame( 'not_verified', AllergenPresenter::states( $base )['egg'] );
	}
}
