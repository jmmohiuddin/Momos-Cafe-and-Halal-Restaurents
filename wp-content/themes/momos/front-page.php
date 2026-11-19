<?php
/**
 * SCR-C01 Home (screens.md §3.1, content-strings.md §8.1, FR-CORP-001, AC-CORP-01-01…07, DEC-060).
 * Section order (binding): site-wide notices (frame) → hero → offers → branch teaser → featured dishes →
 * halal summary → how reservations work. Every data section is omitted when it has no data. No counters,
 * marquee, carousel, ordering UI or seal (Doc 08A).
 *
 * @package momos
 */

defined( 'ABSPATH' ) || exit;

get_header();

$momos_lang    = momos_theme_lang();
$momos_home_id = (int) get_option( 'page_on_front' );
$momos_brand   = class_exists( \Momos\Presentation\View\Brand::class ) ? \Momos\Presentation\View\Brand::class : null;
$momos_tagline = $momos_brand ? $momos_brand::text( 'momo_tagline' ) : '';
$momos_h1      = '' !== $momos_tagline ? $momos_tagline : ( $momos_brand ? $momos_brand::trading_name() : get_bloginfo( 'name' ) );
$momos_lead    = $momos_home_id && class_exists( \Momos\Presentation\View\Content::class ) ? \Momos\Presentation\View\Content::excerpt( $momos_home_id ) : '';
$momos_hero    = $momos_home_id ? (int) get_post_thumbnail_id( $momos_home_id ) : 0;

// Data (module services; each list is empty when its module is absent).
$momos_offer_ids = [];
$momos_has_offer = false;
if ( class_exists( \Momos\Branch\Query\OfferQuery::class ) ) {
	try {
		$momos_offer_ids = \Momos\Branch\Query\OfferQuery::home_featured( $momos_lang );
		\Momos\Presentation\View\Cards::prime( $momos_offer_ids, true );
		$momos_has_offer = [] !== $momos_offer_ids;
	} catch ( \Throwable $e ) {
		$momos_offer_ids = [];
	}
}
$momos_branches = class_exists( \Momos\Presentation\View\BranchLinks::class ) ? \Momos\Presentation\View\BranchLinks::all() : [];
$momos_dishes   = [];
if ( class_exists( \Momos\Menu\Service\MenuQueryService::class ) ) {
	try {
		$momos_dishes = \Momos\Menu\Service\MenuQueryService::featured( $momos_lang, 6 );
	} catch ( \Throwable $e ) {
		$momos_dishes = [];
	}
}
$momos_cats = class_exists( \Momos\Presentation\View\MenuView::class ) ? \Momos\Presentation\View\MenuView::categories( null ) : [];
?>
<section class="momo-hero" aria-labelledby="momo-hero-title">
	<div class="momo-container momo-hero__inner">
		<div class="momo-hero__copy">
			<span class="momo-eyebrow"><?php echo esc_html( momos_s( 'home.hero.eyebrow' ) ); ?></span>
			<h1 id="momo-hero-title" class="momo-display"><?php echo esc_html( $momos_h1 ); ?></h1>
			<?php if ( '' !== $momos_lead ) : ?>
				<p class="momo-lead"><?php echo esc_html( $momos_lead ); ?></p>
			<?php endif; ?>
			<div class="momo-hero__ctas">
				<?php
				momos_component( 'button', [ 'label' => momos_s( 'cta.request_table' ), 'url' => momos_page_url( 'reservation' ), 'variant' => 'primary', 'size' => 'lg', 'icon' => 'calendar', 'attrs' => [ 'data-momo-event' => 'click_reservation', 'data-momo-cta' => 'hero' ] ] );
				momos_component( 'button', [ 'label' => momos_s( 'cta.find_branch' ), 'url' => momos_page_url( 'branches' ), 'variant' => 'secondary', 'size' => 'lg', 'icon' => 'store' ] );
				?>
			</div>
			<?php if ( $momos_has_offer ) : ?>
				<p class="momo-hero__offer"><?php momos_component( 'link', [ 'label' => momos_s( 'offers.see_current' ), 'url' => momos_page_url( 'offers' ), 'more' => true ] ); ?></p>
			<?php endif; ?>
			<?php if ( $momos_cats ) : ?>
				<div class="momo-hero__cats">
					<p class="momo-hero__cats-label"><?php echo esc_html( momos_s( 'home.categories.label' ) ); ?></p>
					<?php momos_component( 'category-tiles', [ 'items' => $momos_cats, 'variant' => 'chip', 'label' => momos_s( 'home.categories.label' ) ] ); ?>
				</div>
			<?php endif; ?>
		</div>
		<div class="momo-hero__art">
			<?php
			if ( $momos_hero ) {
				// Decorative unless it names a dish (alt from media data).
				echo wp_get_attachment_image( $momos_hero, 'momo-hero', false, [ 'fetchpriority' => 'high', 'loading' => 'eager', 'decoding' => 'async', 'sizes' => '(min-width: 1024px) 480px, 240px' ] );
			} else {
				echo '<span class="momo-placeholder momo-hero__placeholder" aria-hidden="true">' . momos_icon( 'utensils', 'momo-icon--lg' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static markup.
			}
			?>
		</div>
	</div>
</section>

<?php if ( $momos_offer_ids ) : ?>
	<section class="momo-section" aria-labelledby="momo-home-offers">
		<div class="momo-container">
			<?php momos_component( 'section-header', [ 'id' => 'momo-home-offers', 'title' => momos_s( 'offers.title' ), 'link' => [ 'label' => momos_s( 'offers.see_all' ), 'url' => momos_page_url( 'offers' ) ] ] ); ?>
			<ul class="momo-grid momo-grid--3 momo-list-reset">
				<?php foreach ( $momos_offer_ids as $momos_oid ) : ?>
					<li><?php momos_component( 'offer-card', [ 'offer_id' => (int) $momos_oid, 'h' => 3 ] ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	</section>
<?php endif; ?>

<?php if ( $momos_branches ) : ?>
	<section class="momo-band momo-on-dark momo-home-branches" aria-labelledby="momo-home-branches">
		<div class="momo-container">
			<?php momos_component( 'section-header', [ 'id' => 'momo-home-branches', 'title' => momos_s( 'nav.branches' ), 'lead' => momos_s( 'home.branches.lead' ), 'link' => [ 'label' => momos_s( 'home.branches.see_all' ), 'url' => momos_page_url( 'branches' ) ] ] ); ?>
			<ul class="momo-grid momo-grid--3 momo-list-reset">
				<?php foreach ( $momos_branches as $momos_b ) : ?>
					<li><?php momos_component( 'branch-card', [ 'branch_id' => (int) $momos_b['id'], 'variant' => 'on-dark', 'h' => 3, 'cta_location' => 'branch_card' ] ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	</section>
<?php endif; ?>

<?php if ( $momos_dishes ) : ?>
	<section class="momo-section" aria-labelledby="momo-home-dishes">
		<div class="momo-container">
			<?php momos_component( 'section-header', [ 'id' => 'momo-home-dishes', 'title' => momos_s( 'home.featured.title' ), 'center' => true ] ); ?>
			<ul class="momo-grid momo-grid--3 momo-grid--dishes momo-list-reset">
				<?php foreach ( $momos_dishes as $momos_did ) : ?>
					<li><?php momos_component( 'dish-card', [ 'dish_id' => (int) $momos_did, 'h' => 3 ] ); ?></li>
				<?php endforeach; ?>
			</ul>
			<p class="momo-center-action"><?php momos_component( 'button', [ 'label' => momos_s( 'home.featured.link' ), 'url' => momos_page_url( 'menu' ), 'variant' => 'secondary', 'icon_end' => 'arrow-end' ] ); ?></p>
		</div>
	</section>
<?php endif; ?>

<?php
// Halal summary band only when the statement renders (screens.md §2 "sections without data are not rendered").
ob_start();
momos_component( 'claim-statement', [ 'context' => 'brand', 'h' => 2, 'link' => true ] );
$momos_claim_html = trim( (string) ob_get_clean() );
if ( '' !== $momos_claim_html ) :
	?>
<div class="momo-section momo-section--raised">
	<div class="momo-container momo-reading-center">
		<?php echo $momos_claim_html; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by the component. ?>
	</div>
</div>
<?php endif; ?>

<section class="momo-section" aria-labelledby="momo-home-how">
	<div class="momo-container">
		<?php momos_component( 'section-header', [ 'id' => 'momo-home-how', 'title' => momos_s( 'home.how.title' ), 'center' => true ] ); ?>
		<?php
		momos_component(
			'step-list',
			[
				'steps' => [
					[ 'title' => momos_s( 'home.how.step1' ), 'text' => momos_s( 'home.how.step1.text' ), 'icon' => 'send' ],
					[ 'title' => momos_s( 'home.how.step2' ), 'text' => momos_s( 'home.how.step2.text' ), 'icon' => 'store' ],
					[ 'title' => momos_s( 'home.how.step3' ), 'text' => momos_s( 'home.how.step3.text' ), 'icon' => 'mail' ],
				],
			]
		);
		?>
		<p class="momo-center-action"><?php momos_component( 'button', [ 'label' => momos_s( 'cta.request_table' ), 'url' => momos_page_url( 'reservation' ), 'variant' => 'primary', 'icon' => 'calendar', 'attrs' => [ 'data-momo-event' => 'click_reservation', 'data-momo-cta' => 'home_section' ] ] ); ?></p>
	</div>
</section>
<?php
get_footer();
