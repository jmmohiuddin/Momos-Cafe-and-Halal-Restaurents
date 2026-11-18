<?php
declare(strict_types=1);

namespace Momos\Menu\Admin;

use Momos\Menu\ContentTypes;

/**
 * SCR-A05 category ordering (FR-MENU-005): up/down buttons (≥ 24 px, keyboard operable, no JS) writing
 * `momo_cat_order`. Name/slug/intro/image/translations are edited on the native term screen.
 */
final class CategoryOrderPage {
	public const SLUG = 'momo-category-order';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ], 22 );
		add_action( 'admin_post_momos_category_move', [ self::class, 'move' ] );
	}

	public static function menu(): void {
		add_submenu_page( 'edit.php?post_type=' . ContentTypes::DISH, __( 'Category order', 'momos-core' ), __( 'Category order', 'momos-core' ), 'momo_manage_menu_categories', self::SLUG, [ self::class, 'render' ] );
	}

	/** @return list<\WP_Term> categories in display order */
	public static function ordered(): array {
		$terms = get_terms( [ 'taxonomy' => ContentTypes::CATEGORY, 'hide_empty' => false ] );
		$terms = is_array( $terms ) ? $terms : [];
		usort( $terms, static fn( $a, $b ) => (int) get_term_meta( $a->term_id, 'momo_cat_order', true ) <=> (int) get_term_meta( $b->term_id, 'momo_cat_order', true ) ?: strcmp( $a->name, $b->name ) );
		return $terms;
	}

	/** Move a category one step and renumber all (10, 20, 30 …). */
	public static function move_term( int $term_id, string $direction ): void {
		$terms = self::ordered();
		$ids   = array_map( static fn( $t ) => (int) $t->term_id, $terms );
		$pos   = array_search( $term_id, $ids, true );
		if ( false === $pos ) {
			return;
		}
		$swap = 'up' === $direction ? $pos - 1 : $pos + 1;
		if ( $swap < 0 || $swap >= count( $ids ) ) {
			return;
		}
		[ $ids[ $pos ], $ids[ $swap ] ] = [ $ids[ $swap ], $ids[ $pos ] ];
		foreach ( $ids as $i => $id ) {
			update_term_meta( $id, 'momo_cat_order', (string) ( ( $i + 1 ) * 10 ) );
		}
		\Momos\Menu\Support\CachePurge::tags( [ 'momo-cat-' . $term_id, 'momo-home' ] );
	}

	public static function render(): void {
		if ( ! current_user_can( 'momo_manage_menu_categories' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'momos-core' ), 403 );
		}
		$terms = self::ordered();
		include MOMOS_CORE_DIR . '/templates/admin/menu-category-order.php';
	}

	public static function move(): void {
		$term_id = isset( $_POST['term_id'] ) ? (int) $_POST['term_id'] : 0;
		check_admin_referer( 'momos_category_move_' . $term_id );
		if ( ! current_user_can( 'momo_manage_menu_categories' ) ) {
			momos_audit( 'access.denied', 'menu_category', $term_id, null, [ 'reason' => 'forbidden', 'screen' => self::SLUG ] );
			wp_die( esc_html__( 'You do not have permission to do this.', 'momos-core' ), 403 );
		}
		self::move_term( $term_id, isset( $_POST['direction'] ) && 'up' === $_POST['direction'] ? 'up' : 'down' );
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . ContentTypes::DISH . '&page=' . self::SLUG . '&moved=' . $term_id . '#cat-' . $term_id ) );
		exit;
	}
}
