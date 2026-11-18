<?php
declare(strict_types=1);

namespace Momos\Dietary\Admin;

use Momos\Dietary\Guard\ClaimFieldGuard;
use Momos\Dietary\Service\AllergenPresenter;
use Momos\Kernel\I18n\Locale;

/**
 * SCR-A06 "Dietary & allergens" status box: lock notice for non-CAP-DCA users, "Ingredients changed —
 * re-verify allergens" banner while the review flag is set, unverified count and stamps.
 */
final class DishDietaryBox {
	public static function register(): void {
		add_action( 'add_meta_boxes_momo_dish', [ self::class, 'add' ] );
	}

	public static function add(): void {
		add_meta_box( 'momos-dish-dietary-status', __( 'Allergen verification', 'momos-core' ), [ self::class, 'render' ], 'momo_dish', 'side', 'default' );
	}

	public static function render( \WP_Post $post ): void {
		if ( ! current_user_can( ClaimFieldGuard::CAP ) ) {
			echo '<p>🔒 ' . esc_html__( 'Only Dietary Claims Approvers can change these fields', 'momos-core' ) . '</p>';
		}
		if ( '1' === (string) get_post_meta( $post->ID, 'momo_allergen_review_required', true ) ) {
			echo '<p class="notice notice-warning inline" role="note">' . esc_html__( 'Ingredients changed — re-verify allergens', 'momos-core' ) . '</p>';
		}
		$codes = AllergenPresenter::not_verified_codes( $post->ID );
		echo '<p>' . esc_html( $codes ? sprintf( /* translators: %d: count */ _n( '%d allergen not verified', '%d allergens not verified', count( $codes ), 'momos-core' ), count( $codes ) ) : __( 'All allergens verified', 'momos-core' ) ) . '</p>';
		if ( $codes && count( $codes ) <= 12 ) {
			echo '<p class="description">' . esc_html( implode( ', ', AllergenPresenter::names( $codes, Locale::admin() ) ) ) . '</p>';
		}
		$by = (int) get_post_meta( $post->ID, 'momo_claims_verified_by', true );
		$at = (string) get_post_meta( $post->ID, 'momo_claims_verified_at', true );
		if ( $by && $at ) {
			$u = get_userdata( $by );
			/* translators: 1: user 2: UTC datetime */
			echo '<p class="description">' . esc_html( sprintf( __( 'Last verified by %1$s at %2$s (UTC)', 'momos-core' ), $u ? $u->display_name : '#' . $by, $at ) ) . '</p>';
		}
	}
}
