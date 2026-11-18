<?php
declare(strict_types=1);

namespace Momos\Dietary\Service;

use Momos\Dietary\ContentTypes;
use Momos\Dietary\Domain\ClaimStatus;
use Momos\Dietary\Guard\ClaimFieldGuard;
use Momos\Dietary\Storage\EvidenceStore;
use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Events\AfterCommit;
use Momos\Menu\Support\CachePurge;

/**
 * Claim/certificate record lifecycle (FR-DIET-001/002, Doc 11 §8.3, spec §2.8/§3.14/§3.16):
 * activate (stamps verified_by/at), revoke (reason), re-attest (quarterly re-stamp), suspend on
 * material change (active → pending_verification hides wording immediately), expire (job).
 * Only users with momo_manage_certificates + CAP-DCA act; every transition is audited
 * `certificate.status_changed` and emits `momos/certificate/status_changed (int $id, string $from, string $to)`.
 */
final class CertificateService {
	/** Fields whose change on an active record requires re-verification. */
	public const MATERIAL = [ 'momo_cert_claim_type', 'momo_cert_scope', 'momo_cert_certifier_name', 'momo_cert_number', 'momo_cert_valid_from', 'momo_cert_valid_until', 'momo_cert_covered_ingredients', 'momo_cert_branches', 'momo_cert_dishes', 'momo_cert_statement_key', 'momo_cert_evidence', 'momo_cert_source_document' ];

	public static function can_manage( int $user_id ): bool {
		return user_can( $user_id, 'momo_manage_certificates' ) && user_can( $user_id, ClaimFieldGuard::CAP );
	}

	public static function status( int $cert_id ): ClaimStatus {
		return ClaimStatus::tryFrom( (string) get_post_meta( $cert_id, 'momo_cert_status', true ) ) ?? ClaimStatus::PendingVerification;
	}

	/** Displayable (CMP-013): active ∧ valid_from ≤ today_JST ≤ valid_until ∧ verified_by set. */
	public static function is_displayable( int $cert_id, ?string $today = null ): bool {
		if ( $cert_id <= 0 || ContentTypes::CERTIFICATE !== get_post_type( $cert_id ) || ClaimStatus::Active !== self::status( $cert_id ) ) {
			return false;
		}
		$today = $today ?? Clock::jst()->format( 'Y-m-d' );
		$from  = (string) get_post_meta( $cert_id, 'momo_cert_valid_from', true );
		$until = (string) get_post_meta( $cert_id, 'momo_cert_valid_until', true );
		if ( 'certified' === get_post_meta( $cert_id, 'momo_cert_claim_type', true ) && ( '' === $from || '' === $until ) ) {
			return false;
		}
		if ( ( '' !== $from && $today < $from ) || ( '' !== $until && $today > $until ) ) {
			return false;
		}
		return (int) get_post_meta( $cert_id, 'momo_cert_verified_by', true ) > 0;
	}

	/** Mark additionally needs permission, mark image and permission document. */
	public static function mark_allowed( int $cert_id, ?string $today = null ): bool {
		return self::is_displayable( $cert_id, $today )
			&& '1' === (string) get_post_meta( $cert_id, 'momo_cert_mark_permission', true )
			&& (int) get_post_meta( $cert_id, 'momo_cert_mark_image', true ) > 0
			&& EvidenceStore::is_valid_record( get_post_meta( $cert_id, 'momo_cert_permission_doc', true ) );
	}

	/** @return list<int> */
	public static function branch_ids( int $cert_id ): array {
		return array_values( array_filter( array_map( 'intval', (array) get_post_meta( $cert_id, 'momo_cert_branches', true ) ) ) );
	}

	/** @return list<int> */
	public static function dish_ids( int $cert_id ): array {
		return array_values( array_filter( array_map( 'intval', (array) get_post_meta( $cert_id, 'momo_cert_dishes', true ) ) ) );
	}

	public static function record_links_branch( int $cert_id, int $branch_id, string $claim_type ): bool {
		return $cert_id > 0 && ContentTypes::CERTIFICATE === get_post_type( $cert_id ) && ClaimStatus::Active === self::status( $cert_id )
			&& get_post_meta( $cert_id, 'momo_cert_claim_type', true ) === $claim_type && in_array( $branch_id, self::branch_ids( $cert_id ), true );
	}

	public static function record_links_dish( int $cert_id, int $dish_id, string $claim_type, ?string $scope ): bool {
		return $cert_id > 0 && ContentTypes::CERTIFICATE === get_post_type( $cert_id ) && ClaimStatus::Active === self::status( $cert_id )
			&& get_post_meta( $cert_id, 'momo_cert_claim_type', true ) === $claim_type
			&& ( null === $scope || get_post_meta( $cert_id, 'momo_cert_scope', true ) === $scope )
			&& in_array( $dish_id, self::dish_ids( $cert_id ), true );
	}

	/** @throws \DomainException listing the reasons */
	public static function activate( int $cert_id, int $user_id ): void {
		self::authorize( $cert_id, $user_id, 'activate' );
		$errors = ClaimRecordValidator::activation_errors( $cert_id );
		if ( ClaimStatus::PendingVerification !== self::status( $cert_id ) ) {
			$errors[] = __( 'Only records pending verification can be activated.', 'momos-core' );
		}
		if ( $errors ) {
			throw new \DomainException( implode( "\n", $errors ) );
		}
		ClaimFieldGuard::system(
			static function () use ( $cert_id, $user_id ): void {
				update_post_meta( $cert_id, 'momo_cert_verified_by', $user_id );
				update_post_meta( $cert_id, 'momo_cert_verified_at', Clock::mysql_utc() );
			}
		);
		self::transition( $cert_id, ClaimStatus::Active, [ 'by' => $user_id ] );
	}

	/** @throws \DomainException */
	public static function revoke( int $cert_id, string $reason, int $user_id ): void {
		self::authorize( $cert_id, $user_id, 'revoke' );
		$reason = trim( sanitize_text_field( $reason ) );
		if ( '' === $reason || mb_strlen( $reason ) > 255 ) {
			throw new \DomainException( __( 'Enter the reason for revoking (max. 255 characters).', 'momos-core' ) );
		}
		ClaimFieldGuard::system( static fn() => update_post_meta( $cert_id, 'momo_cert_revocation_reason', $reason ) );
		self::transition( $cert_id, ClaimStatus::Revoked, [ 'by' => $user_id ] );
	}

	/** Quarterly re-attestation: re-stamps verified_at of an active record (spec §11 C14). */
	public static function reattest( int $cert_id, int $user_id ): void {
		self::authorize( $cert_id, $user_id, 'reattest' );
		if ( ClaimStatus::Active !== self::status( $cert_id ) ) {
			throw new \DomainException( __( 'Only active records can be re-verified.', 'momos-core' ) );
		}
		$before = (string) get_post_meta( $cert_id, 'momo_cert_verified_at', true );
		ClaimFieldGuard::system(
			static function () use ( $cert_id, $user_id ): void {
				update_post_meta( $cert_id, 'momo_cert_verified_by', $user_id );
				update_post_meta( $cert_id, 'momo_cert_verified_at', Clock::mysql_utc() );
			}
		);
		momos_audit( 'certificate.updated', 'certificate', $cert_id, null, [ 'reattested' => true, 'verified_at' => [ $before, get_post_meta( $cert_id, 'momo_cert_verified_at', true ) ] ] );
		self::purge( $cert_id );
	}

	/** Active → pending_verification (supplier/recipe/scope change; hides wording immediately). */
	public static function suspend( int $cert_id, string $why ): void {
		if ( ClaimStatus::Active === self::status( $cert_id ) ) {
			self::transition( $cert_id, ClaimStatus::PendingVerification, [ 'reason' => $why ] );
		}
	}

	/** Job: active → expired, conditional (idempotent). */
	public static function expire( int $cert_id ): bool {
		if ( ClaimStatus::Active !== self::status( $cert_id ) ) {
			return false;
		}
		self::transition( $cert_id, ClaimStatus::Expired, [ 'by' => 'system' ], 'certificate.expired', 'system' );
		return true;
	}

	/** @param list<string> $changed field names changed in the G14 save */
	public static function after_fields_changed( int $cert_id, array $changed ): void {
		if ( array_intersect( $changed, self::MATERIAL ) ) {
			self::suspend( $cert_id, 'material_change' );
		}
		self::purge( $cert_id );
	}

	/**
	 * Attach an evidence or permission document (already stored by EvidenceStore).
	 *
	 * @param array<string,mixed> $record
	 */
	public static function attach_document( int $cert_id, string $kind, array $record, int $user_id ): void {
		self::authorize( $cert_id, $user_id, 'upload' );
		$key = 'permission' === $kind ? 'momo_cert_permission_doc' : 'momo_cert_evidence';
		ClaimFieldGuard::system( static fn() => update_post_meta( $cert_id, $key, $record ) );
		momos_audit( 'certificate.updated', 'certificate', $cert_id, null, [ 'document' => $kind, 'sha256' => $record['sha256'] ?? '', 'mime' => $record['mime'] ?? '' ] );
		if ( 'evidence' === $kind ) {
			self::suspend( $cert_id, 'evidence_replaced' );
		}
		self::purge( $cert_id );
	}

	/** @param array<string,mixed> $context */
	private static function transition( int $cert_id, ClaimStatus $to, array $context, string $action = 'certificate.status_changed', string $actor = 'staff' ): void {
		$from = self::status( $cert_id );
		if ( ! $from->can_transition_to( $to ) ) {
			throw new \DomainException( __( 'This status change is not allowed.', 'momos-core' ) );
		}
		ClaimFieldGuard::system( static fn() => update_post_meta( $cert_id, 'momo_cert_status', $to->value ) );
		momos_audit( $action, 'certificate', $cert_id, null, [ 'from' => $from->value, 'to' => $to->value ] + $context, $actor );
		if ( 'certificate.expired' === $action ) {
			momos_audit( 'certificate.status_changed', 'certificate', $cert_id, null, [ 'from' => $from->value, 'to' => $to->value ] + $context, $actor );
		}
		AfterCommit::dispatch( 'momos/certificate/status_changed', $cert_id, $from->value, $to->value );
		AfterCommit::dispatch( 'momos/dietary/claims_changed', 'certificate', $cert_id );
		self::purge( $cert_id );
	}

	private static function authorize( int $cert_id, int $user_id, string $what ): void {
		if ( ContentTypes::CERTIFICATE !== get_post_type( $cert_id ) ) {
			throw new \DomainException( __( 'Record not found.', 'momos-core' ) );
		}
		if ( ! self::can_manage( $user_id ) ) {
			momos_audit( 'access.denied', 'certificate', $cert_id, null, [ 'reason' => 'cap_dca_required', 'action' => $what ] );
			throw new \DomainException( __( 'Only Dietary Claims Approvers can change these fields', 'momos-core' ) );
		}
	}

	private static function purge( int $cert_id ): void {
		CachePurge::claims( self::branch_ids( $cert_id ), self::dish_ids( $cert_id ) );
	}
}
