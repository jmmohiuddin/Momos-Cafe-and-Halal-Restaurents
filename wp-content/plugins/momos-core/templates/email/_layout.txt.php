<?php
/**
 * Customer email plain-text part (same content order as the HTML part).
 *
 * @var array<string,mixed> $v view
 */

use Momos\Notification\Template\Html;

defined( 'ABSPATH' ) || exit;

$branch = (array) $v['branch'];
echo $v['brand'] . "\n" . str_repeat( '=', 40 ) . "\n\n"; // phpcs:ignore -- plain text
echo $v['headline'] . "\n\n"; // phpcs:ignore
if ( ! empty( $v['ref'] ) ) {
	echo Html::fill_text( (string) $v['reference_text'], [ 'ref' => (string) $v['ref'] ] ) . "\n\n"; // phpcs:ignore
}
foreach ( (array) ( $v['details_rows'] ?? [] ) as $row ) {
	echo $row[0] . ': ' . $row[1] . "\n"; // phpcs:ignore
}
echo "\n";
require __DIR__ . '/_blocks.txt.php';
if ( ! empty( $v['button'] ) ) {
	echo $v['button']['label'] . ":\n" . $v['button']['url'] . "\n\n"; // phpcs:ignore
}
if ( '' !== (string) $branch['name'] ) {
	echo str_repeat( '-', 40 ) . "\n" . $branch['name'] . "\n"; // phpcs:ignore
	if ( '' !== (string) $branch['address_local'] ) {
		echo $branch['address_local'] . "\n"; // phpcs:ignore
	}
	if ( '' !== (string) $branch['address_ja'] ) {
		echo ( 'ja' !== $v['lang'] ? $v['labels']['address_ja'] . ":\n" : '' ) . $branch['address_ja'] . "\n"; // phpcs:ignore
	}
	if ( '' !== (string) $branch['phone'] ) {
		echo Html::fill_text( (string) $v['labels']['call'], [ 'phone' => (string) $branch['phone'] ] ) . "\n"; // phpcs:ignore
	}
	if ( '' !== (string) $branch['directions'] ) {
		echo $v['labels']['directions'] . ': ' . $branch['directions'] . "\n"; // phpcs:ignore
	}
	echo "\n";
}
if ( ! empty( $v['carrier'] ) ) {
	echo Html::wrap( Html::fill_text( (string) $v['labels']['carrier'], [ 'domain' => (string) $v['carrier_domain'] ] ) ) . "\n\n"; // phpcs:ignore
}
echo "--\n" . Html::wrap( (string) $v['labels']['auto'] ) . "\n"; // phpcs:ignore
echo $v['labels']['privacy'] . ': ' . $v['privacy_url'] . "\n"; // phpcs:ignore
if ( '' !== (string) $v['operator'] ) {
	echo Html::fill_text( (string) $v['labels']['copyright'], [ 'year' => (string) $v['year'], 'operator' => (string) $v['operator'] ] ) . "\n"; // phpcs:ignore
}
echo $v['brand'] . "\n"; // phpcs:ignore
