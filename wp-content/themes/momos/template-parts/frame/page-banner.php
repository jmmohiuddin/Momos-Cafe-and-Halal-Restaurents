<?php
/**
 * Page banner (inner pages, ui-design-system.md §8.1): breadcrumbs · eyebrow? · H1 · lead? on the
 * peach-glow canvas. Exactly one H1 per page.
 *
 * @package momos
 * @var array{title:string,eyebrow?:string,lead?:string,breadcrumbs?:bool|list<array>,center?:bool} $args
 */

defined( 'ABSPATH' ) || exit;

$momos_bc = $args['breadcrumbs'] ?? true;
if ( true === $momos_bc && class_exists( \Momos\Presentation\PageContext::class ) && ! \Momos\Presentation\PageContext::has_breadcrumbs() ) {
	$momos_bc = false; // Not on Home, C09–C11, 404 (ui-design-system.md §8.8).
}
?>
<div class="momo-banner<?php echo ! empty( $args['center'] ) ? ' momo-banner--center' : ''; ?>">
	<div class="momo-container">
		<?php
		if ( false !== $momos_bc ) {
			get_template_part( 'template-parts/frame/breadcrumbs', null, is_array( $momos_bc ) ? [ 'items' => $momos_bc ] : [] );
		}
		?>
		<?php if ( ! empty( $args['eyebrow'] ) ) : ?>
			<span class="momo-eyebrow momo-mt-4"><?php echo esc_html( $args['eyebrow'] ); ?></span>
		<?php endif; ?>
		<h1><?php echo esc_html( (string) ( $args['title'] ?? '' ) ); ?></h1>
		<?php if ( ! empty( $args['lead'] ) ) : ?>
			<p class="momo-lead"><?php echo esc_html( $args['lead'] ); ?></p>
		<?php endif; ?>
	</div>
</div>
