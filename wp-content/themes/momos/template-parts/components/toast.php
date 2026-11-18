<?php
/**
 * CMP-StatusMessage toast trigger (§7.7). The single live region lives in footer.php; this partial renders
 * a button that shows a toast via frame.js (`data-momo-toast-msg`). Toasts never steal focus and are
 * never the only place an error is shown.
 *
 * @package momos
 * @var array{label:string,message:string} $args
 */

defined( 'ABSPATH' ) || exit;
?>
<button type="button" class="momo-btn momo-btn--secondary" data-momo-toast-msg="<?php echo esc_attr( (string) $args['message'] ); ?>"><?php echo esc_html( (string) $args['label'] ); ?></button>
