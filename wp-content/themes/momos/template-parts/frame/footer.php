<?php
/**
 * CMP-Footer (ui-design-system.md §8.7, FR-CORP-009). Emerald band; columns Branches · Explore · Legal &
 * privacy · Social & languages; © line only while the legal operator name is set. No keyword lists, no
 * admin link. `#momo-footer-nav` is the no-JS target of the header menu button.
 *
 * @package momos
 */

defined( 'ABSPATH' ) || exit;

$momos_has_core = class_exists( \Momos\Presentation\View\Navigation::class );
$momos_branches = $momos_has_core ? \Momos\Presentation\View\BranchLinks::all() : [];
$momos_explore  = $momos_has_core ? \Momos\Presentation\View\Navigation::explore() : [];
$momos_legal    = $momos_has_core ? \Momos\Presentation\View\Navigation::legal() : [];
$momos_social   = $momos_has_core ? \Momos\Presentation\View\Brand::social_links() : [];
$momos_operator = $momos_has_core ? \Momos\Presentation\View\Brand::operator() : '';
?>
<footer class="momo-footer momo-on-dark">
	<div class="momo-container">
		<div class="momo-footer__top">
			<?php get_template_part( 'template-parts/frame/logo', null, [ 'variant' => 'on-dark' ] ); ?>
			<a class="momo-btn momo-btn--on-dark" href="<?php echo esc_url( momos_page_url( 'reservation' ) ); ?>" data-momo-event="click_reservation" data-momo-cta="footer"><?php momos_the_icon( 'calendar' ); ?><?php echo esc_html( momos_s( 'cta.request_table' ) ); ?></a>
		</div>
		<div class="momo-footer__cols">
			<?php if ( $momos_branches ) : ?>
				<nav aria-labelledby="momo-ftr-branches">
					<h2 id="momo-ftr-branches"><?php echo esc_html( momos_s( 'nav.branches' ) ); ?></h2>
					<ul>
						<?php foreach ( $momos_branches as $momos_b ) : ?>
							<li class="momo-footer__branch">
								<a href="<?php echo esc_url( $momos_b['url'] ); ?>"><?php echo esc_html( $momos_b['name'] ); ?></a>
								<?php if ( '' !== $momos_b['tel'] ) : ?>
									<br><a class="momo-footer__tel" href="<?php echo esc_attr( $momos_b['tel'] ); ?>" data-momo-event="click_phone" data-momo-cta="footer" data-momo-branch="<?php echo esc_attr( $momos_b['code'] ); ?>"><?php momos_the_icon( 'phone', 'momo-icon--sm' ); ?><span class="momo-visually-hidden"><?php echo esc_html( momos_s( 'cta.call' ) . ' ' . $momos_b['name'] ); ?></span><?php echo momos_ltr( $momos_b['phone'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></a>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</nav>
			<?php endif; ?>
			<nav aria-labelledby="momo-ftr-explore" id="momo-footer-nav" tabindex="-1">
				<h2 id="momo-ftr-explore"><?php echo esc_html( momos_s( 'nav.explore' ) ); ?></h2>
				<ul>
					<?php foreach ( $momos_explore as $momos_i ) : ?>
						<li><a href="<?php echo esc_url( $momos_i['url'] ); ?>"><?php echo esc_html( $momos_i['label'] ); ?></a></li>
					<?php endforeach; ?>
					<?php if ( ! $momos_branches ) : ?>
						<li><a href="<?php echo esc_url( momos_page_url( 'branches' ) ); ?>"><?php echo esc_html( momos_s( 'nav.branches' ) ); ?></a></li>
					<?php endif; ?>
				</ul>
			</nav>
			<?php if ( $momos_legal ) : ?>
				<nav aria-labelledby="momo-ftr-legal">
					<h2 id="momo-ftr-legal"><?php echo esc_html( momos_s( 'nav.legal' ) ); ?></h2>
					<ul>
						<?php foreach ( $momos_legal as $momos_i ) : ?>
							<li><a href="<?php echo esc_url( $momos_i['url'] ); ?>"><?php echo esc_html( $momos_i['label'] ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				</nav>
			<?php endif; ?>
			<div>
				<?php if ( $momos_social ) : ?>
					<nav aria-labelledby="momo-ftr-social">
						<h2 id="momo-ftr-social"><?php echo esc_html( momos_s( 'nav.social' ) ); ?></h2>
						<ul>
							<?php foreach ( $momos_social as $momos_s ) : ?>
								<li><a href="<?php echo esc_url( $momos_s['url'] ); ?>" target="_blank" rel="noopener"><?php momos_the_icon( in_array( $momos_s['network'], [ 'instagram', 'facebook' ], true ) ? $momos_s['network'] : 'link', 'momo-icon--sm' ); ?><?php echo esc_html( $momos_s['label'] ); ?><?php echo momos_new_tab_hint(); // phpcs:ignore WordPress.Security.EscapeOutput ?></a></li>
							<?php endforeach; ?>
						</ul>
					</nav>
				<?php endif; ?>
				<nav aria-labelledby="momo-ftr-lang"<?php echo $momos_social ? ' class="momo-mt-8"' : ''; ?>>
					<h2 id="momo-ftr-lang"><?php echo esc_html( momos_s( 'nav.languages' ) ); ?></h2>
					<?php get_template_part( 'template-parts/frame/lang-switcher', null, [ 'variant' => 'list' ] ); ?>
				</nav>
			</div>
		</div>
		<?php if ( '' !== $momos_operator ) : ?>
			<div class="momo-footer__bottom">
				<p><?php echo strtr( esc_html( momos_s( 'ftr.copyright' ) ), [ '{year}' => momos_ltr( (string) wp_date( 'Y' ) ), '{operator}' => '<bdi>' . esc_html( $momos_operator ) . '</bdi>' ] ); // phpcs:ignore WordPress.Security.EscapeOutput -- parts escaped. ?></p>
			</div>
		<?php endif; ?>
	</div>
</footer>
