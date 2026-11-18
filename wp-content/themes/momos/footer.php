<?php
/**
 * Customer page frame — bottom (ui-design-system.md §8.1): </main> → footer → mobile action bar →
 * mobile menu dialog → toast live region.
 *
 * @package momos
 */

defined( 'ABSPATH' ) || exit;
?>
</main>
<?php
get_template_part( 'template-parts/frame/footer' );
get_template_part( 'template-parts/frame/mobile-action-bar' );
get_template_part( 'template-parts/frame/mobile-menu' );
?>
<div class="momo-toast" role="status" aria-live="polite"><span class="momo-toast__msg" data-momo-toast></span></div>
<?php wp_footer(); ?>
</body>
</html>
