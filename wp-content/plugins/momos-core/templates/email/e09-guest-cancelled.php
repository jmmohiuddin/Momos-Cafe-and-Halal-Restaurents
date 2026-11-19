<?php
/**
 * SCR-E09 Guest cancelled (US-NOT-006, spec §5.3): date/time, party, given name, cancelled at.
 *
 * @var array<string,mixed> $d
 * @var callable            $t
 */

use Momos\Kernel\Clock\Clock;
use Momos\Notification\Template\EmailFormatter;

defined( 'ABSPATH' ) || exit;

$r         = $d['reservation'];
$cancelled = Clock::from_mysql_utc( (string) ( $r['cancelled_at'] ?? '' ) ) ?? $d['created_utc'];

return [
	'subject_key'  => 'staff.email.subject.e09',
	'subject_args' => [ 'branch_code' => $d['branch']['code'], 'ref' => $d['ref'] ],
	'headline'     => $t( 'staff.email.headline.e09' ),
	'blocks'       => [
		[ 'type' => 'p', 'text' => $t( 'staff.email.e09.intro' ), 'args' => [ 'time' => EmailFormatter::staff_datetime( $cancelled, $d['lang'] ) ] ],
		require __DIR__ . '/_staff-rows.php',
	],
	'button'       => [ 'label' => $t( 'staff.email.open_admin' ), 'url' => $d['admin_url'] ],
];
