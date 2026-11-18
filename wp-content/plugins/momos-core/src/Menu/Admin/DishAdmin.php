<?php
declare(strict_types=1);

namespace Momos\Menu\Admin;

use Momos\Kernel\I18n\Lang;
use Momos\Kernel\I18n\Locale;
use Momos\Kernel\I18n\Translations;
use Momos\Menu\ContentTypes;
use Momos\Menu\Repository\BranchMenuItemRepository;
use Momos\Menu\Service\DishLifecycle;
use Momos\Menu\Service\PriceFormatter;
use Momos\Menu\Support\Branches;

/**
 * SCR-A06 dish list/edit additions: list columns (image, SKU, primary category, status incl. retired,
 * allergen verification, sample, translations), pricing preview + large-change confirmation, branch rows
 * summary (read-only, link to SCR-A07), Retire action (publish cap), sample badge.
 */
final class DishAdmin {
	public static function register(): void {
		add_filter( 'manage_' . ContentTypes::DISH . '_posts_columns', [ self::class, 'columns' ] );
		add_action( 'manage_' . ContentTypes::DISH . '_posts_custom_column', [ self::class, 'column' ], 10, 2 );
		add_action( 'add_meta_boxes_' . ContentTypes::DISH, [ self::class, 'meta_boxes' ] );
		add_action( 'admin_post_momos_dish_retire', [ self::class, 'handle_retire' ] );
		add_filter( 'display_post_states', [ self::class, 'post_states' ], 10, 2 );
		add_action( 'admin_notices', [ self::class, 'notices' ] );
	}

	/** @param array<string,string> $cols @return array<string,string> */
	public static function columns( array $cols ): array {
		$out = [];
		foreach ( $cols as $k => $v ) {
			if ( 'title' === $k ) {
				$out['momos_image'] = __( 'Image', 'momos-core' );
			}
			$out[ $k ] = $v;
			if ( 'title' === $k ) {
				$out['momos_sku']      = __( 'Code', 'momos-core' );
				$out['momos_primary']  = __( 'Primary category', 'momos-core' );
				$out['momos_status']   = __( 'Status', 'momos-core' );
				$out['momos_allergen'] = __( 'Allergen verification', 'momos-core' );
				$out['momos_i18n']     = __( 'Translations', 'momos-core' );
			}
		}
		return $out;
	}

	public static function column( string $col, int $post_id ): void {
		switch ( $col ) {
			case 'momos_image':
				echo get_the_post_thumbnail( $post_id, [ 48, 48 ] ); // phpcs:ignore
				break;
			case 'momos_sku':
				echo '<code>' . esc_html( (string) get_post_meta( $post_id, 'momo_dish_sku', true ) ) . '</code>';
				if ( '1' === (string) get_post_meta( $post_id, 'momo_is_sample', true ) ) {
					echo ' <span class="momos-badge">' . esc_html__( 'Sample', 'momos-core' ) . '</span>';
				}
				break;
			case 'momos_primary':
				$t = get_term( (int) get_post_meta( $post_id, 'momo_primary_category', true ), ContentTypes::CATEGORY );
				echo esc_html( $t instanceof \WP_Term ? $t->name : '—' );
				break;
			case 'momos_status':
				echo esc_html( self::status_label( $post_id ) );
				break;
			case 'momos_allergen':
				if ( class_exists( \Momos\Dietary\Service\AllergenPresenter::class ) ) {
					$n = count( \Momos\Dietary\Service\AllergenPresenter::not_verified_codes( $post_id ) );
					/* translators: %d: number of allergens not verified */
					echo esc_html( $n ? sprintf( _n( '%d not verified', '%d not verified', $n, 'momos-core' ), $n ) : __( 'All verified', 'momos-core' ) );
					if ( '1' === (string) get_post_meta( $post_id, 'momo_allergen_review_required', true ) ) {
						echo ' <span class="momos-badge momos-badge--warn">' . esc_html__( 'Review required', 'momos-core' ) . '</span>';
					}
				}
				break;
			case 'momos_i18n':
				$parts = [];
				foreach ( Lang::targets() as $lang ) {
					$parts[] = $lang . ':' . Translations::status( 'post', $post_id, $lang );
				}
				echo esc_html( implode( ' · ', $parts ) );
				break;
		}
	}

	public static function status_label( int $post_id ): string {
		if ( '1' === (string) get_post_meta( $post_id, 'momo_retired', true ) ) {
			return __( 'Retired', 'momos-core' );
		}
		return match ( get_post_status( $post_id ) ) {
			'publish' => __( 'Published', 'momos-core' ),
			'pending' => __( 'Pending review', 'momos-core' ),
			default   => __( 'Draft', 'momos-core' ),
		};
	}

	/** @param array<string,string> $states @return array<string,string> */
	public static function post_states( array $states, \WP_Post $post ): array {
		if ( ContentTypes::DISH === $post->post_type && '1' === (string) get_post_meta( $post->ID, 'momo_retired', true ) ) {
			$states['momos_retired'] = __( 'Retired', 'momos-core' );
		}
		return $states;
	}

	public static function meta_boxes( \WP_Post $post ): void {
		add_meta_box( 'momos-dish-lifecycle', __( 'Lifecycle', 'momos-core' ), [ self::class, 'box_lifecycle' ], ContentTypes::DISH, 'side', 'high' );
		add_meta_box( 'momos-dish-price-preview', __( 'Price preview', 'momos-core' ), [ self::class, 'box_price' ], ContentTypes::DISH, 'side', 'default' );
		add_meta_box( 'momos-dish-branches', __( 'Branches', 'momos-core' ), [ self::class, 'box_branches' ], ContentTypes::DISH, 'normal', 'low' );
	}

	public static function box_lifecycle( \WP_Post $post ): void {
		echo '<p><strong>' . esc_html( self::status_label( $post->ID ) ) . '</strong></p>';
		if ( '1' === (string) get_post_meta( $post->ID, 'momo_is_sample', true ) ) {
			echo '<p class="momos-badge momos-badge--warn">' . esc_html__( 'Sample — cannot be published on the live site', 'momos-core' ) . '</p>';
		}
		if ( ! current_user_can( 'publish_momo_dishes' ) ) {
			echo '<p class="description">' . esc_html__( 'Use “Submit for Review”; Head Office publishes.', 'momos-core' ) . '</p>';
			return;
		}
		if ( 'publish' !== $post->post_status ) {
			return;
		}
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=momos_dish_retire&dish=' . $post->ID ), 'momos_dish_retire_' . $post->ID );
		echo '<p class="description">' . esc_html__( 'Retiring removes the dish from every branch menu and redirects its page to the category.', 'momos-core' ) . '</p>';
		echo '<a class="button button-secondary" href="' . esc_url( $url ) . '" onclick="return confirm(' . esc_attr( wp_json_encode( __( 'Retire this dish? It will be removed from all branch menus.', 'momos-core' ) ) ) . ');">' . esc_html__( 'Retire', 'momos-core' ) . '</a>';
	}

	public static function box_price( \WP_Post $post ): void {
		$ref  = (string) get_post_meta( $post->ID, 'momo_reference_price_jpy', true );
		$lang = Locale::admin();
		echo '<p>' . ( '' !== $ref ? wp_kses_post( PriceFormatter::html( (int) $ref, $lang ) ) : '—' ) . '</p>';
		if ( current_user_can( 'momo_edit_branch_prices' ) ) {
			echo '<label><input type="checkbox" name="momos_confirm_large_price" value="1"> ' . esc_html__( 'Confirm large price change (> 30 %)', 'momos-core' ) . '</label>';
		}
	}

	public static function box_branches( \WP_Post $post ): void {
		$rows = BranchMenuItemRepository::offered_for_dish( $post->ID );
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'Not offered at any branch.', 'momos-core' ) . '</p>';
		} else {
			echo '<table class="widefat"><thead><tr><th scope="col">' . esc_html__( 'Branch', 'momos-core' ) . '</th><th scope="col">' . esc_html__( 'Price', 'momos-core' ) . '</th><th scope="col">' . esc_html__( 'Availability', 'momos-core' ) . '</th></tr></thead><tbody>';
			$ref = (string) get_post_meta( $post->ID, 'momo_reference_price_jpy', true );
			foreach ( $rows as $r ) {
				$price = $r['price_override_jpy'] ?? ( '' !== $ref ? (int) $ref : null );
				echo '<tr><td>' . esc_html( Branches::code( (int) $r['branch_id'] ) . ' ' . get_the_title( (int) $r['branch_id'] ) ) . '</td><td>' . esc_html( null !== $price ? PriceFormatter::amount( (int) $price ) : '—' ) . '</td><td>' . esc_html( (string) $r['availability'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		if ( current_user_can( 'momo_edit_branch_availability' ) ) {
			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=' . BranchMenuGrid::SLUG ) ) . '">' . esc_html__( 'Edit branch menus', 'momos-core' ) . '</a></p>';
		}
	}

	public static function handle_retire(): void {
		$dish_id = isset( $_GET['dish'] ) ? (int) $_GET['dish'] : 0;
		check_admin_referer( 'momos_dish_retire_' . $dish_id );
		$uid = get_current_user_id();
		try {
			DishLifecycle::retire( $dish_id, $uid );
			set_transient( 'momos_dish_notice_' . $uid, [ 'success', __( 'The dish was retired.', 'momos-core' ) ], 120 );
		} catch ( \RuntimeException $e ) {
			set_transient( 'momos_dish_notice_' . $uid, [ 'error', $e->getMessage() ], 120 );
		}
		wp_safe_redirect( admin_url( 'post.php?post=' . $dish_id . '&action=edit' ) );
		exit;
	}

	public static function notices(): void {
		$uid = get_current_user_id();
		$n   = get_transient( 'momos_dish_notice_' . $uid );
		if ( is_array( $n ) ) {
			delete_transient( 'momos_dish_notice_' . $uid );
			echo '<div class="notice notice-' . esc_attr( 'error' === $n[0] ? 'error' : 'success' ) . '" role="' . ( 'error' === $n[0] ? 'alert' : 'status' ) . '"><p>' . esc_html( (string) $n[1] ) . '</p></div>';
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ContentTypes::DISH !== $screen->post_type || 'post' !== $screen->base || ! class_exists( \Momos\Dietary\Service\AllergenPresenter::class ) ) {
			return;
		}
		$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0; // phpcs:ignore
		if ( $post_id && 'publish' === get_post_status( $post_id ) ) {
			$codes = \Momos\Dietary\Service\AllergenPresenter::not_verified_codes( $post_id );
			if ( $codes ) {
				/* translators: %s: list of allergens */
				echo '<div class="notice notice-warning"><p>' . esc_html( sprintf( __( 'Allergens not verified on this published dish: %s', 'momos-core' ), implode( ', ', \Momos\Dietary\Service\AllergenPresenter::names( $codes, Locale::admin() ) ) ) ) . '</p></div>';
			}
		}
	}
}
