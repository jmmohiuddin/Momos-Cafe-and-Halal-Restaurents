<?php
/**
 * SCR-E08 (spec §3.4/§5.3): `sla_reminder` (branch + handled_by), `sla_escalation` (HO list),
 * `notify_failed` (branch recipients after a final customer-email failure, US-NOT-005). Waiting time
 * {n} = minutes from the request to the time this notification was queued.
 *
 * @var array<string,mixed> $d
 * @var callable            $t
 */

use Momos\Kernel\Clock\Clock;
use Momos\Notification\Repository\NotificationRepository;

defined( 'ABSPATH' ) || exit;

$r       = $d['reservation'];
$variant = in_array( $d['variant'], [ 'sla_reminder', 'sla_escalation', 'notify_failed' ], true ) ? $d['variant'] : 'sla_reminder';
$created = Clock::from_mysql_utc( (string) $r['created_at'] );
$n       = $created ? max( 0, (int) floor( ( $d['created_utc']->getTimestamp() - $created->getTimestamp() ) / 60 ) ) : 0;
$rows    = include __DIR__ . '/_staff-rows.php';
$blocks  = [];
if ( 'sla_reminder' === $variant ) {
	$blocks[] = [ 'type' => 'p', 'text' => $t( 'staff.email.e08.reminder' ), 'args' => [ 'n' => $n ] ];
} elseif ( 'sla_escalation' === $variant ) {
	$blocks[] = [ 'type' => 'p', 'text' => $t( 'staff.email.e08.escalation' ), 'args' => [ 'branch' => trim( $d['branch']['code'] . ' ' . $d['branch']['name'] ), 'n' => $n ] ];
} else {
	$failed   = ! empty( $d['row']['object_id'] ) ? NotificationRepository::find( (int) $d['row']['object_id'] ) : null;
	$blocks[] = [ 'type' => 'p', 'text' => $t( 'staff.warn.customer_not_notified' ), 'strong' => true ];
	if ( $failed ) {
		$blocks[] = [ 'type' => 'p', 'text' => $t( 'staff.email.e08.failed_template' ), 'args' => [ 'template_name' => $t( 'staff.email.tpl.' . $failed['template_key'] ), 'error' => (string) $failed['last_error_code'] ] ];
	}
}
$blocks[] = $rows;
$subject  = [ 'sla_reminder' => 'staff.email.subject.e08.reminder', 'sla_escalation' => 'staff.email.subject.e08.escalation', 'notify_failed' => 'staff.email.subject.e08.failed' ][ $variant ];

return [
	'subject_key'  => $subject,
	'subject_args' => [ 'branch_code' => $d['branch']['code'], 'ref' => $d['ref'], 'n' => $n ],
	'headline'     => $t( 'staff.email.headline.e08.' . $variant ),
	'blocks'       => $blocks,
	'button'       => [ 'label' => $t( 'staff.email.open_admin' ), 'url' => $d['admin_url'] ],
];
