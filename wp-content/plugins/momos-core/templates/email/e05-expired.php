<?php
/**
 * SCR-E05 Expired — not confirmed in time (spec §4.7): apology, branch phone prominently, walk-in
 * line only when the branch policy allows it (DEC-054, filter, default off). Never blames the guest
 * and never implies a table is held.
 *
 * @var array<string,mixed> $d
 * @var callable            $t
 */

defined( 'ABSPATH' ) || exit;

$blocks = [ [ 'type' => 'p', 'text' => $t( 'email.e05.apology' ) ] ];
if ( '' !== $d['branch']['phone'] ) {
	$blocks[] = [ 'type' => 'p', 'text' => $t( 'email.branch.call' ), 'args' => [ 'phone' => $d['branch']['phone'] ], 'strong' => true, 'href' => $d['branch']['tel'] ];
}
if ( (bool) apply_filters( 'momos/notification/e05_walk_in_allowed', false, (int) $d['branch_id'] ) ) {
	$blocks[] = [ 'type' => 'p', 'text' => $t( 'email.e05.walk_in' ) ];
}

return [
	'subject_key'  => 'email.subject.e05',
	'subject_args' => [ 'brand' => $d['brand'], 'ref' => $d['ref'] ],
	'headline'     => $t( 'status.expired' ),
	'tone'         => 'neutral',
	'blocks'       => $blocks,
	'button'       => [ 'label' => $t( 'cta.request_other_time' ), 'url' => $d['request_url'] ],
];
