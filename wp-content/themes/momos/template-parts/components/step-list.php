<?php
/**
 * CMP-StepList (§7.3): `<ol>` of 3 cards (number, icon disc, title, text). Western digits; icons decorative.
 *
 * @package momos
 * @var array{steps:list<array{title?:string,text:string,icon?:string}>} $args
 */

defined( 'ABSPATH' ) || exit;

$momos_steps = (array) ( $args['steps'] ?? [] );
if ( ! $momos_steps ) {
	return;
}
?>
<ol class="momo-steps">
	<?php foreach ( $momos_steps as $momos_s ) : ?>
		<li class="momo-card">
			<span class="momo-disc"><?php momos_the_icon( (string) ( $momos_s['icon'] ?? 'check' ), 'momo-icon--lg' ); ?></span>
			<?php if ( ! empty( $momos_s['title'] ) ) : ?>
				<h3 class="momo-card__title"><?php echo esc_html( (string) $momos_s['title'] ); ?></h3>
			<?php endif; ?>
			<p class="momo-muted"><?php echo esc_html( (string) $momos_s['text'] ); ?></p>
		</li>
	<?php endforeach; ?>
</ol>
