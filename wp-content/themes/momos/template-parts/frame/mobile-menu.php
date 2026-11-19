<?php
/**
 * CMP-MobileMenu (ui-design-system.md §8.5, Doc 06 §3.2): native <dialog> opened with showModal()
 * (focus containment, Esc, inert background); slides in from inline-end; links close it; focus returns
 * to the menu button (AC-CORP-08-02). Order: nav rows · Request a table · language grid · branch phones.
 *
 * @package momos
 */

defined( 'ABSPATH' ) || exit;

$momos_items  = class_exists( \Momos\Presentation\View\Navigation::class ) ? \Momos\Presentation\View\Navigation::mobile() : [];
$momos_phones = class_exists( \Momos\Presentation\View\BranchLinks::class ) ? array_filter( \Momos\Presentation\View\BranchLinks::all(), static fn( $b ) => '' !== $b['tel'] ) : [];
?>
<dialog class="momo-mmenu" id="momo-mmenu" aria-labelledby="momo-mmenu-title" data-momo-mmenu>
	<div class="momo-mmenu__head">
		<?php get_template_part( 'template-parts/frame/logo' ); ?>
		<h2 id="momo-mmenu-title" class="momo-visually-hidden"><?php echo esc_html( momos_s( 'a11y.menu.title' ) ); ?></h2>
		<button type="button" class="momo-btn momo-btn--icon" data-momo-mmenu-close aria-label="<?php echo esc_attr( momos_s( 'a11y.close' ) ); ?>"><?php momos_the_icon( 'x' ); ?></button>
	</div>
	<div class="momo-mmenu__body">
		<nav class="momo-mmenu__nav" aria-labelledby="momo-mmenu-title">
			<ul>
				<?php foreach ( $momos_items as $momos_item ) : ?>
					<li>
						<?php if ( ! empty( $momos_item['children'] ) ) : ?>
							<details class="momo-disclosure">
								<summary class="momo-mmenu__row"<?php echo $momos_item['current'] ? ' aria-current="page"' : ''; ?>><span><?php echo esc_html( $momos_item['label'] ); ?></span><?php momos_the_icon( 'chevron-down', 'momo-icon--chev' ); ?></summary>
								<ul class="momo-mmenu__sub">
									<?php foreach ( $momos_item['children'] as $momos_child ) : ?>
										<li><a href="<?php echo esc_url( $momos_child['url'] ); ?>"><?php echo esc_html( $momos_child['label'] ); ?>
										<?php
										if ( '' !== $momos_child['sub'] ) :
											?>
											<small class="momo-muted"><?php echo esc_html( $momos_child['sub'] ); ?></small><?php endif; ?></a></li>
									<?php endforeach; ?>
									<li><a href="<?php echo esc_url( $momos_item['url'] ); ?>"><?php echo esc_html( momos_s( 'nav.all_branches' ) ); ?></a></li>
								</ul>
							</details>
						<?php else : ?>
							<a class="momo-mmenu__row" href="<?php echo esc_url( $momos_item['url'] ); ?>"<?php echo $momos_item['current'] ? ' aria-current="page"' : ''; ?>><span><?php echo esc_html( $momos_item['label'] ); ?></span><?php momos_the_icon( 'chevron-end' ); ?></a>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</nav>
		<a class="momo-btn momo-btn--primary momo-btn--lg momo-btn--block" href="<?php echo esc_url( momos_page_url( 'reservation' ) ); ?>" data-momo-event="click_reservation" data-momo-cta="header"><?php momos_the_icon( 'calendar' ); ?><?php echo esc_html( momos_s( 'cta.request_table' ) ); ?></a>
		<div>
			<p class="momo-mmenu__label" id="momo-mmenu-lang"><?php echo esc_html( momos_s( 'lang.switcher.label' ) ); ?></p>
			<nav aria-labelledby="momo-mmenu-lang"><?php get_template_part( 'template-parts/frame/lang-switcher', null, [ 'variant' => 'grid' ] ); ?></nav>
		</div>
		<?php if ( $momos_phones ) : ?>
			<ul class="momo-mmenu__phones">
				<?php foreach ( $momos_phones as $momos_b ) : ?>
					<li><a href="<?php echo esc_attr( $momos_b['tel'] ); ?>" data-momo-event="click_phone" data-momo-cta="header" data-momo-branch="<?php echo esc_attr( $momos_b['code'] ); ?>"><?php momos_the_icon( 'phone' ); ?><span><?php echo esc_html( momos_s( 'cta.call' ) . ' ' . $momos_b['name'] ); ?></span> <?php echo momos_ltr( $momos_b['phone'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></a></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>
</dialog>
