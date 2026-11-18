<?php
/**
 * SCR-E06 Details updated (FR-RES-015, spec §4.7). Variants `pending` / `confirmed` (by status after
 * the change) show before → after rows marked "(changed)" from the latest `modified` event;
 * `new_link` sends the re-issued status link (SDD §5.13). Pending wording never says "reservation".
 *
 * @var array<string,mixed> $d
 * @var callable            $t
 */

use Momos\Notification\Support\ReservationReader;
use Momos\Notification\Template\EmailFormatter;
use Momos\Notification\Template\Renderer;

defined( 'ABSPATH' ) || exit;

$r       = $d['reservation'];
$variant = in_array( $d['variant'], [ 'pending', 'confirmed', 'new_link' ], true ) ? $d['variant'] : 'pending';
$status  = (string) $r['status'];
$label   = in_array( $status, [ 'pending', 'confirmed', 'rejected', 'cancelled', 'expired' ], true ) ? $status : 'confirmed';
$blocks  = [];
if ( 'new_link' === $variant ) {
	$blocks[] = [ 'type' => 'p', 'text' => $t( 'email.e06.new_link' ) ];
} else {
	$event  = ReservationReader::latest_event( (int) $r['id'], 'modified' );
	$before = is_array( $event['payload']['before'] ?? null ) ? $event['payload']['before'] : [];
	$after  = is_array( $event['payload']['after'] ?? null ) ? $event['payload']['after'] : [];
	$pick   = static fn( array $a, string $k1, string $k2 ) => (string) ( $a[ $k1 ] ?? $a[ $k2 ] ?? '' );
	$rows   = [];
	$fields = [
		'date'  => [ 'requested_date', 'date', 'email.details.date', static fn( $v ) => EmailFormatter::date_long( $v, $d['lang'] ) ],
		'time'  => [ 'requested_time', 'time', 'email.details.time', static fn( $v ) => EmailFormatter::customer_time( $v, $d['lang'], $d['jst'] ) ],
		'party' => [ 'party_size', 'party', 'email.details.party', static fn( $v ) => Renderer::party( (int) $v ) ],
	];
	foreach ( $fields as [ $k1, $k2, $lbl, $fmt ] ) {
		$b = $pick( $before, $k1, $k2 );
		$a = $pick( $after, $k1, $k2 );
		if ( '' !== $b && '' !== $a && $b !== $a ) {
			$rows[] = [ $t( $lbl ), $fmt( $b ), $fmt( $a ) ];
		}
	}
	if ( $rows ) {
		$blocks[] = [ 'type' => 'changes', 'rows' => $rows, 'marker' => $t( 'email.e06.changed' ) ];
	}
	$blocks[] = [ 'type' => 'p', 'text' => $t( 'email.e06.reason' ) ];
}
// `new_link` follows the current status so a pending request is never called a reservation.
$subject = 'confirmed' === $variant || ( 'new_link' === $variant && 'confirmed' === $status ) ? 'email.subject.e06.confirmed' : 'email.subject.e06.pending';
$button_label = 'confirmed' === $variant ? $t( 'email.cta.view_reservation' ) : $t( 'res.submitted.status_link' );

return [
	'subject_key'  => $subject,
	'subject_args' => [ 'brand' => $d['brand'], 'ref' => $d['ref'] ],
	'headline'     => $t( 'status.' . $label ),
	'tone'         => 'confirmed' === $label ? 'confirmed' : ( 'pending' === $label ? 'pending' : 'neutral' ),
	'blocks'       => $blocks,
	'button'       => '' !== $d['status_url'] ? [ 'label' => $button_label, 'url' => $d['status_url'] ] : null,
];
