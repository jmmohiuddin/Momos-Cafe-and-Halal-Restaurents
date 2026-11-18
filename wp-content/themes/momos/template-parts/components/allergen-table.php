<?php
/**
 * CMP-AllergenTable (§7.5, DEC-021): (0) summary · (1) not-verified banner (role=note) · (2) legend
 * above · (3) "only contains / may contain" toggle · (4) grouped table with `<caption>` and
 * `scope="rowgroup"` headers · (5) disclaimers · (6) last verified. Data = AllergenPresenter::for_dish().
 * Never "allergen-free" / "safe". Icons never mirrored; columns mirror in RTL.
 *
 * @package momos
 * @var array{data?:array<string,mixed>,dish_id?:int,id?:string} $args
 */

defined( 'ABSPATH' ) || exit;

$momos_d = $args['data'] ?? null;
if ( null === $momos_d && ! empty( $args['dish_id'] ) && class_exists( \Momos\Dietary\Service\AllergenPresenter::class ) ) {
	$momos_d = \Momos\Dietary\Service\AllergenPresenter::for_dish( (int) $args['dish_id'], momos_theme_lang() );
}
if ( ! is_array( $momos_d ) || empty( $momos_d['groups'] ) ) {
	return;
}
$momos_id    = sanitize_html_class( (string) ( $args['id'] ?? 'momo-allergens' ) );
$momos_icons = [ 'contains' => 'allergen-contains', 'may_contain' => 'allergen-may', 'not_contained' => 'allergen-not', 'not_verified' => 'allergen-unverified' ];
$momos_lbl   = (array) ( $momos_d['labels'] ?? [] );
?>
<div class="momo-allergens" id="<?php echo esc_attr( $momos_id ); ?>">
	<?php foreach ( [ 'contains', 'may_contain' ] as $momos_k ) : ?>
		<?php if ( ! empty( $momos_d['summary'][ $momos_k ] ) ) : ?>
			<p class="<?php echo 'contains' === $momos_k ? 'momo-title' : ''; ?>"><?php echo esc_html( (string) $momos_d['summary'][ $momos_k ] ); ?></p>
		<?php endif; ?>
	<?php endforeach; ?>
	<?php if ( ! empty( $momos_d['banner'] ) ) : ?>
		<div class="momo-alert momo-alert--warning" role="note"><?php momos_the_icon( 'alert-triangle' ); ?><p><?php echo esc_html( (string) $momos_d['banner'] ); ?></p></div>
	<?php endif; ?>
	<ul class="momo-allergens__legend">
		<?php foreach ( (array) ( $momos_d['legend'] ?? [] ) as $momos_state => $momos_text ) : ?>
			<li><span class="momo-al--<?php echo esc_attr( (string) $momos_state ); ?>"><?php momos_the_icon( $momos_icons[ $momos_state ] ?? 'info', 'momo-icon--sm' ); ?></span><?php echo esc_html( (string) $momos_text ); ?></li>
		<?php endforeach; ?>
	</ul>
	<?php if ( ! empty( $momos_lbl['toggle'] ) ) : ?>
		<label class="momo-choice"><input type="checkbox" data-momo-allergen-toggle="<?php echo esc_attr( $momos_id ); ?>"><span><?php echo esc_html( (string) $momos_lbl['toggle'] ); ?></span></label>
	<?php endif; ?>
	<div class="momo-allergens__scroll" role="region" aria-labelledby="<?php echo esc_attr( $momos_id ); ?>-cap" tabindex="0">
		<table>
			<caption id="<?php echo esc_attr( $momos_id ); ?>-cap"><?php echo esc_html( (string) ( $momos_lbl['caption'] ?? '' ) ); ?></caption>
			<thead><tr><th scope="col"><?php echo esc_html( (string) ( $momos_lbl['allergen'] ?? '' ) ); ?></th><th scope="col"><?php echo esc_html( (string) ( $momos_lbl['status'] ?? '' ) ); ?></th></tr></thead>
			<?php foreach ( (array) $momos_d['groups'] as $momos_g ) : ?>
				<tbody>
					<tr><th scope="rowgroup" colspan="2"><?php echo esc_html( (string) $momos_g['label'] ); ?></th></tr>
					<?php foreach ( (array) $momos_g['rows'] as $momos_r ) : ?>
						<?php
						if ( '' === (string) $momos_r['name'] ) {
							continue;
						}
						?>
						<tr class="is-<?php echo esc_attr( (string) $momos_r['state'] ); ?>">
							<th scope="row"><?php echo esc_html( (string) $momos_r['name'] ); ?></th>
							<td><span><span class="momo-al--<?php echo esc_attr( (string) $momos_r['state'] ); ?>"><?php momos_the_icon( $momos_icons[ $momos_r['state'] ] ?? 'info', 'momo-icon--sm' ); ?></span><?php echo esc_html( (string) $momos_r['state_label'] ); ?></span></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			<?php endforeach; ?>
		</table>
	</div>
	<?php foreach ( (array) ( $momos_d['disclaimers'] ?? [] ) as $momos_disc ) : ?>
		<p class="momo-small"><?php echo esc_html( (string) $momos_disc ); ?></p>
	<?php endforeach; ?>
	<?php if ( ! empty( $momos_d['last_verified'] ) ) : ?>
		<p class="momo-small momo-muted"><?php echo esc_html( (string) $momos_d['last_verified'] ); ?></p>
	<?php endif; ?>
</div>
