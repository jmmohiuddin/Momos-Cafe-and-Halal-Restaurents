<?php
/**
 * SCR-C15–C18 Legal pages (screens.md §3.15, content-strings.md §3.4/§8.6, FR-CORP-006, AC-CORP-06-01…04).
 * Breadcrumb · H1 · `legal.ja_authoritative` (non-ja) · last updated + version (`momo_legal_last_updated`,
 * `momo_legal_version`) · table of contents when > 5 H2 · body at reading width (tables scroll/stack at
 * 360 px). External Transmission (C17) adds `#analytics-settings` with the opt-out toggle
 * (`<button aria-pressed>`, cookie `momo_analytics_optout`, GPC honoured; i18n-seo-analytics.md §12.5).
 *
 * @package momos
 */

defined( 'ABSPATH' ) || exit;

get_header();
$momos_id    = (int) get_queried_object_id();
$momos_meta  = \Momos\Presentation\View\Content::legal_meta( $momos_id );
$momos_body  = \Momos\Presentation\View\Content::with_toc( \Momos\Presentation\View\Content::body( $momos_id ) );
$momos_is_ext = \Momos\Presentation\SystemPages::legal_id( 'ext_trans' ) === $momos_id;
get_template_part( 'template-parts/frame/page-banner', null, [ 'title' => \Momos\Presentation\View\Content::title( $momos_id ) ] );
?>
<div class="momo-container momo-section momo-legal">
	<div class="momo-legal__meta">
		<?php if ( $momos_meta['ja_note'] ) : ?>
			<p class="momo-alert momo-alert--info" role="note"><?php momos_the_icon( 'info' ); ?><span><?php echo esc_html( momos_s( 'legal.ja_authoritative' ) ); ?></span></p>
		<?php endif; ?>
		<?php if ( '' !== $momos_meta['updated'] || '' !== $momos_meta['version'] ) : ?>
			<p class="momo-small momo-muted momo-legal__dates">
				<?php if ( '' !== $momos_meta['updated'] ) : ?>
					<span><?php echo wp_kses( \Momos\Presentation\Helpers\Bdi::t( 'legal.last_updated', [ 'date' => $momos_meta['updated'] ], [] ), [ 'bdi' => [ 'dir' => true ] ] ); ?></span>
				<?php endif; ?>
				<?php if ( '' !== $momos_meta['version'] ) : ?>
					<span><?php echo wp_kses( \Momos\Presentation\Helpers\Bdi::t( 'legal.version', [ 'version' => $momos_meta['version'] ], [ 'version' ] ), [ 'bdi' => [ 'dir' => true ] ] ); ?></span>
				<?php endif; ?>
			</p>
		<?php endif; ?>
	</div>
	<?php if ( $momos_body['toc'] ) : ?>
		<nav class="momo-card momo-card--tint momo-toc" aria-labelledby="momo-toc-h">
			<h2 id="momo-toc-h" class="momo-h3"><?php echo esc_html( momos_s( 'legal.toc' ) ); ?></h2>
			<ol>
				<?php foreach ( $momos_body['toc'] as $momos_t ) : ?>
					<li><a href="#<?php echo esc_attr( $momos_t['id'] ); ?>"><?php echo esc_html( $momos_t['text'] ); ?></a></li>
				<?php endforeach; ?>
			</ol>
		</nav>
	<?php endif; ?>
	<?php if ( '' !== $momos_body['html'] ) : ?>
		<div class="momo-prose momo-legal__body"><?php echo $momos_body['html']; // phpcs:ignore WordPress.Security.EscapeOutput -- the_content filters (kses on save). ?></div>
	<?php else : ?>
		<?php momos_component( 'empty-state', [ 'title' => momos_s( 'legal.pending' ) ] ); ?>
	<?php endif; ?>
	<?php if ( $momos_is_ext ) : ?>
		<section class="momo-card momo-optout" id="analytics-settings" aria-labelledby="analytics-settings-h" data-momo-optout>
			<h2 id="analytics-settings-h" class="momo-h3"><?php echo esc_html( momos_s( 'ftr.analytics' ) ); ?></h2>
			<noscript><p><?php echo esc_html( momos_s( 'anl.optout.nojs' ) ); ?></p></noscript>
			<div class="momo-optout__row" hidden data-momo-optout-ui>
				<span id="momo-optout-label" class="momo-strong"><?php echo esc_html( momos_s( 'anl.optout.label' ) ); ?></span>
				<button type="button" class="momo-switch" aria-pressed="true" aria-labelledby="momo-optout-label momo-optout-state" data-on="<?php echo esc_attr( momos_s( 'anl.optout.on' ) ); ?>" data-off="<?php echo esc_attr( momos_s( 'anl.optout.off' ) ); ?>" data-done-on="<?php echo esc_attr( momos_s( 'anl.optout.on_done' ) ); ?>" data-done-off="<?php echo esc_attr( momos_s( 'anl.optout.off_done' ) ); ?>">
					<span class="momo-switch__track" aria-hidden="true"><span class="momo-switch__thumb"></span></span>
					<span id="momo-optout-state" class="momo-switch__text"><?php echo esc_html( momos_s( 'anl.optout.on' ) ); ?></span>
				</button>
			</div>
			<p class="momo-small" data-momo-optout-gpc hidden><?php echo esc_html( momos_s( 'anl.optout.gpc' ) ); ?></p>
			<p class="momo-small" role="status" data-momo-optout-status></p>
		</section>
	<?php endif; ?>
</div>
<?php
get_footer();
