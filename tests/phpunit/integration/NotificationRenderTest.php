<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/NotificationTestCaseTest.php';

use Momos\Notification\Queue\Dispatcher;
use Momos\Notification\Queue\NotificationQueue;
use Momos\Notification\Repository\NotificationRepository;
use Momos\Notification\Template\EmailFormatter;
use Momos\Notification\Template\Renderer;
use Momos\Notification\Transport\Message;

/**
 * Templates and rendering (F-NOT-01/02, FR-NOT-001/002/005, FR-I18N-005/006, BR-RES-01, PRV-004/005/
 * 008/009): AC-NOT-01-01/02/03/04, TC-NOT-001/002/003/007/014/015/016, TC-RTL-004, AC-NOT-02-01,
 * given-name rule, language fallback.
 */
final class NotificationRenderTest extends NotificationTestCaseTest {
	private const LANGS = [ 'ja', 'en', 'ar', 'ur', 'hi', 'bn' ];

	/** Doc 07 §6.6 request-context terms (BR-RES-01, CMP-004). */
	private const BANNED = [
		'en' => [ 'booked', "you're booked", 'reservation confirmed', 'booking confirmed', 'confirm table reservation', 'guarantee your seating', 'instant seating', 'your table is reserved', 'reservation complete', 'your reservation' ],
		'ja' => [ '予約完了', '予約確定', '予約が確定', '予約を確定', 'ご予約を承りました', 'このご予約', '席を予約' ],
		'ar' => [ 'تأكيد الحجز', 'تم تأكيد الحجز' ],
	];

	private function render_row( string $template, int $reservation, ?string $variant = null, string $lang = 'en' ): \Momos\Notification\Template\Rendered {
		$row = [
			'id'               => 0,
			'uuid'             => wp_generate_uuid4(),
			'template_key'     => $template,
			'template_variant' => $variant,
			'lang'             => $lang,
			'reservation_id'   => $reservation,
			'branch_id'        => null,
			'object_type'      => 'reservation',
			'object_id'        => $reservation,
			'created_at'       => \Momos\Kernel\Clock\Clock::mysql_utc(),
			'recipient_type'   => in_array( $template, [ 'E07', 'E08', 'E09' ], true ) ? 'branch' : 'customer',
		];
		return Renderer::render( $row );
	}

	private static function all_text( \Momos\Notification\Template\Rendered $r ): string {
		return $r->subject . "\n" . $r->html . "\n" . $r->text;
	}

	public function test_e01_in_six_languages_lang_dir_jst_ref_isolation_text_part(): void {
		$a = $this->branch( 'QAA', [], [], [ 'en', 'ar', 'ur', 'hi', 'bn' ] );
		foreach ( self::LANGS as $lang ) {
			$r   = $this->reservation( $a, [ 'preferred_lang' => $lang ] );
			$out = $this->render_row( 'E01', $r, null, $lang );
			$this->assertSame( $lang, $out->lang, "rendered in {$lang}" );
			$dir = in_array( $lang, [ 'ar', 'ur' ], true ) ? 'rtl' : 'ltr';
			$this->assertStringContainsString( '<html lang="' . $lang . '" dir="' . $dir . '">', $out->html );
			$ref = \Momos\Kernel\Support\Crockford::display( (string) \Momos\Notification\Support\ReservationReader::find( $r )['public_ref'] );
			$this->assertStringContainsString( '<bdi dir="ltr" style="font-weight:700;letter-spacing:0.05em;">' . $ref . '</bdi>', $out->html, 'reference isolated LTR' );
			$this->assertStringContainsString( $ref, $out->subject );
			$this->assertStringContainsString( momos_t( 'status.pending', [], $lang ), $out->html );
			$this->assertNotSame( '', trim( $out->text ) );
			$this->assertStringNotContainsString( '<', $out->text, 'plain-text part has no markup' );
			if ( 'ja' === $lang ) {
				$this->assertStringNotContainsString( 'JST', $out->html, 'ja omits the JST label (DEC-062)' );
			} else {
				$this->assertStringContainsString( '19:00 ' . momos_t( 'email.jst', [], $lang ), $out->html, "JST label in {$lang}" );
				$this->assertStringContainsString( '〒542-0085', $out->html, 'Japanese address shown for non-ja' );
			}
			$this->assertStringContainsString( 'tel:+81600000000', $out->html );
		}
	}

	public function test_cmp_004_no_request_context_terms_in_pending_contexts(): void {
		$a = $this->branch( 'QAA', [], [], [ 'en', 'ar', 'ur', 'hi', 'bn' ] );
		foreach ( self::LANGS as $lang ) {
			$r      = $this->reservation( $a, [ 'preferred_lang' => $lang ] );
			$checks = [ $this->render_row( 'E01', $r, null, $lang ), $this->render_row( 'E04', $r, 'by_customer', $lang ), $this->render_row( 'E04', $r, 'by_branch', $lang ), $this->render_row( 'E06', $r, 'pending', $lang ) ];
			foreach ( $checks as $out ) {
				$hay = mb_strtolower( self::all_text( $out ) );
				foreach ( self::BANNED[ $lang ] ?? [] as $term ) {
					$this->assertStringNotContainsString( mb_strtolower( $term ), $hay, "{$lang}: '{$term}' in a pending-context email" );
				}
			}
		}
	}

	public function test_tc_not_007_subjects_contain_no_pii_except_reference(): void {
		$a = $this->branch( 'QAA', [], [], [ 'en', 'ar', 'ur', 'hi', 'bn' ] );
		$r = $this->reservation( $a, [ 'status' => 'confirmed', 'confirmed_at' => \Momos\Kernel\Clock\Clock::mysql_utc(), 'message_to_customer' => 'See you!' ] );
		$cases = [ [ 'E01', null ], [ 'E02', null ], [ 'E03', null ], [ 'E04', 'by_customer' ], [ 'E04', 'by_branch' ], [ 'E05', null ], [ 'E06', 'pending' ], [ 'E06', 'confirmed' ], [ 'E06', 'new_link' ], [ 'E07', null ], [ 'E08', 'sla_reminder' ], [ 'E08', 'sla_escalation' ], [ 'E08', 'notify_failed' ], [ 'E09', null ] ];
		foreach ( self::LANGS as $lang ) {
			foreach ( $cases as [ $tpl, $variant ] ) {
				$staff = in_array( $tpl, [ 'E07', 'E08', 'E09' ], true );
				if ( $staff && ! in_array( $lang, [ 'ja', 'en' ], true ) ) {
					continue;
				}
				$s = $this->render_row( $tpl, $r, $variant, $lang )->subject;
				foreach ( [ 'Aisha', 'Rahman', 'aisha.guest', '+6012', '2026-12-24', '12月24日', 'Window seat', 'peanut' ] as $pii ) {
					$this->assertStringNotContainsString( $pii, $s, "{$tpl}/{$variant}/{$lang} subject" );
				}
				$this->assertStringNotContainsString( "\n", $s );
			}
		}
	}

	public function test_ac_not_01_04_e02_message_directions_no_policy_no_internal_note(): void {
		$a = $this->branch( 'QAA' );
		$r = $this->reservation( $a, [ 'status' => 'confirmed', 'confirmed_at' => \Momos\Kernel\Clock\Clock::mysql_utc(), 'message_to_customer' => "Please arrive on time.\n<script>alert(1)</script>" ] );
		global $wpdb;
		$wpdb->insert( \Momos\Kernel\Db\Tables::events(), [ 'reservation_id' => $r, 'event_type' => 'note', 'actor_type' => 'staff', 'note' => 'INTERNAL-NOTE-XYZ guest is VIP', 'created_at' => \Momos\Kernel\Clock\Clock::mysql_utc() ] );
		$out = $this->render_row( 'E02', $r );
		$all = self::all_text( $out );
		$this->assertStringContainsString( 'Message from the branch', $out->html );
		$this->assertStringContainsString( 'Please arrive on time.<br />', $out->html );
		$this->assertStringContainsString( '&lt;script&gt;', $out->html, 'user text escaped (SEC-021)' );
		$this->assertStringNotContainsString( '<script>', $out->html );
		$this->assertStringContainsString( 'https://www.google.com/maps/dir/?api=1&#038;destination=34.6745%2C135.5011', $out->html );
		$this->assertStringNotContainsString( 'Branch policy', $all, 'no policy paragraph unless supplied (DEC-054)' );
		$this->assertStringNotContainsString( 'INTERNAL-NOTE-XYZ', $all, 'internal note never in E02 (BR-NOT-02)' );
		$this->assertStringContainsString( 'If anyone in your group has a food allergy', $out->html );
	}

	public function test_customer_emails_never_echo_special_request_or_tracking(): void {
		$a = $this->branch( 'QAA' );
		$r = $this->reservation( $a, [ 'email' => 'guest@docomo.ne.jp' ] );
		foreach ( [ [ 'E01', null ], [ 'E05', null ], [ 'E04', 'by_customer' ] ] as [ $tpl, $v ] ) {
			$out = $this->render_row( $tpl, $r, $v );
			$all = self::all_text( $out );
			$this->assertStringNotContainsString( 'Window seat', $all, 'special request never rendered (PRV-004)' );
			$this->assertStringNotContainsString( 'utm_', $all, 'no UTM (PRV-005)' );
			$this->assertStringNotContainsString( '<img', $out->html, 'no pixels/images' );
			$this->assertStringNotContainsString( 'Rahman', $all );
		}
		$e01 = $this->render_row( 'E01', $r );
		$this->assertStringContainsString( 'We have received your note.', $e01->html );
		$this->assertStringContainsString( 'allow mail from', $e01->html, 'carrier tip when carrier_email (FR-RES-029)' );
		$plain = $this->reservation( $a );
		$this->assertStringNotContainsString( 'allow mail from', $this->render_row( 'E01', $plain )->html );
	}

	public function test_tc_not_002_variants_e03_suggestions_e05_phone_e06_wording(): void {
		$a   = $this->branch( 'QAA' );
		$r   = $this->reservation( $a, [ 'status' => 'rejected', 'decision_reason_code' => 'fully_booked', 'suggested_times' => wp_json_encode( [ [ 'date' => '2026-12-25', 'time' => '18:30' ], [ 'date' => '2026-12-26', 'time' => '20:00' ], [ 'date' => '2026-12-27', 'time' => '20:00' ] ] ) ] );
		$e03 = $this->render_row( 'E03', $r );
		$this->assertStringContainsString( 'The branch is fully booked at that time.', $e03->html );
		$this->assertSame( 2, substr_count( $e03->html, '/en/reservation/?branch=QAA&#038;date=' ), 'up to 2 suggested times, each a prefilled link' );
		$this->assertStringContainsString( 'They are not held for you', $e03->html );

		$e05 = $this->render_row( 'E05', $r );
		$this->assertStringContainsString( 'Call the branch:', $e05->html );
		$this->assertStringContainsString( '<bdi>+81 ', $e05->html, 'international phone format for non-ja' );
		$this->assertStringNotContainsString( 'visit without a request', $e05->html, 'walk-in line off unless allowed' );

		$p = $this->reservation( $a );
		global $wpdb;
		$wpdb->insert( \Momos\Kernel\Db\Tables::events(), [ 'reservation_id' => $p, 'event_type' => 'modified', 'actor_type' => 'staff', 'payload' => wp_json_encode( [ 'before' => [ 'party_size' => 4, 'requested_time' => '19:00' ], 'after' => [ 'party_size' => 5, 'requested_time' => '19:30' ] ] ), 'created_at' => \Momos\Kernel\Clock\Clock::mysql_utc() ] );
		$e06 = $this->render_row( 'E06', $p, 'pending' );
		$this->assertStringContainsString( '(changed)', $e06->html );
		$this->assertStringContainsString( '5 people', $e06->html );
		$this->assertStringContainsString( 'details of your request were updated', $e06->subject );
		$this->assertStringNotContainsString( 'reservation', strtolower( strip_tags( $e06->html ) ) );
	}

	public function test_ac_not_02_01_e07_minimal_pii_given_name_and_admin_link(): void {
		$a   = $this->branch( 'QAA' );
		$r   = $this->reservation( $a );
		$out = $this->render_row( 'E07', $r, null, 'en' );
		$all = self::all_text( $out );
		$this->assertStringContainsString( 'Aisha', $all );
		foreach ( [ 'Rahman', 'aisha.guest@example.test', '+60123456789', 'Window seat', 'peanut' ] as $pii ) {
			$this->assertStringNotContainsString( $pii, $all, 'PRV-008' );
		}
		$this->assertStringContainsString( 'Special request: yes', $all );
		$this->assertStringContainsString( '19:00 JST', $all, 'staff en time with JST' );
		$this->assertStringContainsString( 'page=momos-reservations&#038;id=' . $r, $out->html );
		$this->assertStringContainsString( '[QAA] New reservation request', $out->subject );
		$ja = $this->render_row( 'E07', $r, null, 'ja' );
		$this->assertStringContainsString( '新しい予約リクエスト', $ja->subject );
	}

	public function test_given_name_rule(): void {
		$this->assertSame( 'Aisha', EmailFormatter::given_name( 'Aisha Rahman' ) );
		$this->assertSame( '山田', EmailFormatter::given_name( '山田　太郎' ), 'U+3000 split' );
		$this->assertSame( 'ヤマダタロウヤマダタロウヤマダタロウヤマ', EmailFormatter::given_name( 'ヤマダタロウヤマダタロウヤマダタロウヤマダタロウ' ), '≤ 20 characters' );
		$this->assertSame( '', EmailFormatter::given_name( '   ' ) );
	}

	public function test_language_fallback_recorded_when_branch_not_translated(): void {
		$a = $this->branch( 'QAA', [], [], [ 'en' ] ); // No approved Arabic branch translation.
		$r = $this->reservation( $a, [ 'preferred_lang' => 'ar' ] );
		do_action( 'momos/reservation/created', $r );
		$id = (int) $this->rows( 'E01' )[0]['id'];
		$this->assertSame( 'sent', Dispatcher::send( $id ) );
		$row = NotificationRepository::find( $id );
		$this->assertSame( 'en', $row['lang'], 'whole email falls back (no mixed language)' );
		$this->assertSame( 'lang_fallback', $row['last_error_code'] );
		$this->assertStringContainsString( 'requested=ar used=en', (string) $row['last_error'] );
		update_option( 'momos_languages_enabled', [ 'ja', 'en' ] );
		$b = $this->reservation( $this->branch( 'QAB', [], [], [ 'en', 'hi' ] ), [ 'preferred_lang' => 'hi' ] );
		$this->assertSame( 'en', $this->render_row( 'E01', $b, null, 'hi' )->lang, 'language not publicly enabled → en' );
	}

	public function test_e11_certificate_expiry_render(): void {
		$a    = $this->branch( 'QAA' );
		$cert = self::factory()->post->create( [ 'post_type' => 'post', 'post_title' => 'cert' ] );
		update_post_meta( $cert, 'momo_cert_number', 'JHA-0001' );
		update_post_meta( $cert, 'momo_cert_certifier_name', 'Japan Halal Example' );
		update_post_meta( $cert, 'momo_cert_valid_until', '2026-12-31' );
		update_post_meta( $cert, 'momo_cert_branches', [ $a ] );
		$id  = \Momos\Notification\Notifier::queue( 'E11', [ 'type' => 'head_office', 'email' => 'ho@momos.example', 'lang' => 'en' ], [ 'certificate_id' => $cert, 'variant' => 'cert_30' ], 'cert:' . $cert . ':30:x' );
		$out = Renderer::render( NotificationRepository::find( (int) $id ) );
		$this->assertSame( '[Halal] Certificate JHA-0001 expires Thu 31 Dec 2026', $out->subject );
		$this->assertStringContainsString( 'QAA', $out->html );
		$id2 = \Momos\Notification\Notifier::queue( 'E11', [ 'type' => 'head_office', 'email' => 'ho@momos.example', 'lang' => 'ja' ], [ 'certificate_id' => $cert, 'variant' => 'cert_expired' ], 'cert:' . $cert . ':0:x' );
		$this->assertStringContainsString( '有効期限切れ', Renderer::render( NotificationRepository::find( (int) $id2 ) )->subject );
	}

	public function test_render_failure_is_permanent(): void {
		$id = NotificationQueue::insert( 'E11', [ 'type' => 'head_office', 'email' => 'ho@momos.example', 'lang' => 'en', 'user_id' => null ], 'cert:999999:30:x', 'cert_30', null, null, 'certificate', 999999 );
		$this->assertSame( 'failed', Dispatcher::send( (int) $id ) );
		$this->assertSame( 'render_failed', NotificationRepository::find( (int) $id )['last_error_code'] );
		$this->assertSame( [], array_map( static fn( Message $m ) => $m->to, $this->sent ) );
	}
}
