<?php
/**
 * SCR-A05 category order view (FR-MENU-005(a), Doc 07 §5.20): drag handle (mouse/touch, saved by AJAX)
 * and up/down buttons (keyboard, no JS needed). @var list<\WP_Term> $terms
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap momos-admin">
	<h1><?php esc_html_e( 'Category order', 'momos-core' ); ?></h1>
	<p class="description"><?php esc_html_e( 'Order of the categories on the menu hub. Drag a row by its handle, or use the up/down buttons. Categories without a displayable dish are not shown to customers.', 'momos-core' ); ?></p>
	<?php if ( isset( $_GET['moved'] ) ) : // phpcs:ignore ?>
		<div class="notice notice-success" role="status"><p><?php esc_html_e( 'Order saved.', 'momos-core' ); ?></p></div>
	<?php endif; ?>
	<p id="momos-cat-order-status" role="status" aria-live="polite"></p>
	<table class="widefat striped">
		<thead><tr><th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Drag handle', 'momos-core' ); ?></span></th><th scope="col"><?php esc_html_e( 'Position', 'momos-core' ); ?></th><th scope="col"><?php esc_html_e( 'Category', 'momos-core' ); ?></th><th scope="col"><?php esc_html_e( 'Dishes', 'momos-core' ); ?></th><th scope="col"><?php esc_html_e( 'Move', 'momos-core' ); ?></th></tr></thead>
		<tbody id="momos-cat-order">
		<?php foreach ( $terms as $i => $term ) : ?>
			<tr id="cat-<?php echo (int) $term->term_id; ?>" data-term-id="<?php echo (int) $term->term_id; ?>">
				<td><span class="momos-cat-handle dashicons dashicons-menu" style="cursor:move;min-inline-size:24px;min-block-size:24px" title="<?php esc_attr_e( 'Drag to reorder', 'momos-core' ); ?>" aria-hidden="true"></span></td>
				<td class="momos-cat-pos"><?php echo (int) ( $i + 1 ); ?></td>
				<td><a href="<?php echo esc_url( (string) get_edit_term_link( $term->term_id, $term->taxonomy ) ); ?>"><?php echo esc_html( $term->name ); ?></a> <code><?php echo esc_html( $term->slug ); ?></code></td>
				<td><?php echo (int) \Momos\Menu\Service\CategoryGuard::object_count( $term ); ?></td>
				<td>
					<?php foreach ( [ 'up' => __( 'Move up', 'momos-core' ), 'down' => __( 'Move down', 'momos-core' ) ] as $dir => $label ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
							<input type="hidden" name="action" value="momos_category_move">
							<input type="hidden" name="term_id" value="<?php echo (int) $term->term_id; ?>">
							<input type="hidden" name="direction" value="<?php echo esc_attr( $dir ); ?>">
							<?php wp_nonce_field( 'momos_category_move_' . $term->term_id ); ?>
							<button type="submit" class="button" data-dir="<?php echo esc_attr( $dir ); ?>" style="min-inline-size:32px;min-block-size:32px" aria-label="<?php echo esc_attr( $label . ': ' . $term->name ); ?>" <?php disabled( ( 'up' === $dir && 0 === $i ) || ( 'down' === $dir && count( $terms ) - 1 === $i ) ); ?>><?php echo 'up' === $dir ? '↑' : '↓'; ?></button>
						</form>
					<?php endforeach; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</div>
