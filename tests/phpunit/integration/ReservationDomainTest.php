<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/ReservationTestCaseTest.php';

use Momos\Kernel\Support\Crockford;
use Momos\Reservation\Domain\ActorType;
use Momos\Reservation\Domain\CaptchaStatus;
use Momos\Reservation\Domain\EventType;
use Momos\Reservation\Domain\ReservationStatus;
use Momos\Reservation\Domain\Source;
use Momos\Reservation\Domain\StateMachine;
use Momos\Reservation\Service\TokenService;
use Momos\Reservation\Validation\Digits;
use Momos\Reservation\Validation\FieldValidator;
use Momos\Reservation\Validation\Normalizer;

/**
 * Domain: enum ↔ CHECK parity (spec §2.1 CI check), state matrix (DEC-011), normalisation
 * (spec §3.2, AC-RES-01-17), public_ref (DEC-051), token (DEC-058/064).
 */
final class ReservationDomainTest extends ReservationTestCaseTest {
	/** @return list<string> values listed in a CHECK of M0002 */
	private function check_values( string $constraint, string $column ): array {
		$src = (string) file_get_contents( MOMOS_CORE_DIR . '/src/Kernel/Migration/M0002_Constraints.php' );
		$this->assertMatchesRegularExpression( '/' . $constraint . '/', $src );
		preg_match( "/'" . $constraint . "'.*?" . $column . " IN \(([^)]*)\)/s", $src, $m );
		$this->assertNotEmpty( $m, $constraint );
		preg_match_all( "/'([a-z_]+)'/", $m[1], $v );
		return $v[1];
	}

	public function test_enums_mirror_check_constraints(): void {
		$this->assertSame( $this->check_values( 'chk_momo_res_status', 'status' ), ReservationStatus::values() );
		$this->assertSame( $this->check_values( 'chk_momo_evt_type', 'event_type' ), EventType::values() );
		$this->assertSame( $this->check_values( 'chk_momo_evt_actor', 'actor_type' ), ActorType::values() );
		$this->assertSame( $this->check_values( 'chk_momo_res_captcha', 'captcha_status' ), CaptchaStatus::values() );
		$this->assertSame( $this->check_values( 'chk_momo_res_source', 'source' ), Source::values() );
		$this->assertSame( $this->check_values( 'chk_momo_res_cancelled_by', 'cancelled_by' ), ActorType::values() );
	}

	public function test_state_matrix_7_states_x_6_actions(): void {
		$expected = [
			'pending'   => [ 'accept' => 'confirmed', 'decline' => 'rejected', 'cancel' => 'cancelled', 'expire' => 'expired' ],
			'confirmed' => [ 'cancel' => 'cancelled', 'mark_completed' => 'completed', 'mark_no_show' => 'no_show' ],
		];
		$actors = [ 'accept' => ActorType::Staff, 'decline' => ActorType::Staff, 'cancel' => ActorType::Staff, 'mark_completed' => ActorType::Staff, 'mark_no_show' => ActorType::Staff, 'expire' => ActorType::System ];
		foreach ( ReservationStatus::cases() as $from ) {
			foreach ( $actors as $action => $actor ) {
				$to = StateMachine::target( $from, $action, $actor );
				$this->assertSame( $expected[ $from->value ][ $action ] ?? null, $to?->value, $from->value . ' + ' . $action );
			}
			$this->assertSame( $from->is_terminal(), ! isset( $expected[ $from->value ] ) );
		}
		// Actor rules: customers may only cancel; only the system expires.
		$this->assertSame( 'cancelled', StateMachine::target( ReservationStatus::Confirmed, 'cancel', ActorType::Customer )?->value );
		$this->assertNull( StateMachine::target( ReservationStatus::Pending, 'accept', ActorType::Customer ) );
		$this->assertNull( StateMachine::target( ReservationStatus::Pending, 'expire', ActorType::Staff ) );
	}

	public function test_native_digits_and_phone_normalisation_ac_res_01_17(): void {
		$this->assertSame( '4', Digits::normalize( '৪' ) );
		$this->assertSame( '0123456789', Digits::normalize( '٠١٢٣٤٥٦٧٨٩' ) );
		$this->assertSame( '19', Digits::normalize( '१९' ) );
		$this->assertSame( '+819012345678', Normalizer::phone( '০৯০-১২৩৪-৫৬৭৮' ) );
		$this->assertSame( '+60123456789', Normalizer::phone( '+60 12-345 6789' ) );
		$this->assertSame( '+81600000000', Normalizer::phone( '０６－００００－００００' ) );
		$this->assertFalse( Normalizer::phone( '12' ) );
		$this->assertFalse( Normalizer::phone( 'call me' ) );
		$this->assertNull( Normalizer::phone( '  ' ) );
	}

	public function test_field_rules(): void {
		$base = [ 'name' => ' Aisha ', 'email' => 'Aisha@EXAMPLE.ORG', 'lang' => 'en', 'preferred_lang' => 'ar', 'consent' => true, 'date' => '2026-12-24', 'time' => '19:00', 'party_size' => '৪' ];
		$r    = FieldValidator::submission( $base );
		$this->assertSame( [], $r['errors'] );
		$this->assertSame( 'Aisha', $r['values']['guest_name'] );
		$this->assertSame( 'Aisha@example.org', $r['values']['email'], 'domain lower-cased, local part kept' );
		$this->assertSame( 4, $r['values']['party_size'] );
		$this->assertSame( 'invalid_format', FieldValidator::submission( array_merge( $base, [ 'name' => 'Bob <script>' ] ) )['errors']['name'], 'C-9: < > rejected' );
		$this->assertSame( 'invalid_format', FieldValidator::submission( array_merge( $base, [ 'name' => "Bob\nSmith" ] ) )['errors']['name'] );
		$this->assertSame( 'required', FieldValidator::submission( array_merge( $base, [ 'consent' => false ] ) )['errors']['consent'] );
		$this->assertSame( 'invalid_format', FieldValidator::submission( array_merge( $base, [ 'email' => "a@b.org\r\nBcc: x@y.org" ] ) )['errors']['email'] );
		$this->assertSame( 'invalid_format', FieldValidator::submission( array_merge( $base, [ 'date' => '2026-02-30' ] ) )['errors']['date'] );
		$sr = FieldValidator::submission( array_merge( $base, [ 'special_request' => "<b>High chair</b>\n\n\n\n\nThanks" ] ) )['values']['special_request'];
		$this->assertSame( "High chair\n\n\nThanks", $sr, 'tags stripped, > 2 blank lines collapsed' );
		update_option( 'momos_languages_enabled', [ 'ja', 'en' ] );
		$this->assertSame( 'not_allowed', FieldValidator::submission( $base )['errors']['preferred_lang'] );
		$this->assertArrayNotHasKey( 'preferred_lang', FieldValidator::submission( $base, true )['errors'], 'staff may use a disabled language' );
		delete_option( 'momos_languages_enabled' );
	}

	public function test_public_ref_and_given_name(): void {
		$ref = Crockford::random( 8 );
		$this->assertMatchesRegularExpression( '/^[0-9A-HJKMNP-TV-Z]{8}$/', $ref );
		$this->assertSame( '7KQ2M9XR', Crockford::normalize( '7kq2-m9xr' ) );
		$this->assertSame( '10000000', Crockford::normalize( 'IO000000' ) );
		$this->assertSame( 'Aisha', Normalizer::given_name( 'Aisha Rahman' ) );
		$this->assertSame( '山田', Normalizer::given_name( '山田　太郎' ) );
	}

	public function test_token_derivation_and_versions(): void {
		$t1 = TokenService::derive( 12, '7Q2KX9MD', '2026-12-01 01:00:00', 1 );
		$this->assertMatchesRegularExpression( TokenService::PATTERN, $t1 );
		$this->assertSame( $t1, TokenService::derive( 12, '7Q2KX9MD', '2026-12-01 01:00:00', 1 ), 'deterministic (re-derived for every email)' );
		$this->assertNotSame( $t1, TokenService::derive( 13, '7Q2KX9MD', '2026-12-01 01:00:00', 1 ) );
		$this->assertNotSame( $t1, TokenService::derive( 12, '7Q2KX9MD', '2026-12-01 01:00:00', 2 ), 'key version is part of the derivation' );
		$this->assertSame( 64, strlen( TokenService::hash( $t1 ) ) );
		$url = TokenService::status_url( [ 'id' => 12, 'public_ref' => '7Q2KX9MD', 'created_at' => '2026-12-01 01:00:00', 'token_key_version' => 1, 'preferred_lang' => 'en' ] );
		$this->assertStringContainsString( '/en/reservation/status/7Q2KX9MD/#t=' . $t1, $url );
		$ja = TokenService::status_url( [ 'id' => 12, 'public_ref' => '7Q2KX9MD', 'created_at' => '2026-12-01 01:00:00', 'token_key_version' => 1, 'preferred_lang' => 'ja' ] );
		$this->assertStringNotContainsString( '/ja/', $ja );
	}
}
