<?php
/**
 * CMP-AddressBlock (§7.4): pin · localised address (non-ja) · Japanese address `<span lang="ja" dir="ltr">`
 * (always) · copy button (component `copy-address`; toast `branch.copied`, select-and-show fallback without the
 * Clipboard API) · access lines.
 *
 * @package momos
 * @var array{address:array{local:string,ja:string,postal:string},access?:array{lines:list<string>,notes:string}} $args
 */

defined( 'ABSPATH' ) || exit;

$momos_a  = (array) ( $args['address'] ?? [] );
$momos_ja = trim( (string) ( $momos_a['postal'] ?? '' ) . ' ' . (string) ( $momos_a['ja'] ?? '' ) );
$momos_ac = (array) ( $args['access'] ?? [] );
if ( '' === trim( (string) ( $momos_a['ja'] ?? '' ) ) && '' === trim( (string) ( $momos_a['local'] ?? '' ) ) && empty( $momos_ac['lines'] ) ) {
	return;
}
?>
<div class="momo-address">
	<?php if ( '' !== trim( (string) ( $momos_a['ja'] ?? '' ) ) || '' !== (string) ( $momos_a['local'] ?? '' ) ) : ?>
		<div class="momo-address__row">
			<?php momos_the_icon( 'map-pin' ); ?>
			<div>
				<?php if ( '' !== (string) ( $momos_a['local'] ?? '' ) ) : ?>
					<p dir="auto"><?php echo esc_html( (string) $momos_a['local'] ); ?></p>
				<?php endif; ?>
				<?php if ( '' !== $momos_ja ) : ?>
					<p class="<?php echo '' !== (string) ( $momos_a['local'] ?? '' ) ? 'momo-muted momo-small' : ''; ?>"><?php echo \Momos\Presentation\Helpers\Bdi::ja( $momos_ja ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
					<?php momos_component( 'copy-address', [ 'text' => $momos_ja ] ); ?>
				<?php endif; ?>
			</div>
		</div>
	<?php endif; ?>
	<?php if ( ! empty( $momos_ac['lines'] ) ) : ?>
		<div class="momo-address__row">
			<?php momos_the_icon( 'directions' ); ?>
			<ul class="momo-list-reset">
				<?php foreach ( (array) $momos_ac['lines'] as $momos_line ) : ?>
					<li><?php echo wp_kses( (string) $momos_line, [ 'bdi' => [ 'dir' => true ] ] ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>
	<?php if ( ! empty( $momos_ac['notes'] ) ) : ?>
		<p class="momo-small momo-muted"><?php echo esc_html( (string) $momos_ac['notes'] ); ?></p>
	<?php endif; ?>
</div>
