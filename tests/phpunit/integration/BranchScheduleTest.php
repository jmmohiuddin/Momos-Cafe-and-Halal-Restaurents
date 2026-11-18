<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/BranchTestCaseTest.php';

use Momos\Branch\Domain\AccessContext;
use Momos\Branch\Repository\ClosureRepository;
use Momos\Branch\Repository\HoursRepository;
use Momos\Branch\Service\ScheduleService;
use Momos\Kernel\Clock\Clock;

/**
 * ScheduleService (BR-BR-05, spec §4.5): TC-BR-003, TC-BR-009, AC-BR-01-02/03, AC-BR-04-01.
 */
final class BranchScheduleTest extends BranchTestCaseTest {
	public function test_split_hours_with_last_order_and_opens_later_ac_br_01_02(): void {
		$this->freeze( '2026-12-07 15:30:00' ); // Monday JST.
		$b = $this->make_branch( 'QAB' );
		$this->set_week( $b, [ 1 => [ [ '11:00', '15:00', '14:30', 'lunch' ], [ '17:00', '23:00', '22:30', 'dinner' ] ] ] );
		$periods = ScheduleService::periods_on( $b, '2026-12-07' );
		$this->assertCount( 2, $periods );
		$this->assertSame( '14:30', $periods[0]['last_order_at'] );
		$status = ScheduleService::open_status( $b );
		$this->assertSame( 'opens_later', $status['state'] );
		$this->assertSame( [ 'date' => '2026-12-07', 'time' => '17:00' ], $status['next_open'] );
		// Same instant expressed from a UTC−5 device is the same instant: JST is always used.
		$ny = new \DateTimeImmutable( '2026-12-07 01:30:00', new \DateTimeZone( 'America/New_York' ) );
		$this->assertSame( 'opens_later', ScheduleService::open_status( $b, $ny )['state'] );
	}

	public function test_cross_midnight_period_ac_br_04_01(): void {
		$b = $this->make_branch( 'QAC' );
		$this->set_week( $b, [ 6 => [ [ '17:00', '02:00', '01:30', 'dinner' ] ] ] );
		$row = HoursRepository::all_rows( $b )[0];
		$this->assertTrue( $row['closes_next_day'] );
		// Sunday 01:00 JST belongs to Saturday's business date 2026-12-12.
		$sun_1am = Clock::jst_at( '2026-12-13', '01:00' );
		$this->assertTrue( ScheduleService::is_open_at( $b, $sun_1am ) );
		$status = ScheduleService::open_status( $b, $sun_1am );
		$this->assertSame( 'open', $status['state'] ); // 60 min before close, before LO 01:30.
		$this->assertSame( '02:00', $status['until'] );
		$this->assertSame( 'open', ScheduleService::open_status( $b, Clock::jst_at( '2026-12-12', '20:00' ) )['state'] );
		$this->assertFalse( ScheduleService::is_open_at( $b, Clock::jst_at( '2026-12-13', '02:00' ) ) );
		$this->assertSame( 'closing_soon', ScheduleService::open_status( $b, Clock::jst_at( '2026-12-13', '01:40' ) )['state'] ); // After LO.
	}

	public function test_closure_removes_own_tail_but_keeps_previous_days_tail(): void {
		$this->freeze( '2026-12-01 10:00:00' );
		$b = $this->make_branch( 'QAC' );
		$this->set_week( $b, [ 5 => [ [ '18:00', '01:00', '00:30' ] ], 6 => [ [ '18:00', '01:00', '00:30' ] ] ] );
		// Closure on Saturday 2026-12-12.
		ClosureRepository::replace_dates( $b, [ '2026-12-12' ], 'closed_all_day', [], 'private_event', 'Party of 40', AccessContext::system() );
		$this->assertTrue( ScheduleService::is_open_at( $b, Clock::jst_at( '2026-12-12', '00:30' ) ), 'Friday tail into Saturday is kept' );
		$this->assertFalse( ScheduleService::is_open_at( $b, Clock::jst_at( '2026-12-12', '19:00' ) ) );
		$this->assertFalse( ScheduleService::is_open_at( $b, Clock::jst_at( '2026-12-13', '00:30' ) ), 'Saturday tail into Sunday is removed' );
	}

	public function test_closure_on_regular_closed_day_and_year_boundary(): void {
		$this->freeze( '2026-12-01 10:00:00' );
		$b = $this->make_branch( 'QAA' );
		$this->set_week( $b, [ 4 => [ [ '17:00', '02:00', '01:00' ] ] ] ); // Thursday only (31 Dec 2026 is a Thursday).
		$this->assertTrue( ScheduleService::is_open_at( $b, Clock::jst_at( '2027-01-01', '01:30' ) ) );
		ClosureRepository::replace_dates( $b, [ '2027-01-01' ], 'closed_all_day', [], 'public_holiday', '', AccessContext::system() ); // Friday, regular closed day.
		$this->assertTrue( ScheduleService::is_closed_on( $b, '2027-01-01' ) );
		$this->assertTrue( ScheduleService::is_open_at( $b, Clock::jst_at( '2027-01-01', '01:30' ) ), 'tail of 31 Dec unaffected' );
		$closure = ScheduleService::closure_on( $b, '2027-01-01' );
		$this->assertSame( 'public_holiday', $closure['public_reason'] );
		$this->assertArrayNotHasKey( 'internal_note', $closure );
	}

	public function test_seasonal_set_replaces_default_weekday_only_where_defined(): void {
		$b = $this->make_branch( 'QAA' );
		$this->set_week( $b, [ 1 => [ [ '11:00', '22:00', '21:30' ] ], 2 => [ [ '11:00', '22:00', '21:30' ] ] ] );
		$this->set_week( $b, [ 1 => [ [ '12:00', '20:00', '19:30' ] ] ], '2026-12-01', '2026-12-31' );
		$this->assertSame( '12:00', ScheduleService::periods_on( $b, '2026-12-07' )[0]['opens_at'] ); // Monday in set.
		$this->assertSame( '11:00', ScheduleService::periods_on( $b, '2026-12-08' )[0]['opens_at'] ); // Tuesday falls back.
		$this->assertSame( '11:00', ScheduleService::periods_on( $b, '2027-01-04' )[0]['opens_at'] ); // After the set.
		$this->freeze( '2026-12-02 09:00:00' );
		$this->assertSame( '12:00', ScheduleService::week( $b )[1][0]['opens_at'] );
	}

	public function test_special_hours_replace_weekly_and_reason_shown_in_schedule_json(): void {
		$this->freeze( '2026-12-20 10:00:00' );
		$b = $this->make_branch( 'QAB' );
		$this->set_week( $b, [ 4 => [ [ '11:00', '22:00', '21:00' ] ] ] );
		ClosureRepository::replace_dates( $b, [ '2026-12-24' ], 'special_hours', [ [ 'label' => 'dinner', 'opens_at' => '17:00', 'closes_at' => '02:00', 'last_order_at' => '01:30' ] ], 'other', '', AccessContext::system() );
		$p = ScheduleService::periods_on( $b, '2026-12-24' );
		$this->assertCount( 1, $p );
		$this->assertSame( '17:00', $p[0]['opens_at'] );
		$this->assertTrue( $p[0]['closes_next_day'] );
		$json = ScheduleService::schedule_json( $b );
		$this->assertSame( 'Asia/Tokyo', $json['tz'] );
		$this->assertCount( 9, $json['days'] ); // yesterday … today + 7.
		$day = array_values( array_filter( $json['days'], static fn( $d ) => '2026-12-24' === $d['date'] ) )[0];
		$this->assertSame( '2026-12-25T02:00:00+09:00', $day['periods'][0]['close'] );
	}

	public function test_closed_all_day_today_reports_reason_and_next_open(): void {
		$this->freeze( '2026-12-10 12:00:00' ); // Thursday.
		$b = $this->make_branch( 'QAB' );
		$this->set_week( $b, [ 4 => [ [ '11:00', '15:00', '14:30' ] ], 5 => [ [ '11:00', '15:00', '14:30' ] ] ] );
		ClosureRepository::replace_dates( $b, [ '2026-12-10' ], 'closed_all_day', [], 'public_holiday', '', AccessContext::system() );
		$s = ScheduleService::open_status( $b );
		$this->assertSame( 'closed', $s['state'] );
		$this->assertSame( 'public_holiday', $s['closed_reason'] );
		$this->assertSame( [ 'date' => '2026-12-11', 'time' => '11:00' ], $s['next_open'] );
	}

	public function test_temporarily_closed_branch_is_closed(): void {
		$b = $this->make_branch( 'QAY', [ 'momo_operating_status' => 'temporarily_closed', 'momo_reopen_date' => '2026-12-10' ] );
		$this->set_week( $b, [ 1 => [ [ '00:00', '23:59' ] ], 2 => [ [ '00:00', '23:59' ] ], 3 => [ [ '00:00', '23:59' ] ], 4 => [ [ '00:00', '23:59' ] ], 5 => [ [ '00:00', '23:59' ] ], 6 => [ [ '00:00', '23:59' ] ], 7 => [ [ '00:00', '23:59' ] ] ] );
		$s = ScheduleService::open_status( $b );
		$this->assertSame( 'closed', $s['state'] );
		$this->assertSame( 'temporarily_closed', $s['closed_reason'] );
	}

	public function test_hours_write_is_audited_and_fires_schedule_changed(): void {
		$b = $this->make_branch( 'QAA' );
		$this->set_week( $b, [ 1 => [ [ '11:00', '15:00', '14:30' ] ] ] );
		$this->assertContains( 'branch.hours_updated', $this->audit_actions() );
		$ev = $this->events_of( 'momos/branch/schedule_changed' );
		$this->assertNotEmpty( $ev );
		$this->assertSame( [ 'momos/branch/schedule_changed', $b, [] ], end( $ev ) );
	}

	public function test_enum_values_match_db_check_constraints(): void {
		$src = (string) file_get_contents( MOMOS_CORE_DIR . '/src/Kernel/Migration/M0002_Constraints.php' );
		$this->assertStringContainsString( "period_label IN ('" . implode( "','", \Momos\Branch\Domain\PeriodLabel::values() ) . "')", $src );
		$this->assertStringContainsString( "closure_type IN ('" . implode( "','", \Momos\Branch\Domain\ClosureType::values() ) . "')", $src );
		$this->assertStringContainsString( "public_reason IN ('" . implode( "','", \Momos\Branch\Domain\ClosureReason::values() ) . "')", $src );
	}
}
