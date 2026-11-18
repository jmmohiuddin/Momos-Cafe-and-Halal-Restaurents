<?php
/**
 * CMP-BranchSelector (§7.4): GET form, native `<select>` labelled `menu.branch_select.label`; first option
 * `menu.branch_select.all`. Works without JS (submit button); screens.js submits on change and keeps the
 * scroll position. Unknown codes are ignored server-side.
 *
 * @package momos
 * @var array{options:list<array{code:string,name:string,selected:bool}>,action:string,id?:string,keep?:array<string,string>} $args
 */

defined( 'ABSPATH' ) || exit;

$momos_opts = (array) ( $args['options'] ?? [] );
if ( ! $momos_opts ) {
	return;
}
$momos_id = sanitize_html_class( (string) ( $args['id'] ?? 'momo-branch-select' ) );
?>
<form class="momo-branch-select" method="get" action="<?php echo esc_url( (string) $args['action'] ); ?>" data-momo-autosubmit>
	<label class="momo-field__label" for="<?php echo esc_attr( $momos_id ); ?>"><?php momos_the_icon( 'store', 'momo-icon--sm' ); ?><?php echo esc_html( momos_s( 'menu.branch_select.label' ) ); ?></label>
	<div class="momo-branch-select__row">
		<select class="momo-input" id="<?php echo esc_attr( $momos_id ); ?>" name="branch">
			<option value=""><?php echo esc_html( momos_s( 'menu.branch_select.all' ) ); ?></option>
			<?php foreach ( $momos_opts as $momos_o ) : ?>
				<option value="<?php echo esc_attr( (string) $momos_o['code'] ); ?>"<?php selected( ! empty( $momos_o['selected'] ) ); ?>><?php echo esc_html( (string) $momos_o['name'] ); ?></option>
			<?php endforeach; ?>
		</select>
		<button type="submit" class="momo-btn momo-btn--secondary" data-momo-autosubmit-btn><?php echo esc_html( momos_s( 'menu.branch_select.submit' ) ); ?></button>
	</div>
</form>
