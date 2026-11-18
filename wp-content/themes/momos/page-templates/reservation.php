<?php
/**
 * SCR-C09 Reservation form — PAGE SHELL ONLY (TASK 2). The form (CMP-ReservationForm, API-01/02,
 * reservation.md §6.1) is built by the Reservation team against the REST API and replaces the
 * placeholder block below. Until then the page shows the documented "no accepting branch" state:
 * notice + branch phones instead of the form (screens.md §3.9 States). No breadcrumb, no action bar.
 *
 * @package momos
 */

defined( 'ABSPATH' ) || exit;

get_header();
$momos_id   = (int) get_queried_object_id();
$momos_brs  = class_exists( \Momos\Presentation\View\BranchLinks::class ) ? \Momos\Presentation\View\BranchLinks::all() : [];
get_template_part( 'template-parts/frame/page-banner', null, [ 'title' => momos_s( 'res.title' ), 'lead' => momos_s( 'res.intro' ), 'breadcrumbs' => false ] );
?>
<div class="momo-container momo-section momo-res-shell" data-momo-placeholder="reservation-form">
	<div class="momo-reading momo-stack">
		<?php momos_component( 'alert', [ 'type' => 'info', 'body' => momos_s( 'res.placeholder.body' ) ] ); ?>
		<?php if ( array_filter( $momos_brs, static fn( $b ) => '' !== $b['tel'] && empty( $b['temp_closed'] ) ) ) : ?>
			<section class="momo-card" aria-labelledby="momo-res-phones">
				<h2 id="momo-res-phones" class="momo-h3"><?php echo esc_html( momos_s( 'res.placeholder.phones' ) ); ?></h2>
				<ul class="momo-list-reset momo-stack momo-mt-4">
					<?php foreach ( $momos_brs as $momos_b ) : ?>
						<?php if ( '' !== $momos_b['tel'] && empty( $momos_b['temp_closed'] ) ) : ?>
							<li class="momo-res-phone">
								<span class="momo-strong"><?php echo esc_html( (string) $momos_b['name'] ); ?></span>
								<a class="momo-btn momo-btn--secondary" href="<?php echo esc_attr( (string) $momos_b['tel'] ); ?>" data-momo-event="click_phone" data-momo-cta="reservation_page" data-momo-branch="<?php echo esc_attr( (string) $momos_b['code'] ); ?>"><?php momos_the_icon( 'phone' ); ?><span class="momo-visually-hidden"><?php echo esc_html( momos_s( 'cta.call' ) . ' ' . $momos_b['name'] ); ?> </span><?php echo momos_ltr( (string) $momos_b['phone'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></a>
							</li>
						<?php endif; ?>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endif; ?>
		<p class="momo-small momo-muted"><?php echo esc_html( momos_s( 'res.no_payment' ) ); ?></p>
	</div>
</div>
<?php
get_footer();
