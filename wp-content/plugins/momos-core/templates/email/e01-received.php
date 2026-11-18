<?php
/**
 * SCR-E01 Request received (F-NOT-01, US-NOT-001, spec §4.7). BR-RES-01: no request-context term —
 * the only "confirmed" is the negated DEC-011 label `status.pending`.
 *
 * @var array<string,mixed> $d data contract (Renderer::data)
 * @var callable            $t catalog lookup in the email language
 */

use Momos\Kernel\Clock\Clock;
use Momos\Notification\Template\Renderer;

defined( 'ABSPATH' ) || exit;

$r      = $d['reservation'];
$s      = $d['settings'];
$blocks = [ [ 'type' => 'p', 'text' => $t( 'res.submitted.next' ) ] ];

$start = Clock::from_mysql_utc( (string) $r['requested_start_utc'] );
if ( $start ) {
	$deadline = $start->modify( '-' . (int) $s['decision_cutoff_minutes'] . ' minutes' );
	$blocks[] = [ 'type' => 'p', 'text' => $t( 'res.submitted.expiry' ), 'args' => [ 'time' => Renderer::jst_time( $deadline, (string) $r['requested_date'], $d['lang'] ) ] ];
}
if ( ! empty( $s['show_response_promise'] ) && (int) $s['response_sla_minutes'] > 0 ) {
	$blocks[] = [ 'type' => 'p', 'text' => $t( 'res.submitted.promise' ), 'args' => [ 'duration' => Renderer::duration( (int) $s['response_sla_minutes'] ) ] ];
}
if ( '1' === (string) \Momos\Notification\Admin\SettingsFields::get( 'momo_res_show_dont_travel', '1' ) ) {
	$blocks[] = [ 'type' => 'p', 'text' => $t( 'res.submitted.dont_travel' ), 'strong' => true ];
}
if ( '' !== $d['status_url'] ) {
	$blocks[] = [ 'type' => 'p', 'text' => $t( 'email.e01.cancel_hint' ) ];
}
$policy = \Momos\Notification\Template\Policy::block( $s, $d['lang'], $t );
if ( $policy ) {
	$blocks[] = $policy;
}
if ( $d['note'] ) {
	$blocks[] = [ 'type' => 'p', 'text' => $t( 'email.common.note_received' ) ];
}

return [
	'subject_key'  => 'email.subject.e01',
	'subject_args' => [ 'brand' => $d['brand'], 'ref' => $d['ref'] ],
	'headline'     => $t( 'status.pending' ),
	'tone'         => 'pending',
	'blocks'       => $blocks,
	'button'       => '' !== $d['status_url'] ? [ 'label' => $t( 'res.submitted.status_link' ), 'url' => $d['status_url'] ] : null,
];
