<?php
/**
 * SCR-C08 Dish detail (screens.md §3.8, FR-MENU-003/009, FR-DIET-006, TC-DISH-001/005/006, TC-DIET-011/012/027).
 * Own URL, no modal (UI-001 dish sheet → information page + "Request a table"; portions, add-ons,
 * quantity and "Add to order" removed, DEC-042). Order: breadcrumb · H1 + original name · gallery (1–6,
 * swipe, no autoplay; "Image for illustration" below flagged images) · price + availability (branch
 * context / single / per-branch / not offered) · description · advance-order note · dietary tags + spice ·
 * halal basis line (never omitted) · key ingredients · allergen table (summary → not-verified banner →
 * legend → grouped table → disclaimers) · available at · related dishes · CTA (hidden when offered nowhere).
 * ≥ 1024: gallery 7 cols (start) + summary 5 cols (end). Retired/sample/untranslated → Menu module redirects.
 *
 * @package momos
 */

defined( 'ABSPATH' ) || exit;

get_header();
$momos_id   = (int) get_queried_object_id();
$momos_lang = momos_theme_lang();
$momos_mv   = \Momos\Presentation\View\MenuView::class;
$momos_ctx  = $momos_mv::branch_context();
$momos_d    = $momos_mv::dish( $momos_id, $momos_ctx );
$momos_kses = [ 'bdi' => [ 'dir' => true ] ];

if ( null === $momos_d || '' === (string) $momos_d['title'] ) {
	get_template_part( 'template-parts/frame/page-banner', null, [ 'title' => \Momos\Presentation\View\Content::title( $momos_id ) ] );
	echo '<div class="momo-container momo-section">';
	momos_component( 'empty-state', [ 'variant' => 'error', 'title' => momos_s( 'common.error.title' ), 'body' => momos_s( 'common.error.body' ), 'action' => [ 'label' => momos_s( 'cta.view_menu' ), 'url' => momos_page_url( 'menu' ) ] ] );
	echo '</div>';
	get_footer();
	return;
}
$momos_card  = (array) $momos_d['card'];
$momos_trail = [ [ 'name' => momos_s( 'bc.home' ), 'url' => momos_theme_url( '/' ) ], [ 'name' => momos_s( 'nav.menu' ), 'url' => $momos_mv::with_branch( momos_page_url( 'menu' ), $momos_ctx ) ] ];
if ( is_array( $momos_d['term'] ) && '' !== $momos_d['term']['name'] ) {
	$momos_trail[] = [ 'name' => (string) $momos_d['term']['name'], 'url' => (string) $momos_d['term']['url'] ];
}
$momos_trail[] = [ 'name' => (string) $momos_d['title'] ];
$momos_orig    = (string) ( $momos_card['original_name'] ?? '' );
$momos_claim   = is_array( $momos_d['claim'] ) ? $momos_d['claim'] : null;
$momos_alg     = is_array( $momos_d['allergens'] ) ? $momos_d['allergens'] : null;
$momos_self    = \Momos\Kernel\I18n\Url::localize( (string) get_permalink( $momos_id ), $momos_lang );
$momos_price   = static function ( string $key, string $price_html, string $branch = '' ) use ( $momos_lang ): string {
	return strtr( esc_html( momos_t( $key, [], $momos_lang ) ), [ '{price}' => '<span class="momo-price">' . wp_kses( $price_html, [ 'bdi' => [ 'dir' => true ] ] ) . '</span>', '{branch}' => esc_html( $branch ) ] );
};
?>
<div class="momo-banner momo-dish-banner">
	<div class="momo-container">
		<?php get_template_part( 'template-parts/frame/breadcrumbs', null, [ 'items' => $momos_trail ] ); ?>
	</div>
</div>

<div class="momo-container momo-dish-layout">
	<div class="momo-dish-gallery">
		<?php if ( $momos_d['gallery'] ) : ?>
			<ul class="momo-dish-gallery__track momo-list-reset" aria-label="<?php echo esc_attr( momos_s( 'menu.dish.photos', [ 'dish' => (string) $momos_d['title'] ] ) ); ?>"<?php echo count( $momos_d['gallery'] ) > 1 ? ' tabindex="0"' : ''; ?>>
				<?php foreach ( $momos_d['gallery'] as $momos_n => $momos_g ) : ?>
					<li>
						<figure class="momo-dish-gallery__item">
							<?php echo wp_get_attachment_image( (int) $momos_g['id'], 'large', false, [ 'alt' => (string) $momos_g['alt'], 'loading' => 0 === $momos_n ? 'eager' : 'lazy', 'fetchpriority' => 0 === $momos_n ? 'high' : 'auto', 'decoding' => 'async', 'sizes' => '(min-width: 1024px) 640px, 100vw' ] ); ?>
							<?php if ( ! empty( $momos_g['illustrative'] ) ) : ?>
								<figcaption class="momo-illustration"><?php echo esc_html( momos_s( 'media.illustration' ) ); ?></figcaption>
							<?php endif; ?>
						</figure>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php else : ?>
			<div class="momo-placeholder momo-dish-gallery__ph" aria-hidden="true"><?php echo esc_html( mb_substr( (string) $momos_d['title'], 0, 1 ) ); ?></div>
		<?php endif; ?>
	</div>

	<div class="momo-dish-summary">
		<?php if ( is_array( $momos_d['term'] ) && '' !== $momos_d['term']['name'] ) : ?>
			<span class="momo-eyebrow"><?php echo esc_html( (string) $momos_d['term']['name'] ); ?></span>
		<?php endif; ?>
		<h1><?php echo esc_html( (string) $momos_d['title'] ); ?></h1>
		<?php if ( '' !== $momos_orig && $momos_orig !== $momos_d['title'] ) : ?>
			<p class="momo-dish-orig" lang="ja"><?php echo esc_html( $momos_orig ); ?></p>
		<?php endif; ?>

		<div class="momo-card momo-dish-price">
			<?php if ( 'branch' === $momos_d['price_mode'] ) : ?>
				<p class="momo-dish-price__line"><?php echo $momos_price( 'menu.price_at_branch', (string) $momos_card['price_html'], (string) $momos_ctx['name'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
				<?php momos_component( 'dish-labels', [ 'card' => [ 'availability' => $momos_card['availability'] ?? null ] ] ); ?>
			<?php elseif ( 'not_offered_here' === $momos_d['price_mode'] ) : ?>
				<p><?php echo esc_html( momos_s( 'menu.dish.not_offered_here', [ 'branch' => (string) $momos_ctx['name'] ] ) ); ?></p>
			<?php elseif ( 'single' === $momos_d['price_mode'] ) : ?>
				<p class="momo-dish-price__line"><?php echo $momos_price( 'menu.price_all_branches', (string) $momos_d['price_html'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
			<?php elseif ( 'table' === $momos_d['price_mode'] ) : ?>
				<p class="momo-dish-price__line"><?php echo esc_html( momos_s( 'menu.dish.prices_vary' ) ); ?> <a href="#available-at"><?php echo esc_html( momos_s( 'menu.available_at' ) ); ?></a></p>
			<?php else : ?>
				<p class="momo-avail momo-avail--not_offered"><?php momos_the_icon( 'info', 'momo-icon--sm' ); ?><?php echo esc_html( momos_s( 'menu.not_available' ) ); ?></p>
			<?php endif; ?>
			<?php if ( $momos_d['offered'] ) : ?>
				<?php momos_component( 'branch-select', [ 'options' => $momos_mv::selector( $momos_ctx ), 'action' => $momos_self, 'id' => 'momo-dish-branch' ] ); ?>
			<?php endif; ?>
		</div>

		<?php
		$momos_tags = (array) ( $momos_card['tags'] ?? [] );
		$momos_sp   = (int) ( $momos_card['spice'] ?? 0 );
		?>
		<div class="momo-dish-facts">
			<?php momos_component( 'dish-labels', [ 'card' => [ 'tags' => $momos_tags, 'spice' => $momos_sp, 'spice_label' => $momos_sp > 0 ? momos_s( 'menu.spice', [ 'label' => (string) $momos_card['spice_label'] ] ) : '' ] ] ); ?>
			<?php if ( ! empty( $momos_card['advance_order_hours'] ) ) : ?>
				<div class="momo-alert momo-alert--warning" role="note">
					<?php momos_the_icon( 'clock' ); ?>
					<div>
						<p><?php echo wp_kses( \Momos\Presentation\Helpers\Bdi::t( 'menu.advance_order.notice', [ 'hours' => (int) $momos_card['advance_order_hours'] ], [ 'hours' ] ), $momos_kses ); ?></p>
						<?php if ( '' !== (string) $momos_d['request_url'] ) : ?>
							<a href="<?php echo esc_url( (string) $momos_d['request_url'] ); ?>"><?php echo esc_html( momos_s( 'menu.advance_order.mention' ) ); ?></a>
						<?php endif; ?>
					</div>
				</div>
			<?php endif; ?>
		</div>

		<?php if ( '' !== (string) $momos_d['request_url'] ) : ?>
			<?php momos_component( 'button', [ 'label' => momos_s( 'cta.request_table' ), 'url' => (string) $momos_d['request_url'], 'variant' => 'primary', 'size' => 'lg', 'block' => true, 'icon' => 'calendar', 'attrs' => [ 'data-momo-event' => 'click_reservation', 'data-momo-cta' => 'dish_page', 'data-momo-branch' => (string) ( $momos_ctx['code'] ?? '' ) ] ] ); ?>
		<?php endif; ?>
	</div>
</div>

<div class="momo-container momo-dish-body">
	<?php if ( '' !== trim( (string) $momos_d['content'] ) ) : ?>
		<section class="momo-dish-sec" aria-labelledby="dish-about">
			<h2 id="dish-about"><?php echo esc_html( momos_s( 'menu.dish.description' ) ); ?></h2>
			<div class="momo-prose"><?php echo wp_kses_post( (string) apply_filters( 'the_content', (string) $momos_d['content'] ) ); ?></div>
		</section>
	<?php endif; ?>

	<?php if ( is_array( $momos_claim ) && '' !== trim( (string) $momos_claim['statement'] ) ) : ?>
		<section class="momo-dish-sec" aria-labelledby="dish-halal">
			<h2 id="dish-halal"><?php echo esc_html( momos_s( 'menu.dish.halal' ) ); ?></h2>
			<p class="momo-dish-halal"><?php momos_the_icon( 'shield-check' ); ?><span><?php echo wp_kses( (string) $momos_claim['statement_html'], [ 'a' => [ 'href' => true ] ] ); ?></span></p>
			<?php if ( ! empty( $momos_claim['last_verified'] ) ) : ?>
				<p class="momo-small momo-muted"><?php echo esc_html( (string) $momos_claim['last_verified'] ); ?></p>
			<?php endif; ?>
		</section>
	<?php endif; ?>

	<?php if ( $momos_d['ingredients'] ) : ?>
		<section class="momo-dish-sec" aria-labelledby="dish-ingredients">
			<h2 id="dish-ingredients"><?php echo esc_html( momos_s( 'ingredients.heading' ) ); ?></h2>
			<ul class="momo-chips">
				<?php foreach ( $momos_d['ingredients'] as $momos_ing ) : ?>
					<li class="momo-chip momo-chip--tag"><?php echo esc_html( (string) $momos_ing ); ?></li>
				<?php endforeach; ?>
			</ul>
		</section>
	<?php endif; ?>

	<?php if ( is_array( $momos_alg ) ) : ?>
		<section class="momo-dish-sec" aria-labelledby="dish-allergens">
			<h2 id="dish-allergens"><?php echo esc_html( momos_s( 'allergen.heading' ) ); ?></h2>
			<?php momos_component( 'allergen-table', [ 'data' => $momos_alg, 'id' => 'momo-allergens' ] ); ?>
		</section>
	<?php endif; ?>

	<?php if ( $momos_d['rows'] ) : ?>
		<section class="momo-dish-sec" id="available-at" aria-labelledby="dish-available">
			<h2 id="dish-available"><?php echo esc_html( momos_s( 'menu.available_at' ) ); ?></h2>
			<div class="momo-table-wrap">
				<table class="momo-table">
					<caption class="momo-visually-hidden"><?php echo esc_html( momos_s( 'menu.available_at' ) ); ?></caption>
					<thead><tr><th scope="col"><?php echo esc_html( momos_s( 'menu.table.branch' ) ); ?></th><th scope="col"><?php echo esc_html( momos_s( 'menu.table.price' ) ); ?></th><th scope="col"><?php echo esc_html( momos_s( 'menu.table.availability' ) ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $momos_d['rows'] as $momos_r ) : ?>
							<tr>
								<th scope="row"><a href="<?php echo esc_url( (string) $momos_r['url'] ); ?>"><?php echo esc_html( (string) $momos_r['name'] ); ?></a></th>
								<td><?php echo '' !== $momos_r['price_html'] ? '<span class="momo-price">' . wp_kses( (string) $momos_r['price_html'], $momos_kses ) . '</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
								<td><?php momos_component( 'dish-labels', [ 'card' => [ 'availability' => 'available' === $momos_r['avail']['code'] ? null : $momos_r['avail'] ] ] ); ?><?php echo 'available' === $momos_r['avail']['code'] ? esc_html( (string) $momos_r['avail']['label'] ) : ''; ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</section>
	<?php endif; ?>

	<?php if ( $momos_d['related'] ) : ?>
		<section class="momo-dish-sec" aria-labelledby="dish-related">
			<h2 id="dish-related"><?php echo esc_html( momos_s( 'menu.related' ) ); ?></h2>
			<ul class="momo-grid momo-grid--4 momo-grid--dishes momo-list-reset">
				<?php foreach ( $momos_d['related'] as $momos_rid ) : ?>
					<li><?php momos_component( 'dish-card', [ 'dish_id' => (int) $momos_rid, 'branch_id' => $momos_ctx['id'] ?? null, 'variant' => 'related', 'h' => 3 ] ); ?></li>
				<?php endforeach; ?>
			</ul>
		</section>
	<?php endif; ?>
</div>
<?php
if ( '' !== (string) $momos_d['request_url'] ) {
	momos_component( 'closing-cta', [ 'cta' => 'dish_page', 'url' => (string) $momos_d['request_url'], 'branch' => (string) ( $momos_ctx['code'] ?? '' ), 'id' => 'momo-dish-closing' ] );
}
get_footer();
