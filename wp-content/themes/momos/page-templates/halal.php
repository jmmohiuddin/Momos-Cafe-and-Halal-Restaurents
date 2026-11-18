<?php
/**
 * SCR-C03 Halal information (screens.md §3.3, content-strings.md §8.3, FR-CORP-003, AC-CORP-03-01…04,
 * DEC-020/021). Banner · brand statement (approved template, else `brand.neutral`) · per-branch register
 * (one card per public branch — 8 data columns do not fit a table, spec rule "else cards") · what the
 * statements mean · ask our staff (branch links + Request) · FAQ excerpt (Halal & dietary). All claim text
 * comes from Dietary\ClaimResolver (approved G2 templates + register); nothing is typed here. Register
 * failure → brand text + ask-staff box only (never stale claims).
 *
 * @package momos
 */

defined( 'ABSPATH' ) || exit;

get_header();
$momos_lang = momos_theme_lang();
$momos_rows = null;
if ( class_exists( \Momos\Dietary\Service\ClaimResolver::class ) ) {
	try {
		$momos_rows = array_values( array_filter( \Momos\Dietary\Service\ClaimResolver::halal_page_rows( $momos_lang ), static fn( $r ) => '' !== (string) $r['name'] ) );
	} catch ( \Throwable $e ) {
		$momos_rows = null;
	}
}
$momos_faq_id = class_exists( \Momos\Presentation\SystemPages::class ) ? \Momos\Presentation\SystemPages::id( 'faq' ) : 0;
$momos_faq    = $momos_faq_id && \Momos\Kernel\I18n\Translations::post_visible( $momos_faq_id, $momos_lang ) ? array_slice( \Momos\Presentation\View\Content::faq( $momos_faq_id )['halal_dietary'] ?? [], 0, 3 ) : [];
get_template_part( 'template-parts/frame/page-banner', null, [ 'title' => momos_s( 'halal.title' ), 'lead' => momos_s( 'halal.lead' ) ] );
?>
<div class="momo-container momo-section momo-stack momo-halal-page">
	<?php momos_component( 'claim-statement', [ 'context' => 'brand', 'h' => 2 ] ); ?>

	<?php if ( null === $momos_rows ) : ?>
		<?php momos_component( 'alert', [ 'type' => 'info', 'body' => momos_s( 'halal.unavailable' ) ] ); ?>
	<?php elseif ( $momos_rows ) : ?>
		<section class="momo-halal-register" aria-labelledby="momo-halal-register">
			<h2 id="momo-halal-register"><?php echo esc_html( momos_s( 'halal.register.title' ) ); ?></h2>
			<ul class="momo-grid momo-grid--2 momo-list-reset">
				<?php foreach ( $momos_rows as $momos_r ) : ?>
					<?php
					$momos_type = (string) $momos_r['type'];
					$momos_cert = 'certified' === $momos_type;
					$momos_ing  = 'halal_ingredients' === $momos_type;
					$momos_lbl  = $momos_cert || $momos_ing ? (string) $momos_r['label'] : momos_s( 'halal.label.none' );
					$momos_dl   = [];
					if ( $momos_cert || $momos_ing ) {
						$momos_dl[] = [ momos_s( 'halal.certifier' ), $momos_cert && $momos_r['certifier'] ? '<bdi>' . esc_html( (string) $momos_r['certifier'] ) . '</bdi>' : esc_html( momos_s( 'halal.na' ) ) ];
						$momos_dl[] = [ momos_s( 'halal.scope' ), $momos_cert && $momos_r['scope_label'] ? esc_html( (string) $momos_r['scope_label'] ) : esc_html( momos_s( 'halal.na' ) ) ];
						$momos_dl[] = [ momos_s( 'halal.cert_no' ), $momos_cert && $momos_r['number'] ? '<bdi dir="ltr">' . esc_html( (string) $momos_r['number'] ) . '</bdi>' : esc_html( momos_s( 'halal.na' ) ) ];
						$momos_dl[] = [ momos_s( 'halal.valid_until' ), $momos_cert && $momos_r['valid_until_display'] ? '<bdi>' . esc_html( (string) $momos_r['valid_until_display'] ) . '</bdi>' : esc_html( momos_s( 'halal.na' ) ) ];
					}
					?>
					<li>
						<article class="momo-card momo-halal-row momo-halal-row--<?php echo esc_attr( $momos_cert ? 'certified' : ( $momos_ing ? 'ingredients' : 'none' ) ); ?>">
							<div class="momo-halal-row__head">
								<h3 class="momo-card__title"><a href="<?php echo esc_url( (string) $momos_r['url'] ); ?>"><?php echo esc_html( (string) $momos_r['name'] ); ?></a></h3>
								<span class="momo-chip momo-chip--tag<?php echo $momos_cert || $momos_ing ? ' momo-concept' : ''; ?>"><?php echo esc_html( $momos_lbl ); ?></span>
							</div>
							<?php if ( '' !== (string) $momos_r['statement'] ) : ?>
								<p><?php echo esc_html( (string) $momos_r['statement'] ); ?></p>
							<?php endif; ?>
							<?php if ( $momos_dl ) : ?>
								<dl class="momo-deflist">
									<?php foreach ( $momos_dl as [ $momos_dt, $momos_dd ] ) : ?>
										<div><dt><?php echo esc_html( $momos_dt ); ?></dt><dd><?php echo wp_kses( $momos_dd, [ 'bdi' => [ 'dir' => true ] ] ); ?></dd></div>
									<?php endforeach; ?>
								</dl>
							<?php endif; ?>
							<?php if ( $momos_cert && ! empty( $momos_r['mark_url'] ) ) : ?>
								<img class="momo-halal__mark" src="<?php echo esc_url( (string) $momos_r['mark_url'] ); ?>" alt="<?php echo esc_attr( (string) $momos_r['mark_alt'] ); ?>" width="72" height="72" loading="lazy">
							<?php endif; ?>
							<?php if ( ! empty( $momos_r['alcohol_lines'] ) ) : ?>
								<div class="momo-halal-row__alcohol">
									<p class="momo-small momo-strong"><?php echo esc_html( momos_s( 'halal.col.alcohol' ) ); ?></p>
									<?php foreach ( (array) $momos_r['alcohol_lines'] as $momos_line ) : ?>
										<p class="momo-small"><?php momos_the_icon( 'wine-off', 'momo-icon--sm' ); ?> <?php echo esc_html( (string) $momos_line ); ?></p>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>
							<?php if ( $momos_cert && ! empty( $momos_r['register_url'] ) ) : ?>
								<?php momos_component( 'link', [ 'label' => momos_s( 'halal.verify_link' ), 'url' => (string) $momos_r['register_url'], 'external' => true ] ); ?>
							<?php endif; ?>
							<?php if ( ! empty( $momos_r['last_verified'] ) ) : ?>
								<p class="momo-small momo-muted"><?php echo esc_html( (string) $momos_r['last_verified'] ); ?></p>
							<?php endif; ?>
						</article>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
	<?php endif; ?>

	<section aria-labelledby="momo-halal-explainer">
		<h2 id="momo-halal-explainer"><?php echo esc_html( momos_s( 'halal.explainer.title' ) ); ?></h2>
		<ul class="momo-grid momo-grid--3 momo-list-reset momo-mt-4">
			<?php foreach ( [ [ 'halal.label.certified', 'halal.explainer.certified', 'shield-check' ], [ 'halal.label.ingredients', 'halal.explainer.ingredients', 'leaf' ], [ 'halal.label.none', 'halal.explainer.none', 'help-circle' ] ] as [ $momos_t, $momos_x, $momos_i ] ) : ?>
				<li><?php momos_component( 'card', [ 'title' => momos_s( $momos_t ), 'text' => momos_s( $momos_x ), 'icon' => $momos_i, 'variant' => 'pillar', 'h' => 3 ] ); ?></li>
			<?php endforeach; ?>
		</ul>
	</section>

	<section class="momo-card momo-card--brand momo-ask-staff" aria-labelledby="momo-ask-staff">
		<h2 id="momo-ask-staff"><?php echo esc_html( momos_s( 'halal.ask_staff.title' ) ); ?></h2>
		<p><?php echo esc_html( momos_s( 'halal.ask_staff.body' ) ); ?></p>
		<?php $momos_links = class_exists( \Momos\Presentation\View\BranchLinks::class ) ? \Momos\Presentation\View\BranchLinks::all() : []; ?>
		<?php if ( $momos_links ) : ?>
			<ul class="momo-chips momo-mt-4">
				<?php foreach ( $momos_links as $momos_l ) : ?>
					<li><a class="momo-chip momo-chip--link" href="<?php echo esc_url( (string) $momos_l['url'] ); ?>"><?php momos_the_icon( 'store', 'momo-icon--sm' ); ?><?php echo esc_html( (string) $momos_l['name'] ); ?></a></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<p class="momo-mt-4"><?php momos_component( 'button', [ 'label' => momos_s( 'cta.request_table' ), 'url' => momos_page_url( 'reservation' ), 'variant' => 'primary', 'icon' => 'calendar', 'attrs' => [ 'data-momo-event' => 'click_reservation', 'data-momo-cta' => 'halal_page' ] ] ); ?></p>
	</section>

	<?php if ( $momos_faq ) : ?>
		<section aria-labelledby="momo-halal-faq">
			<?php momos_component( 'section-header', [ 'id' => 'momo-halal-faq', 'title' => momos_s( 'halal.faq.title' ), 'link' => [ 'label' => momos_s( 'halal.faq.more' ), 'url' => (string) \Momos\Presentation\SystemPages::url( 'faq' ) ] ] ); ?>
			<?php momos_component( 'accordion', [ 'items' => $momos_faq, 'h' => 3, 'group' => 'halal_dietary' ] ); ?>
		</section>
	<?php endif; ?>
</div>
<?php
get_footer();
