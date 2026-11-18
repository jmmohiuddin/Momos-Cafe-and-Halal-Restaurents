<?php
declare(strict_types=1);

namespace Momos\Dietary\Domain;

/**
 * Claim/certificate record status and its state machine (Doc 11 §8.3, spec §2.8):
 * pending_verification → active | revoked; active → pending_verification | expired | revoked;
 * expired and revoked are terminal (renewal = new record).
 */
enum ClaimStatus: string {
	case PendingVerification = 'pending_verification';
	case Active              = 'active';
	case Expired             = 'expired';
	case Revoked             = 'revoked';

	/** @return array<string,string> */
	public static function choices(): array {
		return [
			'pending_verification' => __( 'Pending verification', 'momos-core' ),
			'active'               => __( 'Active', 'momos-core' ),
			'expired'              => __( 'Expired', 'momos-core' ),
			'revoked'              => __( 'Revoked', 'momos-core' ),
		];
	}

	public function can_transition_to( self $to ): bool {
		return match ( $this ) {
			self::PendingVerification => in_array( $to, [ self::Active, self::Revoked ], true ),
			self::Active              => in_array( $to, [ self::PendingVerification, self::Expired, self::Revoked ], true ),
			self::Expired, self::Revoked => false,
		};
	}
}
