<?php
/**
 * CMP-HalalStatement — claim statement block (§7.5, DEC-020/021). Text ONLY from approved templates via
 * `Dietary\ClaimResolver` (never typed in the theme). Certified: certifier / scope / certificate no. /
 * valid until (`<dl>`) + mark image only when the resolver supplies it. Emerald disc with a
 * shield-check outline (not a seal shape). No absolute or "fully/strictly" wording (Doc 07 §6.6 prohibited terms).
 *
 * @package momos
 * @var array{claim?:array<string,mixed>,branch_id?:int,context?:string,h?:int,link?:bool} $args context: brand | branch | dish
 */

defined( 'ABSPATH' ) || exit;

$momos_ctx   = (string) ( $args['context'] ?? 'branch' );
$momos_claim = $args['claim'] ?? null;
if ( null === $momos_claim && class_exists( \Momos\Dietary\Service\ClaimResolver::class ) ) {
	$momos_claim = 'brand' === $momos_ctx ? \Momos\Dietary\Service\ClaimResolver::brand_claim() : ( ! empty( $args['branch_id'] ) ? \Momos\Dietary\Service\ClaimResolver::branch_claim( (int) $args['branch_id'] ) : null );
}
if ( ! is_array( $momos_claim ) || '' === trim( (string) ( $momos_claim['statement'] ?? '' ) ) ) {
	return; // No approved statement text: render nothing (no fallback wording in the theme).
}
$momos_h    = max( 2, min( 4, (int) ( $args['h'] ?? 2 ) ) );
$momos_cert = 'certified' === ( $momos_claim['type'] ?? '' );
$momos_rows = [];
if ( $momos_cert ) {
	foreach ( [ 'certifier' => 'halal.certifier', 'scope_label' => 'halal.scope', 'number' => 'halal.cert_no', 'valid_until_display' => 'halal.valid_until' ] as $momos_k => $momos_label ) {
		if ( ! empty( $momos_claim[ $momos_k ] ) ) {
			$momos_rows[] = [ momos_s( $momos_label ), (string) $momos_claim[ $momos_k ], in_array( $momos_k, [ 'number', 'valid_until_display', 'certifier' ], true ) ];
		}
	}
}
if ( 'dish' === $momos_ctx ) : ?>
	<p class="momo-diet"><?php momos_the_icon( 'shield-check', 'momo-icon--sm' ); ?><?php echo esc_html( (string) $momos_claim['statement'] ); ?></p>
	<?php
	return;
endif;
?>
<section class="momo-card momo-halal" aria-labelledby="momo-halal-<?php echo esc_attr( $momos_ctx ); ?>">
	<span class="momo-disc momo-disc--brand"><?php momos_the_icon( 'shield-check', 'momo-icon--lg' ); ?></span>
	<div>
		<h<?php echo (int) $momos_h; ?> id="momo-halal-<?php echo esc_attr( $momos_ctx ); ?>"><?php echo esc_html( momos_s( 'cta.halal_info' ) ); ?></h<?php echo (int) $momos_h; ?>>
		<p><?php echo esc_html( (string) $momos_claim['statement'] ); ?></p>
		<?php if ( $momos_rows ) : ?>
			<dl>
				<?php foreach ( $momos_rows as [ $momos_dt, $momos_dd, $momos_iso ] ) : ?>
					<dt><?php echo esc_html( $momos_dt ); ?></dt>
					<dd><?php echo $momos_iso ? '<bdi>' . esc_html( $momos_dd ) . '</bdi>' : esc_html( $momos_dd ); // phpcs:ignore WordPress.Security.EscapeOutput ?></dd>
				<?php endforeach; ?>
			</dl>
		<?php endif; ?>
		<?php if ( $momos_cert && ! empty( $momos_claim['mark_url'] ) ) : ?>
			<img class="momo-halal__mark" src="<?php echo esc_url( (string) $momos_claim['mark_url'] ); ?>" alt="<?php echo esc_attr( (string) ( $momos_claim['mark_alt'] ?? '' ) ); ?>" loading="lazy" width="72" height="72">
		<?php endif; ?>
		<?php foreach ( (array) ( $momos_claim['alcohol_lines'] ?? [] ) as $momos_line ) : ?>
			<?php if ( is_string( $momos_line ) && '' !== $momos_line ) : ?>
				<p class="momo-small"><?php momos_the_icon( 'wine', 'momo-icon--sm' ); ?> <?php echo esc_html( $momos_line ); ?></p>
			<?php endif; ?>
		<?php endforeach; ?>
		<?php if ( ! empty( $momos_claim['last_verified'] ) ) : ?>
			<p class="momo-small momo-muted"><?php echo esc_html( (string) $momos_claim['last_verified'] ); ?></p>
		<?php endif; ?>
		<?php if ( ! empty( $args['link'] ) ) : ?>
			<?php momos_component( 'link', [ 'label' => momos_s( 'cta.halal_info' ), 'url' => momos_page_url( 'halal' ), 'more' => true ] ); ?>
		<?php endif; ?>
	</div>
</section>
