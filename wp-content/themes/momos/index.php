<?php
/**
 * Fallback template (classic hierarchy). Real screens use the templates routed by
 * Momos\Presentation\Templates\TemplateRouter (screens.md §0.1).
 *
 * @package momos
 */

defined( 'ABSPATH' ) || exit;

get_header();
get_template_part( 'template-parts/frame/page-banner', null, [ 'title' => is_singular() ? ( function_exists( 'momos_tr' ) ? momos_tr( get_queried_object_id(), 'title' ) : get_the_title() ) : wp_get_document_title() ] );
?>
<div class="momo-container momo-section">
	<?php
	while ( have_posts() ) :
		the_post();
		?>
		<div class="momo-prose"><?php the_content(); ?></div>
	<?php endwhile; ?>
</div>
<?php
get_footer();
