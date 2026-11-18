<?php
/**
 * CMP-Chip / ChipGroup (§7.2) — tabs/filters:
 *  link   → `<nav aria-label><ul>` of link chips (current = action fill + aria-current); mobile scroll row
 *  choice → `<fieldset><legend>` + native radios (arrow keys)
 *  filter → `<fieldset><legend>` + native checkboxes
 *  tag    → non-interactive list
 * Disabled choices show a dashed border and visible reason text.
 *
 * @package momos
 * @var array{type?:string,label:string,name?:string,items:list<array{label:string,url?:string,value?:string,current?:bool,checked?:bool,disabled?:bool,reason?:string,icon?:string}>,scroll?:bool,visible_label?:bool} $args
 */

defined( 'ABSPATH' ) || exit;

$momos_type  = (string) ( $args['type'] ?? 'link' );
$momos_items = (array) ( $args['items'] ?? [] );
if ( ! $momos_items ) {
	return;
}
$momos_list_cls = 'momo-chips' . ( ! empty( $args['scroll'] ) ? ' momo-chips--scroll' : '' );
$momos_name     = (string) ( $args['name'] ?? 'chips' );

if ( 'link' === $momos_type ) : ?>
	<nav aria-label="<?php echo esc_attr( (string) $args['label'] ); ?>">
		<ul class="<?php echo esc_attr( $momos_list_cls ); ?>">
			<?php foreach ( $momos_items as $momos_i ) : ?>
				<li><a class="momo-chip momo-chip--link" href="<?php echo esc_url( (string) $momos_i['url'] ); ?>"<?php echo ! empty( $momos_i['current'] ) ? ' aria-current="page"' : ''; ?>><?php echo ! empty( $momos_i['icon'] ) ? momos_icon( (string) $momos_i['icon'], 'momo-icon--sm' ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?><?php echo esc_html( (string) $momos_i['label'] ); ?></a></li>
			<?php endforeach; ?>
		</ul>
	</nav>
<?php elseif ( 'tag' === $momos_type ) : ?>
	<ul class="momo-chips" aria-label="<?php echo esc_attr( (string) $args['label'] ); ?>">
		<?php foreach ( $momos_items as $momos_i ) : ?>
			<li class="momo-chip momo-chip--tag"><?php echo esc_html( (string) $momos_i['label'] ); ?></li>
		<?php endforeach; ?>
	</ul>
<?php else : ?>
	<fieldset class="momo-fieldset">
		<legend<?php echo false === ( $args['visible_label'] ?? true ) ? ' class="momo-visually-hidden"' : ''; ?>><?php echo esc_html( (string) $args['label'] ); ?></legend>
		<div class="momo-chips">
			<?php foreach ( $momos_items as $momos_n => $momos_i ) : ?>
				<label class="momo-chip momo-chip--<?php echo 'choice' === $momos_type ? 'choice' : 'filter'; ?>">
					<input type="<?php echo 'choice' === $momos_type ? 'radio' : 'checkbox'; ?>" name="<?php echo esc_attr( $momos_name . ( 'filter' === $momos_type ? '[]' : '' ) ); ?>" value="<?php echo esc_attr( (string) ( $momos_i['value'] ?? $momos_n ) ); ?>"<?php checked( ! empty( $momos_i['checked'] ) ); ?><?php disabled( ! empty( $momos_i['disabled'] ) ); ?>>
					<?php momos_the_icon( 'check', 'momo-icon--sm momo-chip__check' ); ?>
					<span><?php echo esc_html( (string) $momos_i['label'] ); ?><?php echo ! empty( $momos_i['disabled'] ) && ! empty( $momos_i['reason'] ) ? ' ' . esc_html( (string) $momos_i['reason'] ) : ''; ?></span>
				</label>
			<?php endforeach; ?>
		</div>
	</fieldset>
<?php endif; ?>
