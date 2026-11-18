<?php
/**
 * Shared block renderer (plain-text part): no markup, URLs on their own line.
 *
 * @var array<string,mixed> $v view
 */

use Momos\Notification\Template\Html;

defined( 'ABSPATH' ) || exit;

foreach ( (array) $v['blocks'] as $b ) {
	switch ( $b['type'] ?? '' ) {
		case 'h2':
			echo "\n" . (string) $b['text'] . "\n";
			break;
		case 'p':
			echo Html::wrap( Html::fill_text( (string) $b['text'], (array) ( $b['args'] ?? [] ) ) ) . "\n\n"; // phpcs:ignore -- text part
			break;
		case 'message':
			echo Html::wrap( wp_strip_all_tags( (string) $b['text'] ) ) . "\n\n"; // phpcs:ignore
			break;
		case 'links':
			if ( ! empty( $b['heading'] ) ) {
				echo Html::wrap( (string) $b['heading'] ) . "\n"; // phpcs:ignore
			}
			foreach ( (array) $b['items'] as [ $label, $url ] ) {
				echo '- ' . $label . "\n  " . $url . "\n"; // phpcs:ignore
			}
			echo "\n";
			break;
		case 'rows':
			foreach ( (array) $b['rows'] as $row ) {
				echo $row[0] . ': ' . $row[1] . "\n"; // phpcs:ignore
			}
			echo "\n";
			break;
		case 'changes':
			foreach ( (array) $b['rows'] as [ $label, $before, $after ] ) {
				echo $label . ': ' . $before . ' -> ' . $after . ' ' . $b['marker'] . "\n"; // phpcs:ignore
			}
			echo "\n";
			break;
		case 'section':
			echo $b['title'] . ' (' . $b['count'] . ")\n"; // phpcs:ignore
			foreach ( (array) ( $b['items'] ?? [] ) as $item ) {
				echo '- ' . $item . "\n"; // phpcs:ignore
			}
			if ( ! empty( $b['link'] ) ) {
				echo $b['link'][0] . ': ' . $b['link'][1] . "\n"; // phpcs:ignore
			}
			echo "\n";
			break;
	}
}
