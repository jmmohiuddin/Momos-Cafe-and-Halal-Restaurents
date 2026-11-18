<?php
/**
 * CMP-Logo — provisional outlined wordmark (DEC-046/067): "MOMO's" + "CAFE & HALAL RESTAURANT", SVG
 * outlines (no font dependency, never mirrored, never a seal). An uploaded official logo
 * (`momo_logo_primary` / `momo_logo_inverse`) replaces it. Link to the current-language home.
 *
 * @package momos
 * @var array{variant?:string} $args variant: default | on-dark
 */

defined( 'ABSPATH' ) || exit;

$momos_dark   = 'on-dark' === ( $args['variant'] ?? '' );
$momos_logo   = class_exists( \Momos\Presentation\View\Brand::class ) ? \Momos\Presentation\View\Brand::logo_url( $momos_dark ) : '';
$momos_sprite = class_exists( \Momos\Presentation\Assets::class ) ? \Momos\Presentation\Assets::sprite_url() : get_theme_file_uri( 'assets/icons/sprite.svg' );
?>
<a class="momo-logo<?php echo $momos_dark ? ' momo-logo--on-dark' : ''; ?>" href="<?php echo esc_url( momos_theme_url( '/' ) ); ?>" aria-label="<?php echo esc_attr( momos_s( 'a11y.logo_home' ) ); ?>">
	<?php if ( '' !== $momos_logo ) : ?>
		<img src="<?php echo esc_url( $momos_logo ); ?>" alt="" height="40">
	<?php else : ?>
		<svg viewBox="0 0 164.3 50" width="164" height="50" aria-hidden="true" focusable="false"><use class="momo-logo__word" href="<?php echo esc_url( $momos_sprite ); ?>#logo-word"/><use class="momo-logo__tag" href="<?php echo esc_url( $momos_sprite ); ?>#logo-tag"/></svg>
	<?php endif; ?>
</a>
