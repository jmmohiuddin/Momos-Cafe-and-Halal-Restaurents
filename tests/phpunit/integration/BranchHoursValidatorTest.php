<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/BranchTestCaseTest.php';

use Momos\Branch\Service\HoursValidator;

/**
 * HoursValidator (FR-BR-006/007, spec §4.4/§4.6): TC-BR-009, AC-BR-04-02.
 */
final class BranchHoursValidatorTest extends BranchTestCaseTest {
	public function test_overlapping_periods_rejected_ac_br_04_02(): void {
		[ $errors ] = HoursValidator::validate_week( [ 1 => [ [ 'label' => 'lunch', 'opens' => '11:00', 'closes' => '15:00' ], [ 'label' => 'dinner', 'opens' => '14:00', 'closes' => '18:00' ] ] ], false );
		$this->assertArrayHasKey( 'w1p2', $errors );
		$this->assertStringContainsString( 'must not overlap', $errors['w1p2'] );
		$this->assertStringContainsString( 'Monday, period 2', $errors['w1p2'] );
	}

	public function test_overlap_with_previous_weekday_tail_including_sunday_to_monday(): void {
		[ $errors ] = HoursValidator::validate_week( [ 7 => [ [ 'opens' => '22:00', 'closes' => '03:00' ] ], 1 => [ [ 'opens' => '02:00', 'closes' => '10:00' ] ] ], false );
		$this->assertArrayHasKey( 'w1p1', $errors );
		[ $ok ] = HoursValidator::validate_week( [ 7 => [ [ 'opens' => '22:00', 'closes' => '03:00' ] ], 1 => [ [ 'opens' => '03:00', 'closes' => '10:00' ] ] ], false );
		$this->assertSame( [], $ok );
	}

	public function test_last_order_bounds_and_after_midnight(): void {
		[ $e1 ] = HoursValidator::validate_week( [ 6 => [ [ 'opens' => '17:00', 'closes' => '02:00', 'last_order' => '01:30' ] ] ], true );
		$this->assertSame( [], $e1 );
		[ $e2 ] = HoursValidator::validate_week( [ 6 => [ [ 'opens' => '17:00', 'closes' => '02:00', 'last_order' => '02:00' ] ] ], false );
		$this->assertStringContainsString( 'Last order must be between', $e2['w6p1'] );
		[ $e3 ] = HoursValidator::validate_week( [ 6 => [ [ 'opens' => '17:00', 'closes' => '22:00', 'last_order' => '16:00' ] ] ], false );
		$this->assertArrayHasKey( 'w6p1', $e3 );
	}

	public function test_same_open_close_and_lo_required_and_max_periods(): void {
		[ $e1 ] = HoursValidator::validate_week( [ 2 => [ [ 'opens' => '10:00', 'closes' => '10:00' ] ] ], false );
		$this->assertStringContainsString( 'cannot be the same', $e1['w2p1'] );
		[ $e2 ] = HoursValidator::validate_week( [ 2 => [ [ 'opens' => '10:00', 'closes' => '12:00' ] ] ], true );
		$this->assertStringContainsString( 'last-order time', $e2['w2p1'] );
		$rows = [];
		for ( $h = 0; $h < 7; $h++ ) {
			$rows[] = [ 'opens' => sprintf( '%02d:00', $h * 3 ), 'closes' => sprintf( '%02d:30', $h * 3 ) ];
		}
		[ $e3 ] = HoursValidator::validate_week( [ 3 => $rows ], false );
		$this->assertArrayHasKey( 'w3p7', $e3 );
	}

	public function test_digit_normalisation_and_sorting(): void {
		[ $errors, $periods ] = HoursValidator::validate_week( [ 4 => [ [ 'label' => 'dinner', 'opens' => '１７：００', 'closes' => '2200', 'last_order' => '٢١:٣٠' ], [ 'label' => 'lunch', 'opens' => '11:00', 'closes' => '14:00' ] ] ], false );
		$this->assertSame( [], $errors );
		$this->assertSame( '11:00', $periods[0]['opens_at'] );
		$this->assertSame( 1, $periods[0]['period_no'] );
		$this->assertSame( '17:00', $periods[1]['opens_at'] );
		$this->assertSame( '21:30', $periods[1]['last_order_at'] );
		$this->assertNull( HoursValidator::normalize_time( '25:00' ) );
		$this->assertSame( '00:00', HoursValidator::normalize_time( '24:00' ) );
	}

	public function test_dated_sets_must_not_overlap(): void {
		$b = $this->make_branch( 'QAA' );
		$this->set_week( $b, [ 1 => [ [ '11:00', '15:00', '14:00' ] ] ], '2026-12-01', '2026-12-31' );
		$this->assertNotEmpty( HoursValidator::validate_set_dates( $b, '2026-12-15', '2027-01-15' ) );
		$this->assertNotEmpty( HoursValidator::validate_set_dates( $b, '2026-11-01', null ) );
		$this->assertSame( [], HoursValidator::validate_set_dates( $b, '2027-01-01', null ) );
		$this->assertSame( [], HoursValidator::validate_set_dates( $b, '2026-12-01', '2026-12-20', '2026-12-01', '2026-12-31' ) );
		$this->assertNotEmpty( HoursValidator::validate_set_dates( $b, '2027-02-01', '2027-01-01' ) );
	}

	public function test_special_hours_need_a_period(): void {
		[ $errors ] = HoursValidator::validate_special( [], false );
		$this->assertStringContainsString( 'Closed all day', $errors['s'] );
	}
}
