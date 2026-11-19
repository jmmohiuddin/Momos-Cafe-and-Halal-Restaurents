<?php
declare(strict_types=1);

namespace Momos\Presentation\Admin;

use Momos\Kernel\Fields\FieldRegistry;
use Momos\Presentation\View\Brand;

/**
 * Brand settings additions owned by Presentation (FR-FND-005, SCR-A15 "Brand" tab, options page `momo-brand`):
 *  - (a) registered address (translatable, ⟦TBD — Owner⟧) next to the G1 group;
 *  - "Required brand settings are incomplete" notice for ROLE-01/02 (`momo_manage_brand`) listing the missing
 *    launch fields: legal operator name, primary logo, contact phone, contact email, the five legal pages;
 *  - saving the Brand page purges the global cache tag (`momo-global`): footer ©, logo, contact data and legal
 *    links render on every page (AC-FND-01-10 "appears … after cache purge").
 */
final class BrandSettings {
	public const ADDRESS = 'momo_registered_address';

	/**
	 * Required launch fields => admin label (FR-FND-005(a), BrandFields "Required before launch").
	 *
	 * @return array<string,string>
	 */
	public static function required(): array {
		return [
			'momo_legal_operator_name'     => __( 'Legal operator name', 'momos-core' ),
			'momo_logo_primary'            => __( 'Logo (primary)', 'momos-core' ),
			'momo_contact_phone_e164'      => __( 'Contact phone (E.164)', 'momos-core' ),
			'momo_contact_email'           => __( 'Contact email', 'momos-core' ),
			'momo_privacy_page'            => __( 'Privacy Policy page', 'momos-core' ),
			'momo_reservation_policy_page' => __( 'Reservation Policy page', 'momos-core' ),
			'momo_terms_page'              => __( 'Terms of Use page', 'momos-core' ),
			'momo_external_tx_page'        => __( 'External Transmission & Cookies page', 'momos-core' ),
			'momo_accessibility_page'      => __( 'Accessibility page', 'momos-core' ),
		];
	}

	public static function register(): void {
		add_action( 'init', [ self::class, 'register_fields' ], 10 );
		add_action( 'admin_notices', [ self::class, 'notice' ] );
		add_action( 'momos/options_page/saved', [ self::class, 'purge' ], 1 );
	}

	public static function register_fields(): void {
		FieldRegistry::group(
			[
				'key'          => 'group_momo_brand_address',
				'title'        => __( 'Registered address', 'momos-core' ),
				'options_page' => Brand::PAGE,
				'order'        => 11,
				'fields'       => [
					[ 'name' => self::ADDRESS, 'label' => __( 'Registered address', 'momos-core' ), 'type' => 'textarea', 'maxlength' => 300, 'translatable' => true, 'translate_type' => 'textarea', 'edit_cap' => 'momo_manage_brand', 'help' => __( 'Registered business address of the operator (owner data). Hidden on the site while empty.', 'momos-core' ) ],
				],
			]
		);
	}

	/** @return array<string,string> missing required field => label */
	public static function missing(): array {
		$out = [];
		foreach ( self::required() as $name => $label ) {
			$v     = Brand::option( $name );
			$v     = is_scalar( $v ) ? trim( (string) $v ) : '';
			$is_id = str_ends_with( $name, '_page' ) || 'momo_logo_primary' === $name;
			if ( '' === $v || ( $is_id && (int) $v <= 0 ) ) {
				$out[ $name ] = $label;
			}
		}
		return $out;
	}

	public static function notice(): void {
		if ( ! current_user_can( 'momo_manage_brand' ) ) {
			return;
		}
		$missing = self::missing();
		if ( ! $missing ) {
			return;
		}
		echo '<div class="notice notice-warning" data-momos-brand-incomplete><p><strong>' . esc_html__( 'Required brand settings are incomplete', 'momos-core' ) . '</strong> — ';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=' . Brand::PAGE ) ) . '">' . esc_html__( 'Brand settings', 'momos-core' ) . '</a></p><ul style="list-style:disc;padding-inline-start:20px">';
		foreach ( $missing as $label ) {
			echo '<li>' . esc_html( $label ) . '</li>';
		}
		echo '</ul></div>';
	}

	/** @param mixed $slug */
	public static function purge( $slug ): void {
		if ( Brand::PAGE === $slug ) {
			do_action( 'momos/cache/purge', [ 'momo-global' ] );
		}
	}
}
