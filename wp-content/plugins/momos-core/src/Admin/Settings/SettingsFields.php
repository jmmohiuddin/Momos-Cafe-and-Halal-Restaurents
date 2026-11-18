<?php
declare(strict_types=1);

namespace Momos\Admin\Settings;

use Momos\Kernel\Fields\FieldRegistry;
use Momos\Kernel\Fields\OptionsPage;

/**
 * G3 `group_momo_settings` on options page `momo-settings` (SCR-A15, TRD §2.5 G3; page cap
 * `momo_manage_brand`; technical tabs `manage_options`). Option names are the documented ones and
 * defaults are the documented proposals (⟦TBD⟧ values read from settings, never hard-coded).
 * Each group carries a `tab` used by SettingsScreen. Secrets (Turnstile keys, token keys, peppers,
 * SMTP credentials, Maps embed key) are constants only — never options (SEC-070, §9.1).
 * The Notifications tab is registered by the Notification module; Languages is a custom tab.
 */
final class SettingsFields {
	public const PAGE = 'momo-settings';

	public const CARRIER_DOMAINS = "docomo.ne.jp\nezweb.ne.jp\nau.com\nsoftbank.ne.jp\ni.softbank.jp\nymobile.ne.jp";

	public static function register(): void {
		add_action( 'init', [ self::class, 'register_groups' ], 9 );
	}

	public static function register_groups(): void {
		OptionsPage::add( self::PAGE, __( 'Settings', 'momos-core' ), 'momo_manage_brand' );
		$brand = 'momo_manage_brand';
		$sa    = 'manage_options';

		FieldRegistry::group(
			[
				'key'          => 'momo_settings_privacy',
				'tab'          => 'privacy',
				'title'        => __( 'Privacy and retention', 'momos-core' ),
				'options_page' => self::PAGE,
				'order'        => 40,
				'fields'       => [
					[ 'name' => 'momo_retention_pii_months', 'label' => __( 'Reservation personal data: months after the visit date', 'momos-core' ), 'type' => 'number', 'default' => 12, 'min' => 1, 'max' => 60, 'step' => 1, 'required' => true, 'edit_cap' => $brand ],
					[ 'name' => 'momo_retention_ip_hash_days', 'label' => __( 'Abuse hashes (IP/user agent): days', 'momos-core' ), 'type' => 'number', 'default' => 30, 'min' => 1, 'max' => 365, 'step' => 1, 'required' => true, 'edit_cap' => $brand ],
					[ 'name' => 'momo_retention_notification_days', 'label' => __( 'Notification log: days', 'momos-core' ), 'type' => 'number', 'default' => 180, 'min' => 30, 'max' => 730, 'step' => 1, 'required' => true, 'edit_cap' => $brand ],
					[ 'name' => 'momo_retention_audit_months', 'label' => __( 'Audit log: months', 'momos-core' ), 'type' => 'number', 'default' => 24, 'min' => 12, 'max' => 84, 'step' => 1, 'required' => true, 'edit_cap' => $brand ],
					[ 'name' => 'momo_retention_activity_months', 'label' => __( 'Login/activity events: months', 'momos-core' ), 'type' => 'number', 'default' => 12, 'min' => 3, 'max' => 24, 'step' => 1, 'required' => true, 'edit_cap' => $brand ],
				],
			]
		);

		FieldRegistry::group(
			[
				'key'          => 'momo_settings_analytics',
				'tab'          => 'analytics',
				'title'        => __( 'Analytics', 'momos-core' ),
				'options_page' => self::PAGE,
				'order'        => 50,
				'view_cap'     => $sa,
				'fields'       => [
					[ 'name' => 'momo_analytics_enabled', 'label' => __( 'Load Google Tag Manager', 'momos-core' ), 'type' => 'true_false', 'default' => '0', 'edit_cap' => $sa, 'help' => __( 'Never on reservation result/status pages; visitors can opt out (PRV-022).', 'momos-core' ) ],
					[ 'name' => 'momo_gtm_container_id', 'label' => __( 'GTM container ID', 'momos-core' ), 'type' => 'text', 'maxlength' => 20, 'pattern' => '^GTM-[A-Z0-9]+$', 'edit_cap' => $sa, 'validate' => [ self::class, 'validate_gtm' ] ],
				],
			]
		);

		FieldRegistry::group(
			[
				'key'          => 'momo_settings_maps',
				'tab'          => 'maps',
				'title'        => __( 'Maps', 'momos-core' ),
				'options_page' => self::PAGE,
				'order'        => 55,
				'fields'       => [
					[ 'name' => 'momo_map_embed_on_click', 'label' => __( 'Offer "Show map" (Google embed after a click)', 'momos-core' ), 'type' => 'true_false', 'default' => '1', 'edit_cap' => $brand, 'help' => __( 'Only effective when the MOMOS_GMAPS_EMBED_KEY constant is defined. Nothing loads from Google before the click (PRV-024).', 'momos-core' ) ],
					[ 'name' => 'momo_map_placeholder_image', 'label' => __( 'Default map preview image', 'momos-core' ), 'type' => 'image', 'edit_cap' => $brand, 'help' => __( 'Own or licensed image (no Google imagery); used when a branch has no preview image.', 'momos-core' ), 'validate' => [ BrandFields::class, 'validate_logo' ] ],
				],
			]
		);

		FieldRegistry::group(
			[
				'key'          => 'momo_settings_monitoring',
				'tab'          => 'monitoring',
				'title'        => __( 'Monitoring', 'momos-core' ),
				'options_page' => self::PAGE,
				'order'        => 60,
				'view_cap'     => $sa,
				'fields'       => [
					[ 'name' => 'momo_heartbeat_enabled', 'label' => __( 'Send job heartbeats to the external monitor', 'momos-core' ), 'type' => 'true_false', 'default' => '1', 'edit_cap' => $sa ],
					[ 'name' => 'momo_alert_recipients', 'label' => __( 'Alert recipients (one email per line)', 'momos-core' ), 'type' => 'textarea', 'edit_cap' => $sa, 'validate' => [ self::class, 'validate_alert_recipients' ], 'help' => __( 'Head Office duty contact and vendor on-call. Empty = the site admin email.', 'momos-core' ) ],
				],
			]
		);

		FieldRegistry::group(
			[
				'key'          => 'momo_settings_security',
				'tab'          => 'security',
				'title'        => __( 'Security', 'momos-core' ),
				'options_page' => self::PAGE,
				'order'        => 70,
				'view_cap'     => $sa,
				'fields'       => [
					[ 'name' => 'momo_csp_mode', 'label' => __( 'Content Security Policy mode', 'momos-core' ), 'type' => 'select', 'default' => 'report_only', 'choices' => [ 'report_only' => __( 'Report-only', 'momos-core' ), 'enforce' => __( 'Enforced', 'momos-core' ) ], 'edit_cap' => $sa, 'help' => __( 'Switch to enforced after 2 weeks of production traffic without unresolved violations; record the switch in the release log (SEC-030).', 'momos-core' ) ],
					[ 'name' => 'momo_hsts_max_age', 'label' => __( 'HSTS max-age (ramp)', 'momos-core' ), 'type' => 'select', 'default' => '300', 'choices' => [ '300' => __( '300 s (go-live)', 'momos-core' ), '86400' => __( '1 day (after 7 clean days)', 'momos-core' ), '31536000' => __( '1 year (after 30 days)', 'momos-core' ) ], 'edit_cap' => $sa ],
					[ 'name' => 'momo_login_lock_invalid_username', 'label' => __( 'Lock an IP immediately after a login with an unknown username', 'momos-core' ), 'type' => 'true_false', 'default' => '1', 'edit_cap' => $sa ],
					[ 'name' => 'momo_2fa_enforce', 'label' => __( 'Require two-factor authentication for all staff', 'momos-core' ), 'type' => 'true_false', 'default' => '0', 'edit_cap' => $sa, 'help' => __( 'Always enforced in production. Turning it off only affects development and staging.', 'momos-core' ) ],
				],
			]
		);
	}

	public static function validate_domains( mixed $v ): ?string {
		foreach ( preg_split( '/[\s,]+/', (string) $v ) ?: [] as $d ) {
			$d = strtolower( trim( ltrim( $d, '@' ) ) );
			if ( '' !== $d && ! preg_match( '/^(?=.{3,253}$)([a-z0-9-]+\.)+[a-z]{2,}$/', $d ) ) {
				/* translators: %s: domain */
				return sprintf( __( '"%s" is not a valid mail domain.', 'momos-core' ), $d );
			}
		}
		return null;
	}

	public static function validate_gtm( mixed $v ): ?string {
		return '' === (string) $v || preg_match( '/^GTM-[A-Z0-9]+$/', (string) $v ) ? null : __( 'The container ID must look like GTM-XXXXXXX.', 'momos-core' );
	}

	public static function validate_alert_recipients( mixed $v ): ?string {
		foreach ( preg_split( '/[\s,]+/', (string) $v ) ?: [] as $e ) {
			$e = trim( $e );
			if ( '' !== $e && ! is_email( $e ) ) {
				/* translators: %s: value */
				return sprintf( __( '"%s" is not a valid email address.', 'momos-core' ), $e );
			}
		}
		return null;
	}

	/** @param array<string,mixed> $all */
	public static function validate_consent_key( mixed $v, $object_id = null, array $all = [] ): ?string {
		$key = (string) $v;
		if ( '' === $key ) {
			return null;
		}
		if ( ! preg_match( '/^PP\d+\.\d+-RP\d+\.\d+$/', $key ) ) {
			return __( 'The consent key must look like PP1.0-RP1.0.', 'momos-core' );
		}
		$keys = array_map( static fn( $r ) => (string) ( $r['consent_key'] ?? '' ), (array) ( $all['momo_consent_versions'] ?? get_option( 'momo_consent_versions', [] ) ) );
		return in_array( $key, $keys, true ) ? null : __( 'Add the consent key to the registry first.', 'momos-core' );
	}

	public static function validate_registry( mixed $v ): ?string {
		$seen = [];
		foreach ( (array) $v as $row ) {
			$k = (string) ( $row['consent_key'] ?? '' );
			if ( ! preg_match( '/^PP\d+\.\d+-RP\d+\.\d+$/', $k ) ) {
				return __( 'Every registry key must look like PP1.0-RP1.0.', 'momos-core' );
			}
			if ( isset( $seen[ $k ] ) ) {
				return __( 'Each consent key can appear only once.', 'momos-core' );
			}
			$seen[ $k ] = true;
			if ( 'PP' . ( $row['privacy_policy_version'] ?? '' ) . '-RP' . ( $row['reservation_policy_version'] ?? '' ) !== $k ) {
				return __( 'The key must match the Privacy Policy and Reservation Policy versions (PP{privacy}-RP{reservation}).', 'momos-core' );
			}
		}
		return null;
	}
}
