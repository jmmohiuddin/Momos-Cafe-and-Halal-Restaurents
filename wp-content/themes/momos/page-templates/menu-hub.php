<?php
/**
 * SCR-C06 Menu hub (screens.md §3.6, FR-MENU-001, TC-MENU-001/003). Banner (breadcrumb · H1 `nav.menu` ·
 * intro = page excerpt) · CMP-BranchSelector (`?branch=`, unknown codes ignored) · CMP-CategoryTile grid
 * (categories with ≥ 1 displayable dish in the language, offered at the branch with context; no counts) ·
 * closing CTA keeping `?branch=`. No search, cart, "Verified" chip, table QR or halal banner.
 *
 * @package momos
 */

defined( 'ABSPATH' ) || exit;

get_header();
$momos_id   = (int) get_queried_object_id();
$momos_mv   = \Momos\Presentation\View\MenuView::class;
$momos_ctx  = $momos_mv::branch_context();
$momos_cats = $momos_mv::categories( $momos_ctx );
$momos_lead = \Momos\Presentation\View\Content::excerpt( $momos_id );
$momos_self = momos_page_url( 'menu' );
get_template_part( 'template-parts/frame/page-banner', null, [ 'title' => momos_s( 'nav.menu' ), 'lead' => '' !== $momos_lead ? $momos_lead : momos_s( 'menu.intro_default' ) ] );
?>
<div class="momo-container momo-section momo-menu-hub">
	<?php momos_component( 'branch-select', [ 'options' => $momos_mv::selector( $momos_ctx ), 'action' => $momos_self, 'id' => 'momo-menu-branch' ] ); ?>
	<?php if ( $momos_cats ) : ?>
		<?php momos_component( 'category-tiles', [ 'items' => $momos_cats, 'label' => momos_s( 'menu.categories.nav' ) ] ); ?>
	<?php elseif ( null !== $momos_ctx ) : ?>
		<?php momos_component( 'empty-state', [ 'title' => momos_s( 'menu.category.branch_empty' ), 'icon' => 'utensils', 'action' => [ 'label' => momos_s( 'menu.category.all_branches_link' ), 'url' => $momos_self ] ] ); ?>
	<?php else : ?>
		<?php momos_component( 'empty-state', [ 'title' => momos_s( 'menu.empty' ), 'icon' => 'utensils', 'action' => [ 'label' => momos_s( 'cta.see_branches' ), 'url' => momos_page_url( 'branches' ) ] ] ); ?>
	<?php endif; ?>
</div>
<?php
momos_component( 'closing-cta', [ 'cta' => 'menu_page', 'url' => \Momos\Presentation\View\MenuView::with_branch( momos_page_url( 'reservation' ), $momos_ctx ), 'branch' => $momos_ctx['code'] ?? '', 'id' => 'momo-menu-closing' ] );
get_footer();
