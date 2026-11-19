<?php
/**
 * Branch phone list for the reservation screens (no-JS / "no accepting branch" states, reservation.md
 * §6.1 "Empty", §6.3 "No JavaScript"). Phones in an LTR isolate, `tel:` in E.164.
 *
 * @package momos
 * @var array{branches:list<array<string,mixed>>,title?:string,cta?:string} $args
 */

defined( 'ABSPATH' ) || exit;

$momos_list = array_values( array_filter( (array) ( $args['branches'] ?? [] ), static fn( $b ) => '' !== (string) ( $b['tel'] ?? '' ) ) );
if ( ! $momos_list ) {
	return;
}
?>
<section class="momo-card momo-res-phones" aria-label="<?php echo esc_attr( (string) ( $args['title'] ?? momos_s( 'res.placeholder.phones' ) ) ); ?>">
	<?php if ( ! empty( $args['title'] ) ) : ?>
		<p class="momo-strong"><?php echo esc_html( (string) $args['title'] ); ?></p>
	<?php endif; ?>
	<ul class="momo-list-reset momo-stack">
		<?php foreach ( $momos_list as $momos_b ) : ?>
			<li class="momo-res-phone">
				<span class="momo-strong"><?php echo esc_html( (string) $momos_b['name'] ); ?></span>
				<a class="momo-btn momo-btn--secondary" href="<?php echo esc_attr( (string) $momos_b['tel'] ); ?>"<?php echo '' !== (string) ( $args['cta'] ?? '' ) && empty( $momos_b['is_test'] ) ? ' data-momo-event="click_phone" data-momo-cta="' . esc_attr( (string) $args['cta'] ) . '" data-momo-branch="' . esc_attr( (string) $momos_b['code'] ) . '"' : ''; ?>><?php momos_the_icon( 'phone' ); ?><span class="momo-visually-hidden"><?php echo esc_html( momos_s( 'cta.call' ) . ' ' . $momos_b['name'] ); ?> </span><?php echo momos_ltr( (string) $momos_b['phone'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></a>
			</li>
		<?php endforeach; ?>
	</ul>
</section>
