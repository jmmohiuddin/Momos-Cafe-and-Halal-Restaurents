<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/BranchTestCaseTest.php';

use Momos\Presentation\Format\DateFormatter;
use Momos\Presentation\View\BranchView;

/**
 * QA (customer team) — branch hours display on SCR-C05 (CMP-HoursTable / CMP-AddressBlock).
 * Doc 07 §6.10 (24 h `h23` "19:00", two-digit hours), AC-BR-04-01 / DEF-010 (after-midnight close "02:00" + next-day
 * label), DEF-009 (postal code printed once).
 */
final class QaCustomerBranchViewTest extends BranchTestCaseTest {
	public function test_time_is_two_digit_24h_h23(): void {
		$this->assertSame( '02:00', DateFormatter::time( '02:00' ) );
		$this->assertSame( '09:30', DateFormatter::time( '09:30:00' ) );
		$this->assertSame( '00:15', DateFormatter::time( new \DateTimeImmutable( '2026-12-01 00:15:00', new \DateTimeZone( 'Asia/Tokyo' ) ) ) );
	}

	public function test_cross_midnight_row_shows_0200_and_a_next_day_marker(): void {
		$this->freeze( '2026-12-07 12:00:00' );
		$b = $this->make_branch( 'QAC' );
		$this->set_week( $b, [ 6 => [ [ '17:00', '02:00', '01:30', 'dinner' ] ] ] );
		$sat = null;
		foreach ( BranchView::hours( $b, 'en' ) as $row ) {
			if ( 6 === $row['weekday'] ) {
				$sat = $row;
			}
		}
		$this->assertNotNull( $sat );
		$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $sat['hours_html'] ) ) );
		$this->assertStringContainsString( '17:00–02:00', $text );
		$this->assertNotSame( '17:00–02:00', $text, 'no next-day label on an after-midnight close' );
		$this->assertStringContainsString( '01:30', wp_strip_all_tags( $sat['lo_html'] ) );
	}

	public function test_japanese_address_contains_the_postal_code_once(): void {
		$b    = $this->make_branch( 'QAP', [ 'momo_address_ja' => '〒540-0000 大阪府大阪市中央区北浜サンプル1-2-3', 'momo_postal_code' => '540-0000' ] );
		$addr = BranchView::address( $b, 'en' );
		$ja   = trim( (string) ( $addr['postal'] ?? '' ) . ' ' . (string) ( $addr['ja'] ?? '' ) );
		$this->assertSame( 1, substr_count( $ja, '540-0000' ), "rendered: {$ja}" );
	}
}
