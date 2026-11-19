<?php
/**
 * CMP-CategoryTile (§7.3): circular image with white ring + label below; whole tile one link; image
 * `alt=""`. `chip` variant = small link chips (home hero / category tools row). No dish counts.
 *
 * @package momos
 * @var array{items:list<array{name:string,url:string,image_id:int,current?:bool,intro?:string}>,variant?:string,label:string,h?:int,priority?:int} $args
 */

defined( 'ABSPATH' ) || exit;

$momos_items = (array) ( $args['items'] ?? [] );
if ( ! $momos_items ) {
	return;
}
$momos_chip = 'chip' === ( $args['variant'] ?? 'tile' );
// `priority` = number of leading tiles above the fold (menu hub LCP): eager + fetchpriority=high, never lazy (NFR-PERF-01).
$momos_prio = max( 0, (int) ( $args['priority'] ?? 0 ) );
$momos_n    = 0;
?>
<nav class="<?php echo $momos_chip ? 'momo-catchips' : 'momo-tiles-nav'; ?>" aria-label="<?php echo esc_attr( (string) $args['label'] ); ?>">
	<ul class="<?php echo $momos_chip ? 'momo-chips momo-chips--scroll' : 'momo-tiles'; ?>">
		<?php foreach ( $momos_items as $momos_c ) : ?>
			<li>
				<a class="<?php echo $momos_chip ? 'momo-chip momo-chip--link momo-catchip' : 'momo-tile'; ?>" href="<?php echo esc_url( (string) $momos_c['url'] ); ?>"<?php echo ! empty( $momos_c['current'] ) ? ' aria-current="page"' : ''; ?>>
					<span class="momo-tile__img" aria-hidden="true">
						<?php
						if ( ! empty( $momos_c['image_id'] ) ) {
							$momos_lead = $momos_n++ < $momos_prio;
							echo wp_get_attachment_image( (int) $momos_c['image_id'], 'momo-thumb', false, [ 'alt' => '', 'loading' => $momos_lead ? 'eager' : 'lazy', 'fetchpriority' => $momos_lead ? 'high' : 'auto', 'decoding' => 'async', 'sizes' => $momos_chip ? '32px' : '120px' ] );
						} else {
							echo esc_html( mb_substr( (string) $momos_c['name'], 0, 1 ) );
						}
						?>
					</span>
					<span class="momo-tile__label"><?php echo esc_html( (string) $momos_c['name'] ); ?></span>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>
</nav>
