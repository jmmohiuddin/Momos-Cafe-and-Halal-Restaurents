<?php
/**
 * SCR-C04 Branch directory (screens.md §3.4, FR-BR-001, TC-BR-001…003). Banner (breadcrumb · H1
 * `nav.branches` · intro) · CMP-BranchCard per public branch in Head Office order (published, non-test,
 * not permanently closed, translated — BranchRepository::public_ids) · closing CTA. Site-wide notices are
 * rendered by the frame. Empty: `branch.dir.empty` + contact link.
 *
 * @package momos
 */

defined( 'ABSPATH' ) || exit;

get_header();
$momos_branches = class_exists( \Momos\Presentation\View\BranchLinks::class ) ? \Momos\Presentation\View\BranchLinks::all() : [];
if ( $momos_branches ) {
	\Momos\Presentation\View\Cards::prime( array_column( $momos_branches, 'id' ) );
}
get_template_part( 'template-parts/frame/page-banner', null, [ 'title' => momos_s( 'nav.branches' ), 'lead' => momos_s( 'branch.dir.intro' ) ] );
?>
<section class="momo-container momo-section" aria-label="<?php echo esc_attr( momos_s( 'nav.branches' ) ); ?>">
	<?php if ( $momos_branches ) : ?>
		<ul class="momo-grid momo-grid--3 momo-list-reset momo-branch-list">
			<?php foreach ( $momos_branches as $momos_b ) : ?>
				<li><?php momos_component( 'branch-card', [ 'branch_id' => (int) $momos_b['id'], 'h' => 2, 'cta_location' => 'branch_card', 'notices' => true ] ); ?></li>
			<?php endforeach; ?>
		</ul>
	<?php else : ?>
		<?php
		$momos_contact = class_exists( \Momos\Presentation\SystemPages::class ) ? \Momos\Presentation\SystemPages::url( 'contact' ) : null;
		momos_component( 'empty-state', [ 'title' => momos_s( 'branch.dir.empty' ), 'icon' => 'store', 'action' => null !== $momos_contact ? [ 'label' => momos_s( 'branch.dir.contact' ), 'url' => $momos_contact ] : null ] );
		?>
	<?php endif; ?>
</section>
<?php
momos_component( 'closing-cta', [ 'cta' => 'home_section', 'id' => 'momo-branches-closing' ] );
get_footer();
