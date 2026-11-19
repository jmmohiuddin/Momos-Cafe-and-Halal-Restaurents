<?php
/**
 * SCR-C10 Request submitted — `{P}/reservation/submitted/` (reservation.md §6.2, screens.md §3.10;
 * F-RES-02, FR-RES-006, DEC-066). Rendered client-side by assets/js/reservation-submitted.js from
 * `sessionStorage["momo_last_request"]` (the API-02 body): no request, no personal data in the URL or
 * in the server HTML, so the page is identical for everyone. `noindex` + `X-Robots-Tag`,
 * `Referrer-Policy: no-referrer`, `no-store` (TemplateRouter / SecurityHeaders); no GTM/GA4, Turnstile
 * or any third-party script (AnalyticsHooks emits nothing here). No .ics / "add to calendar".
 * Empty state (no stored request, or no JavaScript): `res.submitted.generic` + link to C04, never a
 * redirect. Pending badge = hourglass + amber, never check/green (BR-RES-01).
 *
 * @package momos
 */

defined( 'ABSPATH' ) || exit;

get_header();
$momos_v = class_exists( \Momos\Reservation\Front\CustomerPages::class ) ? \Momos\Reservation\Front\CustomerPages::submitted_view() : null;
?>
<div class="momo-container momo-section momo-res momo-res-result" data-momo-res="submitted">
	<div class="momo-reading momo-stack">
		<header class="momo-res-result__head" data-res-head>
			<span class="momo-status momo-status--pending momo-status--block" data-res-badge>
				<?php momos_the_icon( 'hourglass' ); ?>
				<h1 class="momo-title" id="res-heading" tabindex="-1" data-res-heading><?php echo esc_html( momos_s( 'res.submitted.heading' ) ); ?></h1>
			</span>
		</header>
		<div data-res-generic>
			<?php momos_component( 'alert', [ 'type' => 'info', 'body' => momos_s( 'res.submitted.generic' ) ] ); ?>
			<p class="momo-mt-4"><?php momos_component( 'link', [ 'label' => momos_s( 'cta.see_branches' ), 'url' => momos_page_url( 'branches' ), 'more' => true ] ); ?></p>
		</div>
		<div class="momo-stack" data-res-result hidden></div>
		<p class="momo-visually-hidden" role="status" aria-live="polite" data-res-live></p>
	</div>
	<?php if ( null !== $momos_v ) : ?>
		<script type="application/json" id="momo-res-config"><?php echo \Momos\Reservation\Front\CustomerPages::json( $momos_v['config'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON with HEX_TAG/AMP/QUOT/APOS. ?></script>
	<?php endif; ?>
</div>
<?php
get_footer();
