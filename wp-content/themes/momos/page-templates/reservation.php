<?php
/**
 * SCR-C09 Reservation form — `{P}/reservation/` (reservation.md §6.1, screens.md §3.9; F-RES-01,
 * FR-RES-001..005/027/029, BR-RES-01). H1 `res.title` · `res.intro` · promise (if configured) · form
 * (template-parts/reservation/form.php) driven by API-01/API-02 through assets/js/reservation-form.js.
 * Empty state: no accepting branch → notice + branch phones instead of the form. Without JavaScript a
 * `<noscript>` message lists the branch phones (pickers and Turnstile need JS, Doc 05 WF-CUS-07).
 * No breadcrumb, no mobile action bar. Wording: a *request*, never a booking (BR-RES-01, §8.4).
 *
 * @package momos
 */

defined( 'ABSPATH' ) || exit;

get_header();
$momos_v = class_exists( \Momos\Reservation\Front\CustomerPages::class ) ? \Momos\Reservation\Front\CustomerPages::form_view() : null;
get_template_part( 'template-parts/frame/page-banner', null, [ 'title' => momos_s( 'res.title' ), 'lead' => momos_s( 'res.intro' ), 'breadcrumbs' => false ] );
?>
<div class="momo-container momo-section momo-res" data-momo-res="form">
	<?php if ( null === $momos_v || ! $momos_v['any_accepting'] ) : ?>
		<div class="momo-reading momo-stack">
			<?php momos_component( 'alert', [ 'type' => 'info', 'body' => momos_s( 'res.empty.none' ) ] ); ?>
			<?php get_template_part( 'template-parts/reservation/phones', null, [ 'branches' => $momos_v['branches'] ?? ( class_exists( \Momos\Presentation\View\BranchLinks::class ) ? \Momos\Presentation\View\BranchLinks::all() : [] ), 'cta' => 'reservation_page' ] ); ?>
			<p class="momo-small momo-muted"><?php echo esc_html( momos_s( 'res.no_payment' ) ); ?></p>
		</div>
	<?php else : ?>
		<p class="momo-alert momo-alert--info momo-res-promise" data-res-promise hidden></p>
		<noscript>
			<div class="momo-reading momo-stack">
				<?php momos_component( 'alert', [ 'type' => 'info', 'body' => momos_s( 'res.noscript' ) ] ); ?>
				<?php get_template_part( 'template-parts/reservation/phones', null, [ 'branches' => $momos_v['branches'] ] ); ?>
			</div>
		</noscript>
		<?php get_template_part( 'template-parts/reservation/form', null, $momos_v ); ?>
		<script type="application/json" id="momo-res-config"><?php echo \Momos\Reservation\Front\CustomerPages::json( $momos_v['config'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON with HEX_TAG/AMP/QUOT/APOS. ?></script>
	<?php endif; ?>
</div>
<?php
get_footer();
