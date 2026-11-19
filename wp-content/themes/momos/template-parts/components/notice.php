<?php
/**
 * CMP-NoticeBanner (§7.1, FR-BR-009): `role="region"` (not alert), icon + bold title + body + optional
 * link + optional dismiss (site-wide info only). Mobile: title + 1 line, "More" expands. Accent bar on
 * inline-start. `card` variant = branch-page placement.
 *
 * @package momos
 * @var array{severity?:string,title?:string,body:string,link_url?:string,link_label?:string,dismissible?:bool,id?:int,upcoming?:bool,start_ms?:?int,end_ms?:?int,scope?:string,card?:bool} $args
 */

defined( 'ABSPATH' ) || exit;

$momos_body = trim( (string) ( $args['body'] ?? '' ) );
if ( '' === $momos_body ) {
	return; // Untranslated → not rendered.
}
$momos_sev  = 'warning' === ( $args['severity'] ?? 'info' ) ? 'warning' : 'info';
$momos_card = ! empty( $args['card'] );
$momos_id   = 'momo-notice-' . ( ! empty( $args['id'] ) ? (int) $args['id'] : substr( md5( $momos_body ), 0, 8 ) );
// Mobile shows title + 1 body line (§7.1): anything wider than ~1 line at 360 px gets the clamp + "More" server-side
// (East-Asian characters count double), so the collapsed height is in the first paint (no layout shift).
$momos_long = mb_strwidth( $momos_body ) > 44;
$momos_attr = [
	'class'            => 'momo-notice momo-notice--' . $momos_sev . ( $momos_card ? ' momo-notice--card' : '' ) . ( $momos_long ? ' is-collapsible' : '' ),
	'role'             => 'region',
	'aria-label'       => momos_s( 'a11y.notice' ),
	'id'               => $momos_id,
	'hidden'           => ! empty( $args['upcoming'] ),
	'data-momo-notice' => ! empty( $args['dismissible'] ) ? 'dismissible' : 'static',
	'data-start'       => isset( $args['start_ms'] ) ? (string) $args['start_ms'] : null,
	'data-end'         => isset( $args['end_ms'] ) ? (string) $args['end_ms'] : null,
];
?>
<div<?php echo momos_attrs( $momos_attr ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
	<div class="<?php echo $momos_card ? '' : 'momo-container '; ?>momo-notice__inner">
		<?php momos_the_icon( 'warning' === $momos_sev ? 'alert-triangle' : 'info' ); ?>
		<div class="momo-notice__text">
			<?php if ( ! empty( $args['title'] ) ) : ?>
				<p class="momo-notice__title"><?php echo esc_html( (string) $args['title'] ); ?></p>
			<?php endif; ?>
			<p class="momo-notice__body" id="<?php echo esc_attr( $momos_id ); ?>-body"><?php echo esc_html( $momos_body ); ?>
				<?php if ( ! empty( $args['link_url'] ) && ! empty( $args['link_label'] ) ) : ?>
					<a href="<?php echo esc_url( (string) $args['link_url'] ); ?>"><?php echo esc_html( (string) $args['link_label'] ); ?></a>
				<?php endif; ?>
			</p>
			<?php if ( $momos_long ) : ?>
				<button type="button" class="momo-btn momo-btn--text momo-notice__more" aria-expanded="false" aria-controls="<?php echo esc_attr( $momos_id ); ?>-body" data-momo-notice-more data-less="<?php echo esc_attr( momos_s( 'a11y.less' ) ); ?>"><?php echo esc_html( momos_s( 'a11y.more' ) ); ?></button>
			<?php endif; ?>
		</div>
		<?php if ( ! empty( $args['dismissible'] ) ) : ?>
			<button type="button" class="momo-btn momo-btn--icon" data-momo-notice-dismiss aria-label="<?php echo esc_attr( momos_s( 'a11y.close' ) ); ?>"><?php momos_the_icon( 'x' ); ?></button>
		<?php endif; ?>
	</div>
</div>
