<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/AccessTestCaseTest.php';

use Momos\Access\Http\CspReport;
use Momos\Access\Http\SecurityHeaders;
use Momos\Admin\Migration\M0005_DefaultSettings;
use Momos\Admin\Settings\BrandFields;
use Momos\Admin\Settings\LanguageGate;
use Momos\Admin\Settings\ReservationOptions;
use Momos\Admin\Settings\SettingsFields;
use Momos\Admin\Settings\SettingsScreen;
use Momos\Kernel\Fields\FieldRegistry;
use Momos\Kernel\I18n\Lang;
use Momos\Kernel\Settings;

/**
 * Admin shell: G1/G3 settings, language gate, dashboard, security headers
 * (SCR-A02, SCR-A15, FR-I18N-009, FR-SEC-005; TC-I18N-019, TC-SEC-004/029/030, TC-PRV-016/018).
 */
final class AdminSettingsTest extends AccessTestCaseTest {
	public function test_g1_g3_fields_registered_with_documented_names(): void {
		$this->assertSame( BrandFields::PAGE, FieldRegistry::option_page_of( 'momo_trading_name' ) );
		foreach ( [ 'momo_logo_primary', 'momo_social_links', 'momo_privacy_page', 'momo_accessibility_page', 'momo_brand_halal_statement_key' ] as $f ) {
			$this->assertSame( 'momo-brand', FieldRegistry::option_page_of( $f ), $f );
		}
		foreach ( [ 'momo_retention_pii_months', 'momo_retention_audit_months', 'momo_gtm_container_id', 'momo_csp_mode', 'momo_hsts_max_age', 'momo_heartbeat_enabled', 'momo_map_embed_on_click' ] as $f ) {
			$this->assertSame( 'momo-settings', FieldRegistry::option_page_of( $f ), $f );
		}
		$this->assertSame( 24, (int) Settings::get( 'momo_retention_audit_months' ) );
		foreach ( FieldRegistry::groups_for( 'option:momo-settings' ) as $g ) {
			foreach ( $g['fields'] as $f ) {
				$this->assertDoesNotMatchRegularExpression( '/turnstile|secret|password|token_key|pepper/i', $f['name'], 'secrets are never options' );
			}
		}
		$this->assertNull( SettingsFields::validate_gtm( 'GTM-AB12CD' ) );
		$this->assertNotNull( SettingsFields::validate_gtm( 'UA-1234' ) );
		$this->assertNotNull( BrandFields::validate_phone( '03-1234-5678' ) );
		$this->assertNull( BrandFields::validate_phone( '+81312345678' ) );
		$this->assertNotNull( BrandFields::validate_business_email( 'owner@gmail.com' ) );
	}

	public function test_reservation_options_are_plain_options_validated_and_audited(): void {
		wp_set_current_user( $this->user_with_role( 'momo_head_office' ) );
		$errors = ReservationOptions::apply( [ 'momo_soft_duplicate_minutes' => '0', 'momo_res_global_enabled' => '1' ] );
		$this->assertNotEmpty( $errors, 'min 1' );
		$this->assertFalse( get_option( 'momo_soft_duplicate_minutes', false ), 'nothing stored on error' );
		$this->assertSame( [], ReservationOptions::apply( [ 'momo_soft_duplicate_minutes' => '15', 'momo_carrier_domains' => "docomo.ne.jp\nau.com" ] ) );
		$this->assertSame( '15', get_option( 'momo_soft_duplicate_minutes' ) );
		$this->assertSame( '0', get_option( 'momo_res_global_enabled' ), 'unchecked kill switch saved off' );
		if ( class_exists( \Momos\Reservation\Support\Options::class ) ) {
			$this->assertSame( 15, \Momos\Reservation\Support\Options::int( 'momo_soft_duplicate_minutes' ) );
		}
		$audit = $this->audit_rows( 'settings.updated' );
		$this->assertStringContainsString( 'momo_soft_duplicate_minutes', (string) end( $audit )['changes'] );
		$this->assertNotEmpty( ReservationOptions::apply( [ 'momo_res_global_enabled' => '1', 'momo_carrier_domains' => 'not a domain!' ] ) );
		// Consent registry: key must match versions; current key must exist.
		$this->assertNotEmpty( ReservationOptions::apply( [ 'momo_res_global_enabled' => '1', 'momo_consent_versions' => [ [ 'consent_key' => 'PP2.0-RP1.0', 'privacy_policy_version' => '1.0', 'reservation_policy_version' => '1.0', 'effective_from' => '2026-12-01' ] ] ] ) );
		$this->assertSame( [], ReservationOptions::apply( [ 'momo_res_global_enabled' => '1', 'momo_consent_versions' => [ [ 'consent_key' => 'PP2.0-RP1.0', 'privacy_policy_version' => '2.0', 'reservation_policy_version' => '1.0', 'effective_from' => '2026-12-01' ] ], 'momo_consent_version_current' => 'PP2.0-RP1.0' ] ) );
		$this->assertSame( 'PP2.0-RP1.0', get_option( 'momo_consent_version_current' ) );
		$this->assertNotEmpty( ReservationOptions::apply( [ 'momo_res_global_enabled' => '1', 'momo_consent_version_current' => 'PP9.0-RP9.0' ] ) );
	}

	public function test_m0005_seeds_registry_and_defaults_idempotently(): void {
		delete_option( 'momo_consent_versions' );
		( new M0005_DefaultSettings() )->up();
		( new M0005_DefaultSettings() )->up();
		$rows = get_option( 'momo_consent_versions' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'PP1.0-RP1.0', $rows[0]['consent_key'] );
		$this->assertSame( 'report_only', Settings::get( 'momo_csp_mode' ) );
	}

	public function test_language_gate_tc_i18n_019(): void {
		$this->freeze( '2026-11-18 10:00:00' );
		$ho = $this->user_with_role( 'momo_head_office' );
		wp_set_current_user( $ho );
		update_option( 'momos_languages_enabled', [ 'ja', 'en' ] );
		// Isolate the gate from the real translation-gaps provider (covered by I18nLanguageGateTest).
		remove_filter( 'momos/i18n/language_gate_errors', [ \Momos\Kernel\I18n\Admin\TranslationGaps::class, 'gate_errors' ], 10 );
		$gap = static fn( $errors, $lang ) => 'ar' === $lang ? array_merge( (array) $errors, [ 'Page "About" (ar) missing' ] ) : $errors;
		add_filter( 'momos/i18n/language_gate_errors', $gap, 10, 2 );
		$err = LanguageGate::enable( 'ar', [ 'by' => 'Reviewer', 'at' => '2026-11-17', 'ref' => 'R-1' ], $ho );
		$this->assertStringContainsString( '1', $err[0] );
		$this->assertNotContains( 'ar', Lang::enabled() );
		remove_filter( 'momos/i18n/language_gate_errors', $gap, 10 );
		$this->assertNotEmpty( LanguageGate::enable( 'ar', [ 'by' => '', 'at' => '2026-11-17', 'ref' => '' ], $ho ), 'sign-off name required' );
		$this->assertNotEmpty( LanguageGate::enable( 'ar', [ 'by' => 'Reviewer', 'at' => '2026-11-19', 'ref' => '' ], $ho ), 'sign-off date ≤ today' );
		$this->assertSame( [], LanguageGate::enable( 'ar', [ 'by' => 'Reviewer', 'at' => '2026-11-17', 'ref' => 'R-1' ], $ho ) );
		$this->assertContains( 'ar', Lang::enabled() );
		$this->assertSame( 'enabled', LanguageGate::records()['ar']['state'] );
		$this->assertNotEmpty( $this->audit_rows( 'language.enabled' ) );
		$this->assertSame( [], LanguageGate::disable( 'ar', $ho ) );
		$this->assertNotContains( 'ar', Lang::enabled() );
		$this->assertNotEmpty( $this->audit_rows( 'language.disabled' ) );
		$this->assertNotEmpty( LanguageGate::disable( 'ja', $ho ), 'source language always public' );
		// Non-managers: 403 + access.denied.
		wp_set_current_user( $this->user_with_role( 'momo_translator' ) );
		try {
			LanguageGate::handle();
			$this->fail( 'translator changed languages' );
		} catch ( \WPDieException $e ) {
			$this->assertNotEmpty( $this->audit_rows( 'access.denied' ) );
		}
	}

	public function test_settings_tabs_respect_capabilities(): void {
		wp_set_current_user( $this->user_with_role( 'momo_head_office' ) );
		$ho_tabs = SettingsScreen::visible_tabs();
		$this->assertContains( 'reservations', $ho_tabs );
		$this->assertContains( 'languages', $ho_tabs );
		$this->assertContains( 'privacy', $ho_tabs );
		$this->assertNotContains( 'security', $ho_tabs, 'technical tabs are SA only' );
		$this->assertNotContains( 'monitoring', $ho_tabs );
		wp_set_current_user( $this->user_with_role( 'administrator' ) );
		$this->assertContains( 'security', SettingsScreen::visible_tabs() );
		$_GET['tab'] = 'security';
		ob_start();
		SettingsScreen::render();
		$html = ob_get_clean();
		unset( $_GET['tab'] );
		$this->assertStringContainsString( 'momos_fields[momo_csp_mode]', $html );
		$this->assertStringNotContainsString( 'momos_fields[momo_retention_pii_months]', $html, 'one tab per form' );
		wp_set_current_user( $this->user_with_role( 'momo_branch_manager' ) );
		$this->expectException( \WPDieException::class );
		SettingsScreen::render();
	}

	public function test_dashboard_widgets_by_role(): void {
		require_once ABSPATH . 'wp-admin/includes/dashboard.php';
		set_current_screen( 'dashboard' );
		global $wp_meta_boxes;
		$ids = function ( string $role ) use ( &$wp_meta_boxes ): array {
			$wp_meta_boxes = [];
			wp_set_current_user( $this->user_with_role( $role ) );
			\Momos\Admin\Dashboard\Widgets::setup();
			$out = [];
			foreach ( (array) ( $wp_meta_boxes['dashboard'] ?? [] ) as $ctx ) {
				foreach ( $ctx as $prio ) {
					$out = array_merge( $out, array_keys( (array) $prio ) );
				}
			}
			return $out;
		};
		$ho = $ids( 'momo_head_office' );
		$this->assertContains( 'momos_recent_changes', $ho );
		$this->assertContains( 'momos_translation_gaps', $ho );
		$rs = $ids( 'momo_reservation_staff' );
		$this->assertNotContains( 'momos_recent_changes', $rs );
		$this->assertNotContains( 'momos_translation_gaps', $rs );
		if ( class_exists( \Momos\Reservation\Repository\ReservationRepository::class ) ) {
			$this->assertContains( 'momos_pending', $rs );
		}
		ob_start();
		\Momos\Admin\Dashboard\Widgets::translation_gaps();
		$this->assertNotSame( '', ob_get_clean() );
	}

	public function test_security_headers_tc_sec_029_030(): void {
		$orig                   = $_SERVER['REQUEST_URI'] ?? '/';
		$_SERVER['REQUEST_URI'] = '/en/menu/';
		$h = SecurityHeaders::public_headers( [] );
		$this->assertSame( 'nosniff', $h['X-Content-Type-Options'] );
		$this->assertSame( 'SAMEORIGIN', $h['X-Frame-Options'] );
		$this->assertSame( 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), browsing-topics=()', $h['Permissions-Policy'] );
		$this->assertSame( 'same-origin', $h['Cross-Origin-Opener-Policy'] );
		$this->assertSame( 'strict-origin-when-cross-origin', $h['Referrer-Policy'] );
		$this->assertSame( SecurityHeaders::CSP, $h['Content-Security-Policy-Report-Only'] );
		$this->assertArrayNotHasKey( 'Content-Security-Policy', $h );
		$this->assertStringContainsString( "frame-ancestors 'self'", SecurityHeaders::CSP );
		$this->assertStringNotContainsString( 'unsafe-eval', SecurityHeaders::CSP );
		Settings::set( 'momo_csp_mode', 'enforce' );
		$this->assertArrayHasKey( 'Content-Security-Policy', SecurityHeaders::public_headers( [] ) );
		$_SERVER['REQUEST_URI'] = '/ar/reservation/status/ABCD2345/';
		$p = SecurityHeaders::public_headers( [] );
		$this->assertSame( 'no-referrer', $p['Referrer-Policy'] );
		$this->assertSame( 'no-store, private', $p['Cache-Control'] );
		$this->assertSame( 'noindex, nofollow', $p['X-Robots-Tag'] );
		$this->assertTrue( SecurityHeaders::is_private_result_page( '/reservation/submitted/' ) );
		$this->assertFalse( SecurityHeaders::is_private_result_page( '/reservation/' ) );
		$a = SecurityHeaders::admin_headers();
		$this->assertSame( "frame-ancestors 'self'; object-src 'none'; base-uri 'self'", $a['Content-Security-Policy'] );
		$this->assertSame( 'same-origin', $a['Referrer-Policy'] );
		Settings::set( 'momo_hsts_max_age', '31536000' );
		$this->assertSame( 31536000, SecurityHeaders::hsts_max_age() );
		Settings::set( 'momo_hsts_max_age', '999' );
		$this->assertSame( 300, SecurityHeaders::hsts_max_age() );
		$_SERVER['REQUEST_URI'] = $orig;
	}

	public function test_csp_report_endpoint_stores_counters_only(): void {
		delete_option( CspReport::OPTION );
		$req = new \WP_REST_Request( 'POST', '/momos/v1/csp-report' );
		$req->set_header( 'Content-Type', 'application/csp-report' );
		$req->set_body( (string) wp_json_encode( [ 'csp-report' => [ 'document-uri' => 'https://site.example/en/branches/shinjuku/?q=secret#x', 'effective-directive' => 'script-src', 'blocked-uri' => 'https://evil.example/x.js?token=abc' ] ] ) );
		$res = rest_get_server()->dispatch( $req );
		$this->assertSame( 204, $res->get_status() );
		$stored = get_option( CspReport::OPTION );
		$key    = array_key_first( $stored );
		$this->assertStringContainsString( '|script-src|https://evil.example|/{lang}/branches/{slug}', $key );
		$this->assertStringNotContainsString( 'secret', $key );
		$this->assertStringNotContainsString( 'token', $key );
		$big = new \WP_REST_Request( 'POST', '/momos/v1/csp-report' );
		$big->set_header( 'Content-Type', 'application/csp-report' );
		$big->set_body( str_repeat( 'x', 9000 ) );
		$this->assertSame( 204, rest_get_server()->dispatch( $big )->get_status() );
	}
}
