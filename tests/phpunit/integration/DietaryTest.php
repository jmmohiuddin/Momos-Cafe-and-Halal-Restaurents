<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/MenuTest.php';

use Momos\Dietary\Allergens;
use Momos\Dietary\Fields\DietaryFields;
use Momos\Dietary\Guard\WordingGuard;
use Momos\Dietary\Service\AllergenPresenter;
use Momos\Dietary\Service\StatementRepository;
use Momos\Kernel\Fields\FieldRegistry;
use Momos\Kernel\I18n\Translations;
use Momos\Menu\ContentTypes;
use Momos\Tests\TestCase;

/**
 * Allergens, claim-field guard (CAP-DCA, two-person rule), claim tags, ingredient watcher, wording guard,
 * statements (TC-DIET-001/009/010/011/014/017/023/028, AC-DIET-01-01, AC-DIET-02-01..07).
 */
final class DietaryTest extends TestCase {
	use MenuDietaryFixtures;

	public function set_up(): void {
		parent::set_up();
		$this->fixtures_up();
	}

	public function tear_down(): void {
		$this->fixtures_down();
		parent::tear_down();
	}

	/** Simulate the dish edit form posting G10 values as the current user. */
	private function save_g10( int $dish, array $values ): void {
		$group                 = FieldRegistry::get_group( DietaryFields::G10 );
		$_POST['_momos_rendered'] = array_map( static fn( $f ) => $f['name'], $group['fields'] );
		$current               = FieldRegistry::post_values( $dish, $group );
		$submitted             = array_merge( $current, [ 'momo_alg_attest_not_source' => '0' ], $values );
		FieldRegistry::save_group_values( 'post', $dish, $group, $submitted, get_post( $dish ) );
	}

	/** TC-DIET-001: exactly 29 seeded items with codes, classes, ja/en names. */
	public function test_allergen_seed(): void {
		$terms = Allergens::terms_by_code();
		$this->assertCount( 29, $terms );
		$this->assertSame( Allergens::codes(), array_column( Allergens::config(), 'code' ) );
		$this->assertSame( 'mandatory', get_term_meta( $terms['cashew_nut']->term_id, 'momo_alg_class', true ) );
		$this->assertSame( 'recommended', get_term_meta( $terms['pistachio']->term_id, 'momo_alg_class', true ) );
		$this->assertSame( '小麦', $terms['wheat']->name );
		$this->assertSame( 'Wheat', Translations::term( $terms['wheat'], 'name', 'en' ) );
		$this->assertSame( '1', get_term_meta( $terms['gelatin']->term_id, 'momo_alg_seeded', true ) );
		$this->assertSame( 9, count( array_filter( Allergens::config(), static fn( $a ) => 'mandatory' === $a['class'] ) ) );
		( new \Momos\Dietary\Migration\M0003_SeedAllergens() )->up(); // idempotent
		$this->assertCount( 29, Allergens::terms_by_code() );
		wp_set_current_user( $this->user( 'momo_head_office', [], true ) );
		$this->assertFalse( current_user_can( 'delete_term', $terms['wheat']->term_id ), 'seeded allergens cannot be deleted' );
	}

	/** AC-DIET-02-01: new dish → all 29 not_verified, banner, never "free from". */
	public function test_new_dish_reads_not_verified_with_banner(): void {
		$d     = $this->dish( 'D3000', $this->category( 'dt1' ) );
		$table = AllergenPresenter::for_dish( $d, 'en' );
		$this->assertCount( 29, $table['not_verified'] );
		$this->assertSame( 'Allergen information not verified — please ask staff.', $table['banner'] );
		$this->assertFalse( $table['complete'] );
		$this->assertSame( [ 'mandatory', 'recommended' ], array_column( $table['groups'], 'key' ) );
		$this->assertCount( 9, $table['groups'][0]['rows'] );
		$this->assertCount( 20, $table['groups'][1]['rows'] );
		$this->assertArrayHasKey( 'tell_staff', $table['disclaimers'] );
		$this->assertArrayNotHasKey( 'cross_contact', $table['disclaimers'], 'cross-contact only when confirmed' );
		$this->assertNull( $table['last_verified'] );
	}

	/** AC-DIET-01-01 / TC-DIET-009 / TC-ACL-023: MM cannot write claim fields by any path. */
	public function test_non_dca_cannot_change_claim_fields(): void {
		$d = $this->dish( 'D3001', $this->category( 'dt2' ) );
		wp_set_current_user( $this->user( 'momo_menu_manager' ) );
		$this->assertFalse( update_post_meta( $d, 'momo_alg_wheat', 'not_contained' ) );
		$this->assertFalse( (bool) add_post_meta( $d, 'momo_dish_halal_basis', 'certified_item' ) );
		$this->save_g10( $d, [ 'momo_alg_wheat' => 'not_contained', 'momo_alg_source_document' => 'x', 'momo_alg_sourced_by' => 'Chef' ] );
		$this->assertSame( '', get_post_meta( $d, 'momo_alg_wheat', true ) );
		$denied = $this->audits_of( 'access.denied' );
		$this->assertNotEmpty( $denied );
		$this->assertSame( 'cap_dca_required', $denied[0][4]['reason'] );
		// Head office without the per-user capability is refused as well (CAP-DCA is never role-granted).
		wp_set_current_user( $this->user( 'momo_head_office' ) );
		$this->assertFalse( update_post_meta( $d, 'momo_alg_milk', 'contains' ) );
	}

	/** AC-DIET-02-02/03, TC-DIET-010/017: two-person rule, stamps, audit, summary. */
	public function test_dca_save_two_person_rule_and_stamps(): void {
		$d   = $this->dish( 'D3002', $this->category( 'dt3' ) );
		$dca = $this->user( 'momo_head_office', [], true );
		wp_set_current_user( $dca );
		$me  = get_userdata( $dca )->display_name;

		$this->save_g10( $d, [ 'momo_alg_wheat' => 'contains' ] );
		$this->assertSame( '', get_post_meta( $d, 'momo_alg_wheat', true ), 'refused without source document' );
		$this->assertContains( 'Record the kitchen source document and who provided it.', FieldRegistry::last_errors() );

		$this->save_g10( $d, [ 'momo_alg_wheat' => 'contains', 'momo_alg_source_document' => 'Recipe card D3002 v1', 'momo_alg_sourced_by' => $me, 'momo_alg_attest_not_source' => '1' ] );
		$this->assertSame( '', get_post_meta( $d, 'momo_alg_wheat', true ), 'source person must differ from approver' );

		$states = [];
		foreach ( Allergens::codes() as $code ) {
			$states[ Allergens::meta_key( $code ) ] = 'not_contained';
		}
		$states['momo_alg_wheat'] = 'contains';
		$states['momo_alg_milk']  = 'may_contain';
		update_post_meta( $d, 'momo_allergen_review_required', '1' );
		$this->save_g10( $d, $states + [ 'momo_alg_source_document' => 'Recipe card D3002 v1', 'momo_alg_sourced_by' => 'Head Chef Tanaka', 'momo_alg_attest_not_source' => '1' ] );
		$this->assertSame( 'contains', get_post_meta( $d, 'momo_alg_wheat', true ) );
		$this->assertSame( (string) $dca, (string) get_post_meta( $d, 'momo_claims_verified_by', true ) );
		$this->assertNotEmpty( get_post_meta( $d, 'momo_claims_verified_at', true ) );
		$this->assertSame( '0', get_post_meta( $d, 'momo_allergen_review_required', true ) );
		$this->assertSame( '0', get_post_meta( $d, 'momo_alg_attest_not_source', true ), 'attestation is per save' );
		$audit = $this->audits_of( 'dietary.dish_claims_updated' );
		$this->assertCount( 1, $audit );
		$this->assertSame( 'Recipe card D3002 v1', $audit[0][4]['source_document'] );

		$t = AllergenPresenter::for_dish( $d, 'en' );
		$this->assertTrue( $t['complete'] );
		$this->assertNull( $t['banner'] );
		$this->assertSame( 'Contains: Wheat', $t['summary']['contains'] );
		$this->assertSame( 'May contain (cross-contact): Milk', $t['summary']['may_contain'] );
		$this->assertNotNull( $t['last_verified'] );
		$this->assertSame( '含む：小麦', AllergenPresenter::for_dish( $d, 'ja' )['summary']['contains'] );
		// Bengali page shows the same states (AC-DIET-02-05); names need human translation.
		$bn = AllergenPresenter::for_dish( $d, 'bn' );
		$this->assertSame( 'contains', $bn['groups'][0]['rows'][4]['state'] );
		$this->assertFalse( $bn['names_complete'] );
		StatementRepository::set_cross_contact( true, $dca );
		$this->assertArrayHasKey( 'cross_contact', AllergenPresenter::for_dish( $d, 'en' )['disclaimers'] );
	}

	/** Custom allergens (class other): unknown on every dish until set; own group. */
	public function test_custom_allergen_group(): void {
		wp_set_current_user( $this->user( 'momo_head_office', [], true ) );
		$t = wp_insert_term( 'マスタード', Allergens::TAXONOMY, [ 'slug' => 'mustard' ] );
		$this->assertSame( 'mustard', get_term_meta( $t['term_id'], 'momo_alg_code', true ) );
		$this->assertSame( 'other', get_term_meta( $t['term_id'], 'momo_alg_class', true ) );
		$d     = $this->dish( 'D3003', $this->category( 'dt4' ) );
		$table = AllergenPresenter::for_dish( $d, 'ja' );
		$this->assertSame( [ 'mandatory', 'recommended', 'other' ], array_column( $table['groups'], 'key' ) );
		$this->assertSame( 'not_verified', AllergenPresenter::states( $d )['mustard'] );
		$this->assertNull( DietaryFields::validate_custom_allergens( [ [ 'term_id' => (string) $t['term_id'], 'state' => 'contains' ] ] ) );
		$wheat = Allergens::term_by_code( 'wheat' );
		$this->assertNotNull( DietaryFields::validate_custom_allergens( [ [ 'term_id' => (string) $wheat->term_id, 'state' => 'contains' ] ] ) );
		$this->assertNotNull( DietaryFields::validate_alg_code( 'wheat_x', $wheat->term_id ), 'seeded code immutable' );
	}

	/** BR-DIET-07 / AC-DIET-02-04 / TC-DIET-023: ingredient change flags review until CAP-DCA re-save. */
	public function test_ingredient_change_sets_review_flag(): void {
		$d = $this->dish( 'D3004', $this->category( 'dt5' ) );
		$i = wp_insert_term( 'カシューナッツ', ContentTypes::INGREDIENT );
		wp_set_current_user( $this->user( 'momo_menu_manager' ) );
		$fired = 0;
		add_action( 'momos/dietary/review_required', static function () use ( &$fired ) { ++$fired; } );
		wp_set_object_terms( $d, [ (int) $i['term_id'] ], ContentTypes::INGREDIENT );
		$this->assertSame( '1', get_post_meta( $d, 'momo_allergen_review_required', true ) );
		$this->assertCount( 1, $this->audits_of( 'dietary.review_required_set' ) );
		\Momos\Kernel\Events\AfterCommit::flush();
		$this->assertSame( 1, $fired );
		$this->assertNotNull( AllergenPresenter::for_dish( $d, 'en' )['banner'] );
		$this->assertContains( 'review', AllergenPresenter::filter_codes( $d ) );
	}

	/** Claim tags only by CAP-DCA (ClaimTagGuard). */
	public function test_claim_tags_guard(): void {
		$d     = $this->dish( 'D3005', $this->category( 'dt6' ) );
		$claim = wp_insert_term( '豚肉不使用（食材）', ContentTypes::TAG, [ 'slug' => 'no-pork-ingredients' ] );
		$plain = wp_insert_term( 'ベジタリアン', ContentTypes::TAG, [ 'slug' => 'vegetarian' ] );
		update_term_meta( $claim['term_id'], 'momo_tag_is_claim', '1' );
		wp_set_current_user( $this->user( 'momo_menu_manager' ) );
		wp_set_object_terms( $d, [ (int) $claim['term_id'], (int) $plain['term_id'] ], ContentTypes::TAG );
		$this->assertSame( [ (int) $plain['term_id'] ], wp_get_object_terms( $d, ContentTypes::TAG, [ 'fields' => 'ids' ] ) );
		$this->assertNotEmpty( $this->audits_of( 'access.denied' ) );
		$this->assertFalse( current_user_can( 'edit_term', $claim['term_id'] ), 'claim tag terms editable by CAP-DCA only' );
		wp_set_current_user( $this->user( 'momo_head_office', [], true ) );
		wp_set_object_terms( $d, [ (int) $claim['term_id'] ], ContentTypes::TAG, true );
		$this->assertContains( (int) $claim['term_id'], wp_get_object_terms( $d, ContentTypes::TAG, [ 'fields' => 'ids' ] ) );
		$this->assertNotEmpty( $this->audits_of( 'dietary.dish_claims_updated' ) );
	}

	/** BR-DIET-01/03, TC-DIET-013: prohibited wording detection per class/language. */
	public function test_wording_guard(): void {
		$this->assertSame( 'absolute', WordingGuard::check( 'Our food is 100% Halal!', 'en' )[0]['class'] );
		$this->assertSame( 'allergen', WordingGuard::check( 'このメニューはアレルゲンフリーです', 'ja' )[0]['class'] );
		$this->assertNotEmpty( WordingGuard::check( 'حلال معتمد', 'ar' ) );
		$this->assertSame( [], WordingGuard::check( 'Grilled chicken with rice', 'en' ) );
		$this->assertNotEmpty( WordingGuard::statement_violations( 'ハラール認証を取得', 'no_claim', 'ja' ) );
		$this->assertSame( [], WordingGuard::statement_violations( '当店舗は{certifier}によるハラール認証を取得しています', 'certified', 'ja' ) );
		$this->assertNotEmpty( WordingGuard::statement_violations( '100%ハラール', 'certified', 'ja' ) );
		$this->assertContains( 'halal', WordingGuard::base_terms( 'Fully Halal kitchen' ) );
		foreach ( \Momos\Dietary\Migration\M0003_SeedStatements::rows() as $r ) {
			$this->assertSame( [], WordingGuard::statement_violations( $r['text_ja'], $r['claim_type'], 'ja' ), $r['key'] );
			$this->assertSame( [], WordingGuard::statement_violations( $r['i18n']['en']['text'], $r['claim_type'], 'en' ), $r['key'] );
		}
	}

	/** FR-DIET-003 / BR-DIET-05 / TC-DIET-014: templates seeded unapproved; approval per language; CAP-DCA only. */
	public function test_statement_templates(): void {
		$this->assertCount( 11, StatementRepository::all() );
		$this->assertNull( StatementRepository::text( 'no_claim', 'ja' ), 'seeded rows are not approved' );
		$mm = $this->user( 'momo_menu_manager' );
		$this->assertNotEmpty( StatementRepository::save_row( [ 'key' => 'no_claim', 'claim_type' => 'no_claim', 'text_ja' => 'x', 'approved' => true ], $mm, 'no_claim' ) );
		$dca = $this->user( 'momo_head_office', [], true );
		$row = StatementRepository::get( 'no_claim' );
		$this->assertSame( [], StatementRepository::save_row( [ 'key' => 'no_claim', 'claim_type' => 'no_claim', 'text_ja' => $row['text_ja'], 'approved' => true ], $dca, 'no_claim' ) );
		$this->assertSame( $row['text_ja'], StatementRepository::text( 'no_claim', 'ja' ) );
		$this->assertNull( StatementRepository::text( 'no_claim', 'en' ), 'EN draft needs approval' );
		$tr = $this->user( 'momo_translator' );
		StatementRepository::save_translation( 'no_claim', 'en', 'Please ask our staff at this branch about ingredients and preparation.', 'approved', $tr );
		$this->assertSame( 'Please ask our staff at this branch about ingredients and preparation.', StatementRepository::text( 'no_claim', 'en' ) );
		// JA master change → approved translations go back to review (no stale claim text).
		StatementRepository::save_row( [ 'key' => 'no_claim', 'claim_type' => 'no_claim', 'text_ja' => '食材については店舗スタッフにお尋ねください。', 'approved' => true ], $dca, 'no_claim' );
		$this->assertNull( StatementRepository::text( 'no_claim', 'en' ) );
		$this->assertSame( 'needs_review', StatementRepository::get( 'no_claim' )['i18n']['en']['status'] );
		$errors = StatementRepository::save_row( [ 'key' => 'brand.x', 'claim_type' => 'neutral', 'text_ja' => '完全ハラールのお店', 'approved' => true ], $dca );
		$this->assertNotEmpty( $errors, 'absolute wording blocked' );
		$this->assertNotEmpty( $this->audits_of( 'dietary.statement_updated' ) );
	}
}
