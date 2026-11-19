<?php
/**
 * Copy-Japanese-address button of CMP-AddressBlock (§7.4), shared by the branch detail address block and every
 * directory card (FR-BR-001(b), AC-BR-01-04). frame.js copies `data-momo-copy` with the Clipboard API and
 * announces `branch.copied` in the toast (role=status); without the API it shows the text in a read-only field
 * next to the button and selects it. The address is the exact Japanese address (postal code printed once).
 *
 * @package momos
 * @var array{text:string,label?:string} $args
 */

defined( 'ABSPATH' ) || exit;

$momos_text = trim( (string) ( $args['text'] ?? '' ) );
if ( '' === $momos_text ) {
	return;
}
$momos_label = (string) ( $args['label'] ?? '' );
?>
<button type="button" class="momo-btn momo-btn--sm momo-btn--secondary momo-address__copy" data-momo-copy="<?php echo esc_attr( $momos_text ); ?>" data-momo-copied="<?php echo esc_attr( momos_s( 'branch.copied' ) ); ?>"<?php echo momos_attrs( [ 'aria-label' => '' !== $momos_label ? $momos_label : null ] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>><?php momos_the_icon( 'copy', 'momo-icon--sm' ); ?><span><?php echo esc_html( momos_s( 'branch.copy_address' ) ); ?></span></button>
