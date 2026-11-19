<?php
/**
 * Staff email plain-text part.
 *
 * @var array<string,mixed> $v view
 */

defined( 'ABSPATH' ) || exit;

echo $v['headline'] . "\n" . str_repeat( '=', 40 ) . "\n\n"; // phpcs:ignore -- plain text
require __DIR__ . '/_blocks.txt.php';
if ( ! empty( $v['button'] ) ) {
	echo $v['button']['label'] . ":\n" . $v['button']['url'] . "\n\n"; // phpcs:ignore
}
echo "--\n" . \Momos\Notification\Template\Renderer::t( 'staff.email.footer' ) . "\n"; // phpcs:ignore
