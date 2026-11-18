<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/ReservationTestCaseTest.php';

use Momos\Branch\Domain\AccessContext;
use Momos\Branch\Repository\BranchSettingsRepository;
use Momos\Branch\Repository\ClosureRepository;
use Momos\Kernel\Clock\Clock;
use Momos\Reservation\Service\SlotGenerator;

/**
 * SlotGenerator (SDD §5.5, spec §3.3 worked examples 1–6; AC-RES-01-04/05/06; TC-RES-004/005).
 */
final class ReservationSlotTest extends ReservationTestCaseTest {
	/** @return array<string,list<string>> date => slots */
	private function days( int $b ): array {
		$out = [];
		foreach ( SlotGenerator::days( $b, BranchSettingsRepository::get( $b ), null, 'en' ) as $d ) {
			$out[ $d['date'] ] = $d['slots'];
		}
		return $out;
	}

	public function test_example_1_lunch_until_last_order(): void {
		$this->freeze( '2026-12-01 08:00:00' ); // Tue.
		$b = $this->make_branch( 'QRA', [], [ 3 => [ [ '11:30', '15:00', '14:30', 'lunch' ] ] ] );
		$this->assertSame( [ '11:30', '12:00', '12:30', '13:00', '13:30', '14:00', '14:30' ], $this->days( $b )['2026-12-02'] );
	}

	public function test_example_2_and_3_after_midnight_tail_and_closure(): void {
		$this->freeze( '2026-12-01 08:00:00' );
		$b    = $this->make_branch( 'QRA', [], [ 5 => [ [ '18:00', '01:00', '00:30', 'dinner' ] ] ] ); // Fri.
		$days = $this->days( $b );
		$this->assertSame( '18:00', $days['2026-12-04'][0] );
		$this->assertSame( '23:30', end( $days['2026-12-04'] ) );
		$this->assertSame( [ '00:00', '00:30' ], $days['2026-12-05'] );
		// Example 3: Saturday closed all day keeps Friday's tail.
		ClosureRepository::replace_dates( $b, [ '2026-12-05' ], 'closed_all_day', [], 'private_event', '', AccessContext::system() );
		$rules = SlotGenerator::days( $b, BranchSettingsRepository::get( $b ), null, 'en' );
		$sat   = array_values( array_filter( $rules, static fn( $d ) => '2026-12-05' === $d['date'] ) )[0];
		$this->assertSame( [ '00:00', '00:30' ], $sat['slots'] );
		$this->assertNull( $sat['closed_reason'] );
		$sun = array_values( array_filter( $rules, static fn( $d ) => '2026-12-06' === $d['date'] ) )[0];
		$this->assertSame( 'closed', $sun['closed_reason'], 'regular closed day' );
	}

	public function test_example_4_special_hours_and_closure_reason(): void {
		$this->freeze( '2026-12-01 08:00:00' );
		$b = $this->make_branch( 'QRA' );
		ClosureRepository::replace_dates( $b, [ '2026-12-31' ], 'special_hours', [ [ 'label' => 'all_day', 'opens_at' => '12:00', 'closes_at' => '16:00', 'last_order_at' => '15:30' ] ], 'other', '', AccessContext::system() );
		ClosureRepository::replace_dates( $b, [ '2026-12-30' ], 'closed_all_day', [], 'maintenance', '', AccessContext::system() );
		$days = $this->days( $b );
		$this->assertSame( '12:00', $days['2026-12-31'][0] );
		$this->assertSame( '15:30', end( $days['2026-12-31'] ) );
		$this->assertCount( 8, $days['2026-12-31'] );
		$rules = SlotGenerator::days( $b, BranchSettingsRepository::get( $b ), null, 'en' );
		$d30   = array_values( array_filter( $rules, static fn( $d ) => '2026-12-30' === $d['date'] ) )[0];
		$this->assertSame( 'maintenance', $d30['closed_reason'] );
		$this->assertSame( 'Maintenance', $d30['closed_label'] );
		$this->assertSame( 'branch_closed_on_date', SlotGenerator::slot_problem( $b, '2026-12-30', '12:00', 30 ) );
	}

	public function test_example_5_and_6_lead_time_ac_res_01_05(): void {
		$this->freeze( '2026-12-01 17:10:00' );
		$b = $this->make_branch( 'QRA', [], array_fill_keys( range( 1, 7 ), [ [ '17:00', '22:00', '21:00', 'dinner' ] ] ) );
		$this->assertSame( [ '19:30', '20:00', '20:30', '21:00' ], $this->days( $b )['2026-12-01'] );

		$this->freeze( '2026-12-01 18:10:00' );
		$b2    = $this->make_branch( 'QRB', [], array_fill_keys( range( 1, 7 ), [ [ '17:00', '23:00', '22:30', 'dinner' ] ] ) );
		$today = $this->days( $b2 )['2026-12-01'];
		$this->assertSame( '20:30', $today[0] );
		$this->assertSame( '22:30', end( $today ) );
		$this->assertNotContains( '23:00', $today );
		$this->assertCount( 61, $this->days( $b2 ), 'today … today + 60 days' );
	}

	public function test_slot_problem_reasons_ac_res_01_06(): void {
		$this->freeze( '2026-12-01 08:00:00' );
		$b = $this->make_branch( 'QRA', [], [ 2 => [ [ '17:00', '23:00', '22:30', 'dinner' ] ] ] ); // Tuesdays.
		$this->assertNull( SlotGenerator::slot_problem( $b, '2026-12-08', '18:00', 30 ) );
		$this->assertSame( 'not_a_slot', SlotGenerator::slot_problem( $b, '2026-12-08', '18:15', 30 ) );
		$this->assertSame( 'after_last_order', SlotGenerator::slot_problem( $b, '2026-12-08', '22:45', 30 ) );
		$this->assertSame( 'outside_opening_hours', SlotGenerator::slot_problem( $b, '2026-12-08', '12:00', 30 ) );
		$this->assertSame( 'branch_closed_on_date', SlotGenerator::slot_problem( $b, '2026-12-09', '18:00', 30 ) );
	}

	public function test_window_last_day_and_year_boundary(): void {
		$this->freeze( '2026-12-31 08:00:00' );
		$b    = $this->make_branch( 'QRA', [ 'momo_res_booking_window_days' => 3 ] );
		$days = $this->days( $b );
		$this->assertSame( [ '2026-12-31', '2027-01-01', '2027-01-02', '2027-01-03' ], array_keys( $days ) );
		$this->assertSame( 'Asia/Tokyo', Clock::jst()->getTimezone()->getName() );
	}
}
