<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/ReservationTestCaseTest.php';

use Momos\Branch\PostType\BranchScopeTaxonomy;
use Momos\Kernel\I18n\Lang;
use Momos\Kernel\I18n\Locale;
use Momos\Kernel\I18n\Translations;
use Momos\Presentation\View\BranchLinks;
use Momos\Reservation\Admin\InboxPage;
use Momos\Reservation\Front\CustomerPages;
use Momos\Reservation\Support\AdminTime;

/**
 * Reservation gaps of the PRD audit:
 *  - FR-RES-009(a): SCR-A09 "Request" card shows the consent version, the consent time (JST) and the record version.
 *  - FR-I18N-010(c) / AC-I18N-04-01: SCR-A08 list times are JST with a "JST" label, formatted in the
 *    user's admin language (en_US → English labels, ja → Japanese labels and weekday).
 *  - FR-BR-009(b): branch notices on C09 for the selected branch (server-rendered + page config), page
 *    language only (BR-I18N-02).
 *  - AC-RES-01-15: the C09 form has no nationality/religion/DOB/Katakana/payment field, the consent box is
 *    unticked and "No payment or deposit is required" is shown in every enabled language.
 */
final class ReservationStaffI18nNoticesFormTest extends ReservationTestCaseTest {
	private int $a;
	private int $b;

	public function set_up(): void {
		parent::set_up();
		$this->freeze( '2026-12-01 10:00:00' ); // JST (Tue).
		update_option( 'momos_languages_enabled', array_keys( Lang::ALL ) );
		$this->a = $this->make_branch( 'QNA' );
		$this->b = $this->make_branch( 'QNB' );
		foreach ( array_keys( Lang::ALL ) as $l ) {
			if ( 'ja' !== $l ) {
				Translations::save( 'post', $this->a, $l, [ 'title' => 'Notice Branch A ' . $l ], 'approved' );
				Translations::save( 'post', $this->b, $l, [ 'title' => 'Notice Branch B ' . $l ], 'approved' );
			}
		}
		BranchLinks::flush();
		unset( $_GET['branch'], $_GET['date'], $_GET['time'], $_GET['id'], $_GET['view'] );
	}

	public function tear_down(): void {
		unset( $_GET['branch'], $_GET['date'], $_GET['time'], $_GET['id'], $_GET['view'] );
		if ( is_locale_switched() ) {
			restore_current_locale();
		}
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function admin( string $locale ): int {
		$u = $this->staff( 'momo_reservation_staff', [ $this->a ] );
		update_user_meta( $u, 'locale', $locale );
		wp_set_current_user( $u );
		switch_to_user_locale( $u );
		return $u;
	}

	private function render_admin(): string {
		set_current_screen( 'toplevel_page_momos-reservations' );
		ob_start();
		InboxPage::render();
		return (string) ob_get_clean();
	}

	// ------------------------------------------------------------------ FR-RES-009(a)

	public function test_detail_request_card_shows_consent_version_consent_time_jst_and_record_version_fr_res_009a(): void {
		$id = $this->make_reservation(
			$this->a,
			'2026-12-03',
			'19:00',
			[ 'consent_version' => 'PP1.0-RP1.0', 'consent_at' => '2026-11-30 23:05:00', 'version' => 4 ]
		);
		$this->admin( 'en_US' );
		$_GET['id'] = (string) $id;
		$html       = $this->render_admin();
		$this->assertMatchesRegularExpression( '#<section class="momos-card" aria-labelledby="momos-h-request">.*</section>#s', $html );
		preg_match( '#<section class="momos-card" aria-labelledby="momos-h-request">(.*?)</section>#s', $html, $m );
		$card = $m[1];
		$this->assertMatchesRegularExpression( '#<dt>Consent version</dt>\s*<dd class="momos-consent"><bdi dir="ltr">PP1\.0-RP1\.0</bdi>#', $card );
		// 2026-11-30 23:05 UTC = Tue 2026-12-01 08:05 JST.
		$this->assertMatchesRegularExpression( '#<time datetime="2026-12-01T08:05:00\+09:00">2026-12-01 \(Tue\) 08:05 JST</time>#', $card );
		$this->assertMatchesRegularExpression( '#<dt>Version</dt><dd class="momos-record-version">4</dd>#', $card );
	}

	// ------------------------------------------------------------------ FR-I18N-010(c) / AC-I18N-04-01

	public function test_inbox_times_are_jst_with_label_in_english_for_en_us_user_ac_i18n_04_01(): void {
		$this->make_reservation( $this->a, '2026-12-03', '19:00', [ 'created_at' => '2026-12-01 01:00:00' ] );
		$this->admin( 'en_US' );
		$html = $this->render_admin();
		$this->assertStringContainsString( '>2026-12-03 (Thu) 19:00 JST</time>', $html, 'requested date/time: JST label + English weekday' );
		$this->assertStringContainsString( '>2026-12-01 (Tue) 10:00 JST</time>', $html, 'received: absolute JST time (01:00 UTC → 10:00 JST)' );
		$this->assertStringContainsString( '<th scope="col">Received</th>', $html );
		$this->assertStringContainsString( 'Pending</span>', $html, 'English status label' );
		$this->assertDoesNotMatchRegularExpression( '#\b\d+ (mins?|hours?|days?)\b#', $html, 'no relative English age (was human_time_diff)' );
	}

	public function test_inbox_times_are_jst_with_japanese_weekday_and_labels_for_ja_user(): void {
		$this->make_reservation( $this->a, '2026-12-03', '19:00', [ 'created_at' => '2026-12-01 01:00:00' ] );
		$this->admin( 'ja' );
		$this->assertSame( 'ja', AdminTime::lang() );
		unload_textdomain( 'momos-core' );
		$GLOBALS['momos_tests_load_ja'] = true; // bootstrap.php keeps msgids unless asked (see ToolingI18nPoTest).
		$this->assertTrue( load_textdomain( 'momos-core', dirname( __DIR__, 3 ) . '/wp-content/plugins/momos-core/languages/momos-core-ja.mo', 'ja' ) );
		try {
			$html = $this->render_admin();
		} finally {
			unload_textdomain( 'momos-core' );
			unset( $GLOBALS['momos_tests_load_ja'] );
		}
		$this->assertStringContainsString( '>2026-12-03（木）19:00 JST</time>', $html );
		$this->assertStringContainsString( '>2026-12-01（火）10:00 JST</time>', $html );
		$this->assertStringContainsString( '<th scope="col">受付日時</th>', $html, 'Japanese column label (momos-core-ja.mo)' );
		$this->assertStringNotContainsString( '(Thu)', $html );
	}

	public function test_admin_time_formatter_is_jst_whatever_the_input_zone(): void {
		$this->assertSame( '2026-12-03 (Thu) 19:00 JST', AdminTime::requested( '2026-12-03', '19:00:00', 'en' ) );
		$this->assertSame( '2026-12-04（金）00:30 JST', AdminTime::instant( '2026-12-03 15:30:00', 'ja' ), 'MySQL UTC input' );
		$this->assertSame( '2026-12-04 (Fri) 00:30 JST', AdminTime::instant( '2026-12-03T15:30:00+00:00', 'en' ), 'ISO input' );
		$this->assertSame( '', AdminTime::instant( null, 'en' ) );
	}

	// ------------------------------------------------------------------ FR-BR-009(b)

	/** @param list<int> $branches */
	private function notice( array $branches, string $ja_body, array $tr = [], string $severity = 'info' ): int {
		$id = self::factory()->post->create( [ 'post_type' => 'momo_notice', 'post_status' => 'draft', 'post_title' => 'お知らせ' ] );
		update_post_meta( $id, 'momo_notice_body', $ja_body );
		update_post_meta( $id, 'momo_notice_severity', $severity );
		update_post_meta( $id, 'momo_notice_scope', 'branches' );
		update_post_meta( $id, 'momo_notice_start_at', '2026-12-01 09:00' );
		update_post_meta( $id, 'momo_notice_end_at', '2026-12-10 18:00' );
		$terms = array_map( static fn( $b ) => BranchScopeTaxonomy::term_for_branch( $b )->term_id, $branches );
		wp_set_object_terms( $id, $terms, BranchScopeTaxonomy::TAX );
		update_post_meta( $id, 'momo_notice_branches', $terms );
		wp_update_post( [ 'ID' => $id, 'post_status' => 'publish' ] );
		foreach ( $tr as $lang => $body ) {
			Translations::save( 'post', $id, $lang, [ 'title' => 'Notice ' . $lang, 'momo_notice_body' => $body ], 'approved' );
		}
		return $id;
	}

	private function render_form( array $view ): string {
		ob_start();
		get_template_part( 'template-parts/reservation/form', null, $view );
		return (string) ob_get_clean();
	}

	public function test_selected_branch_notices_are_rendered_and_embedded_in_page_language_only_fr_br_009b(): void {
		$na = $this->notice( [ $this->a ], '本日は18時まで営業します。', [ 'en' => 'Open until 18:00 today.' ], 'warning' );
		$nb = $this->notice( [ $this->b ], '設備点検のため休業します。' ); // Japanese only → never on /en/.
		wp_set_current_user( 0 );

		$_GET['branch'] = 'QNA';
		$en             = CustomerPages::form_view( 'en' );
		$cfg            = array_column( $en['config']['branches'], 'notices', 'code' );
		$this->assertSame( [ $na ], array_column( $cfg['QNA'], 'id' ) );
		$this->assertSame( 'warning', $cfg['QNA'][0]['severity'] );
		$this->assertSame( 'Open until 18:00 today.', $cfg['QNA'][0]['body'] );
		$this->assertSame( [], $cfg['QNB'], 'untranslated notice not embedded in English (BR-I18N-02)' );
		$this->assertArrayHasKey( 'a11y.notice', $en['config']['i18n'] );

		$html = $this->render_form( $en );
		$this->assertMatchesRegularExpression( '#<div class="momo-stack" data-res-notices role="group" aria-live="polite" aria-label="[^"]+">\s*<div[^>]*class="momo-notice momo-notice--warning momo-notice--card[^"]*"[^>]*>.*Open until 18:00 today\.#s', $html );
		$this->assertStringNotContainsString( '設備点検', $html );
		$this->assertLessThan( strpos( $html, 'data-res-notices' ), strpos( $html, 'id="res-branch"' ), 'notices follow the branch field' );

		// Japanese page with branch B preselected: B's notice only.
		$_GET['branch'] = 'QNB';
		$ja             = CustomerPages::form_view( 'ja' );
		$html           = $this->render_form( $ja );
		$this->assertStringContainsString( '設備点検のため休業します。', $html );
		$this->assertStringNotContainsString( '本日は18時まで', $html );
		$this->assertSame( [ $nb ], array_column( array_column( $ja['config']['branches'], 'notices', 'code' )['QNB'], 'id' ) );

		// No branch preselected: empty, hidden box; the data stays in the config for the script.
		unset( $_GET['branch'] );
		$none = CustomerPages::form_view( 'en' );
		$html = $this->render_form( $none );
		$this->assertMatchesRegularExpression( '#<div class="momo-stack" data-res-notices [^>]*hidden>\s*</div>#', $html );
		$this->assertCount( 1, array_column( $none['config']['branches'], 'notices', 'code' )['QNA'] );
	}

	public function test_ended_notice_is_not_offered_on_the_form(): void {
		$this->notice( [ $this->a ], '終了したお知らせ', [ 'en' => 'Ended notice.' ] );
		$this->freeze( '2026-12-10 18:01:00' ); // JST, one minute after the end.
		$this->assertSame( [], CustomerPages::notices( $this->a, 'en' ) );
	}

	// ------------------------------------------------------------------ AC-RES-01-15

	public function test_form_has_no_sensitive_or_payment_fields_consent_unticked_and_no_payment_text_in_every_language_ac_res_01_15(): void {
		wp_set_current_user( 0 );
		$_GET['branch'] = 'QNA';
		$seen           = [];
		foreach ( Lang::enabled() as $lang ) {
			$html = Locale::with( $lang, fn() => $this->render_form( CustomerPages::form_view( $lang ) ) );
			preg_match_all( '#<(?:input|select|textarea)\b[^>]*>#i', $html, $m );
			$this->assertNotEmpty( $m[0] );
			foreach ( $m[0] as $tag ) {
				$this->assertDoesNotMatchRegularExpression( '#(?:name|id|autocomplete)="[^"]*(nation|religio|birth|bday|dob|kana|furigana|payment|deposit|card|cc-|iban)[^"]*"#i', $tag, "{$lang}: forbidden field {$tag}" );
			}
			$this->assertMatchesRegularExpression( '#<input type="checkbox" id="res-consent" name="consent"[^>]*>#', $html );
			preg_match( '#<input type="checkbox" id="res-consent"[^>]*>#', $html, $c );
			$this->assertDoesNotMatchRegularExpression( '#\schecked#', $c[0], "{$lang}: consent unticked" );
			$no_pay = momos_t( 'res.no_payment', [], $lang );
			$this->assertNotSame( 'res.no_payment', $no_pay );
			$this->assertStringContainsString( '<p class="momo-small momo-strong">' . esc_html( $no_pay ) . '</p>', $html, "{$lang}: no-payment statement" );
			$seen[ $lang ] = $no_pay;
		}
		$this->assertSame( 'No payment or deposit is required.', $seen['en'] );
		$this->assertCount( count( Lang::enabled() ), array_unique( $seen ), 'each language has its own text (no fallback)' );
	}
}
