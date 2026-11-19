<?php
/**
 * SCR-E11 Certificate expiry `cert_30` / `cert_7` / `cert_expired` (FR-DIET-002(c), CMP-014; spec
 * §4.9). HO list + CAP-DCA holders; no customer data; independent of E10.
 *
 * @var array<string,mixed> $d
 * @var callable            $t
 */

use Momos\Notification\Template\EmailFormatter;
use Momos\Notification\Template\RenderException;

defined( 'ABSPATH' ) || exit;

$cert_id = (int) ( $d['row']['object_id'] ?? 0 );
$post    = $cert_id ? get_post( $cert_id ) : null;
if ( ! $post ) {
	throw new RenderException( 'certificate not found' );
}
$m        = static fn( string $k ): string => (string) get_post_meta( $cert_id, $k, true );
$variant  = in_array( $d['variant'], [ 'cert_30', 'cert_7', 'cert_expired' ], true ) ? $d['variant'] : 'cert_30';
$number   = '' !== $m( 'momo_cert_number' ) ? $m( 'momo_cert_number' ) : '#' . $cert_id;
$until    = $m( 'momo_cert_valid_until' );
$date     = '' !== $until ? EmailFormatter::date_long( $until, $d['lang'] ) : '—';
$branches = [];
foreach ( array_filter( array_map( 'intval', (array) get_post_meta( $cert_id, 'momo_cert_branches', true ) ) ) as $b ) {
	$branches[] = trim( get_post_meta( $b, 'momo_branch_code', true ) . ' ' . get_the_title( $b ) );
}
$rows     = [
	[ $t( 'staff.email.label.cert_no' ), $number ],
	[ $t( 'staff.email.label.certifier' ), $m( 'momo_cert_certifier_name' ) ],
	[ $t( 'staff.email.label.claim' ), $m( 'momo_cert_claim_type' ) ],
	[ $t( 'staff.email.label.scope' ), $m( 'momo_cert_scope' ) ],
	[ $t( 'staff.email.label.branches' ), $branches ? implode( ', ', $branches ) : '—' ],
	[ $t( 'staff.email.label.valid_until' ), $date ],
];
$rows     = array_values( array_filter( $rows, static fn( $r ) => '' !== (string) $r[1] ) );
$blocks   = 'cert_expired' === $variant
	? [ [ 'type' => 'p', 'text' => $t( 'staff.email.e11.expired' ), 'args' => [ 'cert_no' => $number ], 'strong' => true ] ]
	: [ [ 'type' => 'p', 'text' => $t( 'staff.email.e11.warning' ), 'args' => [ 'cert_no' => $number, 'certifier' => $m( 'momo_cert_certifier_name' ), 'date' => $date ], 'strong' => true ] ];
$blocks[] = [ 'type' => 'rows', 'rows' => $rows ];

return [
	'subject_key'  => 'cert_expired' === $variant ? 'staff.email.subject.e11.expired' : 'staff.email.subject.e11.warning',
	'subject_args' => [ 'cert_no' => $number, 'date' => $date ],
	'headline'     => $t( 'cert_expired' === $variant ? 'staff.email.headline.e11.expired' : 'staff.email.headline.e11.warning' ),
	'blocks'       => $blocks,
	'button'       => [ 'label' => $t( 'staff.email.open_admin' ), 'url' => admin_url( 'post.php?post=' . $cert_id . '&action=edit' ) ],
];
