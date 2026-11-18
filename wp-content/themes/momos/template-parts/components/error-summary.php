<?php
/**
 * CMP-ErrorSummary (§7.2): focusable, role="alert", links to invalid fields; error-strong on error tint.
 *
 * @package momos
 * @var array{title:string,errors:array<string,string>} $args field id => message
 */

defined( 'ABSPATH' ) || exit;

$momos_errors = (array) ( $args['errors'] ?? [] );
if ( ! $momos_errors ) {
	return;
}
?>
<div class="momo-errsum" role="alert" tabindex="-1" data-momo-errsum>
	<?php momos_the_icon( 'alert-circle' ); ?>
	<div>
		<h2><?php echo esc_html( (string) $args['title'] ); ?></h2>
		<ul>
			<?php foreach ( $momos_errors as $momos_field => $momos_msg ) : ?>
				<li><a href="#<?php echo esc_attr( (string) $momos_field ); ?>"><?php echo esc_html( (string) $momos_msg ); ?></a></li>
			<?php endforeach; ?>
		</ul>
	</div>
</div>
