<?php
/**
 * CMP-BranchCard (§7.3). Name + concept tag · area · open status · address + "Copy Japanese address"
 * (FR-BR-001(b), AC-BR-01-04; same component as the detail address block) · phone (LTR) · actions
 * Request (only while accepting) / Directions / Details. Temporarily closed: warning chip, no request.
 *
 * @package momos
 * Directory cards (`notices` = true) also show a notice chip with the first active branch notice.
 *
 * @var array{branch?:array<string,mixed>,branch_id?:int,variant?:string,h?:int,cta_location?:string,notices?:bool} $args
 */

defined( 'ABSPATH' ) || exit;

$momos_b = $args['branch'] ?? null;
if ( null === $momos_b && ! empty( $args['branch_id'] ) && class_exists( \Momos\Presentation\View\Cards::class ) ) {
	$momos_b = \Momos\Presentation\View\Cards::branch( (int) $args['branch_id'] );
}
if ( ! is_array( $momos_b ) ) {
	return;
}
$momos_dark = 'on-dark' === ( $args['variant'] ?? '' );
$momos_h    = max( 2, min( 4, (int) ( $args['h'] ?? 3 ) ) );
$momos_loc  = (string) ( $args['cta_location'] ?? 'branch_card' );
$momos_code = (string) $momos_b['code'];
$momos_ja   = 'ja' === momos_theme_lang();
?>
<article class="momo-card momo-branch-card<?php echo $momos_dark ? ' momo-branch-card--on-dark momo-on-dark' : ''; ?>">
	<div class="momo-branch-card__head">
		<h<?php echo (int) $momos_h; ?> class="momo-branch-card__title"><?php echo esc_html( (string) $momos_b['name'] ); ?></h<?php echo (int) $momos_h; ?>>
		<?php if ( ! empty( $momos_b['concept'] ) ) : ?>
			<span class="momo-chip momo-chip--tag momo-concept"><?php echo esc_html( (string) $momos_b['concept'] ); ?></span>
		<?php endif; ?>
	</div>
	<?php if ( ! empty( $momos_b['area'] ) ) : ?>
		<p class="momo-muted momo-small"><?php echo esc_html( (string) $momos_b['area'] ); ?></p>
	<?php endif; ?>
	<?php momos_component( 'open-status', [ 'status' => $momos_b['open'] ?? null, 'branch_id' => (int) $momos_b['id'] ] ); ?>
	<?php
	// Today's effective periods + last order (screens.md §3.4 #3, TC-BR-002, FR-BR-001).
	$momos_td = $momos_b['today'] ?? null;
	if ( is_array( $momos_td ) ) {
		echo '<p class="momo-branch-card__today momo-small">' . momos_icon( 'clock', 'momo-icon--sm' ) . '<span>'; // phpcs:ignore WordPress.Security.EscapeOutput
		if ( ! empty( $momos_td['closed'] ) ) {
			echo wp_kses( (string) $momos_td['closed_html'], [] );
		} else {
			echo '<span class="momo-visually-hidden">' . esc_html( momos_s( 'branch.today' ) ) . ' </span>' . wp_kses( (string) $momos_td['hours_html'], [ 'bdi' => [ 'dir' => true ] ] );
			if ( '' !== (string) $momos_td['lo_html'] ) {
				echo ' <span class="momo-muted">(' . wp_kses( strtr( esc_html( momos_s( 'branch.lo_short' ) ), [ '{time}' => (string) $momos_td['lo_html'] ] ), [ 'bdi' => [ 'dir' => true ] ] ) . ')</span>';
			}
		}
		echo '</span></p>';
	}
	if ( ! empty( $args['notices'] ) && class_exists( \Momos\Branch\Query\NoticeQuery::class ) ) {
		try {
			$momos_n = \Momos\Branch\Query\NoticeQuery::for_branch( (int) $momos_b['id'], momos_theme_lang() );
		} catch ( \Throwable $e ) {
			$momos_n = [];
		}
		if ( $momos_n ) {
			$momos_n0 = $momos_n[0];
			echo '<p class="momo-branch-card__notice momo-branch-card__notice--' . esc_attr( 'warning' === $momos_n0['severity'] ? 'warning' : 'info' ) . '">' . momos_icon( 'warning' === $momos_n0['severity'] ? 'alert-triangle' : 'info', 'momo-icon--sm' ) . '<span><span class="momo-visually-hidden">' . esc_html( momos_s( 'branch.notice.label' ) ) . ': </span>' . esc_html( (string) $momos_n0['body'] ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}
	?>
	<div class="momo-branch-card__meta">
		<?php if ( ! empty( $momos_b['address'] ) || ! empty( $momos_b['address_ja'] ) ) : ?>
			<div><?php momos_the_icon( 'map-pin', 'momo-icon--sm' ); ?><span>
				<?php if ( ! $momos_ja && ! empty( $momos_b['address'] ) ) : ?>
					<span dir="auto"><?php echo esc_html( (string) $momos_b['address'] ); ?></span><br>
				<?php endif; ?>
				<?php echo class_exists( \Momos\Presentation\Helpers\Bdi::class ) ? \Momos\Presentation\Helpers\Bdi::ja( (string) $momos_b['address_ja'] ) : esc_html( (string) $momos_b['address_ja'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php momos_component( 'copy-address', [ 'text' => (string) ( $momos_b['address_copy'] ?? $momos_b['address_ja'] ), 'label' => momos_s( 'branch.copy_address' ) . ' — ' . (string) $momos_b['name'] ] ); ?>
			</span></div>
		<?php endif; ?>
		<?php if ( ! empty( $momos_b['tel'] ) ) : ?>
			<div><?php momos_the_icon( 'phone', 'momo-icon--sm' ); ?><a href="<?php echo esc_attr( (string) $momos_b['tel'] ); ?>" data-momo-event="click_phone" data-momo-cta="<?php echo esc_attr( $momos_loc ); ?>" data-momo-branch="<?php echo esc_attr( $momos_code ); ?>"><span class="momo-visually-hidden"><?php echo esc_html( momos_s( 'cta.call' ) . ' ' . $momos_b['name'] . ' ' ); ?></span><?php echo momos_ltr( (string) $momos_b['phone'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></a></div>
		<?php endif; ?>
	</div>
	<div class="momo-branch-card__actions">
		<?php
		if ( ! empty( $momos_b['request_url'] ) ) {
			momos_component( 'button', [ 'label' => momos_s( 'cta.request_table' ), 'url' => (string) $momos_b['request_url'], 'variant' => 'primary', 'icon' => 'calendar', 'attrs' => [ 'data-momo-event' => 'click_reservation', 'data-momo-cta' => $momos_loc, 'data-momo-branch' => $momos_code ] ] );
		} elseif ( empty( $momos_b['temp_closed'] ) && ! empty( $momos_b['tel'] ) ) {
			// Open but not accepting online requests: "Call to reserve" (screens.md §3.4).
			momos_component( 'button', [ 'label' => momos_s( 'cta.call_to_reserve' ), 'aria_label' => momos_s( 'cta.call_to_reserve' ) . ' — ' . (string) $momos_b['name'], 'url' => (string) $momos_b['tel'], 'variant' => 'primary', 'icon' => 'phone', 'attrs' => [ 'data-momo-event' => 'click_phone', 'data-momo-cta' => $momos_loc, 'data-momo-branch' => $momos_code ] ] );
		}
		if ( ! empty( $momos_b['directions'] ) ) {
			momos_component( 'button', [ 'label' => momos_s( 'cta.directions' ), 'aria_label' => momos_s( 'cta.directions_to', [ 'branch' => (string) $momos_b['name'] ] ) . ' ' . momos_s( 'a11y.new_tab' ), 'url' => (string) $momos_b['directions'], 'variant' => $momos_dark ? 'on-dark' : 'secondary', 'icon' => 'directions', 'external' => true, 'attrs' => [ 'data-momo-event' => 'click_directions', 'data-momo-cta' => $momos_loc, 'data-momo-branch' => $momos_code ] ] );
		}
		momos_component( 'button', [ 'label' => momos_s( 'cta.details' ), 'aria_label' => momos_s( 'cta.details_of', [ 'branch' => (string) $momos_b['name'] ] ), 'url' => (string) $momos_b['url'], 'variant' => 'text' ] );
		?>
	</div>
</article>
