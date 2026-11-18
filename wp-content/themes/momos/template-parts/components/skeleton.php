<?php
/**
 * CMP-Skeleton (§7.7): tint blocks sized like the final content (no CLS), no shimmer; the container
 * announces `aria-busy`.
 *
 * @package momos
 * @var array{shape?:string,count?:int,label?:string} $args shape: text | circle | block | dish
 */

defined( 'ABSPATH' ) || exit;

$momos_shape = (string) ( $args['shape'] ?? 'text' );
$momos_n     = max( 1, min( 12, (int) ( $args['count'] ?? 1 ) ) );
?>
<div aria-busy="true" class="momo-stack">
	<span class="momo-visually-hidden"><?php echo esc_html( (string) ( $args['label'] ?? momos_s( 'a11y.loading' ) ) ); ?></span>
	<?php for ( $i = 0; $i < $momos_n; $i++ ) : ?>
		<?php if ( 'dish' === $momos_shape ) : ?>
			<div class="momo-dish-card" aria-hidden="true"><span class="momo-skel momo-skel--circle"></span><span class="momo-skel momo-skel--text"></span><span class="momo-skel momo-skel--text"></span></div>
		<?php else : ?>
			<span class="momo-skel momo-skel--<?php echo esc_attr( $momos_shape ); ?>" aria-hidden="true"></span>
		<?php endif; ?>
	<?php endfor; ?>
</div>
