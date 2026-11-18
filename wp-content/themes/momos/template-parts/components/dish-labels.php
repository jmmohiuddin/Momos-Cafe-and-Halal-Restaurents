<?php
/**
 * Dish label row: CMP-DietaryIcon (icon + text), spice (3 chilli icons + text), CMP-AvailabilityLabel,
 * CMP-AdvanceOrderLabel. Labels come from MenuQueryService::card(); never a halal badge (UI-CHG-010).
 *
 * @package momos
 * @var array{card:array<string,mixed>} $args
 */

defined( 'ABSPATH' ) || exit;

$momos_c     = (array) ( $args['card'] ?? [] );
$momos_icons = [ 'vegetarian' => 'leaf', 'vegan' => 'sprout' ];
$momos_out   = '';
foreach ( (array) ( $momos_c['tags'] ?? [] ) as $momos_t ) {
	$momos_out .= '<span class="momo-diet">' . momos_icon( $momos_icons[ $momos_t['code'] ] ?? 'check', 'momo-icon--sm' ) . esc_html( (string) $momos_t['label'] ) . '</span>';
}
$momos_sp = (int) ( $momos_c['spice'] ?? 0 );
if ( $momos_sp > 0 && ! empty( $momos_c['spice_label'] ) ) {
	$momos_ch = '';
	for ( $i = 1; $i <= 3; $i++ ) {
		$momos_ch .= momos_icon( $i <= $momos_sp ? 'chili-filled' : 'chili-outline' );
	}
	$momos_out .= '<span class="momo-spice"><span class="momo-spice__chilis">' . $momos_ch . '</span>' . esc_html( (string) $momos_c['spice_label'] ) . '</span>';
}
$momos_av = $momos_c['availability'] ?? null;
if ( is_array( $momos_av ) && 'available' !== $momos_av['code'] ) {
	$momos_out .= '<span class="momo-avail momo-avail--' . esc_attr( (string) $momos_av['code'] ) . '">' . momos_icon( 'sold_out' === $momos_av['code'] ? 'clock' : 'info', 'momo-icon--sm' ) . esc_html( (string) $momos_av['label'] ) . ( ! empty( $momos_av['reason_label'] ) ? ' · ' . esc_html( (string) $momos_av['reason_label'] ) : '' ) . '</span>';
}
if ( ! empty( $momos_c['advance_order_label'] ) ) {
	$momos_out .= '<span class="momo-advance">' . momos_icon( 'clock', 'momo-icon--sm' ) . esc_html( (string) $momos_c['advance_order_label'] ) . '</span>';
}
if ( '' !== $momos_out ) {
	echo '<div class="momo-dish-card__labels">' . $momos_out . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
}
