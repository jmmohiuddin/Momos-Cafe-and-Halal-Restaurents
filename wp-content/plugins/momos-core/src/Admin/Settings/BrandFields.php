<?php
declare(strict_types=1);

namespace Momos\Admin\Settings;

use Momos\Kernel\Fields\FieldRegistry;
use Momos\Kernel\Fields\OptionsPage;

/**
 * G1 `group_momo_brand` on options page `momo-brand` (ENT-01, TRD §2.5 G1, SCR-A15 "Brand" tab;
 * page cap `momo_manage_brand`). Option names are the documented ones; text fields marked T are
 * translatable through the translation editor. Saves are audited `brand.updated` (Audit module).
 */
final class BrandFields {
	public const PAGE = 'momo-brand';

	public static function register(): void {
		add_action( 'init', [ self::class, 'register_groups' ], 9 );
	}

	public static function register_groups(): void {
		OptionsPage::add( self::PAGE, __( 'Brand', 'momos-core' ), 'momo_manage_brand' );
		$cap    = 'momo_manage_brand';
		$pages  = [
			'momo_privacy_page'            => __( 'Privacy Policy page', 'momos-core' ),
			'momo_reservation_policy_page' => __( 'Reservation Policy page', 'momos-core' ),
			'momo_terms_page'              => __( 'Terms of Use page', 'momos-core' ),
			'momo_external_tx_page'        => __( 'External Transmission & Cookies page', 'momos-core' ),
			'momo_accessibility_page'      => __( 'Accessibility page', 'momos-core' ),
		];
		$fields = [
			[ 'name' => 'momo_trading_name', 'label' => __( 'Trading name', 'momos-core' ), 'type' => 'text', 'required' => true, 'maxlength' => 100, 'translatable' => true, 'lccs' => true, 'edit_cap' => $cap, 'validate' => [ self::class, 'validate_length_100' ] ],
			[ 'name' => 'momo_tagline', 'label' => __( 'Tagline', 'momos-core' ), 'type' => 'text', 'maxlength' => 120, 'translatable' => true, 'edit_cap' => $cap, 'help' => __( 'Optional. No superlatives or unverifiable claims.', 'momos-core' ) ],
			[ 'name' => 'momo_legal_operator_name', 'label' => __( 'Legal operator name', 'momos-core' ), 'type' => 'text', 'maxlength' => 150, 'translatable' => true, 'edit_cap' => $cap, 'help' => __( 'Hidden on the site while empty (Q-024).', 'momos-core' ) ],
			[ 'name' => 'momo_logo_primary', 'label' => __( 'Logo (primary)', 'momos-core' ), 'type' => 'image', 'edit_cap' => $cap, 'help' => __( 'PNG or WebP; SVG is not accepted. Required before launch.', 'momos-core' ), 'validate' => [ self::class, 'validate_logo' ] ],
			[ 'name' => 'momo_logo_inverse', 'label' => __( 'Logo (inverse, for dark backgrounds)', 'momos-core' ), 'type' => 'image', 'edit_cap' => $cap, 'validate' => [ self::class, 'validate_logo' ] ],
			[ 'name' => 'momo_contact_email', 'label' => __( 'Contact email', 'momos-core' ), 'type' => 'email', 'edit_cap' => $cap, 'validate' => [ self::class, 'validate_business_email' ] ],
			[ 'name' => 'momo_contact_phone_e164', 'label' => __( 'Contact phone (E.164)', 'momos-core' ), 'type' => 'tel', 'edit_cap' => $cap, 'pattern' => '^\+81\d{9,10}$', 'validate' => [ self::class, 'validate_phone' ], 'help' => __( 'Format +81 followed by 9–10 digits, e.g. +81312345678.', 'momos-core' ) ],
			[ 'name' => 'momo_contact_phone_display', 'label' => __( 'Contact phone (display)', 'momos-core' ), 'type' => 'text', 'maxlength' => 30, 'edit_cap' => $cap ],
			[
				'name'         => 'momo_social_links',
				'label'        => __( 'Social links', 'momos-core' ),
				'type'         => 'repeater',
				'max_rows'     => 10,
				'edit_cap'     => $cap,
				'button_label' => __( 'Add link', 'momos-core' ),
				'sub_fields'   => [
					[ 'name' => 'network', 'label' => __( 'Network', 'momos-core' ), 'type' => 'select', 'required' => true, 'choices' => [ 'instagram' => 'Instagram', 'facebook' => 'Facebook', 'x' => 'X', 'youtube' => 'YouTube', 'tiktok' => 'TikTok', 'line' => 'LINE', 'other' => __( 'Other', 'momos-core' ) ] ],
					[ 'name' => 'url', 'label' => __( 'URL', 'momos-core' ), 'type' => 'url', 'required' => true, 'https_only' => true ],
				],
			],
			[ 'name' => 'momo_brand_halal_statement_key', 'label' => __( 'Brand halal statement', 'momos-core' ), 'type' => 'select', 'choices' => self::statement_choices(), 'edit_cap' => 'momo_edit_dietary_claims', 'help' => __( 'Approved statement templates only. Dietary Claims Approvers only.', 'momos-core' ) ],
		];
		foreach ( $pages as $name => $label ) {
			$fields[] = [ 'name' => $name, 'label' => $label, 'type' => 'post_object', 'post_type' => 'page', 'edit_cap' => $cap, 'help' => __( 'Required before launch.', 'momos-core' ) ];
		}
		FieldRegistry::group(
			[
				'key'          => 'group_momo_brand',
				'title'        => __( 'Brand', 'momos-core' ),
				'options_page' => self::PAGE,
				'order'        => 10,
				'fields'       => $fields,
			]
		);
	}

	/** @return array<string,string> */
	private static function statement_choices(): array {
		$choices = [ '' => __( '— None —', 'momos-core' ) ];
		if ( class_exists( \Momos\Dietary\Service\StatementRepository::class ) ) {
			try {
				$choices += \Momos\Dietary\Service\StatementRepository::choices( [ 'certified', 'halal_ingredients', 'no_claim' ] );
			} catch ( \Throwable $e ) {
				unset( $e ); // Statements unavailable during early boot.
			}
		}
		return $choices;
	}

	public static function validate_length_100( mixed $v ): ?string {
		return mb_strlen( (string) $v ) > 100 ? __( 'The trading name can be at most 100 characters.', 'momos-core' ) : null;
	}

	/** FR-ADM-005: PNG/WebP only for logos. */
	public static function validate_logo( mixed $v ): ?string {
		$id = (int) $v;
		if ( $id <= 0 ) {
			return null;
		}
		$mime = (string) get_post_mime_type( $id );
		return in_array( $mime, [ 'image/png', 'image/webp' ], true ) ? null : __( 'The logo must be a PNG or WebP image (SVG is not accepted).', 'momos-core' );
	}

	public static function validate_phone( mixed $v ): ?string {
		return '' === (string) $v || preg_match( '/^\+81\d{9,10}$/', (string) $v ) ? null : __( 'Enter the phone number as +81 followed by 9–10 digits.', 'momos-core' );
	}

	public static function validate_business_email( mixed $v ): ?string {
		if ( '' === (string) $v ) {
			return null;
		}
		return \Momos\Access\Admin\UserAccessFields::personal_mail_domain( (string) $v ) ? __( 'Use a business email address, not personal webmail (DEC-049).', 'momos-core' ) : null;
	}
}
