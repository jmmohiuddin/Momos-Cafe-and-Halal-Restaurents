<?php
/**
 * CMP-LangSwitcher (FR-I18N-002, ui-design-system.md §8.4). Native disclosure (<details>/<summary>,
 * works without JS; frame.js adds Esc/outside-click close and focus return). Enabled languages only,
 * fixed order, native names, no flags; each option `lang`/`hreflang`/`dir`; current `aria-current`.
 *
 * @package momos
 * @var array{variant?:string} $args variant: header (default) | grid | list
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( \Momos\Presentation\View\LanguageSwitcher::class ) ) {
	return;
}
$momos_variant = (string) ( $args['variant'] ?? 'header' );
$momos_opts    = \Momos\Presentation\View\LanguageSwitcher::options();
if ( count( $momos_opts ) < 2 ) {
	return;
}
$momos_lang    = momos_theme_lang();
$momos_current = \Momos\Kernel\I18n\Lang::native( $momos_lang );

$momos_link = static function ( array $o, string $class = '' ) use ( $momos_lang ): string {
	return sprintf(
		'<a%s href="%s" lang="%s" hreflang="%s" dir="%s"%s data-momo-event="select_language" data-momo-from="%s" data-momo-to="%s">%s%s</a>',
		'' !== $class ? ' class="' . esc_attr( $class ) . '"' : '',
		esc_url( $o['url'] ),
		esc_attr( $o['code'] ),
		esc_attr( $o['code'] ),
		esc_attr( $o['dir'] ),
		$o['current'] ? ' aria-current="true"' : '',
		esc_attr( $momos_lang ),
		esc_attr( $o['code'] ),
		esc_html( $o['native'] ),
		$o['current'] ? momos_icon( 'check', 'momo-icon--sm' ) : ''
	);
};

if ( 'grid' === $momos_variant ) : ?>
	<ul class="momo-langgrid">
		<?php foreach ( $momos_opts as $momos_o ) : ?>
			<li><?php echo $momos_link( $momos_o ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above. ?></li>
		<?php endforeach; ?>
	</ul>
<?php elseif ( 'list' === $momos_variant ) : ?>
	<ul>
		<?php foreach ( $momos_opts as $momos_o ) : ?>
			<li><?php echo $momos_link( $momos_o ); // phpcs:ignore WordPress.Security.EscapeOutput ?></li>
		<?php endforeach; ?>
	</ul>
<?php else : ?>
	<details class="momo-lang" data-momo-disclosure>
		<summary class="momo-lang__btn" aria-label="<?php echo esc_attr( momos_s( 'a11y.lang.current', [ 'language' => $momos_current ] ) ); ?>">
			<?php momos_the_icon( 'globe' ); ?><span><?php echo esc_html( $momos_current ); ?></span><?php momos_the_icon( 'chevron-down', 'momo-icon--sm momo-icon--chev' ); ?>
		</summary>
		<ul class="momo-dropdown momo-dropdown--end momo-lang__list">
			<?php foreach ( $momos_opts as $momos_o ) : ?>
				<li><?php echo $momos_link( $momos_o ); // phpcs:ignore WordPress.Security.EscapeOutput ?></li>
			<?php endforeach; ?>
		</ul>
	</details>
<?php endif; ?>
