<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

use Momos\Kernel\Db\Tables;
use Momos\Kernel\Db\Transaction;
use Momos\Kernel\Events\AfterCommit;
use Momos\Kernel\I18n\Translations;
use Momos\Menu\ContentTypes;
use Momos\Menu\Repository\BranchMenuItemRepository;
use Momos\Menu\Rewrite\MenuRewrite;
use Momos\Menu\Service\AvailabilityLabel;
use Momos\Menu\Service\CategoryGuard;
use Momos\Menu\Service\DishLifecycle;
use Momos\Menu\Service\DishPublishValidator;
use Momos\Menu\Service\MenuQueryService;
use Momos\Menu\Service\PriceFormatter;
use Momos\Menu\Service\PriceResolver;
use Momos\Tests\TestCase;

/**
 * Shared fixtures for the Menu & Dietary integration tests (roles from config/capabilities.php,
 * branches, dishes, audit capture). Kernel transactions are joined to the test transaction so that
 * rollback isolation is kept (Transaction::run would otherwise COMMIT the test data).
 */
trait MenuDietaryFixtures {
	/** @var list<array{0:string,1:string,2:?int,3:?int,4:array<mixed>,5:string}> */
	protected array $audits = [];

	protected function fixtures_up(): void {
		$cfg = require MOMOS_CORE_DIR . '/config/capabilities.php';
		foreach ( $cfg['roles'] as $role => $labels ) {
			if ( ! get_role( $role ) ) {
				add_role( $role, $labels[0], [] );
			}
		}
		foreach ( $cfg['caps'] as $cap => $roles ) {
			foreach ( $roles as $role ) {
				get_role( $role )?->add_cap( $cap );
			}
		}
		if ( count( \Momos\Dietary\Allergens::terms_by_code() ) < 29 ) {
			( new \Momos\Dietary\Migration\M0003_SeedAllergens() )->up(); // WP test suite deletes terms between classes.
		}
		$this->audits = [];
		add_action( 'momos/audit/log', [ $this, 'capture_audit' ], 10, 6 );
		$this->set_tx_depth( 1 );
		\Momos\Dietary\Guard\ClaimFieldGuard::reset();
		$GLOBALS['momos_last_field_errors'] = [];
	}

	protected function fixtures_down(): void {
		remove_action( 'momos/audit/log', [ $this, 'capture_audit' ], 10 );
		AfterCommit::discard();
		$this->set_tx_depth( 0 );
		$_POST = [];
		wp_set_current_user( 0 );
	}

	private function set_tx_depth( int $d ): void {
		$p = new \ReflectionProperty( Transaction::class, 'depth' );
		$p->setValue( null, $d );
	}

	public function capture_audit( string $action, string $type, ?int $id, ?int $branch, array $changes, string $actor ): void {
		$this->audits[] = [ $action, $type, $id, $branch, $changes, $actor ];
	}

	/** @return list<array<mixed>> */
	protected function audits_of( string $action ): array {
		return array_values( array_filter( $this->audits, static fn( $a ) => $a[0] === $action ) );
	}

	protected function user( string $role, array $branches = [], bool $dca = false ): int {
		$id = $this->user_with_role( $role, $branches );
		if ( $dca ) {
			get_userdata( $id )->add_cap( 'momo_edit_dietary_claims' );
		}
		return $id;
	}

	protected function branch( string $code, array $meta = [] ): int {
		$id = self::factory()->post->create( [ 'post_type' => 'momo_branch', 'post_title' => '店舗' . $code, 'post_name' => strtolower( $code ), 'post_status' => 'draft' ] );
		global $wpdb;
		$wpdb->update( $wpdb->posts, [ 'post_status' => 'publish' ], [ 'ID' => $id ] );
		clean_post_cache( $id );
		update_post_meta( $id, 'momo_branch_code', $code );
		update_post_meta( $id, 'momo_operating_status', $meta['operating_status'] ?? 'open' );
		if ( ! empty( $meta['test'] ) ) {
			update_post_meta( $id, 'momo_is_test_branch', '1' );
		}
		return $id;
	}

	protected function category( string $slug, string $name = 'カテゴリー' ): int {
		$t = wp_insert_term( $name . $slug, ContentTypes::CATEGORY, [ 'slug' => $slug ] );
		return (int) $t['term_id'];
	}

	protected function image(): int {
		$aid = self::factory()->attachment->create_object( 'dish.jpg', 0, [ 'post_mime_type' => 'image/jpeg', 'post_type' => 'attachment' ] );
		update_post_meta( $aid, '_wp_attachment_image_alt', '料理の写真' );
		return (int) $aid;
	}

	/** Published, valid dish (bypasses the UI; publish validation still runs). */
	protected function dish( string $sku, int $cat, int $price = 1500, array $meta = [], string $title = '料理' ): int {
		$id = self::factory()->post->create( [ 'post_type' => ContentTypes::DISH, 'post_title' => $title . $sku, 'post_status' => 'draft', 'post_name' => strtolower( $sku ) ] );
		wp_set_object_terms( $id, [ $cat ], ContentTypes::CATEGORY );
		$defaults = [
			'momo_short_description'   => '説明',
			'momo_dish_sku'            => $sku,
			'momo_primary_category'    => (string) $cat,
			'momo_reference_price_jpy' => (string) $price,
			'momo_spice_level'         => '1',
		];
		foreach ( array_merge( $defaults, $meta ) as $k => $v ) {
			update_post_meta( $id, $k, $v );
		}
		set_post_thumbnail( $id, $this->image() );
		wp_update_post( [ 'ID' => $id, 'post_status' => 'publish' ] );
		$this->assertSame( 'publish', get_post_status( $id ), implode( ' | ', \Momos\Kernel\Fields\FieldRegistry::last_errors() ) );
		return $id;
	}

	protected function row( int $branch, int $dish, array $values = [] ): int {
		return BranchMenuItemRepository::insert( $branch, $dish, array_merge( [ 'offered' => true ], $values ), 1 );
	}
}

final class MenuTest extends TestCase {
	use MenuDietaryFixtures;

	public function set_up(): void {
		parent::set_up();
		$this->fixtures_up();
	}

	public function tear_down(): void {
		$this->fixtures_down();
		parent::tear_down();
	}

	public function test_types_registered_with_documented_settings(): void {
		$dish = get_post_type_object( ContentTypes::DISH );
		$this->assertTrue( $dish->show_in_rest );
		$this->assertFalse( $dish->rewrite );
		$this->assertSame( 'create_momo_dishes', $dish->cap->create_posts );
		$this->assertTrue( taxonomy_exists( ContentTypes::CATEGORY ) && taxonomy_exists( ContentTypes::INGREDIENT ) && taxonomy_exists( ContentTypes::TAG ) );
		$cert = get_post_type_object( 'momo_certificate' );
		$this->assertFalse( $cert->show_in_rest, 'SEC-023: certificates never in REST' );
		$this->assertFalse( $cert->public );
		$this->assertFalse( get_taxonomy( 'momo_allergen' )->meta_box_cb );
	}

	/** TC-DISH-003 / AC-MENU-03-02: publish refused with the list of missing fields. */
	public function test_publish_validation_lists_missing_fields_and_saves_draft(): void {
		$cat = $this->category( 'curry' );
		$id  = self::factory()->post->create( [ 'post_type' => ContentTypes::DISH, 'post_title' => 'ビリヤニ', 'post_status' => 'draft' ] );
		wp_set_object_terms( $id, [ $cat ], ContentTypes::CATEGORY );
		update_post_meta( $id, 'momo_dish_sku', 'D0001' );
		wp_update_post( [ 'ID' => $id, 'post_status' => 'publish' ] );
		$this->assertSame( 'draft', get_post_status( $id ) );
		$errors = DishPublishValidator::errors_for_post( $id );
		$this->assertStringContainsString( 'Complete required fields before publishing:', $errors[0] );
		$this->assertStringContainsString( 'reference price', $errors[0] );
		$this->assertStringContainsString( 'short description', $errors[0] );
		$this->assertStringContainsString( 'featured image', $errors[0] );
	}

	/** TC-DISH-003 field validation: D12, duplicate SKU, price −1/100001, spice 4, lead time missing. */
	public function test_field_validation_rules(): void {
		$cat   = $this->category( 'grill' );
		$first = $this->dish( 'D0100', $cat );
		$base  = [ 'momo_short_description' => 'x', 'momo_primary_category' => (string) $cat, 'momo_reference_price_jpy' => '1000', 'momo_spice_level' => '0', 'momo_dish_sku' => 'D0101' ];
		$thumb = $this->image();
		$check = fn( array $over ) => implode( ' | ', DishPublishValidator::errors( array_merge( $base, $over ), 0, 'Name', [ $cat ], $thumb ) );
		$this->assertSame( '', $check( [] ) );
		$this->assertStringContainsString( 'Dish code must be D followed by 4 digits and unique.', $check( [ 'momo_dish_sku' => 'D12' ] ) );
		$this->assertStringContainsString( 'Dish code must be D followed by 4 digits and unique.', $check( [ 'momo_dish_sku' => 'D0100' ] ) );
		$this->assertStringContainsString( 'Price must be between 0 and 100,000 yen.', $check( [ 'momo_reference_price_jpy' => '-1' ] ) );
		$this->assertStringContainsString( 'Price must be between 0 and 100,000 yen.', $check( [ 'momo_reference_price_jpy' => '100001' ] ) );
		$this->assertSame( '', $check( [ 'momo_reference_price_jpy' => '0' ] ), '¥0 allowed when explicitly entered' );
		$this->assertStringContainsString( 'Spice level must be 0–3.', $check( [ 'momo_spice_level' => '4' ] ) );
		$this->assertStringContainsString( 'Enter the advance-order time in hours (1–168).', $check( [ 'momo_advance_order_required' => '1' ] ) );
		$this->assertStringContainsString( 'primary category must be one of the assigned', $check( [ 'momo_primary_category' => (string) $this->category( 'other' ) ] ) );
		$this->assertGreaterThan( 0, $first );
	}

	/** AC-MENU-03-03: SKU immutable after first publication (any write path). */
	public function test_sku_is_immutable_after_publish(): void {
		$id = $this->dish( 'D0200', $this->category( 'naan' ) );
		$this->assertFalse( update_post_meta( $id, 'momo_dish_sku', 'D0201' ) );
		$this->assertSame( 'D0200', get_post_meta( $id, 'momo_dish_sku', true ) );
		$this->assertNotEmpty( $this->audits_of( 'dish.published' ) );
	}

	/** AC-MENU-03-04: price change audited with before/after. */
	public function test_reference_price_change_is_audited(): void {
		$id = $this->dish( 'D0300', $this->category( 'set' ), 1500 );
		$this->audits = [];
		update_post_meta( $id, 'momo_reference_price_jpy', '1600' );
		$a = $this->audits_of( 'dish.reference_price_changed' );
		$this->assertCount( 1, $a );
		$this->assertSame( [ 'before' => 1500, 'after' => 1600 ], $a[0][4] );
	}

	/** FR-MENU-009 / spec §3.8: retire ends every offer, keeps the slug and is audited; variants block it. */
	public function test_retire_dish_and_variant_guard(): void {
		$hq   = $this->user( 'momo_head_office' );
		$cat  = $this->category( 'retire' );
		$b1   = $this->branch( 'AAA' );
		$b2   = $this->branch( 'BBB' );
		$base = $this->dish( 'D0400', $cat );
		$var  = $this->dish( 'D0401', $cat );
		wp_update_post( [ 'ID' => $var, 'post_parent' => $base ] );
		$this->row( $b1, $base );
		$this->row( $b2, $base );
		try {
			DishLifecycle::retire( $base, $hq );
			$this->fail( 'Expected variant guard' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'Retire or move the 1 variants first.', $e->getMessage() );
		}
		DishLifecycle::retire( $var, $hq );
		DishLifecycle::retire( $base, $hq );
		$this->assertSame( 'draft', get_post_status( $base ) );
		$this->assertSame( '1', get_post_meta( $base, 'momo_retired', true ) );
		$this->assertSame( 'd0400', get_post( $base )->post_name );
		$this->assertSame( 0, BranchMenuItemRepository::count_offered_rows_for_dish( $base ) );
		$this->assertNotEmpty( $this->audits_of( 'dish.retired' ) );
		$this->assertFalse( MenuQueryService::is_displayable( $base, 'ja' ) );
		$this->assertNotNull( DishLifecycle::delete_block_reason( $base ), 'retired dish is never hard-deleted' );
		$mm = $this->user( 'momo_menu_manager' );
		$this->expectException( \RuntimeException::class );
		DishLifecycle::retire( $var, $mm ); // MM without publish cap.
	}

	/** AC-MENU-03-05: category with dishes cannot be deleted; published dish cannot be trashed. */
	public function test_delete_guards(): void {
		$cat = $this->category( 'guarded' );
		$id  = $this->dish( 'D0500', $cat );
		wp_set_current_user( $this->user( 'momo_head_office' ) );
		$this->assertFalse( current_user_can( 'delete_term', $cat ) );
		$this->assertSame( 'This category contains 1 dishes. Move them before deleting.', CategoryGuard::delete_block_reason( $cat ) );
		$this->assertFalse( current_user_can( 'delete_post', $id ) );
		$this->assertFalse( wp_trash_post( $id ) );
		$this->assertSame( 'publish', get_post_status( $id ) );
	}

	public function test_categories_are_top_level_and_slug_validated(): void {
		$parent = $this->category( 'parent' );
		$this->assertWPError( wp_insert_term( 'Child', ContentTypes::CATEGORY, [ 'slug' => 'child', 'parent' => $parent ] ) );
		$child = $this->category( 'child' );
		wp_update_term( $child, ContentTypes::CATEGORY, [ 'parent' => $parent ] );
		$this->assertSame( 0, get_term( $child )->parent );
		$bad = wp_insert_term( 'カレー', ContentTypes::CATEGORY );
		$this->assertWPError( $bad, 'Japanese-only name without a Latin slug is refused' );
	}

	/** AC-MENU-01-01 / BR-MENU-02: branch override, not offered, reference price, "prices may differ". */
	public function test_price_resolver(): void {
		$cat = $this->category( 'price' );
		$a   = $this->branch( 'PRA' );
		$b   = $this->branch( 'PRB' );
		$x   = $this->dish( 'D0600', $cat, 1500 );
		$this->row( $a, $x, [ 'price_override_jpy' => 1600 ] );
		$this->assertSame( 1600, PriceResolver::price( $x, $a ) );
		$this->assertNull( PriceResolver::price( $x, $b ) );
		$this->assertSame( 1500, PriceResolver::price( $x ) );
		$this->assertTrue( PriceResolver::prices_may_differ( $x ) );
		$this->assertSame( [ 'PRA' => 1600 ], PriceResolver::prices_by_branch( $x ) );
		$this->assertSame( 'single', PriceResolver::summary( $x )['mode'] );
		$this->row( $b, $x );
		$this->assertSame( 'table', PriceResolver::summary( $x )['mode'] );
		$y = $this->dish( 'D0601', $cat, 900 );
		$this->assertSame( 'none', PriceResolver::summary( $y )['mode'] );
		$this->assertFalse( PriceResolver::prices_may_differ( $y ) );
	}

	/** TC-MENU-003: ¥1,200 / ¥1,350, translated tax label, Western digits, bidi isolation. */
	public function test_price_formatter(): void {
		$this->assertSame( '<bdi dir="ltr">¥1,200</bdi>（税込）', PriceFormatter::html( 1200, 'ja' ) );
		$this->assertSame( '¥1,350 (tax incl.)', PriceFormatter::text( 1350, 'en' ) );
		$this->assertStringStartsWith( '<bdi dir="ltr">¥1,350</bdi>', PriceFormatter::html( 1350, 'ar' ) );
	}

	/** BR-MENU-05 / AC-MENU-01-04 / TC-DISH-005: no language fallback; categories need a displayable dish. */
	public function test_language_gate_and_category_listing(): void {
		$cat = $this->category( 'mains' );
		$d   = $this->dish( 'D0700', $cat );
		$this->assertTrue( MenuQueryService::is_displayable( $d, 'ja' ) );
		$this->assertFalse( MenuQueryService::is_displayable( $d, 'hi' ) );
		$this->assertSame( [], MenuQueryService::categories( 'hi' ) );
		$this->assertSame( [ $cat ], array_map( static fn( $t ) => $t->term_id, MenuQueryService::categories( 'ja' ) ) );
		Translations::save( 'post', $d, 'hi', [ 'title' => 'बिरयानी' ], 'approved' );
		$this->assertSame( [ $d ], MenuQueryService::dishes_in_category( $cat, 'hi' ) );
		$this->assertSame( [], MenuQueryService::categories( 'hi' ), 'category name not approved in hi' );
		Translations::save( 'term', $cat, 'hi', [ 'name' => 'मुख्य व्यंजन' ], 'approved' );
		$this->assertCount( 1, MenuQueryService::categories( 'hi' ) );
	}

	/**
	 * BR-CORP-03 / content-strings §10.1 D2: sample categories (term meta `momo_is_sample`) and sample dishes are
	 * never public in production — not listed by categories(), the term archive is a 404, a sample dish is not
	 * displayable and cannot be published. Production is simulated with `momos/menu/hide_samples` (the test
	 * bootstrap fixes MOMOS_ENV=development); outside production samples stay visible for demos.
	 */
	public function test_sample_categories_and_dishes_are_not_public_in_production(): void {
		$real   = $this->category( 'mains' );
		$sample = $this->category( 'sample-cat' );
		update_term_meta( $sample, 'momo_is_sample', '1' );
		$this->dish( 'D0710', $real );
		$demo   = $this->dish( 'D0711', $sample, 1500, [ 'momo_is_sample' => '1' ] );
		$listed = static fn(): array => array_map( static fn( $t ) => (int) $t->term_id, MenuQueryService::categories( 'ja' ) );

		$this->assertFalse( MenuQueryService::hides_samples(), 'development: samples visible' );
		$this->assertEqualsCanonicalizing( [ $real, $sample ], $listed() );
		$this->assertTrue( MenuQueryService::is_displayable( $demo, 'ja' ) );
		$this->assertFalse( $this->category_archive_is_404( 'sample-cat' ) );

		add_filter( 'momos/menu/hide_samples', '__return_true' );
		try {
			$this->assertTrue( MenuQueryService::hides_samples() );
			$this->assertSame( [ $real ], $listed(), 'the sample category is not listed' );
			$this->assertFalse( MenuQueryService::is_category_public( get_term( $sample ), 'ja' ) );
			$this->assertTrue( MenuQueryService::is_category_public( get_term( $real ), 'ja' ) );
			$this->assertFalse( MenuQueryService::is_displayable( $demo, 'ja' ), 'sample dish hidden' );
			$this->assertTrue( $this->category_archive_is_404( 'sample-cat' ), 'sample term archive → 404' );
			$this->assertFalse( $this->category_archive_is_404( 'mains' ), 'real category archive still served' );

			$draft = self::factory()->post->create( [ 'post_type' => ContentTypes::DISH, 'post_title' => '見本', 'post_status' => 'draft' ] );
			wp_set_object_terms( $draft, [ $real ], ContentTypes::CATEGORY );
			foreach ( [ 'momo_short_description' => '説明', 'momo_dish_sku' => 'D0712', 'momo_primary_category' => (string) $real, 'momo_reference_price_jpy' => '900', 'momo_spice_level' => '0', 'momo_is_sample' => '1' ] as $k => $v ) {
				update_post_meta( $draft, $k, $v );
			}
			set_post_thumbnail( $draft, $this->image() );
			$this->assertContains( 'Sample dishes cannot be published on the live site', DishPublishValidator::errors_for_post( $draft ) );
			wp_update_post( [ 'ID' => $draft, 'post_status' => 'publish' ] );
			$this->assertSame( 'draft', get_post_status( $draft ), 'publish refused for a sample dish' );
		} finally {
			remove_filter( 'momos/menu/hide_samples', '__return_true' );
		}
		add_filter( 'momos/menu/hide_samples', '__return_false' );
		$this->assertSame( \Momos\Kernel\Env::is_production(), MenuQueryService::hides_samples(), 'the filter cannot lift the production rule' );
		remove_filter( 'momos/menu/hide_samples', '__return_false' );
	}

	/** Runs the menu term-archive routing (MenuRewrite::template_redirect) for /menu/{slug}/ and reports a 404. */
	private function category_archive_is_404( string $slug ): bool {
		$this->go_to( add_query_arg( ContentTypes::CATEGORY, $slug, home_url( '/' ) ) );
		MenuRewrite::template_redirect();
		return is_404();
	}

	/**
	 * FR-DIET-007 (Doc 04 §16.5): the dish page's "Key ingredients (not a complete list)" block lists the dish's
	 * `momo_ingredient` terms — private taxonomy (no archive/REST), names in the page language with no
	 * mixed-language fallback (DEC-027), an ingredient class per term, and the heading string in all six catalogs.
	 */
	public function test_key_ingredients_block_data(): void {
		$tax = get_taxonomy( ContentTypes::INGREDIENT );
		$this->assertFalse( $tax->public, 'ingredients have no public archive' );
		$this->assertFalse( $tax->rewrite );
		$this->assertFalse( $tax->show_in_rest );
		$this->assertSame( 'Key ingredients', $tax->labels->name );

		$cat     = $this->category( 'curry-ing' );
		$dish    = $this->dish( 'D0720', $cat );
		$chicken = (int) wp_insert_term( '鶏肉', ContentTypes::INGREDIENT )['term_id'];
		$cashew  = (int) wp_insert_term( 'カシューナッツ', ContentTypes::INGREDIENT )['term_id'];
		update_term_meta( $chicken, 'momo_ing_class', 'poultry' );
		wp_set_object_terms( $dish, [ $chicken, $cashew ], ContentTypes::INGREDIENT );
		$this->assertArrayHasKey( 'poultry', \Momos\Menu\Domain\IngredientClass::choices() );
		$this->assertSame( 'poultry', (string) get_term_meta( $chicken, 'momo_ing_class', true ) );

		$names = static fn( string $lang ): array => \Momos\Presentation\View\MenuView::dish( $dish, null, $lang )['ingredients'] ?? null;
		$ja    = $names( 'ja' );
		sort( $ja );
		$expected = [ '鶏肉', 'カシューナッツ' ];
		sort( $expected );
		$this->assertSame( $expected, $ja, 'Japanese source names' );

		Translations::save( 'post', $dish, 'en', [ 'title' => 'Chicken curry' ], 'approved' );
		Translations::save( 'term', $chicken, 'en', [ 'name' => 'Chicken' ], 'approved' );
		Translations::save( 'term', $cashew, 'en', [ 'name' => 'Cashew nuts' ], 'draft' );
		$this->assertSame( [ 'Chicken' ], $names( 'en' ), 'only approved names; no Japanese fallback for the draft one' );

		wp_set_object_terms( $dish, [], ContentTypes::INGREDIENT );
		$this->assertSame( [], $names( 'ja' ), 'no terms → empty list → the template omits the block' );

		$this->assertSame( 'Key ingredients (not a complete list)', momos_t( 'ingredients.heading', [], 'en' ) );
		foreach ( [ 'ja', 'en', 'ar', 'ur', 'hi', 'bn' ] as $lang ) {
			$this->assertArrayHasKey( 'ingredients.heading', \Momos\Kernel\I18n\Strings::catalog( $lang ), $lang );
		}
	}

	/** FR-MENU-001: branch context lists only offered dishes; invalid/TEST/closed codes ignored (AC-MENU-01-05). */
	public function test_branch_context_and_selector(): void {
		$cat  = $this->category( 'ctx' );
		$a    = $this->branch( 'CTA' );
		$t    = $this->branch( 'TST', [ 'test' => true ] );
		$tc   = $this->branch( 'CTC', [ 'operating_status' => 'temporarily_closed' ] );
		$x    = $this->dish( 'D0800', $cat );
		$y    = $this->dish( 'D0801', $cat );
		$this->row( $a, $x );
		$this->assertSame( [ $x ], MenuQueryService::dishes_in_category( $cat, 'ja', $a ) );
		$this->assertEqualsCanonicalizing( [ $x, $y ], MenuQueryService::dishes_in_category( $cat, 'ja' ) );
		$this->assertSame( $a, MenuQueryService::branch_context( 'CTA' ) );
		$this->assertNull( MenuQueryService::branch_context( 'ZZZ' ) );
		$this->assertNull( MenuQueryService::branch_context( 'TST' ) );
		$this->assertSame( $tc, MenuQueryService::branch_context( 'CTC' ) );
		$codes = array_column( MenuQueryService::selector_branches( 'ja' ), 'code' );
		$this->assertContains( 'CTA', $codes );
		$this->assertNotContains( 'TST', $codes );
		$this->assertNotContains( 'CTC', $codes );
		$this->assertNotNull( MenuQueryService::branch_offers_dish( $a, $x ) );
		$this->assertNull( MenuQueryService::branch_offers_dish( $a, $y ) );
		$this->assertGreaterThan( 0, $t );
	}

	public function test_featured_requires_offer_at_public_branch(): void {
		$cat = $this->category( 'feat' );
		$a   = $this->branch( 'FEA' );
		$f1  = $this->dish( 'D0900', $cat, 1000, [ 'momo_featured' => '1' ] );
		$f2  = $this->dish( 'D0901', $cat, 1000, [ 'momo_featured' => '1' ] );
		$this->row( $a, $f1 );
		$this->assertSame( [ $f1 ], MenuQueryService::featured( 'ja' ) );
		$this->assertNotContains( $f2, MenuQueryService::featured( 'ja' ) );
	}

	/** AC-MENU-01-03 / spec §3.4: render-time availability labels. */
	public function test_availability_labels(): void {
		$this->freeze( '2026-12-01 20:00:00' );
		$row = [ 'is_offered' => 1, 'availability' => 'sold_out', 'sold_out_until' => '2026-12-01 13:00:00', 'availability_reason' => 'sold_out_today' ];
		$this->assertSame( 'Sold out today', AvailabilityLabel::for_row( $row, 'en' )['label'] );
		$row['sold_out_until'] = '2026-12-01 10:00:00'; // 19:00 JST, passed.
		$this->assertSame( 'available', AvailabilityLabel::for_row( $row, 'en' )['code'] );
		$u = AvailabilityLabel::for_row( [ 'is_offered' => 1, 'availability' => 'unavailable', 'sold_out_until' => null, 'availability_reason' => 'seasonal' ], 'en' );
		$this->assertSame( [ 'Not available at this branch now', 'Seasonal' ], [ $u['label'], $u['reason_label'] ] );
		$this->assertNull( AvailabilityLabel::for_row( [ 'is_offered' => 1, 'availability' => 'unavailable', 'sold_out_until' => null, 'availability_reason' => 'other' ], 'en' )['reason_label'] );
		$this->assertSame( 'not_offered', AvailabilityLabel::for_row( null, 'ja' )['code'] );
	}

	/** Spec §3.1: canonical URLs under the primary category. */
	public function test_permalinks(): void {
		$cat = $this->category( 'biryani' );
		$d   = $this->dish( 'D1000', $cat );
		$home = untrailingslashit( get_option( 'home' ) );
		$this->assertSame( $home . '/menu/biryani/d1000/', get_permalink( $d ) );
		$this->assertSame( $home . '/menu/biryani/', get_term_link( $cat ) );
		$this->assertSame( '/menu/biryani/d1000/', MenuRewrite::dish_path( get_post( $d ) ) );
		global $wp_rewrite;
		$this->assertArrayHasKey( '^menu/([^/]+)/([^/]+)/?$', $wp_rewrite->extra_rules_top );
		$this->assertSame( 'index.php?momo_menu_category=$matches[1]', $wp_rewrite->extra_rules_top['^menu/([^/]+)/?$'] );
	}

	/** AC-MENU-05-01/02: AND tags, max spice, conservative allergen exclusion. */
	public function test_filters(): void {
		$cat  = $this->category( 'flt' );
		$veg  = wp_insert_term( 'ベジタリアン', ContentTypes::TAG, [ 'slug' => 'vegetarian' ] );
		update_term_meta( $veg['term_id'], 'momo_tag_code', 'vegetarian' );
		$mild = $this->dish( 'D1100', $cat, 1000, [ 'momo_spice_level' => '1' ] );
		$hot  = $this->dish( 'D1101', $cat, 1000, [ 'momo_spice_level' => '3' ] );
		$meat = $this->dish( 'D1102', $cat, 1000, [ 'momo_spice_level' => '0' ] );
		wp_set_object_terms( $mild, [ $veg['term_id'] ], ContentTypes::TAG );
		wp_set_object_terms( $hot, [ $veg['term_id'] ], ContentTypes::TAG );
		$all = [ $mild, $hot, $meat ];
		$f   = MenuQueryService::parse_filters( [ 'diet' => [ 'vegetarian' ], 'max_spice' => '1' ] );
		$this->assertSame( [ $mild ], MenuQueryService::apply_filters( $all, $f, 'ja' ) );
		// Peanut not_verified everywhere (default) → excluded by the peanut filter.
		$f = MenuQueryService::parse_filters( [ 'exclude' => [ 'peanut' ] ] );
		$this->assertSame( [], MenuQueryService::apply_filters( $all, $f, 'ja' ) );
		$card = MenuQueryService::card( $mild, 'ja' );
		$this->assertSame( 'vegetarian', $card['data']['data-tags'] );
		$this->assertStringContainsString( 'peanut', $card['data']['data-alg'] );
		$this->assertSame( 'Mild', MenuQueryService::card( $mild, 'en' )['spice_label'] );
	}
}
