<?php
/**
 * SCR-E03 Declined (US-NOT-003, spec §4.7): reason category in plain language, branch message if
 * set, up to 2 suggested times — each a link to the prefilled request form, explicitly not held.
 * Internal reason text is never rendered.
 *
 * @var array<string,mixed> $d
 * @var callable            $t
 */

use Momos\Kernel\I18n\Url;
use Momos\Notification\Template\EmailFormatter;

defined( 'ABSPATH' ) || exit;

$r      = $d['reservation'];
$code   = (string) ( $r['decision_reason_code'] ?? '' );
$codes  = [ 'fully_booked', 'closed_or_private_event', 'party_size_not_supported', 'incomplete_information', 'other' ];
$code   = in_array( $code, $codes, true ) ? $code : 'other';
$blocks = [
	[ 'type' => 'h2', 'text' => $t( 'email.e03.reason_heading' ) ],
	[ 'type' => 'p', 'text' => $t( 'decline.reason.' . $code ) ],
];
if ( '' !== $d['message'] ) {
	$blocks[] = [ 'type' => 'h2', 'text' => $t( 'email.e02.message_heading' ) ];
	$blocks[] = [ 'type' => 'message', 'text' => $d['message'] ];
}
$suggested = \Momos\Kernel\Support\Json::decode_array( isset( $r['suggested_times'] ) ? (string) $r['suggested_times'] : null );
$items     = [];
foreach ( array_slice( $suggested, 0, 2 ) as $s ) {
	$date = is_array( $s ) ? (string) ( $s['date'] ?? '' ) : '';
	$time = is_array( $s ) ? (string) ( $s['time'] ?? '' ) : '';
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || ! preg_match( '/^\d{2}:\d{2}/', $time ) ) {
		continue;
	}
	$label   = EmailFormatter::date_long( $date, $d['lang'] ) . ' ' . EmailFormatter::customer_time( $time, $d['lang'], $d['jst'] );
	$url     = Url::to( '/reservation/?branch=' . rawurlencode( $d['branch']['code'] ) . '&date=' . $date . '&time=' . rawurlencode( substr( $time, 0, 5 ) ), $d['lang'] );
	$items[] = [ $label, $url ];
}
if ( $items ) {
	$blocks[] = [ 'type' => 'links', 'heading' => $t( 'decline.suggested' ), 'items' => $items ];
}

return [
	'subject_key'  => 'email.subject.e03',
	'subject_args' => [ 'brand' => $d['brand'], 'ref' => $d['ref'] ],
	'headline'     => $t( 'status.rejected' ),
	'tone'         => 'rejected',
	'blocks'       => $blocks,
	'button'       => [ 'label' => $t( 'cta.request_other_time' ), 'url' => $d['request_url'] ],
];
