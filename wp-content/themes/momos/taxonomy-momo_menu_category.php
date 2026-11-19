<?php
/**
 * SCR-C07 Menu category (screens.md §3.7, FR-MENU-002, FR-MENU-008 (S), TC-MENU-002/003/007).
 * Banner (Home › Menu › {Category} · H1 term name · intro) · tools row (category chips · branch selector ·
 * filters disclosure: dietary tags in use, max spice, (C) allergen exclusion + note) · result count
 * (`role="status"`) · CMP-DishCard list/grid · empty states · closing CTA (`?branch=` kept).
 * Filters work without JS (GET form, server marks non-matching cards hidden via MenuQueryService) and are
 * re-applied client-side by screens.js (URL state, no reload). Allergen exclusion is conservative
 * (contains / may contain / not verified / review flag hide the dish); never "allergen-free".
 * Untranslated term / no visible dish without branch context → 404 (Menu module MenuRewrite).
 *
 * @package momos
 */

defined( 'ABSPATH' ) || exit;

get_header();
$momos_term     = get_queried_object();
$momos_lang     = momos_theme_lang();
$momos_mv       = \Momos\Presentation\View\MenuView::class;
$momos_tr       = \Momos\Kernel\I18n\Translations::class;
$momos_ctx      = $momos_mv::branch_context();
$momos_name     = $momos_term instanceof \WP_Term ? $momos_tr::term( $momos_term, 'name', $momos_lang ) : '';
$momos_intro    = $momos_term instanceof \WP_Term ? $momos_tr::term( $momos_term, 'description', $momos_lang ) : '';
$momos_tid      = $momos_term instanceof \WP_Term ? (int) $momos_term->term_id : 0;
$momos_self     = $momos_term instanceof \WP_Term ? $momos_mv::term_url( $momos_term, $momos_lang ) : momos_page_url( 'menu' );
$momos_cards    = $momos_tid ? $momos_mv::category_cards( $momos_tid, $momos_ctx ) : [];
$momos_opts     = $momos_mv::filters( $momos_cards );
$momos_f        = class_exists( \Momos\Menu\Service\MenuQueryService::class ) ? \Momos\Menu\Service\MenuQueryService::parse_filters( wp_unslash( $_GET ) ) : [ 'diet' => [], 'max_spice' => null, 'exclude' => [] ]; // phpcs:ignore WordPress.Security.NonceVerification
$momos_show     = $momos_cards && ( $momos_f['diet'] || null !== $momos_f['max_spice'] || $momos_f['exclude'] )
	? \Momos\Menu\Service\MenuQueryService::apply_filters( array_map( static fn( $c ) => (int) $c['id'], $momos_cards ), $momos_f, $momos_lang )
	: [];
$momos_filtered = $momos_f['diet'] || null !== $momos_f['max_spice'] || $momos_f['exclude'];
$momos_visible  = $momos_filtered ? count( $momos_show ) : count( $momos_cards );
$momos_active   = count( $momos_f['diet'] ) + ( null !== $momos_f['max_spice'] ? 1 : 0 ) + count( $momos_f['exclude'] );
$momos_menu_url = $momos_mv::with_branch( momos_page_url( 'menu' ), $momos_ctx );
$momos_cats     = $momos_mv::categories( $momos_ctx, null, $momos_tid );
$momos_count    = static fn( int $n ): string => momos_s( 1 === $n ? 'menu.filters.count.one' : 'menu.filters.count.other', [ 'n' => $n ] );

get_template_part(
	'template-parts/frame/page-banner',
	null,
	[
		'title'       => $momos_name,
		'lead'        => $momos_intro,
		'breadcrumbs' => [
			[ 'name' => momos_s( 'bc.home' ), 'url' => momos_theme_url( '/' ) ],
			[ 'name' => momos_s( 'nav.menu' ), 'url' => $momos_menu_url ],
			[ 'name' => $momos_name ],
		],
	]
);
?>
<div class="momo-container momo-section momo-menu-cat">
	<div class="momo-tools">
		<?php if ( count( $momos_cats ) > 1 ) : ?>
			<?php momos_component( 'chip-group', [ 'type' => 'link', 'label' => momos_s( 'menu.categories.nav' ), 'scroll' => true, 'items' => array_map( static fn( $c ) => [ 'label' => $c['name'], 'url' => $c['url'], 'current' => $c['current'] ], $momos_cats ) ] ); ?>
		<?php endif; ?>
		<div class="momo-tools__row">
			<?php momos_component( 'branch-select', [ 'options' => $momos_mv::selector( $momos_ctx ), 'action' => $momos_self, 'id' => 'momo-cat-branch' ] ); ?>
			<?php if ( $momos_opts['tags'] && $momos_cards ) : ?>
				<details class="momo-filters" data-momo-filters<?php echo $momos_active ? ' open' : ''; ?>>
					<summary class="momo-btn momo-btn--secondary"><?php momos_the_icon( 'filter' ); ?><span data-momo-filter-label data-template="<?php echo esc_attr( momos_s( 'menu.filters.toggle', [ 'n' => '{n}' ] ) ); ?>"><?php echo esc_html( momos_s( 'menu.filters.toggle', [ 'n' => $momos_active ] ) ); ?></span></summary>
					<form class="momo-filters__panel" method="get" action="<?php echo esc_url( $momos_self ); ?>" data-momo-filter-form>
						<?php if ( null !== $momos_ctx ) : ?>
							<input type="hidden" name="branch" value="<?php echo esc_attr( (string) $momos_ctx['code'] ); ?>">
						<?php endif; ?>
						<?php
						momos_component(
							'chip-group',
							[
								'type'  => 'filter',
								'label' => momos_s( 'menu.filter.diet' ),
								'name'  => 'diet',
								'items' => array_map( static fn( $t ) => [ 'label' => $t['label'], 'value' => $t['code'], 'checked' => in_array( $t['code'], $momos_f['diet'], true ) ], $momos_opts['tags'] ),
							]
						);
						$momos_sp = [ [ 'label' => momos_s( 'menu.filter.max_spice.any' ), 'value' => '', 'checked' => null === $momos_f['max_spice'] ] ];
						for ( $momos_i = 0; $momos_i <= 3; $momos_i++ ) {
							$momos_sp[] = [ 'label' => momos_s( 'spice.' . $momos_i ), 'value' => (string) $momos_i, 'checked' => $momos_f['max_spice'] === $momos_i ];
						}
						momos_component( 'chip-group', [ 'type' => 'choice', 'label' => momos_s( 'menu.filter.max_spice' ), 'name' => 'max_spice', 'items' => $momos_sp ] );
						if ( $momos_opts['allergens'] ) {
							echo '<details class="momo-filters__more"' . ( $momos_f['exclude'] ? ' open' : '' ) . '><summary>' . esc_html( momos_s( 'menu.filter.exclude' ) ) . '</summary>';
							momos_component(
								'chip-group',
								[
									'type'          => 'filter',
									'label'         => momos_s( 'menu.filter.exclude' ),
									'visible_label' => false,
									'name'          => 'exclude',
									'items'         => array_map( static fn( $a ) => [ 'label' => $a['label'], 'value' => $a['code'], 'checked' => in_array( $a['code'], $momos_f['exclude'], true ) ], $momos_opts['allergens'] ),
								]
							);
							echo '<p class="momo-small momo-muted">' . esc_html( momos_s( 'menu.filter.allergen_note' ) ) . '</p></details>';
						}
						?>
						<div class="momo-cluster">
							<button type="submit" class="momo-btn momo-btn--primary" data-momo-filter-apply><?php echo esc_html( momos_s( 'menu.filters.apply' ) ); ?></button>
							<a class="momo-btn momo-btn--text" href="<?php echo esc_url( $momos_mv::with_branch( $momos_self, $momos_ctx ) ); ?>" data-momo-filter-clear><?php echo esc_html( momos_s( 'menu.filters.clear' ) ); ?></a>
						</div>
					</form>
				</details>
			<?php endif; ?>
		</div>
	</div>

	<?php if ( $momos_cards ) : ?>
		<p class="momo-result-count" role="status" data-momo-result-count data-one="<?php echo esc_attr( momos_s( 'menu.filters.count.one', [ 'n' => '{n}' ] ) ); ?>" data-other="<?php echo esc_attr( momos_s( 'menu.filters.count.other', [ 'n' => '{n}' ] ) ); ?>"><?php echo esc_html( $momos_count( $momos_visible ) ); ?></p>
		<ul class="momo-grid momo-grid--3 momo-grid--dishes momo-list-reset" data-momo-dish-list>
			<?php foreach ( $momos_cards as $momos_c ) : ?>
				<li<?php echo $momos_filtered && ! in_array( (int) $momos_c['id'], $momos_show, true ) ? ' hidden' : ''; ?>><?php momos_component( 'dish-card', [ 'card' => $momos_c, 'eyebrow' => $momos_name, 'h' => 2 ] ); ?></li>
			<?php endforeach; ?>
		</ul>
		<div data-momo-filter-empty<?php echo $momos_visible > 0 ? ' hidden' : ''; ?>>
			<?php momos_component( 'empty-state', [ 'title' => momos_s( 'menu.filters.empty' ), 'icon' => 'filter', 'action' => [ 'label' => momos_s( 'menu.filters.clear' ), 'url' => $momos_mv::with_branch( $momos_self, $momos_ctx ) ] ] ); ?>
		</div>
	<?php elseif ( null !== $momos_ctx ) : ?>
		<?php momos_component( 'empty-state', [ 'title' => momos_s( 'menu.category.branch_empty' ), 'icon' => 'store', 'action' => [ 'label' => momos_s( 'menu.category.all_branches_link' ), 'url' => $momos_self ] ] ); ?>
	<?php else : ?>
		<?php momos_component( 'empty-state', [ 'title' => momos_s( 'menu.empty' ), 'icon' => 'utensils' ] ); ?>
	<?php endif; ?>
</div>
<?php
momos_component( 'closing-cta', [ 'cta' => 'menu_page', 'url' => $momos_mv::with_branch( momos_page_url( 'reservation' ), $momos_ctx ), 'branch' => $momos_ctx['code'] ?? '', 'id' => 'momo-cat-closing' ] );
get_footer();
