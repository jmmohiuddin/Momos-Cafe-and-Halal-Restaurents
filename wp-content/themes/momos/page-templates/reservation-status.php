<?php
/**
 * SCR-C11 Status & cancel — PAGE SHELL ONLY (TASK 2; content by the Reservation team against API-03/04,
 * reservation.md §6.3). Served for `/{lang}/reservation/status/{REF}/#t={token}` by the TemplateRouter
 * rewrite (REF in query var `momo_status_ref`; the token stays in the fragment and never reaches the
 * server). Non-personalised: `no-store, private`, `noindex`, no-referrer, no third-party scripts, no
 * analytics data, no action bar. Shell shows the "no JS / link invalid" state.
 *
 * @package momos
 */

defined( 'ABSPATH' ) || exit;

get_header();
$momos_ref = strtoupper( (string) get_query_var( \Momos\Presentation\Templates\TemplateRouter::QV_REF ) );
$momos_ok  = (bool) preg_match( '/^[0-9A-HJKMNP-TV-Z]{4}-?[0-9A-HJKMNP-TV-Z]{4}$/', $momos_ref );
get_template_part( 'template-parts/frame/page-banner', null, [ 'title' => momos_s( 'status.title' ), 'breadcrumbs' => false ] );
?>
<div class="momo-container momo-section" data-momo-placeholder="reservation-status"<?php echo $momos_ok ? ' data-ref="' . esc_attr( str_replace( '-', '', $momos_ref ) ) . '"' : ''; ?>>
	<div class="momo-reading momo-stack">
		<?php momos_component( 'alert', [ 'type' => 'info', 'body' => momos_s( 'status.link_invalid' ) ] ); ?>
		<p><?php momos_component( 'link', [ 'label' => momos_s( 'cta.see_branches' ), 'url' => momos_page_url( 'branches' ), 'more' => true ] ); ?></p>
	</div>
</div>
<?php
get_footer();
