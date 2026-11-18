<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/BranchTestCaseTest.php';

use Momos\Branch\Admin\HoursFormHandler;
use Momos\Branch\Domain\AccessContext;
use Momos\Branch\Domain\UnscopedQueryException;
use Momos\Branch\Repository\ClosureRepository;
use Momos\Branch\Repository\HoursRepository;
use Momos\Branch\Service\ClosureImpactService;
use Momos\Kernel\Db\Tables;

/**
 * Closures/special hours, SCR-A04 save flow and closure impact (FR-BR-007/008/010, BR-RES-13):
 * TC-BR-010, TC-BR-011, TC-BR-013, TC-ACL-009, AC-BR-04-03/05/07.
 */
final class BranchClosureTest extends BranchTestCaseTest {
	private int $branch;
	private int $bm;

	public function set_up(): void {
		parent::set_up();
		$this->freeze( '2026-12-01 10:00:00' );
		$this->branch = $this->make_branch( 'QAA' );
		$this->set_week( $this->branch, [ 1 => [ [ '17:00', '23:00', '22:00' ] ], 2 => [ [ '17:00', '23:00', '22:00' ] ], 3 => [ [ '17:00', '23:00', '22:00' ] ], 4 => [ [ '17:00', '01:00', '00:30' ] ], 5 => [ [ '17:00', '23:00', '22:00' ] ], 6 => [ [ '17:00', '23:00', '22:00' ] ], 7 => [ [ '17:00', '23:00', '22:00' ] ] ] );
		$this->bm = $this->user_with_role( 'momo_branch_manager', [ $this->branch ] );
		wp_set_current_user( $this->bm );
		$this->events = [];
		$this->audits = [];
	}

	public function test_all_day_closure_flags_three_confirmed_without_status_change_ac_br_04_03(): void {
		global $wpdb;
		$ids = [
			$this->make_reservation( $this->branch, '2026-12-31', '18:00' ),
			$this->make_reservation( $this->branch, '2026-12-31', '19:00' ),
			$this->make_reservation( $this->branch, '2026-12-31', '20:30', 'pending' ),
			$this->make_reservation( $this->branch, '2027-01-01', '00:15' ), // After-midnight slot of 31 Dec.
		];
		$this->make_reservation( $this->branch, '2026-12-30', '19:00' ); // Unaffected.
		$input  = [ 'date_from' => '2026-12-31', 'type' => 'closed_all_day', 'public_reason' => 'public_holiday', 'internal_note' => 'Year end' ];
		$result = HoursFormHandler::process_closure( $this->branch, $input, $this->bm );
		$this->assertSame( 'impact', $result['status'] );
		$this->assertCount( 4, $result['affected'] );
		$this->assertArrayNotHasKey( 'guest_name', $result['affected'][0], 'no PII in the impact list' );
		$this->assertSame( [], ClosureRepository::rows_on( $this->branch, '2026-12-31' ), 'nothing saved before confirmation' );

		$result = HoursFormHandler::process_closure( $this->branch, $input + [ 'confirm' => '1' ], $this->bm );
		$this->assertSame( 'saved', $result['status'] );
		$this->assertCount( 1, ClosureRepository::rows_on( $this->branch, '2026-12-31' ) );
		$ev = $this->events_of( 'momos/branch/schedule_changed' );
		$this->assertSame( [ 'momos/branch/schedule_changed', $this->branch, [ '2026-12-31' ] ], end( $ev ) );
		$statuses = $wpdb->get_col( 'SELECT status FROM ' . Tables::reservations() . ' WHERE id IN (' . implode( ',', $ids ) . ') ORDER BY id' ); // phpcs:ignore
		$this->assertSame( [ 'confirmed', 'confirmed', 'pending', 'confirmed' ], $statuses, 'never auto-cancelled (BR-RES-13)' );
		$this->assertContains( 'branch.closure_created', $this->audit_actions() );
		$audit = $this->audits[ array_search( 'branch.closure_created', $this->audit_actions(), true ) ];
		$this->assertStringNotContainsString( 'Year end', (string) wp_json_encode( $audit ), 'internal note text is not copied into audit' );
	}

	public function test_range_creates_one_record_per_date_ac_br_04_07(): void {
		$result = HoursFormHandler::process_closure( $this->branch, [ 'date_from' => '2026-12-31', 'date_to' => '2027-01-03', 'type' => 'closed_all_day', 'public_reason' => 'public_holiday' ], $this->bm );
		$this->assertSame( 'saved', $result['status'] );
		$this->assertCount( 4, ClosureRepository::group_by_date( ClosureRepository::rows_between( $this->branch, '2026-12-31', '2027-01-03' ) ) );
	}

	public function test_range_limit_past_dates_and_existing_entries(): void {
		$r = HoursFormHandler::process_closure( $this->branch, [ 'date_from' => '2026-12-01', 'date_to' => '2027-01-05', 'type' => 'closed_all_day', 'public_reason' => 'other' ], $this->bm );
		$this->assertSame( 'A closure range can cover at most 31 days. Use temporary closure for longer periods.', $r['errors']['date'] );
		$r = HoursFormHandler::process_closure( $this->branch, [ 'date_from' => '2026-11-30', 'type' => 'closed_all_day', 'public_reason' => 'other' ], $this->bm );
		$this->assertSame( 'You cannot change past dates.', $r['errors']['date'] );
		HoursFormHandler::process_closure( $this->branch, [ 'date_from' => '2026-12-10', 'type' => 'closed_all_day', 'public_reason' => 'other' ], $this->bm );
		$r = HoursFormHandler::process_closure( $this->branch, [ 'date_from' => '2026-12-09', 'date_to' => '2026-12-11', 'type' => 'closed_all_day', 'public_reason' => 'other' ], $this->bm );
		$this->assertArrayHasKey( 'replace_existing', $r['errors'] );
		$r = HoursFormHandler::process_closure( $this->branch, [ 'date_from' => '2026-12-09', 'date_to' => '2026-12-11', 'type' => 'closed_all_day', 'public_reason' => 'other', 'replace_existing' => '1' ], $this->bm );
		$this->assertSame( 'saved', $r['status'] );
		$this->expectException( \InvalidArgumentException::class );
		ClosureRepository::delete_date( $this->branch, '2026-11-30', AccessContext::user( $this->bm ) );
	}

	public function test_special_hours_validation_and_save(): void {
		$r = HoursFormHandler::process_closure( $this->branch, [ 'date_from' => '2026-12-24', 'type' => 'special_hours', 'public_reason' => 'other', 'periods' => [] ], $this->bm );
		$this->assertArrayHasKey( 's', $r['errors'] );
		$r = HoursFormHandler::process_closure( $this->branch, [ 'date_from' => '2026-12-24', 'type' => 'special_hours', 'public_reason' => 'other', 'periods' => [ [ 'label' => 'dinner', 'opens' => '12:00', 'closes' => '16:00', 'last_order' => '15:00' ] ] ], $this->bm );
		$this->assertSame( 'saved', $r['status'] );
		$this->assertSame( '12:00', \Momos\Branch\Service\ScheduleService::periods_on( $this->branch, '2026-12-24' )[0]['opens_at'] );
		$this->assertTrue( ClosureRepository::delete_date( $this->branch, '2026-12-24', AccessContext::user( $this->bm ) ) );
		$this->assertContains( 'branch.closure_deleted', $this->audit_actions() );
	}

	public function test_weekly_save_flow_with_impact_and_set_replacement(): void {
		$this->make_reservation( $this->branch, '2026-12-07', '18:00' ); // Monday.
		$input = [ 'set' => 'default', 'periods' => [ 1 => [ [ 'label' => 'lunch', 'opens' => '11:00', 'closes' => '15:00', 'last_order' => '14:00' ] ] ] ];
		$r     = HoursFormHandler::process_hours( $this->branch, $input, $this->bm );
		$this->assertSame( 'impact', $r['status'] );
		$this->assertCount( 1, $r['affected'] );
		$r = HoursFormHandler::process_hours( $this->branch, $input + [ 'confirm' => '1' ], $this->bm );
		$this->assertSame( 'saved', $r['status'] );
		$this->assertCount( 1, HoursRepository::all_rows( $this->branch ), 'whole week replaced (other weekdays now closed)' );
		$this->assertContains( 'branch.hours_updated', $this->audit_actions() );
		// Overlap → nothing saved (AC-BR-04-02).
		$bad = HoursFormHandler::process_hours( $this->branch, [ 'set' => 'default', 'periods' => [ 1 => [ [ 'opens' => '11:00', 'closes' => '15:00' ], [ 'opens' => '14:00', 'closes' => '18:00' ] ] ] ], $this->bm );
		$this->assertSame( 'errors', $bad['status'] );
		$this->assertSame( '11:00', HoursRepository::all_rows( $this->branch )[0]['opens_at'] );
	}

	public function test_dated_set_edit_moves_the_set(): void {
		$p = [ 1 => [ [ 'opens' => '12:00', 'closes' => '20:00', 'last_order' => '19:00' ] ] ];
		HoursFormHandler::process_hours( $this->branch, [ 'set' => 'new', 'valid_from' => '2026-12-14', 'valid_until' => '2026-12-31', 'periods' => $p, 'confirm' => '1' ], $this->bm );
		$this->assertCount( 2, HoursRepository::sets( $this->branch ) );
		$r = HoursFormHandler::process_hours( $this->branch, [ 'set' => '2026-12-14_2026-12-31', 'valid_from' => '2026-12-15', 'valid_until' => '', 'periods' => $p, 'confirm' => '1' ], $this->bm );
		$this->assertSame( 'saved', $r['status'] );
		$sets = HoursRepository::sets( $this->branch );
		$this->assertCount( 2, $sets );
		$this->assertSame( [ 'valid_from' => '2026-12-15', 'valid_until' => null ], $sets[1] );
	}

	public function test_bm_cannot_write_other_branch_ac_br_04_05(): void {
		$other = $this->make_branch( 'QAB' );
		$this->assertFalse( \Momos\Branch\Service\BranchAccess::can_edit( $other, 'hours', $this->bm ) );
		$this->assertTrue( \Momos\Branch\Service\BranchAccess::can_edit( $this->branch, 'hours', $this->bm ) );
		try {
			ClosureRepository::replace_dates( $other, [ '2026-12-31' ], 'closed_all_day', [], 'other', '', AccessContext::user( $this->bm ) );
			$this->fail( 'Expected UnscopedQueryException' );
		} catch ( UnscopedQueryException $e ) {
			$this->assertSame( [], ClosureRepository::rows_on( $other, '2026-12-31' ) );
		}
		$this->expectException( UnscopedQueryException::class );
		HoursRepository::replace_week( $other, null, null, [], AccessContext::user( $this->bm ) );
	}

	public function test_admin_post_handler_forbids_foreign_branch_with_audit(): void {
		$other               = $this->make_branch( 'QAB' );
		$_POST               = [ 'branch_id' => (string) $other, '_momos_nonce' => wp_create_nonce( 'momos_hours_' . $other ), 'date_from' => '2026-12-31', 'type' => 'closed_all_day' ];
		$_REQUEST            = $_POST;
		$this->audits        = [];
		try {
			HoursFormHandler::save_closure();
			$this->fail( 'Expected wp_die' );
		} catch ( \WPDieException $e ) {
			$this->assertContains( 'access.denied', $this->audit_actions() );
			$this->assertSame( [], ClosureRepository::rows_on( $other, '2026-12-31' ) );
		}
	}

	public function test_kill_switch_and_temporary_closure_preview_all_future(): void {
		$this->make_reservation( $this->branch, '2026-12-07', '18:00' );
		$this->make_reservation( $this->branch, '2026-11-07', '18:00' ); // Past.
		$this->assertCount( 1, ClosureImpactService::preview_all( $this->branch ) );
	}

	public function test_custom_slot_validity_filter_is_used(): void {
		$this->make_reservation( $this->branch, '2026-12-20', '18:00' );
		add_filter( 'momos/branch/slot_valid', '__return_false' );
		$this->assertCount( 1, ClosureImpactService::preview_closure( $this->branch, [ '2026-12-20' ], null ) );
		remove_filter( 'momos/branch/slot_valid', '__return_false' );
		$this->assertCount( 0, ClosureImpactService::preview_closure( $this->branch, [ '2026-12-20' ], null ) );
	}
}
