<?php
/**
 * CMP-Card (§7.3). Variants: default · tint · brand · pillar (icon disc + title + text) · link (whole card
 * one link with stretched hit area + visible text link, UI-CHG-041). No nested cards.
 *
 * @package momos
 * @var array{title:string,text?:string,url?:string,more?:string,icon?:string,variant?:string,h?:int,attrs?:array<string,string>,link_attrs?:array<string,string>} $args
 */

defined( 'ABSPATH' ) || exit;

$momos_v   = (string) ( $args['variant'] ?? 'default' );
$momos_h   = max( 2, min( 4, (int) ( $args['h'] ?? 3 ) ) );
$momos_url = (string) ( $args['url'] ?? '' );
$momos_cls = 'momo-card' . ( 'default' !== $momos_v ? ' momo-card--' . sanitize_html_class( $momos_v ) : '' ) . ( '' !== $momos_url ? ' momo-card--interactive' : '' );
?>
<div class="<?php echo esc_attr( $momos_cls ); ?>"<?php echo momos_attrs( (array) ( $args['attrs'] ?? [] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
	<?php if ( ! empty( $args['icon'] ) ) : ?>
		<span class="momo-disc"><?php momos_the_icon( (string) $args['icon'] ); ?></span>
	<?php endif; ?>
	<h<?php echo (int) $momos_h; ?> class="momo-card__title">
		<?php if ( '' !== $momos_url ) : ?>
			<a class="momo-card__link" href="<?php echo esc_url( $momos_url ); ?>"<?php echo momos_attrs( (array) ( $args['link_attrs'] ?? [] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>><?php echo esc_html( $args['title'] ); ?></a>
		<?php else : ?>
			<?php echo esc_html( $args['title'] ); ?>
		<?php endif; ?>
	</h<?php echo (int) $momos_h; ?>>
	<?php if ( ! empty( $args['text'] ) ) : ?>
		<p class="momo-muted momo-small"><?php echo esc_html( (string) $args['text'] ); ?></p>
	<?php endif; ?>
	<?php if ( '' !== $momos_url && ! empty( $args['more'] ) ) : ?>
		<span class="momo-card__more" aria-hidden="true"><?php echo esc_html( (string) $args['more'] ); ?> <?php momos_the_icon( 'arrow-end', 'momo-icon--sm' ); ?></span>
	<?php endif; ?>
</div>
