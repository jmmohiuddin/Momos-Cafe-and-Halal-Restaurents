<?php
/**
 * CMP-FacilityList (§7.4): icon + label rows, confirmed values only (unknown omitted by BranchView).
 *
 * @package momos
 * @var array{items:list<array{label:string,value:string,icon:string}>} $args
 */

defined( 'ABSPATH' ) || exit;

$momos_items = (array) ( $args['items'] ?? [] );
if ( ! $momos_items ) {
	return;
}
?>
<dl class="momo-facilities">
	<?php foreach ( $momos_items as $momos_f ) : ?>
		<div class="momo-facilities__row">
			<dt><?php momos_the_icon( (string) $momos_f['icon'], 'momo-icon--sm' ); ?><?php echo esc_html( (string) $momos_f['label'] ); ?></dt>
			<dd><?php echo esc_html( (string) $momos_f['value'] ); ?></dd>
		</div>
	<?php endforeach; ?>
</dl>
