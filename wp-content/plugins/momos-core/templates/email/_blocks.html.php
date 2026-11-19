<?php
/**
 * Shared block renderer (HTML part). Every value is escaped; catalog placeholders are isolated with
 * <bdi> (Html::fill); user text uses dir="auto" (FR-I18N-005, SEC-021).
 *
 * @var array<string,mixed> $v view
 */

use Momos\Notification\Template\Html;

defined( 'ABSPATH' ) || exit;

$align = 'rtl' === $v['dir'] ? 'right' : 'left';
$p     = 'margin:0 0 16px;font-size:16px;line-height:1.6;color:#221A17;';
foreach ( (array) $v['blocks'] as $b ) {
	switch ( $b['type'] ?? '' ) {
		case 'h2':
			echo '<h2 style="margin:24px 0 8px;font-size:18px;line-height:1.4;color:#221A17;">' . esc_html( (string) $b['text'] ) . '</h2>';
			break;
		case 'p':
			$inner = Html::fill( (string) $b['text'], (array) ( $b['args'] ?? [] ) );
			if ( ! empty( $b['href'] ) ) {
				$inner = '<a href="' . esc_url( (string) $b['href'], [ 'tel', 'https', 'http' ] ) . '" style="color:#A03B00;">' . $inner . '</a>';
			}
			echo '<p style="' . esc_attr( $p . ( ! empty( $b['strong'] ) ? 'font-weight:700;' : '' ) ) . '">' . $inner . '</p>'; // phpcs:ignore -- escaped above
			break;
		case 'message':
			echo '<div style="' . esc_attr( $p . 'padding:12px 16px;background:#FDF8F3;border-' . $align . ':4px solid #D99B43;' ) . '">' . Html::user_text( (string) $b['text'] ) . '</div>'; // phpcs:ignore -- escaped in user_text
			break;
		case 'links':
			if ( ! empty( $b['heading'] ) ) {
				echo '<p style="' . esc_attr( $p . 'margin-bottom:8px;' ) . '">' . esc_html( (string) $b['heading'] ) . '</p>';
			}
			echo '<ul style="margin:0 0 16px;padding-' . esc_attr( $align ) . ':20px;font-size:16px;line-height:1.6;">';
			foreach ( (array) $b['items'] as [ $label, $url ] ) {
				echo '<li style="margin:0 0 8px;"><a href="' . esc_url( (string) $url ) . '" style="color:#A03B00;"><bdi>' . esc_html( (string) $label ) . '</bdi></a></li>';
			}
			echo '</ul>';
			break;
		case 'rows':
			echo '<table style="width:100%;border-collapse:collapse;margin:0 0 16px;font-size:15px;line-height:1.5;">';
			foreach ( (array) $b['rows'] as $row ) {
				echo '<tr><th scope="row" style="text-align:' . esc_attr( $align ) . ';vertical-align:top;padding:6px 12px 6px 0;color:#6E625D;font-weight:400;width:40%;">' . esc_html( (string) $row[0] ) . '</th><td style="padding:6px 0;color:#221A17;font-weight:700;"><bdi>' . esc_html( (string) $row[1] ) . '</bdi></td></tr>';
			}
			echo '</table>';
			break;
		case 'changes':
			echo '<table style="width:100%;border-collapse:collapse;margin:0 0 16px;font-size:15px;line-height:1.5;">';
			foreach ( (array) $b['rows'] as [ $label, $before, $after ] ) {
				echo '<tr><th scope="row" style="text-align:' . esc_attr( $align ) . ';vertical-align:top;padding:6px 12px 6px 0;color:#6E625D;font-weight:400;">' . esc_html( (string) $label ) . '</th><td style="padding:6px 0;color:#221A17;"><s><bdi>' . esc_html( (string) $before ) . '</bdi></s> → <strong><bdi>' . esc_html( (string) $after ) . '</bdi></strong> <span style="color:#A03B00;">' . esc_html( (string) $b['marker'] ) . '</span></td></tr>';
			}
			echo '</table>';
			break;
		case 'section':
			echo '<h2 style="margin:20px 0 6px;font-size:16px;line-height:1.4;color:#221A17;">' . esc_html( (string) $b['title'] ) . ' <bdi>(' . esc_html( (string) $b['count'] ) . ')</bdi></h2>';
			if ( ! empty( $b['items'] ) ) {
				echo '<ul style="margin:0 0 8px;padding-' . esc_attr( $align ) . ':20px;font-size:14px;line-height:1.5;">';
				foreach ( (array) $b['items'] as $item ) {
					echo '<li><bdi>' . esc_html( (string) $item ) . '</bdi></li>';
				}
				echo '</ul>';
			}
			if ( ! empty( $b['link'] ) ) {
				echo '<p style="margin:0 0 12px;font-size:14px;"><a href="' . esc_url( (string) $b['link'][1] ) . '" style="color:#2271B1;">' . esc_html( (string) $b['link'][0] ) . '</a></p>';
			}
			break;
	}
}
