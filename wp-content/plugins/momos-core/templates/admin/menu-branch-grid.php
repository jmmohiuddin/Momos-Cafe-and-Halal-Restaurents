<?php
/**
 * SCR-A07 Branch menu grid view. Variables: $view (see Menu\Admin\BranchMenuGrid::render()).
 * All output escaped. One <form> per row via the HTML `form` attribute (no nested forms).
 *
 * @var array<string,mixed> $view
 */

use Momos\Kernel\Clock\Clock;
use Momos\Menu\Domain\Availability;
use Momos\Menu\Domain\AvailabilityReason;
use Momos\Menu\Service\PriceFormatter;
use Momos\Menu\Support\Branches;

defined( 'ABSPATH' ) || exit;

$perm      = $view['permissions'];
$branch_id = (int) $view['branch_id'];
$notice    = $view['notice'];
?>
<div class="wrap momos-admin momos-branch-menu">
	<h1><?php esc_html_e( 'Branch menus', 'momos-core' ); ?></h1>

	<form method="get" class="momos-bmg-filters">
		<input type="hidden" name="page" value="<?php echo esc_attr( \Momos\Menu\Admin\BranchMenuGrid::SLUG ); ?>">
		<label for="momos-bmg-branch"><?php esc_html_e( 'Branch', 'momos-core' ); ?></label>
		<select id="momos-bmg-branch" name="branch">
			<?php foreach ( $view['branches'] as $bid ) : ?>
				<option value="<?php echo (int) $bid; ?>" <?php selected( $bid, $branch_id ); ?>><?php echo esc_html( Branches::code( (int) $bid ) . ' — ' . get_the_title( (int) $bid ) ); ?></option>
			<?php endforeach; ?>
		</select>
		<label for="momos-bmg-cat"><?php esc_html_e( 'Category', 'momos-core' ); ?></label>
		<select id="momos-bmg-cat" name="cat">
			<option value=""><?php esc_html_e( 'All categories', 'momos-core' ); ?></option>
			<?php foreach ( is_array( $view['categories'] ) ? $view['categories'] : [] as $term ) : ?>
				<option value="<?php echo esc_attr( $term->slug ); ?>" <?php selected( $term->slug, $view['category'] ); ?>><?php echo esc_html( $term->name ); ?></option>
			<?php endforeach; ?>
		</select>
		<label for="momos-bmg-q"><?php esc_html_e( 'Search (name or code)', 'momos-core' ); ?></label>
		<input id="momos-bmg-q" type="search" name="q" value="<?php echo esc_attr( $view['q'] ); ?>">
		<button type="submit" class="button"><?php esc_html_e( 'Show', 'momos-core' ); ?></button>
	</form>

	<?php if ( ! $view['branches'] ) : ?>
		<p><?php esc_html_e( 'You are not assigned to a branch.', 'momos-core' ); ?></p>
	<?php elseif ( ! $view['items'] ) : ?>
		<p><?php esc_html_e( 'No published dishes match.', 'momos-core' ); ?></p>
	<?php else : ?>
	<table class="widefat striped momos-bmg-table">
		<caption class="screen-reader-text"><?php esc_html_e( 'Dishes at this branch', 'momos-core' ); ?></caption>
		<thead><tr>
			<th scope="col"><?php esc_html_e( 'Dish', 'momos-core' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Offered', 'momos-core' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Reference price', 'momos-core' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Price override', 'momos-core' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Displayed price', 'momos-core' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Availability', 'momos-core' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Save', 'momos-core' ); ?></th>
		</tr></thead>
		<tbody>
		<?php
		foreach ( $view['items'] as $item ) :
			$did     = (int) $item['dish_id'];
			$row     = $item['row'];
			$form    = 'momos-bmg-row-' . $did;
			$offered = $row && $row['offered'];
			$avail   = $row ? (string) $row['availability'] : 'available';
			$until   = $row && $row['sold_out_until'] ? ( new \DateTimeImmutable( (string) $row['sold_out_until'] ) )->setTimezone( Clock::tz() )->format( 'Y-m-d\TH:i' ) : '';
			$can_av  = $perm['availability'] && null !== $row;
			$mine    = $notice && (int) $notice['dish_id'] === $did ? $notice : null;
			?>
			<tr id="dish-<?php echo (int) $did; ?>">
				<td>
					<form id="<?php echo esc_attr( $form ); ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="momos_branch_menu_save">
						<input type="hidden" name="branch_id" value="<?php echo (int) $branch_id; ?>">
						<input type="hidden" name="dish_id" value="<?php echo (int) $did; ?>">
						<input type="hidden" name="version" value="<?php echo (int) ( $row['version'] ?? 0 ); ?>">
						<input type="hidden" name="cat" value="<?php echo esc_attr( $view['category'] ); ?>">
						<input type="hidden" name="q" value="<?php echo esc_attr( $view['q'] ); ?>">
						<input type="hidden" name="paged" value="<?php echo (int) $view['paged']; ?>">
						<?php wp_nonce_field( 'momos_bmg_' . $branch_id . '_' . $did ); ?>
					</form>
					<?php echo $item['thumbnail_id'] ? wp_get_attachment_image( (int) $item['thumbnail_id'], [ 48, 48 ], false, [ 'alt' => '' ] ) : ''; // phpcs:ignore ?>
					<strong><?php echo esc_html( (string) $item['name'] ); ?></strong><br>
					<code><?php echo esc_html( (string) $item['dish_sku'] ); ?></code>
					<?php
					if ( $item['is_variant'] ) :
						?>
						<span class="momos-badge"><?php esc_html_e( 'Variant', 'momos-core' ); ?></span><?php endif; ?>
				</td>
				<td>
					<?php if ( $perm['offered'] ) : ?>
						<input type="hidden" name="has_offered" value="1" form="<?php echo esc_attr( $form ); ?>">
						<label class="momos-tap"><input type="checkbox" name="offered" value="1" form="<?php echo esc_attr( $form ); ?>" <?php checked( $offered ); ?>> <?php esc_html_e( 'Offered', 'momos-core' ); ?></label>
						<?php if ( $mine && isset( $mine['fields']['offered'] ) ) : ?>
							<label class="momos-tap"><input type="checkbox" name="confirm_variant_overlap" value="1" form="<?php echo esc_attr( $form ); ?>"> <?php esc_html_e( 'Confirm: base dish/variant also offered here', 'momos-core' ); ?></label>
						<?php endif; ?>
					<?php else : ?>
						<?php echo esc_html( $offered ? __( 'Offered', 'momos-core' ) : __( 'Not offered', 'momos-core' ) ); ?>
					<?php endif; ?>
				</td>
				<td><?php echo null !== $item['reference_price_jpy'] ? wp_kses_post( PriceFormatter::html( (int) $item['reference_price_jpy'], 'en' === \Momos\Kernel\I18n\Locale::admin() ? 'en' : 'ja' ) ) : '—'; ?></td>
				<td>
					<?php if ( $perm['price'] ) : ?>
						<label class="screen-reader-text" for="<?php echo esc_attr( $form ); ?>-price"><?php esc_html_e( 'Price override (yen)', 'momos-core' ); ?></label>
						<span aria-hidden="true">¥</span><input id="<?php echo esc_attr( $form ); ?>-price" class="small-text" type="number" min="0" max="100000" step="1" inputmode="numeric" name="price_override_jpy" value="<?php echo esc_attr( null !== ( $row['price_override_jpy'] ?? null ) ? (string) $row['price_override_jpy'] : '' ); ?>" form="<?php echo esc_attr( $form ); ?>">
						<?php if ( $mine && isset( $mine['fields']['confirm_large_change'] ) ) : ?>
							<label class="momos-tap"><input type="checkbox" name="confirm_large_change" value="1" form="<?php echo esc_attr( $form ); ?>"> <?php esc_html_e( 'Confirm large price change', 'momos-core' ); ?></label>
						<?php endif; ?>
					<?php else : ?>
						<?php echo esc_html( null !== ( $row['price_override_jpy'] ?? null ) ? PriceFormatter::amount( (int) $row['price_override_jpy'] ) : '—' ); ?>
						<span class="momos-locked">🔒 <?php esc_html_e( 'Head Office only', 'momos-core' ); ?></span>
					<?php endif; ?>
				</td>
				<td><?php echo $offered && null !== ( $row['effective_price_jpy'] ?? null ) ? esc_html( PriceFormatter::amount( (int) $row['effective_price_jpy'] ) ) : '—'; ?></td>
				<td>
					<?php if ( $can_av ) : ?>
						<fieldset class="momos-segmented">
							<legend class="screen-reader-text"><?php esc_html_e( 'Availability', 'momos-core' ); ?></legend>
							<?php foreach ( Availability::cases() as $a ) : ?>
								<label class="momos-tap"><input type="radio" name="availability" value="<?php echo esc_attr( $a->value ); ?>" form="<?php echo esc_attr( $form ); ?>" <?php checked( $avail, $a->value ); ?>> <?php echo esc_html( $a->label() ); ?></label>
							<?php endforeach; ?>
						</fieldset>
						<label for="<?php echo esc_attr( $form ); ?>-until"><?php esc_html_e( 'Sold out until (JST)', 'momos-core' ); ?></label>
						<input id="<?php echo esc_attr( $form ); ?>-until" type="datetime-local" name="sold_out_until" value="<?php echo esc_attr( $until ); ?>" form="<?php echo esc_attr( $form ); ?>">
						<label for="<?php echo esc_attr( $form ); ?>-reason"><?php esc_html_e( 'Reason', 'momos-core' ); ?></label>
						<select id="<?php echo esc_attr( $form ); ?>-reason" name="availability_reason" form="<?php echo esc_attr( $form ); ?>">
							<option value=""><?php esc_html_e( '— Select —', 'momos-core' ); ?></option>
							<?php foreach ( AvailabilityReason::cases() as $r ) : ?>
								<option value="<?php echo esc_attr( $r->value ); ?>" <?php selected( (string) ( $row['availability_reason'] ?? '' ), $r->value ); ?>><?php echo esc_html( $r->label() ); ?></option>
							<?php endforeach; ?>
						</select>
					<?php else : ?>
						<?php echo esc_html( $row ? ( Availability::tryFrom( $avail )?->label() ?? $avail ) : __( 'Not configured', 'momos-core' ) ); ?>
						<?php echo $until ? esc_html( ' · ' . str_replace( 'T', ' ', $until ) . ' JST' ) : ''; ?>
					<?php endif; ?>
				</td>
				<td>
					<?php if ( $perm['offered'] || $perm['price'] || $can_av ) : ?>
						<button type="submit" class="button button-primary momos-tap" form="<?php echo esc_attr( $form ); ?>"><?php esc_html_e( 'Save', 'momos-core' ); ?></button>
					<?php endif; ?>
					<?php if ( $can_av && $offered && 'sold_out' !== $avail ) : ?>
						<button type="submit" class="button momos-tap" name="quick" value="sold_out_today" form="<?php echo esc_attr( $form ); ?>"><?php esc_html_e( 'Sold out today', 'momos-core' ); ?></button>
					<?php elseif ( $can_av && 'available' !== $avail ) : ?>
						<button type="submit" class="button momos-tap" name="quick" value="available" form="<?php echo esc_attr( $form ); ?>"><?php esc_html_e( 'Available again', 'momos-core' ); ?></button>
					<?php endif; ?>
					<p class="momos-row-status" role="status" aria-live="polite">
						<?php if ( $mine ) : ?>
							<span class="<?php echo esc_attr( 'error' === $mine['type'] ? 'momos-error' : 'momos-ok' ); ?>"><?php echo esc_html( (string) $mine['message'] ); ?></span>
						<?php endif; ?>
					</p>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
		<?php if ( $view['pages'] > 1 ) : ?>
		<p class="momos-pagination">
			<?php
			echo wp_kses_post(
				(string) paginate_links(
					[
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => (int) $view['paged'],
						'total'   => (int) $view['pages'],
					]
				)
			);
			?>
		</p>
	<?php endif; ?>
	<?php endif; ?>
</div>
<style>
.momos-bmg-table .momos-tap{display:inline-flex;align-items:center;min-block-size:44px;min-inline-size:44px;margin-inline-end:.5em}
.momos-bmg-table img{inline-size:48px;block-size:48px;object-fit:cover;margin-inline-end:.5em;vertical-align:middle}
.momos-locked{display:block;color:#50575e}
.momos-error{color:#b32d2e}.momos-ok{color:#007017}
@media (max-width:782px){.momos-bmg-table thead{display:none}.momos-bmg-table td{display:block}}
</style>
