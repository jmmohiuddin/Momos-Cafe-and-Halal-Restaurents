<?php
/**
 * Minimal staff reservation rows (PRV-008 / DEC-017): reference, branch, date, time, party size,
 * given name (≤ 20 chars) — never email, phone, surname, special-request text or notes.
 *
 * @var array<string,mixed> $d
 * @var callable            $t
 */

defined( 'ABSPATH' ) || exit;

$rows = [
	[ $t( 'staff.email.label.ref' ), $d['ref'] ],
	[ $t( 'staff.email.label.branch' ), trim( $d['branch']['code'] . ' ' . $d['branch']['name'] ) ],
	[ $t( 'staff.email.label.date' ), $d['date'] ],
	[ $t( 'staff.email.label.time' ), $d['time'] ],
	[ $t( 'staff.email.label.party' ), $d['party'] ],
];
if ( '' !== (string) ( $d['given_name'] ?? '' ) ) {
	$rows[] = [ $t( 'staff.email.label.name' ), $d['given_name'] ];
}
return [ 'type' => 'rows', 'rows' => $rows ];
