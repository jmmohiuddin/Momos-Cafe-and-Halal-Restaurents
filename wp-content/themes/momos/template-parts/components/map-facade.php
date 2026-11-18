<?php
/**
 * CMP-MapFacade (§7.4): static map image (16:9, lazy) or a neutral CSS placeholder + pin; buttons
 * "Open in Google Maps" and "Directions" (new tab). Google Maps JS is never loaded (Doc 11 §4.5).
 *
 * @package momos
 * @var array{name:string,image_id?:int,maps_url?:string,directions_url?:string,branch_code?:string,cta_location?:string} $args
 */

defined( 'ABSPATH' ) || exit;

$momos_loc  = (string) ( $args['cta_location'] ?? 'branch_page' );
$momos_code = (string) ( $args['branch_code'] ?? '' );
$momos_img  = (int) ( $args['image_id'] ?? 0 );
if ( empty( $args['maps_url'] ) && empty( $args['directions_url'] ) ) {
	return;
}
?>
<div class="momo-map">
	<?php if ( $momos_img ) : ?>
		<div class="momo-map__img"><?php echo wp_get_attachment_image( $momos_img, 'momo-card', false, [ 'loading' => 'lazy', 'decoding' => 'async', 'alt' => momos_s( 'branch.map_alt', [ 'branch' => (string) $args['name'] ] ) ] ); ?></div>
	<?php else : ?>
		<div class="momo-map__img momo-map__img--placeholder" role="img" aria-label="<?php echo esc_attr( momos_s( 'branch.map_alt', [ 'branch' => (string) $args['name'] ] ) ); ?>"><span class="momo-map__pin"><?php momos_the_icon( 'map-pin' ); ?></span></div>
	<?php endif; ?>
	<div class="momo-cluster">
		<?php
		if ( ! empty( $args['maps_url'] ) ) {
			momos_component( 'button', [ 'label' => momos_s( 'cta.open_maps' ), 'url' => (string) $args['maps_url'], 'variant' => 'secondary', 'icon' => 'map-pin', 'external' => true, 'attrs' => [ 'data-momo-event' => 'click_directions', 'data-momo-cta' => $momos_loc, 'data-momo-branch' => $momos_code ] ] );
		}
		if ( ! empty( $args['directions_url'] ) ) {
			momos_component( 'button', [ 'label' => momos_s( 'cta.directions' ), 'url' => (string) $args['directions_url'], 'variant' => 'secondary', 'icon' => 'directions', 'external' => true, 'attrs' => [ 'data-momo-event' => 'click_directions', 'data-momo-cta' => $momos_loc, 'data-momo-branch' => $momos_code ] ] );
		}
		?>
	</div>
</div>
