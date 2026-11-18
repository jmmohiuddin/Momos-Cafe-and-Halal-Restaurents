<?php
/**
 * SCR-E02 Confirmed by the branch (US-NOT-002, spec §4.7): branch message (only if set), policy text
 * only when supplied (DEC-054, BR-RES-14), allergy line, cancel hint. Internal notes never appear
 * (BR-NOT-02): only `message_to_customer` is rendered.
 *
 * @var array<string,mixed> $d
 * @var callable            $t
 */

defined( 'ABSPATH' ) || exit;

$blocks = [];
if ( '' !== $d['message'] ) {
	$blocks[] = [ 'type' => 'h2', 'text' => $t( 'email.e02.message_heading' ) ];
	$blocks[] = [ 'type' => 'message', 'text' => $d['message'] ];
}
$policy = \Momos\Notification\Template\Policy::block( $d['settings'], $d['lang'], $t );
if ( $policy ) {
	$blocks[] = $policy;
}
$blocks[] = [ 'type' => 'p', 'text' => $t( 'email.confirmed.allergy' ) ];
$blocks[] = [ 'type' => 'p', 'text' => $t( 'email.e02.cancel_hint' ) ];
if ( $d['note'] ) {
	$blocks[] = [ 'type' => 'p', 'text' => $t( 'email.common.note_received' ) ];
}

return [
	'subject_key'  => 'email.subject.e02',
	'subject_args' => [ 'brand' => $d['brand'], 'ref' => $d['ref'] ],
	'headline'     => $t( 'status.confirmed' ),
	'tone'         => 'confirmed',
	'blocks'       => $blocks,
	'button'       => '' !== $d['status_url'] ? [ 'label' => $t( 'email.cta.view_reservation' ), 'url' => $d['status_url'] ] : null,
];
