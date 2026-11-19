<?php
declare(strict_types=1);

namespace Momos\Notification\Template;

/**
 * SCR-E map (spec §3.4): template key → body file in templates/email, audience, variants and the
 * subject string key per variant (spec §5.1).
 */
final class TemplateRegistry {
	public const CUSTOMER = [ 'E01', 'E02', 'E03', 'E04', 'E05', 'E06' ];
	public const STAFF    = [ 'E07', 'E08', 'E09', 'E10', 'E11' ];

	/** @var array<string, array{slug:string,audience:string,variants:list<string>,needs_reservation:bool}> */
	private const DEFS = [
		'E01' => [ 'slug' => 'e01-received', 'audience' => 'customer', 'variants' => [], 'needs_reservation' => true ],
		'E02' => [ 'slug' => 'e02-confirmed', 'audience' => 'customer', 'variants' => [], 'needs_reservation' => true ],
		'E03' => [ 'slug' => 'e03-declined', 'audience' => 'customer', 'variants' => [], 'needs_reservation' => true ],
		'E04' => [ 'slug' => 'e04-cancelled', 'audience' => 'customer', 'variants' => [ 'by_customer', 'by_branch' ], 'needs_reservation' => true ],
		'E05' => [ 'slug' => 'e05-expired', 'audience' => 'customer', 'variants' => [], 'needs_reservation' => true ],
		'E06' => [ 'slug' => 'e06-updated', 'audience' => 'customer', 'variants' => [ 'pending', 'confirmed', 'new_link' ], 'needs_reservation' => true ],
		'E07' => [ 'slug' => 'e07-new-request', 'audience' => 'staff', 'variants' => [], 'needs_reservation' => true ],
		'E08' => [ 'slug' => 'e08-attention', 'audience' => 'staff', 'variants' => [ 'sla_reminder', 'sla_escalation', 'notify_failed' ], 'needs_reservation' => true ],
		'E09' => [ 'slug' => 'e09-guest-cancelled', 'audience' => 'staff', 'variants' => [], 'needs_reservation' => true ],
		'E10' => [ 'slug' => 'e10-digest', 'audience' => 'staff', 'variants' => [ 'branch', 'head_office' ], 'needs_reservation' => false ],
		'E11' => [ 'slug' => 'e11-certificate-expiry', 'audience' => 'staff', 'variants' => [ 'cert_30', 'cert_7', 'cert_expired' ], 'needs_reservation' => false ],
	];

	/** @return array{slug:string,audience:string,variants:list<string>,needs_reservation:bool}|null */
	public static function get( string $key ): ?array {
		return self::DEFS[ $key ] ?? null;
	}

	/** @return list<string> */
	public static function keys(): array {
		return array_keys( self::DEFS );
	}

	public static function is_customer( string $key ): bool {
		return in_array( $key, self::CUSTOMER, true );
	}

	/** Admin label (SCR-A09 panel, log view): "E02 Confirmed" / 「E02 確定のお知らせ」. */
	public static function label( string $key, ?string $variant = null ): string {
		$names = [
			'E01' => __( 'Request received', 'momos-core' ),
			'E02' => __( 'Confirmed', 'momos-core' ),
			'E03' => __( 'Declined', 'momos-core' ),
			'E04' => __( 'Cancelled', 'momos-core' ),
			'E05' => __( 'Expired', 'momos-core' ),
			'E06' => __( 'Details updated', 'momos-core' ),
			'E07' => __( 'New request (staff)', 'momos-core' ),
			'E08' => __( 'Reminder / escalation / not notified (staff)', 'momos-core' ),
			'E09' => __( 'Guest cancelled (staff)', 'momos-core' ),
			'E10' => __( 'Daily summary', 'momos-core' ),
			'E11' => __( 'Certificate expiry', 'momos-core' ),
		];
		$label = $key . ' ' . ( $names[ $key ] ?? '' );
		return null !== $variant && '' !== $variant ? $label . ' (' . $variant . ')' : $label;
	}
}
