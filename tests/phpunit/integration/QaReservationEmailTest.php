<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/NotificationTestCaseTest.php';

use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Support\Crockford;
use Momos\Notification\Template\Renderer;
use Momos\Notification\Template\Rendered;

/**
 * QA (reservation team) — email templates E01–E09 against docs/specs/notifications.md §4.6/§4.7/§5 and
 * Doc 12 TC-NOT-001/002/003/012/015, TC-RTL-004: every customer template and variant in all six
 * languages (lang/dir, LTR isolation of reference and phone, JST label only outside ja, link hosts,
 * language-prefixed status links, no special-request text), and the staff templates in ja/en (minimal
 * PII, absolute deep link). Fictional data only.
 */
final class QaReservationEmailTest extends NotificationTestCaseTest {
	private const LANGS = [ 'ja', 'en', 'ar', 'ur', 'hi', 'bn' ];

	private function render( string $template, int $reservation, ?string $variant, string $lang, string $recipient_type = 'customer' ): Rendered {
		return Renderer::render(
			[
				'id'               => 0,
				'uuid'             => wp_generate_uuid4(),
				'template_key'     => $template,
				'template_variant' => $variant,
				'lang'             => $lang,
				'reservation_id'   => $reservation,
				'branch_id'        => null,
				'object_type'      => 'reservation',
				'object_id'        => $reservation,
				'created_at'       => Clock::mysql_utc(),
				'recipient_type'   => $recipient_type,
			]
		);
	}

	/** @return list<string> */
	private static function hrefs( string $html ): array {
		preg_match_all( '/href="([^"]+)"/', $html, $m );
		return array_map( 'html_entity_decode', $m[1] );
	}

	/** @return array<string,array{0:string,1:?string,2:array<string,mixed>}> label => [template, variant, reservation overrides] */
	private function customer_cases(): array {
		$now = Clock::mysql_utc();
		return [
			'E01'             => [ 'E01', null, [] ],
			'E02'             => [ 'E02', null, [ 'status' => 'confirmed', 'confirmed_at' => $now, 'message_to_customer' => 'See you soon' ] ],
			'E03'             => [ 'E03', null, [ 'status' => 'rejected', 'rejected_at' => $now, 'decision_reason_code' => 'fully_booked', 'suggested_times' => '[{"date":"2026-12-25","time":"19:00"},{"date":"2026-12-26","time":"18:30"}]' ] ],
			'E04 by_customer' => [ 'E04', 'by_customer', [ 'status' => 'cancelled', 'cancelled_at' => $now, 'cancelled_by' => 'customer' ] ],
			'E04 by_branch'   => [ 'E04', 'by_branch', [ 'status' => 'cancelled', 'cancelled_at' => $now, 'cancelled_by' => 'staff', 'decision_reason_code' => 'branch_unable_to_serve', 'confirmed_at' => $now ] ],
			'E05'             => [ 'E05', null, [ 'status' => 'expired', 'expired_at' => $now ] ],
			'E06 pending'     => [ 'E06', 'pending', [] ],
			'E06 confirmed'   => [ 'E06', 'confirmed', [ 'status' => 'confirmed', 'confirmed_at' => $now ] ],
		];
	}

	// ------------------------------------------------------------------ TC-NOT-001/002, TC-RTL-004, TC-NOT-012

	public function test_customer_templates_six_languages_direction_isolation_jst_links(): void {
		$a    = $this->branch( 'QEA', self::recipients( 'qea-inbox@momos.example' ), [], [ 'en', 'ar', 'ur', 'hi', 'bn' ] );
		$site = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		foreach ( $this->customer_cases() as $label => [ $tpl, $variant, $over ] ) {
			foreach ( self::LANGS as $lang ) {
				$id  = $this->reservation( $a, [ 'preferred_lang' => $lang, 'email' => 'guest.' . $lang . '@docomo.ne.jp' ] + $over );
				$out = $this->render( $tpl, $id, $variant, $lang );
				$ctx = "{$label}/{$lang}";
				$this->assertSame( $lang, $out->lang, "{$ctx}: rendered in the preferred language" );
				$dir = in_array( $lang, [ 'ar', 'ur' ], true ) ? 'rtl' : 'ltr';
				$this->assertStringContainsString( '<html lang="' . $lang . '" dir="' . $dir . '">', $out->html, $ctx );
				$ref = Crockford::display( (string) \Momos\Notification\Support\ReservationReader::find( $id )['public_ref'] );
				$this->assertMatchesRegularExpression( '#<bdi dir="ltr"[^>]*>' . preg_quote( $ref, '#' ) . '</bdi>#', $out->html, "{$ctx}: reference in an LTR isolate" );
				$this->assertStringContainsString( $ref, $out->subject, "{$ctx}: reference in the subject" );
				$this->assertMatchesRegularExpression( '#<bdi dir="ltr">[^<]*60-000-0000[^<]*</bdi>|<bdi dir="ltr">06-0000-0000</bdi>#', $out->html, "{$ctx}: phone in an LTR isolate" );
				$this->assertStringContainsString( 'tel:+81600000000', $out->html, "{$ctx}: tel link E.164" );
				if ( 'ja' === $lang ) {
					$this->assertStringNotContainsString( momos_t( 'email.jst', [], 'en' ), $out->html, "{$ctx}: no JST label in ja (DEC-062)" );
				} else {
					$this->assertStringContainsString( momos_t( 'email.jst', [], $lang ), $out->html, "{$ctx}: JST label" );
				}
				foreach ( self::hrefs( $out->html ) as $href ) {
					if ( str_starts_with( $href, 'tel:' ) || str_starts_with( $href, 'mailto:' ) ) {
						continue;
					}
					$host = (string) wp_parse_url( $href, PHP_URL_HOST );
					$this->assertContains( $host, [ $site, 'www.google.com' ], "{$ctx}: link to {$href}" );
					$this->assertStringNotContainsString( 'utm_', $href, $ctx );
					if ( $host === $site && str_contains( $href, '/reservation/' ) ) {
						$path = (string) wp_parse_url( $href, PHP_URL_PATH );
						if ( 'ja' === $lang ) {
							$this->assertStringStartsWith( '/reservation/', $path, "{$ctx}: ja links at the root" );
						} else {
							$this->assertStringStartsWith( '/' . $lang . '/reservation/', $path, "{$ctx}: link in the preferred language" );
						}
					}
				}
				$all = $out->subject . $out->html . $out->text;
				$this->assertStringNotContainsString( 'Window seat', $all, "{$ctx}: special request never echoed" );
				$this->assertStringNotContainsString( 'peanut', $all, $ctx );
				$this->assertStringContainsString( '@', $out->html );
				$tip = (string) strstr( momos_t( 'res.carrier_tip', [ 'domain' => '§' ], $lang ), '§', true );
				$this->assertStringContainsString( esc_html( mb_substr( trim( $tip ), 0, 10 ) ), $out->html, "{$ctx}: carrier tip in every customer email when carrier_email (C6)" );
			}
		}
	}

	// ------------------------------------------------------------------ TC-NOT-002 variant wording (EN source text)

	public function test_tc_not_002_variant_wording_e03_links_e04_pending_vs_confirmed_e05_phone_e06(): void {
		$a   = $this->branch( 'QEA' );
		$now = Clock::mysql_utc();
		$e03 = $this->render( 'E03', $this->reservation( $a, [ 'status' => 'rejected', 'rejected_at' => $now, 'decision_reason_code' => 'closed_or_private_event', 'suggested_times' => '[{"date":"2026-12-25","time":"19:00"},{"date":"2026-12-26","time":"18:30"}]' ] ), null, 'en' );
		$this->assertStringContainsString( 'The branch is closed or reserved for a private event at that time.', $e03->html );
		$this->assertStringContainsString( 'They are not held for you', $e03->html );
		$hrefs = implode( ' ', self::hrefs( $e03->html ) );
		$this->assertStringContainsString( '/en/reservation/?branch=QEA&date=2026-12-25&time=19%3A00', $hrefs, 'suggestion 1 pre-fills the form' );
		$this->assertStringContainsString( 'date=2026-12-26', $hrefs, 'suggestion 2' );

		$pc = $this->render( 'E04', $this->reservation( $a, [ 'status' => 'cancelled', 'cancelled_at' => $now, 'cancelled_by' => 'customer' ] ), 'by_customer', 'en' );
		$this->assertStringContainsString( 'You cancelled this request.', $pc->html );
		$this->assertStringContainsString( 'your request was cancelled', $pc->subject );
		$cc = $this->render( 'E04', $this->reservation( $a, [ 'status' => 'cancelled', 'cancelled_at' => $now, 'cancelled_by' => 'customer', 'confirmed_at' => $now ] ), 'by_customer', 'en' );
		$this->assertStringContainsString( 'You cancelled this reservation.', $cc->html );
		$this->assertStringContainsString( 'reservation cancelled', $cc->subject );
		$bb = $this->render( 'E04', $this->reservation( $a, [ 'status' => 'cancelled', 'cancelled_at' => $now, 'cancelled_by' => 'staff', 'decision_reason_code' => 'duplicate' ] ), 'by_branch', 'en' );
		$this->assertStringContainsString( 'The branch cancelled this request.', $bb->html );
		$this->assertStringContainsString( 'This was a duplicate of another request.', $bb->html );
		$this->assertStringContainsString( 'If this is unexpected, please contact the branch', $bb->html );

		$e05 = $this->render( 'E05', $this->reservation( $a, [ 'status' => 'expired', 'expired_at' => $now ] ), null, 'en' );
		$this->assertStringContainsString( 'the branch could not reply before the deadline', $e05->html );
		$this->assertStringContainsString( 'tel:+81600000000', $e05->html );
		$this->assertStringNotContainsString( 'visit without a request', $e05->html, 'walk-in line only if policy allows (DEC-054)' );

		$p6 = $this->render( 'E06', $this->reservation( $a ), 'pending', 'en' );
		$this->assertStringContainsString( 'details of your request were updated', $p6->subject );
		$this->assertStringContainsString( 'View request status', $p6->html );
		$this->assertStringNotContainsString( 'reservation', strtolower( $p6->subject . strip_tags( $p6->html ) ), 'pending E06 never says "reservation"' );
	}

	// ------------------------------------------------------------------ TC-NOT-003 staff emails minimal PII

	public function test_tc_not_003_staff_templates_minimal_pii_given_name_and_absolute_deep_link(): void {
		$a   = $this->branch( 'QEA', self::recipients( 'qea-inbox@momos.example' ) );
		$id  = $this->reservation( $a, [ 'guest_name' => 'Aisha Rahman-Khan', 'phone_e164' => '+60123456789' ] );
		$cases = [ [ 'E07', null ], [ 'E08', 'sla_reminder' ], [ 'E08', 'sla_escalation' ], [ 'E08', 'notify_failed' ], [ 'E09', null ] ];
		foreach ( [ 'ja', 'en' ] as $lang ) {
			foreach ( $cases as [ $tpl, $variant ] ) {
				$this->update_reservation( $id, 'E09' === $tpl ? [ 'status' => 'cancelled', 'cancelled_by' => 'customer', 'cancelled_at' => Clock::mysql_utc() ] : [ 'status' => 'pending' ] );
				$out = $this->render( $tpl, $id, $variant, $lang, 'E08' === $tpl && 'sla_escalation' === $variant ? 'head_office' : 'branch' );
				$ctx = "{$tpl}/{$variant}/{$lang}";
				$this->assertSame( $lang, $out->lang, $ctx );
				$all = $out->subject . "\n" . $out->html . "\n" . $out->text;
				$this->assertStringContainsString( 'Aisha', $all, "{$ctx}: given name" );
				foreach ( [ 'Rahman', 'aisha.guest', 'example.test', '6012345', '+60 12', 'Window seat', 'peanut' ] as $pii ) {
					$this->assertStringNotContainsString( $pii, $all, "{$ctx}: '{$pii}' must not appear in a staff email (PRV-008)" );
				}
				$link = admin_url( 'admin.php?page=momos-reservations&id=' . $id );
				$this->assertStringContainsString( esc_url( $link ), $out->html, "{$ctx}: absolute deep link" );
				$this->assertStringStartsWith( 'http', $link );
				$this->assertNull( $out->reply_to, "{$ctx}: staff emails have no Reply-To" );
			}
		}
		// Japanese name without a space: first 20 characters only.
		$jp = $this->reservation( $a, [ 'guest_name' => str_repeat( '山', 25 ) ] );
		$e07 = $this->render( 'E07', $jp, null, 'ja', 'branch' );
		$this->assertStringContainsString( str_repeat( '山', 20 ), $e07->html );
		$this->assertStringNotContainsString( str_repeat( '山', 21 ), $e07->html );
	}

	// ------------------------------------------------------------------ TC-RES-034 preferred language drives emails

	public function test_tc_res_034_preferred_language_urdu_from_japanese_ui(): void {
		$a  = $this->branch( 'QEA', self::recipients( 'qea-inbox@momos.example' ), [], [ 'en', 'ur' ] );
		$id = $this->reservation( $a, [ 'preferred_lang' => 'ur', 'source_lang' => 'ja' ] );
		\Momos\Notification\Subscriber\ReservationSubscriber::created( $id );
		$rows = $this->rows( 'E01' );
		$this->assertSame( 'ur', $rows[0]['lang'] );
		$this->update_reservation( $id, [ 'status' => 'confirmed', 'confirmed_at' => Clock::mysql_utc(), 'version' => 2 ] );
		\Momos\Notification\Subscriber\ReservationSubscriber::status_changed( $id, 'pending', 'confirmed', [ 'type' => 'staff' ] );
		$e02 = $this->rows( 'E02' );
		$this->assertSame( 'ur', $e02[0]['lang'] );
		$this->assertSame( 'ur', $this->render( 'E02', $id, null, 'ur' )->lang );
		// The branch alert stays in the recipient's language (ja row), not the guest's.
		$this->assertSame( 'ja', $this->rows( 'E07' )[0]['lang'] );
	}
}
