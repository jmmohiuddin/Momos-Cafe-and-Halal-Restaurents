<?php
/**
 * CMP-MobileActionBar (ui-design-system.md §8.6, FR-CORP-008(c)(d), US-CORP-009). ≤ 767 px only; not
 * on C09–C11; hidden by frame.js while a text field has focus or the keyboard is open.
 *
 * @package momos
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( \Momos\Presentation\View\ActionBar::class ) ) {
	return;
}
$momos_items = \Momos\Presentation\View\ActionBar::items( null, \Momos\Presentation\PageContext::branch_id() );
if ( ! $momos_items ) {
	return;
}
?>
<nav class="momo-bar" aria-label="<?php echo esc_attr( momos_s( 'a11y.nav.quick' ) ); ?>" data-momo-bar>
	<?php foreach ( $momos_items as $momos_i ) : ?>
		<a class="momo-bar__item<?php echo $momos_i['primary'] ? ' momo-bar__item--primary' : ''; ?>" href="<?php echo esc_url( $momos_i['url'] ); ?>"
		<?php
		echo momos_attrs( // phpcs:ignore WordPress.Security.EscapeOutput
			[
				'target'           => $momos_i['external'] ? '_blank' : null,
				'rel'              => $momos_i['external'] ? 'noopener' : null,
				'aria-label'       => '' !== $momos_i['aria'] ? $momos_i['aria'] . ( $momos_i['external'] ? ' ' . momos_s( 'a11y.new_tab' ) : '' ) : null,
				'data-momo-event'  => $momos_i['event'],
				'data-momo-cta'    => '' !== $momos_i['event'] ? 'sticky_bar' : null,
				'data-momo-branch' => $momos_i['branch'],
			]
		);
		?>
		><?php momos_the_icon( $momos_i['icon'], 'momo-icon--lg' ); ?><span><?php echo esc_html( $momos_i['label'] ); ?></span><?php echo ( $momos_i['external'] && '' === $momos_i['aria'] ) ? momos_new_tab_hint() : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?></a>
	<?php endforeach; ?>
</nav>
