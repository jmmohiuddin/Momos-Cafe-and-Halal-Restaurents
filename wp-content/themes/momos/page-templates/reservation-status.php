<?php
/**
 * SCR-C11 Status & cancel — `{P}/reservation/status/{REF}/#t={token}` (reservation.md §6.3,
 * screens.md §3.11; F-RES-03, FR-RES-019/020, DEC-058/064/066). Non-personalised shell, identical
 * for every visitor: the server never looks the reference up. assets/js/reservation-status.js reads the
 * `#t=` fragment, removes it with `history.replaceState`, keeps it in sessionStorage (language
 * switcher re-attaches it as `#t=`) and calls API-03/API-04 with header `X-Momo-Token` only.
 * Headers: `no-store, private`, `noindex, nofollow`, `no-referrer`; no analytics or third-party script.
 * States: skeleton → data; no JS `status.no_js` + branch phones; 404/410 `status.link_invalid`.
 * A malformed reference is a 404 (CustomerPages::guard_status_ref).
 *
 * @package momos
 */

defined( 'ABSPATH' ) || exit;

get_header();
$momos_v = class_exists( \Momos\Reservation\Front\CustomerPages::class ) ? \Momos\Reservation\Front\CustomerPages::status_view() : null;
?>
<div class="momo-container momo-section momo-res momo-res-result" data-momo-res="status">
	<div class="momo-reading momo-stack">
		<header class="momo-res-result__head">
			<h1 class="momo-title" id="res-heading" tabindex="-1" data-res-heading><?php echo esc_html( momos_s( 'status.title' ) ); ?></h1>
		</header>
		<noscript>
			<div class="momo-stack">
				<?php momos_component( 'alert', [ 'type' => 'info', 'body' => momos_s( 'status.no_js.list' ) ] ); ?>
				<?php get_template_part( 'template-parts/reservation/phones', null, [ 'branches' => $momos_v['branches'] ?? [] ] ); ?>
			</div>
		</noscript>
		<div class="momo-res-skel-card" data-res-skeleton hidden aria-busy="true">
			<span class="momo-visually-hidden"><?php echo esc_html( momos_s( 'a11y.loading' ) ); ?></span>
			<span class="momo-skel momo-skel--block" aria-hidden="true"></span>
			<span class="momo-skel momo-skel--text" aria-hidden="true"></span>
			<span class="momo-skel momo-skel--text" aria-hidden="true"></span>
		</div>
		<div data-res-invalid hidden>
			<?php momos_component( 'alert', [ 'type' => 'info', 'body' => momos_s( 'status.link_invalid' ) ] ); ?>
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
