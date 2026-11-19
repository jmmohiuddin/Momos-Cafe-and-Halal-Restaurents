<?php
/**
 * SCR-C21 Offers (screens.md §3.17, FR-CORP-011, AC-CORP-09-03…09, TC-CORP-011…014, DEC-060). Banner
 * (H1 `offers.title` + `offers.intro`) · branch filter chips (only when active offers have different
 * scopes; client-side, `?branch=` kept in the URL, canonical clean) · CMP-OfferCard per active offer
 * translated in the language (OfferQuery: published, in validity, not sample in production). Empty:
 * `offers.empty` + Menu / Branches links; the page is `noindex` while empty (TemplateRouter::robots).
 * No coupon code, redemption, countdown or "ends soon" (BR-CORP-01).
 *
 * @package momos
 */

defined( 'ABSPATH' ) || exit;

get_header();
$momos_lang = momos_theme_lang();
$momos_ids  = [];
if ( class_exists( \Momos\Branch\Query\OfferQuery::class ) ) {
	try {
		$momos_ids = \Momos\Branch\Query\OfferQuery::all_active( $momos_lang );
	} catch ( \Throwable $e ) {
		$momos_ids = [];
	}
}
// Scope per offer: 'all' or branch codes (chips only when scopes differ).
$momos_scope = [];
$momos_chips = [];
foreach ( $momos_ids as $momos_oid ) {
	$momos_codes = [];
	if ( 'all_branches' !== \Momos\Branch\Query\OfferQuery::scope( (int) $momos_oid ) ) {
		foreach ( \Momos\Branch\Query\OfferQuery::card_branch_ids( (int) $momos_oid ) as $momos_bid ) {
			$momos_code = (string) get_post_meta( (int) $momos_bid, 'momo_branch_code', true );
			$momos_name = \Momos\Branch\Repository\BranchRepository::name( (int) $momos_bid, $momos_lang );
			if ( '' !== $momos_code && '' !== $momos_name ) {
				$momos_codes[]              = $momos_code;
				$momos_chips[ $momos_code ] = $momos_name;
			}
		}
	}
	$momos_scope[ (int) $momos_oid ] = $momos_codes ? implode( ' ', $momos_codes ) : 'all';
}
$momos_sel  = isset( $_GET['branch'] ) ? strtoupper( sanitize_key( wp_unslash( (string) $_GET['branch'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
$momos_sel  = isset( $momos_chips[ $momos_sel ] ) ? $momos_sel : '';
$momos_use  = count( array_unique( $momos_scope ) ) > 1 && $momos_chips;
$momos_self = momos_page_url( 'offers' );
get_template_part( 'template-parts/frame/page-banner', null, [ 'title' => momos_s( 'offers.title' ), 'lead' => momos_s( 'offers.intro' ) ] );
?>
<div class="momo-container momo-section momo-offers">
	<?php if ( $momos_ids ) : ?>
		<?php if ( $momos_use ) : ?>
			<nav class="momo-offers__filter" aria-label="<?php echo esc_attr( momos_s( 'offers.filter.label' ) ); ?>" data-momo-offer-filter>
				<ul class="momo-chips momo-chips--scroll">
					<li><a class="momo-chip momo-chip--link" href="<?php echo esc_url( $momos_self ); ?>" data-branch=""<?php echo '' === $momos_sel ? ' aria-current="page"' : ''; ?>><?php echo esc_html( momos_s( 'offers.all_filter' ) ); ?></a></li>
					<?php foreach ( $momos_chips as $momos_code => $momos_name ) : ?>
						<li><a class="momo-chip momo-chip--link" href="<?php echo esc_url( add_query_arg( 'branch', $momos_code, $momos_self ) ); ?>" data-branch="<?php echo esc_attr( $momos_code ); ?>"<?php echo $momos_sel === $momos_code ? ' aria-current="page"' : ''; ?>><?php momos_the_icon( 'store', 'momo-icon--sm' ); ?><?php echo esc_html( $momos_name ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			</nav>
			<p class="momo-result-count" role="status" data-momo-result-count data-one="<?php echo esc_attr( momos_s( 'offers.count.one', [ 'n' => '{n}' ] ) ); ?>" data-other="<?php echo esc_attr( momos_s( 'offers.count.other', [ 'n' => '{n}' ] ) ); ?>"></p>
		<?php endif; ?>
		<ul class="momo-grid momo-grid--3 momo-list-reset" data-momo-offer-list>
			<?php foreach ( $momos_ids as $momos_oid ) : ?>
				<?php $momos_s = $momos_scope[ (int) $momos_oid ]; ?>
				<li data-scope="<?php echo esc_attr( $momos_s ); ?>"<?php echo '' !== $momos_sel && 'all' !== $momos_s && ! in_array( $momos_sel, explode( ' ', $momos_s ), true ) ? ' hidden' : ''; ?>><?php momos_component( 'offer-card', [ 'offer_id' => (int) $momos_oid, 'h' => 2 ] ); ?></li>
			<?php endforeach; ?>
		</ul>
	<?php else : ?>
		<div class="momo-empty">
			<span class="momo-disc"><?php momos_the_icon( 'info' ); ?></span>
			<p class="momo-empty__title"><?php echo esc_html( momos_s( 'offers.empty' ) ); ?></p>
			<div class="momo-cluster">
				<?php momos_component( 'button', [ 'label' => momos_s( 'cta.view_menu' ), 'url' => momos_page_url( 'menu' ), 'variant' => 'secondary' ] ); ?>
				<?php momos_component( 'button', [ 'label' => momos_s( 'cta.see_branches' ), 'url' => momos_page_url( 'branches' ), 'variant' => 'secondary' ] ); ?>
			</div>
		</div>
	<?php endif; ?>
</div>
<?php
get_footer();
