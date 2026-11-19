<?php
/**
 * SCR-E07 New request to the branch recipients (US-NOT-004, FR-NOT-002, spec §5.3). Subject omits
 * date/party (C3). "Special request: yes/no" indicator only, never the text.
 *
 * @var array<string,mixed> $d
 * @var callable            $t
 */

use Momos\Kernel\Clock\Clock;
use Momos\Kernel\I18n\Lang;
use Momos\Notification\Template\EmailFormatter;
use Momos\Notification\Template\Renderer;

defined( 'ABSPATH' ) || exit;

$r              = $d['reservation'];
$lang           = $d['lang'];
$s              = $d['settings'];
$rows           = include __DIR__ . '/_staff-rows.php';
$plang          = (string) ( $r['preferred_lang'] ?? 'ja' );
$rows['rows'][] = [ $t( 'staff.email.label.lang' ), Lang::native( $plang ) ];
$created        = Clock::from_mysql_utc( (string) $r['created_at'] );
if ( $created ) {
	$rows['rows'][] = [ $t( 'staff.email.label.created' ), EmailFormatter::staff_datetime( $created, $lang ) ];
}
$start = Clock::from_mysql_utc( (string) $r['requested_start_utc'] );
if ( $start ) {
	$rows['rows'][] = [ $t( 'staff.email.label.deadline' ), EmailFormatter::staff_datetime( $start->modify( '-' . (int) $s['decision_cutoff_minutes'] . ' minutes' ), $lang ) ];
}
$blocks = [
	[ 'type' => 'p', 'text' => $t( 'staff.email.e07.intro' ), 'args' => [ 'branch' => '' !== $d['branch']['name'] ? $d['branch']['name'] : $d['branch']['code'] ] ],
	$rows,
	[ 'type' => 'p', 'text' => $t( 'staff.email.special_request' ), 'args' => [ 'answer' => $t( $d['note'] ? 'staff.email.answer.yes' : 'staff.email.answer.no' ) ] ],
];
if ( ! empty( $s['show_response_promise'] ) && (int) $s['response_sla_minutes'] > 0 ) {
	$blocks[] = [ 'type' => 'p', 'text' => $t( 'staff.email.e07.target' ), 'args' => [ 'duration' => EmailFormatter::minutes( (int) $s['response_sla_minutes'], $lang ) ] ];
}
if ( $d['carrier'] ) {
	$blocks[] = [ 'type' => 'p', 'text' => $t( 'staff.email.carrier' ) ];
}

return [
	'subject_key'  => 'staff.email.subject.e07',
	'subject_args' => [ 'branch_code' => $d['branch']['code'], 'ref' => $d['ref'] ],
	'headline'     => $t( 'staff.email.headline.e07' ),
	'blocks'       => $blocks,
	'button'       => [ 'label' => $t( 'staff.email.open_admin' ), 'url' => $d['admin_url'] ],
];
