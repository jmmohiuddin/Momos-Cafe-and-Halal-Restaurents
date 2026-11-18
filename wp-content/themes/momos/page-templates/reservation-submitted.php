<?php
/**
 * SCR-C10 Request submitted — PAGE SHELL ONLY (TASK 2; content by the Reservation team, reservation.md
 * §6.2). `noindex` + `X-Robots-Tag`, `Referrer-Policy: no-referrer` (TemplateRouter), no analytics data
 * (AnalyticsHooks), no action bar, reading width. Shows the documented empty state (no sessionStorage):
 * `res.submitted.generic` + link to the branch directory — never a redirect.
 *
 * @package momos
 */

defined( 'ABSPATH' ) || exit;

get_header();
get_template_part( 'template-parts/frame/page-banner', null, [ 'title' => momos_s( 'res.submitted.heading' ), 'breadcrumbs' => false ] );
?>
<div class="momo-container momo-section" data-momo-placeholder="reservation-submitted">
	<div class="momo-reading momo-stack">
		<?php momos_component( 'alert', [ 'type' => 'info', 'body' => momos_s( 'res.submitted.generic' ) ] ); ?>
		<p><?php momos_component( 'link', [ 'label' => momos_s( 'cta.see_branches' ), 'url' => momos_page_url( 'branches' ), 'more' => true ] ); ?></p>
	</div>
</div>
<?php
get_footer();
