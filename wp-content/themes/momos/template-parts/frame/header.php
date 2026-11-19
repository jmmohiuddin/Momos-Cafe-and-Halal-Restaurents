<?php
/**
 * CMP-Header (ui-design-system.md §8.3, FR-CORP-008, DEC-060). DOM order = reading order; RTL mirrors
 * through logical CSS only. Desktop: logo · nav pill · switcher · CTA. Tablet: logo · switcher · CTA ·
 * menu. Mobile: logo · switcher · menu. The menu button is a link to the footer navigation without JS.
 *
 * @package momos
 */

defined( 'ABSPATH' ) || exit;

$momos_items = class_exists( \Momos\Presentation\View\Navigation::class ) ? \Momos\Presentation\View\Navigation::primary() : [];
?>
<header class="momo-header" data-momo-header>
	<div class="momo-container momo-header__inner">
		<?php get_template_part( 'template-parts/frame/logo' ); ?>
		<?php if ( $momos_items ) : ?>
			<nav class="momo-nav" aria-label="<?php echo esc_attr( momos_s( 'a11y.nav.main' ) ); ?>">
				<ul class="momo-navpill">
					<?php foreach ( $momos_items as $momos_item ) : ?>
						<li>
							<?php if ( ! empty( $momos_item['children'] ) ) : ?>
								<details class="momo-disclosure" data-momo-disclosure>
									<summary class="momo-navpill__link"<?php echo $momos_item['current'] ? ' aria-current="page"' : ''; ?>>
										<?php echo esc_html( $momos_item['label'] ); ?>
										<?php momos_the_icon( 'chevron-down', 'momo-icon--sm momo-icon--chev' ); ?>
									</summary>
									<ul class="momo-dropdown">
										<?php foreach ( $momos_item['children'] as $momos_child ) : ?>
											<li><a href="<?php echo esc_url( $momos_child['url'] ); ?>"><?php echo esc_html( $momos_child['label'] ); ?>
											<?php
											if ( '' !== $momos_child['sub'] ) :
												?>
												<small><?php echo esc_html( $momos_child['sub'] ); ?></small><?php endif; ?></a></li>
										<?php endforeach; ?>
										<li class="momo-dropdown__sep" role="presentation"></li>
										<li><a href="<?php echo esc_url( $momos_item['url'] ); ?>"><?php echo esc_html( momos_s( 'nav.all_branches' ) ); ?></a></li>
									</ul>
								</details>
							<?php else : ?>
								<a class="momo-navpill__link" href="<?php echo esc_url( $momos_item['url'] ); ?>"<?php echo $momos_item['current'] ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $momos_item['label'] ); ?></a>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</nav>
		<?php endif; ?>
		<div class="momo-header__actions">
			<?php get_template_part( 'template-parts/frame/lang-switcher' ); ?>
			<a class="momo-btn momo-btn--primary momo-header__cta" href="<?php echo esc_url( momos_page_url( 'reservation' ) ); ?>" data-momo-event="click_reservation" data-momo-cta="header"><?php momos_the_icon( 'calendar' ); ?><?php echo esc_html( momos_s( 'cta.request_table' ) ); ?></a>
			<a class="momo-btn momo-btn--icon momo-header__menu-btn" href="#momo-footer-nav" data-momo-menu-open aria-controls="momo-mmenu" aria-label="<?php echo esc_attr( momos_s( 'a11y.menu.open' ) ); ?>"><?php momos_the_icon( 'menu' ); ?></a>
		</div>
	</div>
</header>
