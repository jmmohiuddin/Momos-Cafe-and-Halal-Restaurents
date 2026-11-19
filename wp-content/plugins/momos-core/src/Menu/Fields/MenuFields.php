<?php
declare(strict_types=1);

namespace Momos\Menu\Fields;

use Momos\Kernel\Fields\FieldRegistry;
use Momos\Menu\ContentTypes;
use Momos\Menu\Domain\IngredientClass;
use Momos\Menu\Domain\SpiceLevel;

/**
 * Field groups G8 (dish content), G9 (dish pricing), G11 (menu category), ingredient class and G13
 * (dietary tag) — TRD §2.5, spec §2.3/§2.4. Meta keys are the documented `momo_*` names.
 * Publish-time completeness is checked by Service\DishPublishValidator (one combined message).
 */
final class MenuFields {
	public const G8  = 'group_momo_dish_content';
	public const G9  = 'group_momo_dish_pricing';
	public const G11 = 'group_momo_menu_category';
	public const ING = 'group_momo_ingredient';
	public const G13 = 'group_momo_dietary_tag';

	public static function register(): void {
		FieldRegistry::group(
			[
				'key'       => self::G8,
				'title'     => __( 'Content', 'momos-core' ),
				'post_type' => ContentTypes::DISH,
				'order'     => 10,
				'fields'    => [
					[
						'name'           => 'momo_short_description',
						'label'          => __( 'Short description', 'momos-core' ),
						'type'           => 'textarea',
						'maxlength'      => 160,
						'translatable'   => true,
						'translate_type' => 'textarea',
						'lccs'           => true,
						'help'           => __( 'Required to publish. Max. 160 characters; cards show two lines.', 'momos-core' ),
					],
					[
						'name'      => 'momo_original_name',
						'label'     => __( 'Original name', 'momos-core' ),
						'type'      => 'text',
						'maxlength' => 80,
						'help'      => __( 'Culinary name in its origin script or romanisation (optional).', 'momos-core' ),
					],
					[
						'name'                    => 'momo_dish_sku',
						'label'                   => __( 'Dish code', 'momos-core' ),
						'type'                    => 'text',
						'maxlength'               => 5,
						'pattern'                 => '^D\d{4}$',
						'pattern_message'         => __( 'Dish code must be D followed by 4 digits and unique.', 'momos-core' ),
						'immutable_after_publish' => true,
						'validate'                => [ self::class, 'validate_sku' ],
						'column'                  => true,
						'help'                    => __( 'D + 4 digits, e.g. D0123. Cannot be changed after publication.', 'momos-core' ),
					],
					[
						'name'     => 'momo_primary_category',
						'label'    => __( 'Primary category', 'momos-core' ),
						'type'     => 'taxonomy',
						'taxonomy' => ContentTypes::CATEGORY,
						'help'     => __( 'Must be one of the assigned categories; defines the canonical URL and breadcrumb.', 'momos-core' ),
					],
					[
						'name'          => 'momo_variant_of',
						'label'         => __( 'Variant of', 'momos-core' ),
						'type'          => 'post_object',
						'post_type'     => ContentTypes::DISH,
						'post_status'   => [ 'publish' ],
						'writes_parent' => true,
						'validate'      => [ self::class, 'validate_variant_of' ],
						'help'          => __( 'Only for branch recipe differences (own code and allergen data). Price-only differences use the branch price override.', 'momos-core' ),
					],
					[
						'name'     => 'momo_gallery',
						'label'    => __( 'Gallery', 'momos-core' ),
						'type'     => 'gallery',
						'validate' => [ self::class, 'validate_gallery' ],
						'help'     => __( 'Featured image plus gallery: 1–6 images in total.', 'momos-core' ),
					],
					[
						'name'    => 'momo_image_illustrative',
						'label'   => __( 'Illustrative images', 'momos-core' ),
						'type'    => 'true_false',
						'default' => '0',
						'message' => __( 'Images are for illustration (shows “Image for illustration” below each image)', 'momos-core' ),
					],
					[
						'name'    => 'momo_featured',
						'label'   => __( 'Featured on home', 'momos-core' ),
						'type'    => 'true_false',
						'default' => '0',
					],
					[
						'name'    => 'momo_spice_level',
						'label'   => __( 'Spice level', 'momos-core' ),
						'type'    => 'select',
						'choices' => SpiceLevel::choices(),
						'column'  => false,
					],
					[
						'name'    => 'momo_advance_order_required',
						'label'   => __( 'Advance order required', 'momos-core' ),
						'type'    => 'true_false',
						'default' => '0',
					],
					[
						'name'     => 'momo_advance_order_lead_hours',
						'label'    => __( 'Advance-order lead time (hours)', 'momos-core' ),
						'type'     => 'number',
						'step'     => 1,
						'show_if'  => [ 'momo_advance_order_required' => '1' ],
						'validate' => [ self::class, 'validate_lead_hours' ],
					],
					[
						'name'      => 'momo_external_ref',
						'label'     => __( 'External reference', 'momos-core' ),
						'type'      => 'text',
						'maxlength' => 64,
						'edit_cap'  => 'manage_options',
						'help'      => __( 'Never displayed (integration seam, DEC-056).', 'momos-core' ),
					],
					[
						'name'     => 'momo_retired',
						'label'    => __( 'Retired', 'momos-core' ),
						'type'     => 'true_false',
						'readonly' => true,
						'default'  => '0',
						'help'     => __( 'Set by the Retire action.', 'momos-core' ),
					],
					[
						'name'     => 'momo_is_sample',
						'label'    => __( 'Sample dish', 'momos-core' ),
						'type'     => 'true_false',
						'default'  => '0',
						'edit_cap' => 'momo_manage_brand',
						'message'  => __( 'Sample — cannot be published on the live site', 'momos-core' ),
					],
				],
			]
		);

		FieldRegistry::group(
			[
				'key'       => self::G9,
				'title'     => __( 'Pricing', 'momos-core' ),
				'post_type' => ContentTypes::DISH,
				'order'     => 20,
				'fields'    => [
					[
						'name'     => 'momo_reference_price_jpy',
						'label'    => __( 'Reference price (JPY, tax incl.)', 'momos-core' ),
						'type'     => 'number',
						'step'     => 1,
						'edit_cap' => 'momo_edit_branch_prices',
						'validate' => [ self::class, 'validate_reference_price' ],
						'help'     => __( 'Integer 0–100,000 yen, tax included. Required to publish.', 'momos-core' ),
					],
				],
			]
		);

		FieldRegistry::group(
			[
				'key'      => self::G11,
				'title'    => __( 'Category settings', 'momos-core' ),
				'taxonomy' => ContentTypes::CATEGORY,
				'fields'   => [
					[ 'name' => 'momo_cat_order', 'label' => __( 'Display order', 'momos-core' ), 'type' => 'number', 'step' => 1, 'min' => 0, 'max' => 9999, 'default' => '0' ],
					[
						'name'     => 'momo_cat_image',
						'label'    => __( 'Category image', 'momos-core' ),
						'type'     => 'image',
						'validate' => [ self::class, 'validate_image_alt' ],
						'help'     => __( 'Alternative text is required (set it in the media library).', 'momos-core' ),
					],
				],
			]
		);

		FieldRegistry::group(
			[
				'key'      => self::ING,
				'title'    => __( 'Ingredient settings', 'momos-core' ),
				'taxonomy' => ContentTypes::INGREDIENT,
				'fields'   => [
					[ 'name' => 'momo_ing_class', 'label' => __( 'Ingredient class', 'momos-core' ), 'type' => 'select', 'choices' => IngredientClass::choices(), 'default' => 'other' ],
				],
			]
		);

		FieldRegistry::group(
			[
				'key'      => self::G13,
				'title'    => __( 'Dietary tag settings', 'momos-core' ),
				'taxonomy' => ContentTypes::TAG,
				'fields'   => [
					[ 'name' => 'momo_tag_code', 'label' => __( 'Tag code', 'momos-core' ), 'type' => 'text', 'maxlength' => 40, 'pattern' => '^[a-z0-9_]{2,40}$' ],
					[ 'name' => 'momo_tag_icon', 'label' => __( 'Icon', 'momos-core' ), 'type' => 'image' ],
					[
						'name'     => 'momo_tag_is_claim',
						'label'    => __( 'Claim tag', 'momos-core' ),
						'type'     => 'true_false',
						'default'  => '0',
						'edit_cap' => 'momo_edit_dietary_claims',
						'message'  => __( 'Claim-natured tag (assignable only by Dietary Claims Approvers)', 'momos-core' ),
					],
				],
			]
		);
	}

	// ----------------------------------------------------------------- validators

	public static function validate_sku( mixed $value, int|string $post_id ): ?string {
		$sku = (string) $value;
		if ( '' === $sku ) {
			return null;
		}
		if ( ! preg_match( '/^D\d{4}$/', $sku ) || self::sku_taken( $sku, (int) $post_id ) ) {
			return __( 'Dish code must be D followed by 4 digits and unique.', 'momos-core' );
		}
		return null;
	}

	public static function sku_taken( string $sku, int $exclude_id ): bool {
		global $wpdb;
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT pm.post_id FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = 'momo_dish_sku' AND pm.meta_value = %s AND p.post_type = %s AND p.post_status NOT IN ('auto-draft','trash') AND pm.post_id <> %d LIMIT 1",
				$sku,
				ContentTypes::DISH,
				$exclude_id
			)
		);
		return null !== $found;
	}

	public static function validate_variant_of( mixed $value, int|string $post_id ): ?string {
		$base_id = (int) $value;
		if ( $base_id <= 0 ) {
			return null;
		}
		$base = get_post( $base_id );
		$msg  = __( 'Choose a published dish that is not itself a variant.', 'momos-core' );
		if ( ! $base || ContentTypes::DISH !== $base->post_type || 'publish' !== $base->post_status || (int) $base->post_parent > 0 || $base_id === (int) $post_id ) {
			return $msg;
		}
		// One level only: a dish that has variants cannot become a variant.
		$children = get_posts( [ 'post_type' => ContentTypes::DISH, 'post_parent' => (int) $post_id, 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 1 ] );
		return ( (int) $post_id > 0 && $children ) ? __( 'This dish has variants and cannot itself become a variant.', 'momos-core' ) : null;
	}

	public static function validate_gallery( mixed $value, int|string $post_id ): ?string {
		$count = count( (array) $value );
		$thumb = isset( $_POST['_thumbnail_id'] ) ? (int) $_POST['_thumbnail_id'] : (int) get_post_thumbnail_id( (int) $post_id ); // phpcs:ignore
		if ( $thumb > 0 ) {
			++$count;
		}
		return $count > 6 ? __( 'A dish can have at most 6 images (featured image plus gallery).', 'momos-core' ) : null;
	}

	/** @param array<string,mixed> $all */
	public static function validate_lead_hours( mixed $value, int|string $post_id, array $all = [] ): ?string {
		if ( '1' !== (string) ( $all['momo_advance_order_required'] ?? '0' ) || '' === (string) $value ) {
			return null;
		}
		$v = (string) $value;
		return ( ctype_digit( $v ) && (int) $v >= 1 && (int) $v <= 168 ) ? null : __( 'Enter the advance-order time in hours (1–168).', 'momos-core' );
	}

	public static function validate_reference_price( mixed $value, int|string $post_id ): ?string {
		$v = (string) $value;
		if ( '' === $v ) {
			return null;
		}
		if ( ! ctype_digit( $v ) || (int) $v > 100000 ) {
			return __( 'Price must be between 0 and 100,000 yen.', 'momos-core' );
		}
		$old = (string) get_post_meta( (int) $post_id, 'momo_reference_price_jpy', true );
		if ( '' !== $old && (int) $old > 0 && abs( (int) $v - (int) $old ) / (int) $old > 0.3 && empty( $_POST['momos_confirm_large_price'] ) ) { // phpcs:ignore -- nonce checked by FieldRegistry.
			return __( 'The reference price changes by more than 30 %. Tick “Confirm large price change” and save again.', 'momos-core' );
		}
		return null;
	}

	public static function validate_image_alt( mixed $value ): ?string {
		$id = (int) $value;
		if ( $id <= 0 ) {
			return null;
		}
		return '' === trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) ? __( 'The image needs alternative text (set it in the media library).', 'momos-core' ) : null;
	}
}
