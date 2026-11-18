<?php
declare(strict_types=1);

namespace Momos\Reservation\Domain;

/**
 * Branch scope of a staff query (BR-RES-02, spec §4 "every list, count, search, export and heartbeat
 * query goes through ReservationRepository with an AccessContext"). Repository list/count methods
 * require a Scope; there is no unscoped variant for staff code paths.
 */
final class Scope {
	/** @param list<int>|null $branch_ids null = all branches */
	private function __construct( public readonly ?array $branch_ids, public readonly int $user_id ) {}

	/** Scope of a WordPress user (assigned branches, or all with momo_access_all_branches). */
	public static function for_user( int $user_id ): self {
		$ids = $user_id ? momos_user_branch_ids( $user_id ) : [];
		return new self( null === $ids ? null : array_values( array_map( 'intval', $ids ) ), $user_id );
	}

	/** System scope (jobs, CLI). Never used for a staff request. */
	public static function system(): self {
		return new self( null, 0 );
	}

	public function is_all(): bool {
		return null === $this->branch_ids;
	}

	public function allows( int $branch_id ): bool {
		return null === $this->branch_ids || in_array( $branch_id, $this->branch_ids, true );
	}

	/**
	 * Intersect with an explicit branch filter.
	 *
	 * @param list<int>|null $filter
	 * @return list<int>|null
	 */
	public function restrict( ?array $filter ): ?array {
		if ( null === $filter ) {
			return $this->branch_ids;
		}
		if ( null === $this->branch_ids ) {
			return array_values( $filter );
		}
		return array_values( array_intersect( $filter, $this->branch_ids ) );
	}
}
