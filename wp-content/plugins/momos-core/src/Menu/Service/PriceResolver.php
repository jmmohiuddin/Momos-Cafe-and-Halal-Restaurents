<?php
declare(strict_types=1);

namespace Momos\Menu\Service;

use Momos\Menu\Repository\BranchMenuItemRepository;
use Momos\Menu\Support\Branches;

/**
 * Displayed price (BR-MENU-02, DEC-022/023, spec §3.3): branch override if set, else the dish's
 * reference price; JPY integer, tax-inclusive. Contract: docs/CONTRACTS.md.
 */
final class PriceResolver {
	/**
	 * With a branch: null when the row is missing or not offered, else override ?? reference.
	 * Without a branch: the reference price (null when not set).
	 */
	public static function price( int $dish_id, ?int $branch_id = null ): ?int {
		$reference = BranchMenuService::reference_price( $dish_id );
		if ( null === $branch_id ) {
			return $reference;
		}
		$row = BranchMenuItemRepository::find( $branch_id, $dish_id );
		if ( null === $row || 1 !== (int) $row['is_offered'] ) {
			return null;
		}
		return $row['price_override_jpy'] ?? $reference;
	}

	/**
	 * Effective prices per public branch offering the dish (published, non-TEST, not permanently closed).
	 *
	 * @return array<string,int> branch_code => price
	 */
	public static function prices_by_branch( int $dish_id ): array {
		$reference = BranchMenuService::reference_price( $dish_id );
		$out       = [];
		foreach ( BranchMenuItemRepository::offered_for_dish( $dish_id ) as $row ) {
			$bid = (int) $row['branch_id'];
			if ( ! Branches::is_public( $bid ) ) {
				continue;
			}
			$price = $row['price_override_jpy'] ?? $reference;
			if ( null !== $price ) {
				$out[ Branches::code( $bid ) ] = (int) $price;
			}
		}
		return $out;
	}

	/** Card note "Prices may differ by branch": ≥ 2 distinct values or any differs from the reference. */
	public static function prices_may_differ( int $dish_id ): bool {
		$prices    = array_values( self::prices_by_branch( $dish_id ) );
		$reference = BranchMenuService::reference_price( $dish_id );
		if ( count( array_unique( $prices ) ) >= 2 ) {
			return true;
		}
		foreach ( $prices as $p ) {
			if ( $p !== $reference ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Dish page price block without branch context (FR-MENU-003, Doc 07 §4.3):
	 * mode none (offered nowhere) | single (one price at all branches) | table (per-branch rows).
	 *
	 * @return array{mode:string, price:?int, rows:list<array{branch_id:int,code:string,price:int,row:array<string,mixed>}>}
	 */
	public static function summary( int $dish_id ): array {
		$reference = BranchMenuService::reference_price( $dish_id );
		$rows      = [];
		foreach ( BranchMenuItemRepository::offered_for_dish( $dish_id ) as $row ) {
			$bid = (int) $row['branch_id'];
			if ( ! Branches::is_public( $bid ) ) {
				continue;
			}
			$price = $row['price_override_jpy'] ?? $reference;
			if ( null === $price ) {
				continue;
			}
			$rows[] = [ 'branch_id' => $bid, 'code' => Branches::code( $bid ), 'price' => (int) $price, 'row' => $row ];
		}
		if ( ! $rows ) {
			return [ 'mode' => 'none', 'price' => null, 'rows' => [] ];
		}
		$distinct = array_unique( array_column( $rows, 'price' ) );
		return 1 === count( $distinct )
			? [ 'mode' => 'single', 'price' => (int) reset( $distinct ), 'rows' => $rows ]
			: [ 'mode' => 'table', 'price' => null, 'rows' => $rows ];
	}
}
