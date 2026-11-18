<?php
declare(strict_types=1);

namespace Momos\Dietary\Service;

use Momos\Dietary\Storage\EvidenceStore;
use Momos\Kernel\Clock\Clock;

/**
 * Activation rules of a claim/certificate record (FR-DIET-001, CMP-011, Doc 11 §8.2, spec §5.7,
 * TC-DIET-002/024): evidence complete, source document (person 1), validity, linked branches/dishes,
 * covered ingredients, mark permission files, approved statement of the same claim type.
 */
final class ClaimRecordValidator {
	/** @return list<string> staff messages (empty = may be activated) */
	public static function activation_errors( int $cert_id, ?string $today = null ): array {
		$today  = $today ?? Clock::jst()->format( 'Y-m-d' );
		$m      = static fn( string $k ) => get_post_meta( $cert_id, $k, true );
		$type   = (string) $m( 'momo_cert_claim_type' );
		$scope  = (string) $m( 'momo_cert_scope' );
		$from   = (string) $m( 'momo_cert_valid_from' );
		$until  = (string) $m( 'momo_cert_valid_until' );
		$errors = [];

		if ( ! in_array( $type, [ 'certified', 'halal_ingredients', 'no_claim' ], true ) ) {
			return [ __( 'Choose a claim type.', 'momos-core' ) ];
		}
		$has_evidence = EvidenceStore::is_valid_record( $m( 'momo_cert_evidence' ) );
		if ( 'certified' === $type ) {
			if ( '' === trim( (string) $m( 'momo_cert_certifier_name' ) ) || '' === trim( (string) $m( 'momo_cert_number' ) ) || '' === $from || '' === $until || ! $has_evidence || '' === $scope ) {
				$errors[] = __( 'Certified records need certifier, certificate number, validity dates and an evidence file.', 'momos-core' );
			}
		}
		if ( 'halal_ingredients' === $type && ! $has_evidence ) {
			$errors[] = __( 'Upload the supplier evidence before activating.', 'momos-core' );
		}
		if ( '' !== $from && '' !== $until && $until < $from ) {
			$errors[] = __( 'Valid until must be on or after valid from.', 'momos-core' );
		}
		if ( '' !== $until && $until < $today ) {
			$errors[] = __( 'This certificate has expired and cannot be activated.', 'momos-core' );
		}
		if ( 'no_claim' !== $type ) {
			if ( '' === trim( (string) $m( 'momo_cert_source_document' ) ) ) {
				$errors[] = __( 'Record the source document (document name/version and person 1) before activating.', 'momos-core' );
			}
			if ( ! array_filter( array_map( 'intval', (array) $m( 'momo_cert_branches' ) ) ) ) {
				$errors[] = __( 'Link at least one branch.', 'momos-core' );
			}
			if ( ( 'halal_ingredients' === $type || 'specific_ingredients' === $scope ) && ! array_filter( array_map( 'intval', (array) $m( 'momo_cert_covered_ingredients' ) ) ) ) {
				$errors[] = __( 'Select the covered ingredients.', 'momos-core' );
			}
			if ( 'certified' === $type && 'specific_menu' === $scope && ! array_filter( array_map( 'intval', (array) $m( 'momo_cert_dishes' ) ) ) ) {
				$errors[] = __( 'Link the certified dishes.', 'momos-core' );
			}
		}
		if ( '1' === (string) $m( 'momo_cert_mark_permission' ) && ( (int) $m( 'momo_cert_mark_image' ) <= 0 || ! EvidenceStore::is_valid_record( $m( 'momo_cert_permission_doc' ) ) ) ) {
			$errors[] = __( 'Upload the mark image and the permission document.', 'momos-core' );
		}
		$key = (string) $m( 'momo_cert_statement_key' );
		$row = '' !== $key ? StatementRepository::get( $key ) : null;
		if ( null === $row || ! $row['approved'] || $row['claim_type'] !== $type || ( 'certified' === $type && 'certified.' . $scope !== $key ) ) {
			$errors[] = __( 'Choose an approved statement template of the same claim type and scope.', 'momos-core' );
		}
		return $errors;
	}
}
