<?php
/**
 * CMP-EmptyState / error state (§7.7): centred bold line + muted text + one action. Sections that
 * Doc 07 says to omit are omitted by the caller, not emptied.
 *
 * @package momos
 * @var array{title:string,body?:string,action?:array{label:string,url?:string,retry?:bool},variant?:string,icon?:string} $args variant: empty | error
 */

defined( 'ABSPATH' ) || exit;

$momos_err = 'error' === ( $args['variant'] ?? 'empty' );
?>
<div class="momo-empty"<?php echo $momos_err ? ' role="status"' : ''; ?>>
	<span class="momo-disc"><?php momos_the_icon( (string) ( $args['icon'] ?? ( $momos_err ? 'alert-circle' : 'info' ) ) ); ?></span>
	<p class="momo-empty__title"><?php echo esc_html( (string) $args['title'] ); ?></p>
	<?php if ( ! empty( $args['body'] ) ) : ?>
		<p><?php echo esc_html( (string) $args['body'] ); ?></p>
	<?php endif; ?>
	<?php
	if ( ! empty( $args['action'] ) ) {
		$momos_a = (array) $args['action'];
		momos_component( 'button', [ 'label' => (string) $momos_a['label'], 'url' => $momos_a['url'] ?? null, 'variant' => 'secondary', 'attrs' => ! empty( $momos_a['retry'] ) ? [ 'data-momo-reload' => '1' ] : [] ] );
	}
	?>
</div>
