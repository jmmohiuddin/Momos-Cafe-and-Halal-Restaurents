<?php
/**
 * SCR-E10 Daily summary (spec §4.8). Sections are recomputed at render time for the window ending
 * at the row's created_at (no body stored). Counts + references + date/time/party only.
 *
 * @var array<string,mixed> $d
 * @var callable            $t
 */

use Momos\Notification\Job\DigestJob;

defined( 'ABSPATH' ) || exit;

$variant  = 'head_office' === $d['variant'] ? 'head_office' : 'branch';
$branch   = 'branch' === $variant ? (int) ( $d['row']['branch_id'] ?? $d['row']['object_id'] ?? 0 ) : null;
$run      = $d['created_utc'];
$sections = DigestJob::sections( $branch, $run );
$blocks   = [ [ 'type' => 'p', 'text' => $t( 'staff.email.e10.intro' ) ] ];
$empty    = true;
foreach ( $sections as $s ) {
	if ( 0 === (int) $s['count'] ) {
		continue;
	}
	$empty    = false;
	$title    = $t( 'staff.email.e10.section.' . $s['key'] );
	$blocks[] = [
		'type'  => 'section',
		'title' => '' !== $s['code'] ? '[' . $s['code'] . '] ' . $title : $title,
		'count' => (int) $s['count'],
		'items' => array_slice( $s['items'], 0, 20 ),
		'link'  => $s['link'] ? [ $t( 'staff.email.e10.view_list' ), $s['link'] ] : null,
	];
}
if ( $empty ) {
	$blocks[] = [ 'type' => 'p', 'text' => $t( 'staff.email.e10.nothing' ) ];
}
$date = $run->setTimezone( new \DateTimeZone( 'Asia/Tokyo' ) )->format( 'Y-m-d' );

return [
	'subject_key'  => 'head_office' === $variant ? 'staff.email.subject.e10.head_office' : 'staff.email.subject.e10.branch',
	'subject_args' => [ 'branch_code' => $d['branch']['code'], 'brand' => $d['brand'], 'date' => $date ],
	'headline'     => $t( 'staff.email.headline.e10' ),
	'blocks'       => $blocks,
	'button'       => [ 'label' => $t( 'staff.email.open_admin' ), 'url' => admin_url( 'admin.php?page=momos-reservations' . ( $branch ? '&branch=' . $branch : '' ) ) ],
];
