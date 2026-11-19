<?php
/**
 * CMP-PriceBlock (§7.4). Compact: isolated amount + tax label; optional branch context line; SAMPLE
 * marker for design comps (sample prices are never public). Amount formatting comes from momos-core.
 *
 * @package momos
 * @var array{yen?:int,html?:string,context?:string,sample?:bool} $args
 */

defined( 'ABSPATH' ) || exit;

$momos_html = (string) ( $args['html'] ?? '' );
if ( '' === $momos_html && isset( $args['yen'] ) && class_exists( \Momos\Presentation\Format\PriceFormatter::class ) ) {
	$momos_html = \Momos\Presentation\Format\PriceFormatter::html( (int) $args['yen'] );
}
if ( '' === $momos_html ) {
	return; // FR-BR-003: no price, no label.
}
?>
<span class="momo-price"><?php echo wp_kses( $momos_html, [ 'bdi' => [ 'dir' => true ] ] ); ?>
<?php
if ( ! empty( $args['sample'] ) ) :
	?>
	<span class="momo-sample"><?php echo esc_html( momos_s( 'demo.sample_badge' ) ); ?></span><?php endif; ?>
	<?php
	if ( ! empty( $args['context'] ) ) :
		?>
	<span class="momo-price__ctx"><?php echo esc_html( (string) $args['context'] ); ?></span><?php endif; ?></span>
