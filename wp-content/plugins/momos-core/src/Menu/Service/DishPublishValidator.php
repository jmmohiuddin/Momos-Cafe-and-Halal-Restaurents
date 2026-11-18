<?php
declare(strict_types=1);

namespace Momos\Menu\Service;

use Momos\Kernel\Env;
use Momos\Kernel\Fields\FieldRegistry;
use Momos\Menu\ContentTypes;
use Momos\Menu\Fields\MenuFields;

/**
 * Publish validation of a dish (FR-MENU-004, spec §3.8, TC-DISH-003, AC-MENU-03-02/07).
 * Hooked on `momos/fields/publish_errors/momo_dish` (FieldRegistry downgrades a new publication to
 * draft when errors are returned). Allergen states may be `not_verified` (warning only).
 */
final class DishPublishValidator {
	public static function register(): void {
		add_filter( 'momos/fields/publish_errors/' . ContentTypes::DISH, [ self::class, 'filter' ], 10, 4 );
	}

	/**
	 * @param list<string>        $errors
	 * @param array<string,mixed> $values merged field values after this save
	 * @param array<string,mixed> $data   post data
	 * @return list<string>
	 */
	public static function filter( array $errors, array $values, array $data, int $post_id ): array {
		// FieldRegistry's generic "title required" message is replaced by the combined list below.
		$errors = array_values( array_filter( $errors, static fn( $e ) => __( 'A title is required to publish.', 'momos-core' ) !== $e ) );
		return array_merge( $errors, self::errors( $values, $post_id, (string) ( $data['post_title'] ?? '' ), self::submitted_categories( $post_id ), self::submitted_thumbnail( $post_id ) ) );
	}

	/**
	 * @param array<string,mixed> $v
	 * @param list<int>           $category_ids
	 * @return list<string>
	 */
	public static function errors( array $v, int $post_id, string $title, array $category_ids, int $thumbnail_id ): array {
		$missing = [];
		$errors  = [];
		$title   = trim( $title );
		if ( '' === $title ) {
			$missing[] = __( 'name', 'momos-core' );
		} elseif ( mb_strlen( $title ) > 100 ) {
			$errors[] = __( 'The dish name can have at most 100 characters.', 'momos-core' );
		}
		if ( '' === trim( (string) ( $v['momo_short_description'] ?? '' ) ) ) {
			$missing[] = __( 'short description', 'momos-core' );
		}
		$primary = (int) ( $v['momo_primary_category'] ?? 0 );
		if ( ! $category_ids ) {
			$missing[] = __( 'category', 'momos-core' );
		}
		if ( $primary <= 0 ) {
			$missing[] = __( 'primary category', 'momos-core' );
		} elseif ( $category_ids && ! in_array( $primary, $category_ids, true ) ) {
			$errors[] = __( 'The primary category must be one of the assigned categories.', 'momos-core' );
		}
		if ( $thumbnail_id <= 0 ) {
			$missing[] = __( 'featured image', 'momos-core' );
		} elseif ( '' === trim( (string) get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true ) ) ) {
			$missing[] = __( 'Japanese alternative text of the featured image', 'momos-core' );
		}
		$sku = (string) ( $v['momo_dish_sku'] ?? '' );
		if ( '' === $sku ) {
			$missing[] = __( 'dish code', 'momos-core' );
		} elseif ( ! preg_match( '/^D\d{4}$/', $sku ) || MenuFields::sku_taken( $sku, $post_id ) ) {
			$errors[] = __( 'Dish code must be D followed by 4 digits and unique.', 'momos-core' );
		}
		$price = (string) ( $v['momo_reference_price_jpy'] ?? '' );
		if ( '' === $price ) {
			$missing[] = __( 'reference price', 'momos-core' );
		} elseif ( ! ctype_digit( $price ) || (int) $price > 100000 ) {
			$errors[] = __( 'Price must be between 0 and 100,000 yen.', 'momos-core' );
		}
		$spice = (string) ( $v['momo_spice_level'] ?? '' );
		if ( '' === $spice ) {
			$missing[] = __( 'spice level', 'momos-core' );
		} elseif ( ! in_array( $spice, [ '0', '1', '2', '3' ], true ) ) {
			$errors[] = __( 'Spice level must be 0–3.', 'momos-core' );
		}
		if ( array_key_exists( 'momo_dish_halal_basis', $v ) && '' === (string) $v['momo_dish_halal_basis'] ) {
			$missing[] = __( 'halal basis', 'momos-core' );
		}
		if ( '1' === (string) ( $v['momo_advance_order_required'] ?? '0' ) ) {
			$h = (string) ( $v['momo_advance_order_lead_hours'] ?? '' );
			if ( ! ctype_digit( $h ) || (int) $h < 1 || (int) $h > 168 ) {
				$errors[] = __( 'Enter the advance-order time in hours (1–168).', 'momos-core' );
			}
		}
		if ( Env::is_production() && '1' === (string) ( $v['momo_is_sample'] ?? '0' ) ) {
			$errors[] = __( 'Sample dishes cannot be published on the live site', 'momos-core' );
		}
		if ( $missing ) {
			/* translators: %s: comma-separated list of missing fields */
			array_unshift( $errors, sprintf( __( 'Complete required fields before publishing: %s.', 'momos-core' ), implode( ', ', $missing ) ) );
		}
		return $errors;
	}

	/** Validate a stored dish (programmatic checks, CLI, tests). @return list<string> */
	public static function errors_for_post( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post || ContentTypes::DISH !== $post->post_type ) {
			return [ __( 'Not a dish.', 'momos-core' ) ];
		}
		$values = [];
		foreach ( FieldRegistry::groups_for( 'post:' . ContentTypes::DISH ) as $g ) {
			$values += FieldRegistry::post_values( $post_id, $g );
		}
		$cats = wp_get_object_terms( $post_id, ContentTypes::CATEGORY, [ 'fields' => 'ids' ] );
		return self::errors( $values, $post_id, $post->post_title, is_array( $cats ) ? array_map( 'intval', $cats ) : [], (int) get_post_thumbnail_id( $post_id ) );
	}

	/** @return list<int> categories as submitted in the classic editor, else stored. */
	private static function submitted_categories( int $post_id ): array {
		// phpcs:disable WordPress.Security.NonceVerification -- read-only, core verified the edit nonce.
		if ( isset( $_POST['tax_input'][ ContentTypes::CATEGORY ] ) ) {
			return array_values( array_filter( array_map( 'intval', (array) wp_unslash( $_POST['tax_input'][ ContentTypes::CATEGORY ] ) ) ) );
		}
		// phpcs:enable
		if ( $post_id <= 0 ) {
			return [];
		}
		$cats = wp_get_object_terms( $post_id, ContentTypes::CATEGORY, [ 'fields' => 'ids' ] );
		return is_array( $cats ) ? array_map( 'intval', $cats ) : [];
	}

	private static function submitted_thumbnail( int $post_id ): int {
		if ( isset( $_POST['_thumbnail_id'] ) ) { // phpcs:ignore
			return (int) $_POST['_thumbnail_id']; // phpcs:ignore
		}
		return $post_id > 0 ? (int) get_post_thumbnail_id( $post_id ) : 0;
	}
}
