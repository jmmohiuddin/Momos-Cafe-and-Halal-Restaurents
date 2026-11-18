<?php
declare(strict_types=1);

namespace Momos\Dietary\Admin;

use Momos\Dietary\ContentTypes;
use Momos\Dietary\Guard\ClaimFieldGuard;
use Momos\Dietary\Service\AllergenPresenter;
use Momos\Dietary\Service\CertificateService;
use Momos\Kernel\Clock\Clock;

/**
 * SCR-A12 halal / certificate register — read-only summary list (FR-DIET-001, spec §5.7/§4): SA/HO/MM
 * see all records, Branch Managers only records linked to their assigned branches (M12), never
 * evidence. CAP-DCA holders get edit/revoke/evidence links. Also the re-verification lists of §3.16
 * (expiring ≤ 30 days, verified > 92 days ago, dishes verified > 183 days ago, dishes with unverified
 * allergens or review flag — KPI-14).
 */
final class CertificateRegister {
	public const SLUG = 'momo-halal-register';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ], 22 );
	}

	public static function menu(): void {
		add_submenu_page( 'momos', __( 'Halal register', 'momos-core' ), __( 'Halal register', 'momos-core' ), 'read_private_momo_certificates', self::SLUG, [ self::class, 'render' ] );
	}

	/**
	 * Records visible to the user (branch-scoped for BM).
	 *
	 * @return list<int>
	 */
	public static function visible_ids( int $user_id ): array {
		if ( ! user_can( $user_id, 'read_private_momo_certificates' ) && ! user_can( $user_id, ClaimFieldGuard::CAP ) ) {
			return [];
		}
		$ids   = array_map( 'intval', get_posts( [ 'post_type' => ContentTypes::CERTIFICATE, 'post_status' => [ 'publish', 'draft', 'pending', 'private' ], 'posts_per_page' => 500, 'fields' => 'ids', 'orderby' => 'title', 'order' => 'ASC' ] ) );
		$scope = momos_user_branch_ids( $user_id );
		if ( null === $scope ) {
			return $ids;
		}
		return array_values( array_filter( $ids, static fn( int $id ) => (bool) array_intersect( CertificateService::branch_ids( $id ), $scope ) ) );
	}

	/** @return array<string,mixed> one summary row (no evidence data) */
	public static function summary( int $id ): array {
		$until = (string) get_post_meta( $id, 'momo_cert_valid_until', true );
		$days  = null;
		if ( '' !== $until ) {
			$days = (int) Clock::jst()->setTime( 0, 0 )->diff( new \DateTimeImmutable( $until, Clock::tz() ) )->format( '%r%a' );
		}
		return [
			'id'          => $id,
			'title'       => get_the_title( $id ),
			'claim_type'  => (string) get_post_meta( $id, 'momo_cert_claim_type', true ),
			'certifier'   => (string) get_post_meta( $id, 'momo_cert_certifier_name', true ),
			'scope'       => (string) get_post_meta( $id, 'momo_cert_scope', true ),
			'branches'    => CertificateService::branch_ids( $id ),
			'dishes'      => CertificateService::dish_ids( $id ),
			'valid_until' => $until,
			'days_left'   => $days,
			'status'      => CertificateService::status( $id )->value,
			'verified_at' => (string) get_post_meta( $id, 'momo_cert_verified_at', true ),
			'displayable' => CertificateService::is_displayable( $id ),
		];
	}

	/** @return array{expiring:list<int>,reverify:list<int>,dish_stale:list<int>,dish_unverified:list<int>} */
	public static function reverification_lists(): array {
		$now      = Clock::utc();
		$expiring = [];
		$reverify = [];
		foreach ( get_posts( [ 'post_type' => ContentTypes::CERTIFICATE, 'post_status' => 'any', 'posts_per_page' => 500, 'fields' => 'ids', 'meta_query' => [ [ 'key' => 'momo_cert_status', 'value' => 'active' ] ] ] ) as $id ) { // phpcs:ignore
			$s = self::summary( (int) $id );
			if ( null !== $s['days_left'] && $s['days_left'] <= 30 ) {
				$expiring[] = (int) $id;
			}
			$v = Clock::from_mysql_utc( $s['verified_at'] );
			if ( null === $v || $v < $now->modify( '-92 days' ) ) {
				$reverify[] = (int) $id;
			}
		}
		$stale      = [];
		$unverified = [];
		foreach ( get_posts( [ 'post_type' => 'momo_dish', 'post_status' => 'publish', 'posts_per_page' => 1000, 'fields' => 'ids' ] ) as $d ) {
			$d = (int) $d;
			$v = Clock::from_mysql_utc( (string) get_post_meta( $d, 'momo_claims_verified_at', true ) );
			if ( null !== $v && $v < $now->modify( '-183 days' ) ) {
				$stale[] = $d;
			}
			if ( '1' === (string) get_post_meta( $d, 'momo_allergen_review_required', true ) || AllergenPresenter::not_verified_codes( $d ) ) {
				$unverified[] = $d;
			}
		}
		return [ 'expiring' => $expiring, 'reverify' => $reverify, 'dish_stale' => $stale, 'dish_unverified' => $unverified ];
	}

	public static function render(): void {
		$uid = get_current_user_id();
		if ( ! current_user_can( 'read_private_momo_certificates' ) && ! current_user_can( ClaimFieldGuard::CAP ) ) {
			momos_audit( 'access.denied', 'certificate', null, null, [ 'reason' => 'forbidden', 'screen' => self::SLUG ] );
			wp_die( esc_html__( 'You do not have permission to view this page.', 'momos-core' ), 403 );
		}
		$rows       = array_map( [ self::class, 'summary' ], self::visible_ids( $uid ) );
		$can_manage = CertificateService::can_manage( $uid );
		$scoped     = null !== momos_user_branch_ids( $uid );
		$lists      = $scoped ? null : self::reverification_lists();
		$revoke     = isset( $_GET['revoke'] ) && $can_manage ? (int) $_GET['revoke'] : 0; // phpcs:ignore
		include MOMOS_CORE_DIR . '/templates/admin/dietary-register.php';
	}
}
