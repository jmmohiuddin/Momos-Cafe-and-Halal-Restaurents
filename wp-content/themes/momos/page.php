<?php
/**
 * Generic page (editorial content at reading width). Title/content come from the page's approved
 * translation in the current language (IMPL-002); an untranslated page is not routed here (404).
 *
 * @package momos
 */

defined( 'ABSPATH' ) || exit;

get_header();
$momos_id      = (int) get_queried_object_id();
$momos_title   = function_exists( 'momos_tr' ) ? momos_tr( $momos_id, 'title' ) : get_the_title( $momos_id );
$momos_content = function_exists( 'momos_tr' ) ? momos_tr( $momos_id, 'content' ) : (string) get_post_field( 'post_content', $momos_id );
$momos_lead    = function_exists( 'momos_tr' ) ? momos_tr( $momos_id, 'excerpt' ) : '';
get_template_part( 'template-parts/frame/page-banner', null, [ 'title' => $momos_title, 'lead' => $momos_lead ] );
?>
<div class="momo-container momo-section">
	<div class="momo-prose"><?php echo apply_filters( 'the_content', $momos_content ); // phpcs:ignore WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook; core content filter (kses on save). ?></div>
</div>
<?php
get_footer();
