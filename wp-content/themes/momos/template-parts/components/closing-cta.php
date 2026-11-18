<?php
/**
 * CMP-FeatureBand closing CTA (screens.md §3 "Closing CTA"; Doc 06 "every page ends with a reservation
 * CTA"): emerald band, H2, one line, primary "Request a table" + optional secondary links.
 *
 * @package momos
 * @var array{title?:string,body?:string,label?:string,url?:string,cta?:string,branch?:string,links?:list<array{label:string,url:string}>,id?:string} $args
 */

defined( 'ABSPATH' ) || exit;

$momos_id   = sanitize_html_class( (string) ( $args['id'] ?? 'momo-closing' ) );
$momos_url  = (string) ( $args['url'] ?? momos_page_url( 'reservation' ) );
$momos_attr = [ 'data-momo-event' => 'click_reservation', 'data-momo-cta' => (string) ( $args['cta'] ?? 'home_section' ) ];
if ( ! empty( $args['branch'] ) ) {
	$momos_attr['data-momo-branch'] = (string) $args['branch'];
}
?>
<section class="momo-band momo-on-dark momo-closing" aria-labelledby="<?php echo esc_attr( $momos_id ); ?>">
	<div class="momo-container momo-closing__inner">
		<div class="momo-closing__text">
			<h2 id="<?php echo esc_attr( $momos_id ); ?>"><?php echo esc_html( (string) ( $args['title'] ?? momos_s( 'common.closing.title' ) ) ); ?></h2>
			<p class="momo-lead"><?php echo esc_html( (string) ( $args['body'] ?? momos_s( 'common.closing.body' ) ) ); ?></p>
		</div>
		<div class="momo-cluster momo-closing__actions">
			<?php momos_component( 'button', [ 'label' => (string) ( $args['label'] ?? momos_s( 'cta.request_table' ) ), 'url' => $momos_url, 'variant' => 'primary', 'size' => 'lg', 'icon' => 'calendar', 'attrs' => $momos_attr ] ); ?>
			<?php foreach ( (array) ( $args['links'] ?? [] ) as $momos_l ) : ?>
				<?php momos_component( 'button', [ 'label' => (string) $momos_l['label'], 'url' => (string) $momos_l['url'], 'variant' => 'ghost-dark' ] ); ?>
			<?php endforeach; ?>
		</div>
	</div>
</section>
