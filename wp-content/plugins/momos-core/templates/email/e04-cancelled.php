<?php
/**
 * SCR-E04 Cancelled (US-NOT-006, spec §4.7). Variant `by_customer` / `by_branch`; wording from the
 * status before cancellation (confirmed_at set → was confirmed). Never sent for `suspected_spam`
 * (the subscriber does not queue it). While it was pending the request wording is used (BR-RES-01).
 *
 * @var array<string,mixed> $d
 * @var callable            $t
 */

defined( 'ABSPATH' ) || exit;

$r      = $d['reservation'];
$was    = empty( $r['confirmed_at'] ) ? 'pending' : 'confirmed';
$blocks = [];
if ( 'by_branch' === $d['variant'] ) {
	$blocks[] = [ 'type' => 'p', 'text' => $t( 'email.e04.by_branch.' . $was ) ];
	$code     = (string) ( $r['decision_reason_code'] ?? '' );
	$code     = in_array( $code, [ 'customer_request_by_phone', 'branch_unable_to_serve', 'duplicate', 'other' ], true ) ? $code : 'other';
	$blocks[] = [ 'type' => 'p', 'text' => $t( 'cancel.reason.' . $code ) ];
	if ( '' !== $d['message'] ) {
		$blocks[] = [ 'type' => 'h2', 'text' => $t( 'email.e02.message_heading' ) ];
		$blocks[] = [ 'type' => 'message', 'text' => $d['message'] ];
	}
	if ( '' !== $d['branch']['phone'] ) {
		$blocks[] = [ 'type' => 'p', 'text' => $t( 'email.e04.unexpected' ), 'args' => [ 'phone' => $d['branch']['phone'] ] ];
	}
} else {
	$blocks[] = [ 'type' => 'p', 'text' => $t( 'email.e04.by_customer.' . $was ) ];
}

return [
	'subject_key'  => 'email.subject.e04.' . $was,
	'subject_args' => [ 'brand' => $d['brand'], 'ref' => $d['ref'] ],
	'headline'     => $t( 'status.cancelled' ),
	'tone'         => 'neutral',
	'blocks'       => $blocks,
	'button'       => [ 'label' => $t( 'cta.request_other_time' ), 'url' => $d['request_url'] ],
];
