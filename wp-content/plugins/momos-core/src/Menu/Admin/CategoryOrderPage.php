<?php
declare(strict_types=1);

namespace Momos\Menu\Admin;

use Momos\Menu\ContentTypes;

/**
 * SCR-A05 category ordering (FR-MENU-005(a), Doc 07 §5.20): drag handle (WordPress-bundled
 * jquery-ui-sortable, saved by a nonce-checked AJAX call) **and** up/down buttons (≥ 24 px, keyboard
 * operable, work without JS through admin-post). Both write `momo_cat_order` (10, 20, 30 …), need
 * `momo_manage_menu_categories` and are audited (`menu_category.reordered`, before/after order).
 * Name/slug/intro/image/translations are edited on the native term screen.
 */
final class CategoryOrderPage {
	public const SLUG  = 'momo-category-order';
	public const AJAX  = 'momos_category_reorder';
	public const CAP   = 'momo_manage_menu_categories';
	public const AUDIT = 'menu_category.reordered';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ], 22 );
		add_action( 'admin_post_momos_category_move', [ self::class, 'move' ] );
		add_action( 'wp_ajax_' . self::AJAX, [ self::class, 'ajax_reorder' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'assets' ] );
	}

	public static function menu(): void {
		add_submenu_page( 'edit.php?post_type=' . ContentTypes::DISH, __( 'Category order', 'momos-core' ), __( 'Category order', 'momos-core' ), self::CAP, self::SLUG, [ self::class, 'render' ] );
	}

	/** @return list<\WP_Term> categories in display order */
	public static function ordered(): array {
		$terms = get_terms( [ 'taxonomy' => ContentTypes::CATEGORY, 'hide_empty' => false ] );
		$terms = is_array( $terms ) ? $terms : [];
		usort( $terms, static fn( $a, $b ) => (int) get_term_meta( $a->term_id, 'momo_cat_order', true ) <=> (int) get_term_meta( $b->term_id, 'momo_cat_order', true ) ?: strcmp( $a->name, $b->name ) );
		return $terms;
	}

	/** @return list<int> */
	public static function ordered_ids(): array {
		return array_map( static fn( $t ) => (int) $t->term_id, self::ordered() );
	}

	/** Move a category one step (buttons) and renumber all (10, 20, 30 …). */
	public static function move_term( int $term_id, string $direction ): void {
		$ids = self::ordered_ids();
		$pos = array_search( $term_id, $ids, true );
		if ( false === $pos ) {
			return;
		}
		$swap = 'up' === $direction ? $pos - 1 : $pos + 1;
		if ( $swap < 0 || $swap >= count( $ids ) ) {
			return;
		}
		$after                              = $ids;
		[ $after[ $pos ], $after[ $swap ] ] = [ $after[ $swap ], $after[ $pos ] ];
		self::apply( $ids, $after, 'buttons' );
	}

	/**
	 * Save a complete new order (drag and drop). The list must contain every category exactly once,
	 * otherwise nothing is written (the screen is stale).
	 *
	 * @param list<int> $order term IDs in display order
	 */
	public static function save_order( array $order ): bool {
		$order  = array_values( array_map( 'intval', $order ) );
		$before = self::ordered_ids();
		$a      = $order;
		$b      = $before;
		sort( $a );
		sort( $b );
		if ( count( $order ) !== count( array_unique( $order ) ) || $a !== $b ) {
			return false;
		}
		self::apply( $before, $order, 'drag' );
		return true;
	}

	/**
	 * @param list<int> $before
	 * @param list<int> $after
	 */
	private static function apply( array $before, array $after, string $via ): void {
		foreach ( $after as $i => $id ) {
			update_term_meta( $id, 'momo_cat_order', (string) ( ( $i + 1 ) * 10 ) );
		}
		if ( $before === $after ) {
			return;
		}
		momos_audit( self::AUDIT, 'menu_category', null, null, [ 'before' => $before, 'after' => $after, 'via' => $via ] );
		$tags = [ 'momo-home' ];
		foreach ( $after as $i => $id ) {
			if ( ( $before[ $i ] ?? 0 ) !== $id ) {
				$tags[] = 'momo-cat-' . $id;
			}
		}
		\Momos\Menu\Support\CachePurge::tags( $tags );
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'momos-core' ), 403 );
		}
		$terms = self::ordered();
		include MOMOS_CORE_DIR . '/templates/admin/menu-category-order.php';
	}

	public static function move(): void {
		$term_id = isset( $_POST['term_id'] ) ? (int) $_POST['term_id'] : 0;
		check_admin_referer( 'momos_category_move_' . $term_id );
		if ( ! current_user_can( self::CAP ) ) {
			momos_audit( 'access.denied', 'menu_category', $term_id, null, [ 'reason' => 'forbidden', 'screen' => self::SLUG ] );
			wp_die( esc_html__( 'You do not have permission to do this.', 'momos-core' ), 403 );
		}
		self::move_term( $term_id, isset( $_POST['direction'] ) && 'up' === $_POST['direction'] ? 'up' : 'down' );
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . ContentTypes::DISH . '&page=' . self::SLUG . '&moved=' . $term_id . '#cat-' . $term_id ) );
		exit;
	}

	/** Drag-and-drop save (`order[]` = term IDs in the new order). */
	public static function ajax_reorder(): void {
		if ( ! check_ajax_referer( self::AJAX, '_ajax_nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'The page has expired. Reload it and try again.', 'momos-core' ) ], 403 );
		}
		if ( ! current_user_can( self::CAP ) ) {
			momos_audit( 'access.denied', 'menu_category', null, null, [ 'reason' => 'forbidden', 'screen' => self::SLUG, 'channel' => 'ajax' ] );
			wp_send_json_error( [ 'message' => __( 'You do not have permission to do this.', 'momos-core' ) ], 403 );
		}
		$order = isset( $_POST['order'] ) && is_array( $_POST['order'] ) ? array_map( 'absint', wp_unslash( $_POST['order'] ) ) : [];
		if ( ! self::save_order( array_values( $order ) ) ) {
			wp_send_json_error( [ 'message' => __( 'The category list has changed. Reload the page and try again.', 'momos-core' ) ], 409 );
		}
		wp_send_json_success( [ 'order' => self::ordered_ids(), 'message' => __( 'Order saved.', 'momos-core' ) ] );
	}

	/** jquery-ui-sortable (bundled with WordPress) only on this screen. */
	public static function assets( string $hook ): void {
		if ( ! str_ends_with( $hook, '_page_' . self::SLUG ) || ! current_user_can( self::CAP ) ) {
			return;
		}
		wp_enqueue_script( 'jquery-ui-sortable' );
		$cfg = wp_json_encode(
			[
				'url'   => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( self::AJAX ),
				'error' => __( 'The order could not be saved.', 'momos-core' ),
			]
		);
		wp_add_inline_script(
			'jquery-ui-sortable',
			'jQuery(function($){var c=' . $cfg . ',b=$("#momos-cat-order"),s=$("#momos-cat-order-status");if(!b.length){return;}'
			. 'function n(){var r=b.children("tr");r.each(function(i){$(this).find(".momos-cat-pos").text(i+1);$(this).find("button[data-dir=up]").prop("disabled",i===0);$(this).find("button[data-dir=down]").prop("disabled",i===r.length-1);});}'
			. 'b.sortable({handle:".momos-cat-handle",axis:"y",update:function(){var o=b.children("tr").map(function(){return $(this).data("term-id");}).get();'
			. '$.post(c.url,{action:"' . self::AJAX . '",_ajax_nonce:c.nonce,order:o}).done(function(r){n();s.text(r&&r.data?r.data.message:"");}).fail(function(x){b.sortable("cancel");n();s.text(x.responseJSON&&x.responseJSON.data?x.responseJSON.data.message:c.error);});}});});'
		);
	}
}
