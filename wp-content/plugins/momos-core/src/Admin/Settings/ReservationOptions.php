<?php
declare(strict_types=1);

namespace Momos\Admin\Settings;

use Momos\Kernel\Fields\FieldRegistry;
use Momos\Kernel\Fields\Renderer;
use Momos\Kernel\Fields\Sanitizer;

/**
 * SCR-A15 "Reservations" tab: global reservation settings and the consent registry (TRD G3,
 * reservation.md §2.4, Access spec §2.4/§10.2). Stored as plain WordPress options with the
 * documented names (autoload off) because the Reservation and Branch modules read them with
 * `get_option()` (reservation.md: "WordPress options with the same names"). Rendered with the
 * kernel field renderer; every save is validated server-side and audited `settings.updated`.
 */
final class ReservationOptions {
	public const ACTION = 'momos_save_reservation_settings';
	public const CAP    = 'momo_manage_brand';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'save' ] );
	}

	/** @return list<array<string,mixed>> normalised field definitions */
	public static function fields(): array {
		$brand  = self::CAP;
		$fields = [
			[ 'name' => 'momo_res_global_enabled', 'label' => __( 'Accept reservation requests (all branches)', 'momos-core' ), 'type' => 'true_false', 'default' => '1', 'edit_cap' => $brand, 'help' => __( 'Kill switch: when off, every branch shows "not accepting requests".', 'momos-core' ) ],
			[ 'name' => 'momo_res_status_page_enabled', 'label' => __( 'Customer status page enabled', 'momos-core' ), 'type' => 'true_false', 'default' => '1', 'edit_cap' => $brand ],
			[ 'name' => 'momo_soft_duplicate_minutes', 'label' => __( 'Duplicate request window (minutes)', 'momos-core' ), 'type' => 'number', 'default' => 10, 'min' => 1, 'max' => 120, 'step' => 1, 'required' => true, 'edit_cap' => $brand ],
			[ 'name' => 'momo_min_decision_window_minutes', 'label' => __( 'Minimum decision window (minutes)', 'momos-core' ), 'type' => 'number', 'default' => 30, 'min' => 0, 'max' => 1440, 'step' => 5, 'required' => true, 'edit_cap' => $brand, 'help' => __( 'BR-RES-15: time a branch needs before the requested time to decide.', 'momos-core' ) ],
			[ 'name' => 'momo_rate_ip_per_hour', 'label' => __( 'Requests per IP per hour', 'momos-core' ), 'type' => 'number', 'default' => 5, 'min' => 1, 'max' => 100, 'step' => 1, 'required' => true, 'edit_cap' => $brand ],
			[ 'name' => 'momo_rate_email_per_hour', 'label' => __( 'Requests per email per hour', 'momos-core' ), 'type' => 'number', 'default' => 3, 'min' => 1, 'max' => 50, 'step' => 1, 'required' => true, 'edit_cap' => $brand ],
			[ 'name' => 'momo_rate_ip_degraded_per_hour', 'label' => __( 'Requests per IP per hour while the captcha is degraded', 'momos-core' ), 'type' => 'number', 'default' => 2, 'min' => 1, 'max' => 50, 'step' => 1, 'required' => true, 'edit_cap' => $brand ],
			[ 'name' => 'momo_carrier_domains', 'label' => __( 'Mobile carrier mail domains', 'momos-core' ), 'type' => 'textarea', 'default' => SettingsFields::CARRIER_DOMAINS, 'edit_cap' => $brand, 'validate' => [ SettingsFields::class, 'validate_domains' ], 'help' => __( 'One domain per line. Customers with these domains see the "allow our address" tip (FR-RES-029).', 'momos-core' ) ],
			[ 'name' => 'momo_customer_whitelist_domain_tip', 'label' => __( 'Domain to allow (shown in the carrier tip)', 'momos-core' ), 'type' => 'text', 'maxlength' => 100, 'edit_cap' => $brand ],
			[ 'name' => 'momo_export_max_range_months', 'label' => __( 'Export: maximum date range (months)', 'momos-core' ), 'type' => 'number', 'default' => 12, 'min' => 1, 'max' => 24, 'step' => 1, 'required' => true, 'edit_cap' => $brand ],
			[ 'name' => 'momo_export_max_rows', 'label' => __( 'Export: maximum rows', 'momos-core' ), 'type' => 'number', 'default' => 10000, 'min' => 100, 'max' => 50000, 'step' => 100, 'required' => true, 'edit_cap' => $brand ],
			[ 'name' => 'momo_recipient_blocked_domains', 'label' => __( 'Blocked recipient domains (personal webmail)', 'momos-core' ), 'type' => 'textarea', 'edit_cap' => $brand, 'validate' => [ SettingsFields::class, 'validate_domains' ], 'help' => __( 'One domain per line; empty = documented default list (DEC-049).', 'momos-core' ) ],
			[ 'name' => 'momo_allowed_recipient_domains', 'label' => __( 'Allowed recipient domains (optional allow-list)', 'momos-core' ), 'type' => 'textarea', 'edit_cap' => $brand, 'validate' => [ SettingsFields::class, 'validate_domains' ] ],
			[ 'name' => 'momo_special_request_separate_consent', 'label' => __( 'Separate consent for health/allergy information in the special request', 'momos-core' ), 'type' => 'true_false', 'default' => '0', 'edit_cap' => $brand ],
			[
				'name'         => 'momo_consent_versions',
				'label'        => __( 'Consent registry', 'momos-core' ),
				'type'         => 'repeater',
				'max_rows'     => 50,
				'edit_cap'     => $brand,
				'button_label' => __( 'Add version', 'momos-core' ),
				'validate'     => [ SettingsFields::class, 'validate_registry' ],
				'sub_fields'   => [
					[ 'name' => 'consent_key', 'label' => __( 'Key', 'momos-core' ), 'type' => 'text', 'required' => true, 'maxlength' => 20 ],
					[ 'name' => 'privacy_policy_version', 'label' => __( 'Privacy Policy version', 'momos-core' ), 'type' => 'text', 'required' => true, 'maxlength' => 10 ],
					[ 'name' => 'reservation_policy_version', 'label' => __( 'Reservation Policy version', 'momos-core' ), 'type' => 'text', 'required' => true, 'maxlength' => 10 ],
					[ 'name' => 'privacy_page_revision_id', 'label' => __( 'Privacy page revision ID', 'momos-core' ), 'type' => 'number', 'min' => 0 ],
					[ 'name' => 'reservation_policy_revision_id', 'label' => __( 'Reservation Policy revision ID', 'momos-core' ), 'type' => 'number', 'min' => 0 ],
					[ 'name' => 'effective_from', 'label' => __( 'Effective from (JST)', 'momos-core' ), 'type' => 'date', 'required' => true ],
				],
			],
			[ 'name' => 'momo_consent_version_current', 'label' => __( 'Current consent version key', 'momos-core' ), 'type' => 'text', 'maxlength' => 20, 'pattern' => '^PP\d+\.\d+-RP\d+\.\d+$', 'edit_cap' => $brand, 'validate' => [ SettingsFields::class, 'validate_consent_key' ], 'help' => __( 'Format PP{privacy version}-RP{reservation policy version}, e.g. PP1.0-RP1.0. Must exist in the registry below.', 'momos-core' ) ],
		];
		return array_map(
			static function ( array $f ): array {
				$f['label'] = $f['label'] ?? $f['name'];
				if ( 'repeater' === $f['type'] ) {
					$f['sub_fields'] = array_map( static fn( $s ) => $s + [ 'label' => $s['name'] ], $f['sub_fields'] );
				}
				return $f;
			},
			$fields
		);
	}

	/** @return array<string,mixed> current values (plain options; documented defaults) */
	public static function values(): array {
		$out = [];
		foreach ( self::fields() as $f ) {
			$v                = get_option( $f['name'], null );
			$out[ $f['name'] ] = ( null === $v || false === $v ) ? ( $f['default'] ?? null ) : $v;
		}
		return $out;
	}

	public static function render_form(): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		wp_nonce_field( self::ACTION, '_momos_res_nonce' );
		$fields = self::fields();
		$split  = array_search( 'momo_special_request_separate_consent', array_column( $fields, 'name' ), true );
		echo '<h2>' . esc_html__( 'Reservations (global)', 'momos-core' ) . '</h2>';
		Renderer::group( [ 'key' => 'momo_settings_reservations', 'fields' => array_slice( $fields, 0, (int) $split ) ], self::values(), 'momo-settings', 'option' );
		echo '<h2>' . esc_html__( 'Consent registry', 'momos-core' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Every new Privacy Policy or Reservation Policy version needs a registry row before it is published; reservations store the current key.', 'momos-core' ) . '</p>';
		Renderer::group( [ 'key' => 'momo_settings_consent', 'fields' => array_slice( $fields, (int) $split ) ], self::values(), 'momo-settings', 'option' );
		submit_button();
		echo '</form>';
	}

	public static function save(): void {
		if ( ! current_user_can( self::CAP ) ) {
			momos_audit( 'access.denied', 'settings', null, null, [ 'cap' => self::CAP, 'channel' => 'admin-post', 'route' => self::ACTION ] );
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'momos-core' ), 403 );
		}
		check_admin_referer( self::ACTION, '_momos_res_nonce' );
		$submitted = isset( $_POST['momos_fields'] ) && is_array( $_POST['momos_fields'] ) ? wp_unslash( $_POST['momos_fields'] ) : []; // phpcs:ignore -- sanitised per field.
		$errors    = self::apply( $submitted );
		if ( $errors ) {
			FieldRegistry::add_errors( $errors );
		}
		wp_safe_redirect( add_query_arg( [ 'page' => 'momo-settings', 'tab' => 'reservations', 'updated' => $errors ? null : 1 ], admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Validate and store submitted values; nothing is stored when any field is invalid.
	 *
	 * @param array<string,mixed> $submitted
	 * @return list<string> errors
	 */
	public static function apply( array $submitted ): array {
		$current = self::values();
		$new_all = $current;
		$errors  = [];
		$changes = [];
		foreach ( self::fields() as $f ) {
			$name = $f['name'];
			$raw  = $submitted[ $name ] ?? ( in_array( $f['type'], [ 'true_false', 'repeater' ], true ) ? null : $current[ $name ] );
			$new  = Sanitizer::sanitize( $f, $raw );
			$err  = Sanitizer::validate( $f, $new );
			if ( null === $err && ! empty( $f['required'] ) && FieldRegistry::is_empty( $new ) ) {
				/* translators: %s: field label */
				$err = sprintf( __( '"%s" is required.', 'momos-core' ), wp_strip_all_tags( (string) $f['label'] ) );
			}
			if ( null === $err && isset( $f['validate'] ) && is_callable( $f['validate'] ) ) {
				$err = call_user_func( $f['validate'], $new, 'momo-settings', array_merge( $new_all, [ $name => $new ] ) );
			}
			if ( null !== $err ) {
				$errors[] = $err;
				continue;
			}
			$new_all[ $name ] = $new;
			if ( ! self::same( $current[ $name ], $new ) ) {
				$changes[ $name ] = [ 'from' => $current[ $name ], 'to' => $new ];
			}
		}
		if ( $errors ) {
			return $errors;
		}
		foreach ( $changes as $name => $pair ) {
			if ( false === get_option( $name, false ) ) {
				add_option( $name, $pair['to'], '', false );
			} else {
				update_option( $name, $pair['to'], false );
			}
		}
		if ( $changes ) {
			momos_audit( 'settings.updated', 'settings', null, null, [ 'page' => 'momo-settings', 'tab' => 'reservations', 'changes' => $changes ] );
		}
		return [];
	}

	private static function same( mixed $a, mixed $b ): bool {
		if ( is_array( $a ) || is_array( $b ) ) {
			return wp_json_encode( $a ?? [] ) === wp_json_encode( $b ?? [] );
		}
		return (string) $a === (string) $b;
	}
}
