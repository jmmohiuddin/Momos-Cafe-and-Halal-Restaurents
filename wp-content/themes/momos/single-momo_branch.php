<?php
/**
 * SCR-C05 Branch detail (screens.md §3.5, FR-BR-002/003/010, AC-CORP-08-01/04, AC-CORP-09-05, DEC-029).
 * Order: breadcrumb · branch notices · H1 + concept · summary (open status / temporarily closed, today's
 * hours, Request · Call · Directions) · in-page anchors · address + access · map facade · weekly hours ·
 * closures (30 days) · halal + alcohol · facilities · offers at this branch · menu link · description ·
 * photos · closing CTA. ≥ 1024 px: content 8 + sticky summary 4 (inline-start in RTL). Empty sections and
 * their headings are not rendered (FR-BR-003). One template renders every branch (FR-BR-011).
 * Redirects/404 for closed-permanently, TEST (non-staff) and untranslated branches: Branch module
 * (BranchVisibility::template_redirect).
 *
 * @package momos
 */

defined( 'ABSPATH' ) || exit;

get_header();

$momos_id   = (int) get_queried_object_id();
$momos_lang = momos_theme_lang();
$momos_b    = class_exists( \Momos\Presentation\View\Cards::class ) ? \Momos\Presentation\View\Cards::branch( $momos_id ) : null;
$momos_raw  = class_exists( \Momos\Branch\Repository\BranchRepository::class ) ? \Momos\Branch\Repository\BranchRepository::get( $momos_id ) : null;
$momos_bv   = \Momos\Presentation\View\BranchView::class;

if ( ! is_array( $momos_b ) || ! is_array( $momos_raw ) ) {
	get_template_part( 'template-parts/frame/page-banner', null, [ 'title' => \Momos\Presentation\View\Content::title( $momos_id ) ] );
	echo '<div class="momo-container momo-section">';
	momos_component( 'empty-state', [ 'variant' => 'error', 'title' => momos_s( 'common.error.title' ), 'body' => momos_s( 'common.error.body' ), 'action' => [ 'label' => momos_s( 'cta.see_branches' ), 'url' => momos_page_url( 'branches' ) ] ] );
	echo '</div>';
	get_footer();
	return;
}

$momos_code    = (string) $momos_b['code'];
$momos_hours   = $momos_bv::hours( $momos_id );
$momos_today   = array_values( array_filter( $momos_hours, static fn( $r ) => $r['today'] ) )[0] ?? null;
$momos_closure = $momos_bv::closures( $momos_id );
$momos_addr    = $momos_bv::address( $momos_id );
$momos_access  = $momos_bv::access( $momos_id );
$momos_fac     = $momos_bv::facilities( $momos_id );
$momos_maps    = $momos_bv::maps_url( $momos_raw );
$momos_photos  = $momos_bv::photos( $momos_id );
$momos_desc    = \Momos\Presentation\View\Content::body( $momos_id );
$momos_claim   = class_exists( \Momos\Dietary\Service\ClaimResolver::class ) ? \Momos\Dietary\Service\ClaimResolver::branch_claim( $momos_id ) : null;
$momos_has_hal = is_array( $momos_claim ) && '' !== trim( (string) ( $momos_claim['statement'] ?? '' ) );
$momos_offers  = [];
$momos_notices = [];
if ( class_exists( \Momos\Branch\Query\OfferQuery::class ) ) {
	try {
		$momos_offers  = \Momos\Branch\Query\OfferQuery::for_branch( $momos_id, $momos_lang );
		$momos_notices = \Momos\Branch\Query\NoticeQuery::for_branch( $momos_id, $momos_lang, 24, null, true );
	} catch ( \Throwable $e ) {
		$momos_offers = [];
	}
}
$momos_menu_url = add_query_arg( 'branch', $momos_code, momos_page_url( 'menu' ) );
$momos_anchors  = array_filter(
	[
		'hours'      => $momos_hours ? momos_s( 'branch.sec.hours' ) : null,
		'access'     => ( '' !== $momos_addr['ja'] || $momos_access['lines'] ) ? momos_s( 'branch.sec.access' ) : null,
		'menu'       => momos_s( 'branch.sec.menu' ),
		'offers'     => $momos_offers ? momos_s( 'branch.sec.offers' ) : null,
		'halal'      => $momos_has_hal ? momos_s( 'branch.sec.halal' ) : null,
		'facilities' => $momos_fac ? momos_s( 'branch.sec.facilities' ) : null,
	]
);
$momos_req      = (string) $momos_b['request_url'];
$momos_trail    = [
	[ 'name' => momos_s( 'bc.home' ), 'url' => momos_theme_url( '/' ) ],
	[ 'name' => momos_s( 'nav.branches' ), 'url' => momos_page_url( 'branches' ) ],
	[ 'name' => (string) $momos_b['name'] ],
];
?>
<div class="momo-banner momo-branch-banner">
	<div class="momo-container">
		<?php get_template_part( 'template-parts/frame/breadcrumbs', null, [ 'items' => $momos_trail ] ); ?>
		<?php if ( ! empty( $momos_raw['is_test'] ) ) : ?>
			<p class="momo-alert momo-alert--warning momo-mt-4" lang="en"><?php momos_the_icon( 'flag' ); ?><strong><?php echo esc_html( momos_s( 'res.test_branch_banner' ) ); ?></strong></p>
		<?php endif; ?>
		<?php if ( $momos_notices ) : ?>
			<div class="momo-stack momo-mt-4" role="group" aria-label="<?php echo esc_attr( momos_s( 'branch.notices.label' ) ); ?>">
				<?php foreach ( $momos_notices as $momos_n ) : ?>
					<?php momos_component( 'notice', [ 'id' => (int) $momos_n['id'], 'severity' => (string) $momos_n['severity'], 'title' => (string) $momos_n['title'], 'body' => (string) $momos_n['body'], 'link_url' => (string) $momos_n['link_url'], 'link_label' => (string) $momos_n['link_label'], 'upcoming' => ! empty( $momos_n['upcoming'] ), 'start_ms' => $momos_n['start_ms'] ?? null, 'end_ms' => $momos_n['end_ms'] ?? null, 'card' => true ] ); ?>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
		<div class="momo-branch-head">
			<h1><?php echo esc_html( (string) $momos_b['name'] ); ?></h1>
			<?php if ( '' !== (string) $momos_b['concept'] ) : ?>
				<span class="momo-chip momo-chip--tag momo-concept"><?php echo esc_html( (string) $momos_b['concept'] ); ?></span>
			<?php endif; ?>
		</div>
		<?php if ( '' !== (string) $momos_b['area'] ) : ?>
			<p class="momo-lead"><?php echo esc_html( (string) $momos_b['area'] ); ?></p>
		<?php endif; ?>
	</div>
</div>

<div class="momo-container momo-branch-layout">
	<aside class="momo-branch-aside" aria-label="<?php echo esc_attr( (string) $momos_b['name'] ); ?>">
		<div class="momo-card momo-branch-summary">
			<?php momos_component( 'open-status', [ 'status' => $momos_b['open'] ?? null ] ); ?>
			<?php if ( is_array( $momos_today ) && empty( $momos_b['temp_closed'] ) ) : ?>
				<p class="momo-branch-summary__today"><?php momos_the_icon( 'clock', 'momo-icon--sm' ); ?><span><span class="momo-branch-summary__label"><?php echo esc_html( momos_s( 'branch.today' ) ); ?></span> <?php echo wp_kses( (string) $momos_today['hours_html'], [ 'bdi' => [ 'dir' => true ] ] ); ?>
				<?php
				if ( '' !== $momos_today['lo_html'] ) :
					?>
					<span class="momo-muted">(<?php echo esc_html( momos_s( 'branch.hours.last_order' ) ); ?> <?php echo wp_kses( (string) $momos_today['lo_html'], [ 'bdi' => [ 'dir' => true ] ] ); ?>)</span><?php endif; ?></span></p>
			<?php endif; ?>
			<div class="momo-branch-summary__actions">
				<?php
				if ( '' !== $momos_req ) {
					momos_component( 'button', [ 'label' => momos_s( 'cta.request_table_branch' ), 'url' => $momos_req, 'variant' => 'primary', 'block' => true, 'icon' => 'calendar', 'attrs' => [ 'data-momo-event' => 'click_reservation', 'data-momo-cta' => 'branch_page', 'data-momo-branch' => $momos_code ] ] );
				} elseif ( empty( $momos_b['temp_closed'] ) ) {
					momos_component( 'alert', [ 'type' => 'info', 'body' => momos_s( 'branch.not_accepting' ) ] );
				}
				if ( '' !== (string) $momos_b['tel'] ) {
					echo '<a class="momo-btn momo-btn--secondary momo-btn--block" href="' . esc_attr( (string) $momos_b['tel'] ) . '" data-momo-event="click_phone" data-momo-cta="branch_page" data-momo-branch="' . esc_attr( $momos_code ) . '">' . momos_icon( 'phone' ) . '<span>' . esc_html( momos_s( 'cta.call' ) ) . '</span> ' . momos_ltr( (string) $momos_b['phone'] ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput
				}
				if ( '' !== (string) $momos_b['directions'] ) {
					momos_component( 'button', [ 'label' => momos_s( 'cta.directions' ), 'aria_label' => momos_s( 'cta.directions_to', [ 'branch' => (string) $momos_b['name'] ] ) . ' ' . momos_s( 'a11y.new_tab' ), 'url' => (string) $momos_b['directions'], 'variant' => 'secondary', 'block' => true, 'icon' => 'directions', 'external' => true, 'attrs' => [ 'data-momo-event' => 'click_directions', 'data-momo-cta' => 'branch_page', 'data-momo-branch' => $momos_code ] ] );
				}
				?>
			</div>
		</div>
	</aside>

	<div class="momo-branch-main">
		<?php if ( count( $momos_anchors ) > 1 ) : ?>
			<nav class="momo-anchors" aria-label="<?php echo esc_attr( momos_s( 'branch.on_this_page' ) ); ?>">
				<ul class="momo-chips momo-chips--scroll">
					<?php foreach ( $momos_anchors as $momos_k => $momos_label ) : ?>
						<li><a class="momo-chip momo-chip--link" href="#branch-<?php echo esc_attr( $momos_k ); ?>"><?php echo esc_html( (string) $momos_label ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			</nav>
		<?php endif; ?>

		<?php if ( isset( $momos_anchors['access'] ) ) : ?>
			<section class="momo-branch-sec" id="branch-access" aria-labelledby="branch-access-h">
				<h2 id="branch-access-h"><?php echo esc_html( momos_s( 'branch.address.title' ) ); ?></h2>
				<div class="momo-branch-sec__split">
					<?php momos_component( 'address-block', [ 'address' => $momos_addr, 'access' => $momos_access ] ); ?>
					<?php momos_component( 'map-facade', [ 'name' => (string) $momos_b['name'], 'image_id' => (int) get_post_meta( $momos_id, 'momo_map_preview_image', true ), 'maps_url' => $momos_maps, 'directions_url' => (string) $momos_b['directions'], 'branch_code' => $momos_code, 'cta_location' => 'branch_page' ] ); ?>
				</div>
			</section>
		<?php endif; ?>

		<?php if ( $momos_hours ) : ?>
			<section class="momo-branch-sec" id="branch-hours" aria-labelledby="branch-hours-h">
				<h2 id="branch-hours-h"><?php echo esc_html( momos_s( 'branch.hours.title' ) ); ?></h2>
				<?php momos_component( 'hours-table', [ 'rows' => $momos_hours, 'caption' => momos_s( 'branch.hours.title' ) ] ); ?>
				<?php if ( $momos_closure ) : ?>
					<h3 class="momo-mt-8"><?php echo esc_html( momos_s( 'branch.closures.title' ) ); ?></h3>
					<ul class="momo-closures">
						<?php foreach ( $momos_closure as $momos_c ) : ?>
							<li><span class="momo-closures__date"><?php echo wp_kses( (string) $momos_c['date_html'], [] ); ?></span><span><?php echo wp_kses( (string) $momos_c['text_html'], [ 'bdi' => [ 'dir' => true ] ] ); ?></span></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</section>
		<?php endif; ?>

		<?php if ( $momos_has_hal ) : ?>
			<div class="momo-branch-sec" id="branch-halal">
				<?php momos_component( 'claim-statement', [ 'claim' => $momos_claim, 'context' => 'branch', 'h' => 2, 'link' => true ] ); ?>
			</div>
		<?php endif; ?>

		<?php if ( $momos_fac ) : ?>
			<section class="momo-branch-sec" id="branch-facilities" aria-labelledby="branch-facilities-h">
				<h2 id="branch-facilities-h"><?php echo esc_html( momos_s( 'branch.facilities.title' ) ); ?></h2>
				<?php momos_component( 'facility-list', [ 'items' => $momos_fac ] ); ?>
			</section>
		<?php endif; ?>

		<?php if ( $momos_offers ) : ?>
			<section class="momo-branch-sec" id="branch-offers" aria-labelledby="branch-offers-h">
				<h2 id="branch-offers-h"><?php echo esc_html( momos_s( 'branch.offers.title' ) ); ?></h2>
				<ul class="momo-grid momo-grid--2 momo-list-reset">
					<?php foreach ( $momos_offers as $momos_oid ) : ?>
						<li><?php momos_component( 'offer-card', [ 'offer_id' => (int) $momos_oid, 'h' => 3 ] ); ?></li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endif; ?>

		<section class="momo-branch-sec" id="branch-menu" aria-labelledby="branch-menu-h">
			<h2 id="branch-menu-h" class="momo-visually-hidden"><?php echo esc_html( momos_s( 'branch.sec.menu' ) ); ?></h2>
			<a class="momo-card momo-card--tint momo-menu-link" href="<?php echo esc_url( $momos_menu_url ); ?>">
				<span class="momo-disc"><?php momos_the_icon( 'utensils' ); ?></span>
				<span class="momo-title"><?php echo esc_html( momos_s( 'branch.menu_link' ) ); ?></span>
				<?php momos_the_icon( 'arrow-end' ); ?>
			</a>
		</section>

		<?php if ( '' !== $momos_desc ) : ?>
			<section class="momo-branch-sec" aria-labelledby="branch-about-h">
				<h2 id="branch-about-h"><?php echo esc_html( momos_s( 'branch.about.title' ) ); ?></h2>
				<div class="momo-prose"><?php echo $momos_desc; // phpcs:ignore WordPress.Security.EscapeOutput -- the_content filters. ?></div>
			</section>
		<?php endif; ?>

		<?php if ( $momos_photos ) : ?>
			<section class="momo-branch-sec" aria-labelledby="branch-photos-h">
				<h2 id="branch-photos-h"><?php echo esc_html( momos_s( 'branch.photos.title' ) ); ?></h2>
				<ul class="momo-photo-grid momo-list-reset">
					<?php foreach ( $momos_photos as $momos_pid ) : ?>
						<li><?php echo wp_get_attachment_image( (int) $momos_pid, 'momo-card', false, [ 'loading' => 'lazy', 'decoding' => 'async', 'alt' => \Momos\Presentation\View\MenuView::alt( (int) $momos_pid, $momos_lang ) ] ); ?></li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endif; ?>
	</div>
</div>
<?php
if ( '' !== $momos_req ) {
	momos_component( 'closing-cta', [ 'cta' => 'branch_page', 'branch' => $momos_code, 'url' => $momos_req, 'label' => momos_s( 'cta.request_table_branch' ), 'id' => 'momo-branch-closing' ] );
}
get_footer();
