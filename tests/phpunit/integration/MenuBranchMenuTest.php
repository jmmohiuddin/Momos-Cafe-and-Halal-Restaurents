<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/MenuTest.php';

use Momos\Kernel\Events\AfterCommit;
use Momos\Menu\Job\SoldOutResetJob;
use Momos\Menu\Repository\BranchMenuItemRepository;
use Momos\Menu\Service\BranchMenuService;
use Momos\Menu\Service\SoldOutPolicy;
use Momos\Menu\Support\MenuItemException;
use Momos\Tests\TestCase;

/**
 * SCR-A07 / API-14/15 branch menu items: M10 field-level caps, branch scope, optimistic locking,
 * sold-out policy and auto-reset (AC-MENU-04-01..06, TC-MENU-005/006, TC-ACL-005, TC-WP-004).
 */
final class MenuBranchMenuTest extends TestCase {
	use MenuDietaryFixtures;

	private int $a;
	private int $b;
	private int $dish;

	public function set_up(): void {
		parent::set_up();
		$this->fixtures_up();
		$this->a    = $this->branch( 'BRA' );
		$this->b    = $this->branch( 'BRB' );
		$this->dish = $this->dish( 'D2000', $this->category( 'grid' ), 1500 );
	}

	public function tear_down(): void {
		remove_all_filters( 'momos/menu/branch_periods' );
		$this->fixtures_down();
		parent::tear_down();
	}

	private function expect_error( string $code, callable $fn ): MenuItemException {
		try {
			$fn();
		} catch ( MenuItemException $e ) {
			$this->assertSame( $code, $e->error_code, $e->getMessage() . ' ' . wp_json_encode( $e->fields ) );
			return $e;
		}
		$this->fail( 'Expected ' . $code );
	}

	public function test_menu_manager_creates_and_updates_row_with_versions(): void {
		$mm  = $this->user( 'momo_menu_manager' );
		$row = BranchMenuService::update( $this->a, $this->dish, [ 'version' => 0, 'offered' => true ], $mm );
		$this->assertTrue( $row['offered'] );
		$this->assertSame( 1, $row['version'] );
		$this->assertSame( 1500, $row['effective_price_jpy'] );
		$row = BranchMenuService::update( $this->a, $this->dish, [ 'version' => 1, 'price_override_jpy' => 1700 ], $mm );
		$this->assertSame( 2, $row['version'] );
		$this->assertSame( 1700, $row['effective_price_jpy'] );
		$this->assertNull( BranchMenuItemRepository::find( $this->b, $this->dish ), 'override only at A (AC-MENU-04-05)' );
		$this->assertCount( 1, $this->audits_of( 'menu_item.offered_changed' ) );
		$price = $this->audits_of( 'menu_item.price_changed' );
		$this->assertSame( [ null, 1700 ], [ $price[0][4]['before'], $price[0][4]['after'] ] );
		$fired = [];
		add_action( 'momos/branch_menu_item/updated', static function ( $b, $d ) use ( &$fired ) { $fired[] = [ $b, $d ]; }, 10, 2 );
		AfterCommit::flush();
		$this->assertContains( [ $this->a, $this->dish ], $fired );
	}

	/** AC-MENU-04-03: Branch Manager price change → forbidden_field, nothing written, audited. */
	public function test_branch_manager_cannot_change_price_or_offered(): void {
		$this->row( $this->a, $this->dish );
		$bm = $this->user( 'momo_branch_manager', [ $this->a ] );
		$e  = $this->expect_error( 'forbidden_field', fn() => BranchMenuService::update( $this->a, $this->dish, [ 'version' => 1, 'price_override_jpy' => 1200 ], $bm ) );
		$this->assertSame( 403, $e->status );
		$this->assertSame( [ 'price_override_jpy' => 'forbidden' ], $e->fields );
		$this->expect_error( 'forbidden_field', fn() => BranchMenuService::update( $this->a, $this->dish, [ 'version' => 1, 'offered' => false ], $bm ) );
		$this->assertNull( BranchMenuItemRepository::find( $this->a, $this->dish )['price_override_jpy'] );
		$this->assertNotEmpty( $this->audits_of( 'access.denied' ) );
		// Same value = no change = allowed no-op.
		$this->assertSame( 1, BranchMenuService::update( $this->a, $this->dish, [ 'version' => 1, 'offered' => true ], $bm )['version'] );
	}

	/** AC-MENU-04-06 / TC-ACL-005: staff of A cannot touch B. */
	public function test_branch_scope(): void {
		$this->row( $this->b, $this->dish );
		$rs = $this->user( 'momo_reservation_staff', [ $this->a ] );
		$e  = $this->expect_error( 'forbidden_branch', fn() => BranchMenuService::update( $this->b, $this->dish, [ 'version' => 1, 'availability' => 'sold_out' ], $rs ) );
		$this->assertSame( 403, $e->status );
		$this->assertSame( 'available', BranchMenuItemRepository::find( $this->b, $this->dish )['availability'] );
	}

	/** Spec §11 C6: BM/RS cannot create rows (cannot offer a dish via availability). */
	public function test_staff_cannot_create_rows(): void {
		$bm = $this->user( 'momo_branch_manager', [ $this->a ] );
		$this->expect_error( 'not_found', fn() => BranchMenuService::update( $this->a, $this->dish, [ 'version' => 0, 'availability' => 'sold_out' ], $bm ) );
		$this->expect_error( 'forbidden_field', fn() => BranchMenuService::update( $this->a, $this->dish, [ 'version' => 0, 'offered' => true ], $bm ) );
		$this->assertNull( BranchMenuItemRepository::find( $this->a, $this->dish ) );
	}

	/** AC-MENU-04-01: RS marks sold out → default = branch close today (JST); cross-midnight close. */
	public function test_sold_out_default_until_branch_close(): void {
		$this->freeze( '2026-12-01 20:00:00' );
		add_filter( 'momos/menu/branch_periods', static fn( $p, $b, $date ) => [ [ 'period_no' => 1, 'opens_at' => '11:00', 'closes_at' => '22:00', 'closes_next_day' => false ] ], 10, 3 );
		$this->row( $this->a, $this->dish );
		$rs  = $this->user( 'momo_reservation_staff', [ $this->a ] );
		$row = BranchMenuService::update( $this->a, $this->dish, [ 'version' => 1, 'availability' => 'sold_out' ], $rs );
		$this->assertSame( '2026-12-01T22:00:00+09:00', $row['sold_out_until'] );
		$this->assertSame( 'sold_out_today', $row['availability_reason'] );
		$this->assertCount( 1, $this->audits_of( 'menu_item.availability_changed' ) );

		remove_all_filters( 'momos/menu/branch_periods' );
		add_filter( 'momos/menu/branch_periods', static fn() => [ [ 'period_no' => 1, 'opens_at' => '18:00', 'closes_at' => '02:00', 'closes_next_day' => true ] ] );
		$until = SoldOutPolicy::default_until( $this->a )->setTimezone( new \DateTimeZone( 'Asia/Tokyo' ) );
		$this->assertSame( '2026-12-02 02:00', $until->format( 'Y-m-d H:i' ) );
		$this->freeze( '2026-12-02 01:00:00' ); // Inside yesterday's overnight period.
		$this->assertSame( '2026-12-02 02:00', SoldOutPolicy::default_until( $this->a )->setTimezone( new \DateTimeZone( 'Asia/Tokyo' ) )->format( 'Y-m-d H:i' ) );
		remove_all_filters( 'momos/menu/branch_periods' );
		$this->assertSame( '2026-12-02 23:59', SoldOutPolicy::default_until( $this->a )->setTimezone( new \DateTimeZone( 'Asia/Tokyo' ) )->format( 'Y-m-d H:i' ), 'no hours → 23:59 JST' );
	}

	public function test_availability_validation(): void {
		$this->freeze( '2026-12-01 12:00:00' );
		$this->row( $this->a, $this->dish );
		$mm = $this->user( 'momo_menu_manager' );
		$e  = $this->expect_error( 'invalid_param', fn() => BranchMenuService::update( $this->a, $this->dish, [ 'version' => 1, 'availability' => 'sold_out', 'sold_out_until' => '2026-12-09T12:00:00+09:00' ], $mm ) );
		$this->assertSame( [ 'sold_out_until' => 'out_of_range' ], $e->fields );
		$this->expect_error( 'invalid_param', fn() => BranchMenuService::update( $this->a, $this->dish, [ 'version' => 1, 'availability' => 'sold_out', 'sold_out_until' => '2026-12-01T11:00:00+09:00' ], $mm ) );
		$e = $this->expect_error( 'invalid_param', fn() => BranchMenuService::update( $this->a, $this->dish, [ 'version' => 1, 'availability' => 'unavailable' ], $mm ) );
		$this->assertSame( [ 'availability_reason' => 'required' ], $e->fields );
		$e = $this->expect_error( 'invalid_param', fn() => BranchMenuService::update( $this->a, $this->dish, [ 'version' => 1, 'colour' => 'red' ], $mm ) );
		$this->assertSame( [ 'colour' => 'not_allowed' ], $e->fields );
		$this->expect_error( 'invalid_param', fn() => BranchMenuService::update( $this->a, $this->dish, [ 'version' => 1, 'price_override_jpy' => 100001 ], $mm ) );
		$row = BranchMenuService::update( $this->a, $this->dish, [ 'version' => 1, 'availability' => 'sold_out', 'sold_out_until' => '2026-12-08 11:00' ], $mm );
		$this->assertSame( '2026-12-08T11:00:00+09:00', $row['sold_out_until'] );
		$row = BranchMenuService::update( $this->a, $this->dish, [ 'version' => 2, 'availability' => 'available' ], $mm );
		$this->assertNull( $row['sold_out_until'] );
		$this->assertNull( $row['availability_reason'] );
	}

	/** AC-MENU-04-04: concurrent save → 409 with the current row. */
	public function test_version_conflict(): void {
		$this->row( $this->a, $this->dish );
		$mm = $this->user( 'momo_menu_manager' );
		BranchMenuService::update( $this->a, $this->dish, [ 'version' => 1, 'price_override_jpy' => 1600 ], $mm );
		$e = $this->expect_error( 'version_conflict', fn() => BranchMenuService::update( $this->a, $this->dish, [ 'version' => 1, 'price_override_jpy' => 1650 ], $mm ) );
		$this->assertSame( 409, $e->status );
		$this->assertSame( 2, $e->data['row']['version'] );
		$this->assertSame( 1600, $e->data['row']['price_override_jpy'] );
		$this->expect_error( 'version_conflict', fn() => BranchMenuService::update( $this->a, $this->dish, [ 'version' => 0, 'offered' => true ], $mm ) );
	}

	public function test_large_price_change_and_variant_overlap_need_confirmation(): void {
		$this->row( $this->a, $this->dish );
		$mm = $this->user( 'momo_menu_manager' );
		$e  = $this->expect_error( 'invalid_param', fn() => BranchMenuService::update( $this->a, $this->dish, [ 'version' => 1, 'price_override_jpy' => 2500 ], $mm ) );
		$this->assertSame( [ 'confirm_large_change' => 'required' ], $e->fields );
		$this->assertSame( 2500, BranchMenuService::update( $this->a, $this->dish, [ 'version' => 1, 'price_override_jpy' => 2500, 'confirm_large_change' => true ], $mm )['effective_price_jpy'] );

		$variant = $this->dish( 'D2001', $this->category( 'grid2' ) );
		wp_update_post( [ 'ID' => $variant, 'post_parent' => $this->dish ] );
		$e = $this->expect_error( 'invalid_param', fn() => BranchMenuService::update( $this->a, $variant, [ 'version' => 0, 'offered' => true ], $mm ) );
		$this->assertSame( [ 'offered' => 'not_allowed' ], $e->fields );
		$this->assertTrue( BranchMenuService::update( $this->a, $variant, [ 'version' => 0, 'offered' => true, 'confirm_variant_overlap' => true ], $mm )['offered'] );
	}

	/** AC-MENU-04-02 / TC-WP-004: job at 22:45 resets the 22:30 item, actor system, idempotent. */
	public function test_sold_out_reset_job(): void {
		$this->freeze( '2026-12-01 22:45:00' );
		$id    = $this->row( $this->a, $this->dish, [ 'availability' => 'sold_out', 'sold_out_until' => '2026-12-01 13:30:00', 'availability_reason' => 'sold_out_today' ] ); // 22:30 JST.
		$other = $this->dish( 'D2002', $this->category( 'grid3' ) );
		$this->row( $this->a, $other, [ 'availability' => 'sold_out', 'sold_out_until' => '2026-12-01 14:00:00' ] ); // 23:00 JST.
		$this->assertSame( 1, SoldOutResetJob::run() );
		$row = BranchMenuItemRepository::find_by_id( $id );
		$this->assertSame( [ 'available', null, null, 2, null ], [ $row['availability'], $row['sold_out_until'], $row['availability_reason'], $row['version'], $row['updated_by'] ] );
		$this->assertSame( 'sold_out', BranchMenuItemRepository::find( $this->a, $other )['availability'] );
		$audit = $this->audits_of( 'menu_item.auto_reset' );
		$this->assertCount( 1, $audit );
		$this->assertSame( 'system', $audit[0][5] );
		$this->assertSame( 0, SoldOutResetJob::run(), 'idempotent' );
	}

	/** API-14/15 through the REST server (TC-ACL-005). */
	public function test_rest_routes(): void {
		$this->row( $this->a, $this->dish );
		$bm = $this->user( 'momo_branch_manager', [ $this->a ] );
		wp_set_current_user( $bm );
		$req = new \WP_REST_Request( 'PATCH', '/momos/v1/staff/branches/BRA/menu-items/' . $this->dish );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( wp_json_encode( [ 'version' => 1, 'price_override_jpy' => 1200 ] ) );
		$res = rest_do_request( $req );
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 'forbidden_field', $res->as_error()->get_error_code() );

		$req->set_body( wp_json_encode( [ 'version' => 1, 'availability' => 'sold_out', 'availability_reason' => 'sold_out_today' ] ) );
		$res = rest_do_request( $req );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 2, $res->get_data()['row']['version'] );

		$get = rest_do_request( new \WP_REST_Request( 'GET', '/momos/v1/staff/branches/BRB/menu-items' ) );
		$this->assertSame( 'forbidden_branch', $get->as_error()->get_error_code() );
		$get = rest_do_request( new \WP_REST_Request( 'GET', '/momos/v1/staff/branches/BRA/menu-items' ) );
		$this->assertSame( 200, $get->get_status() );
		$data = $get->get_data();
		$this->assertSame( [ 'offered' => false, 'price' => false, 'availability' => true ], $data['permissions'] );
		$this->assertSame( 'D2000', $data['items'][0]['dish_sku'] );
		$this->assertSame( 'sold_out', $data['items'][0]['row']['availability'] );

		wp_set_current_user( $this->user( 'momo_content_editor' ) );
		$this->assertSame( 403, rest_do_request( new \WP_REST_Request( 'GET', '/momos/v1/staff/branches/BRA/menu-items' ) )->get_status() );
		wp_set_current_user( 0 );
		$this->assertSame( 401, rest_do_request( new \WP_REST_Request( 'GET', '/momos/v1/staff/branches/BRA/menu-items' ) )->get_status() );
	}
}
