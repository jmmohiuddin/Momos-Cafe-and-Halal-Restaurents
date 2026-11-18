<?php
declare(strict_types=1);

namespace Momos\Menu\Service;

use Momos\Kernel\Clock\Clock;

/**
 * Render-time availability label (spec §3.4, CMP-AvailabilityLabel). An expired `sold_out_until`
 * reads as available even before the reset job runs; cards are never hidden for sold-out (WF-EXC-08).
 */
final class AvailabilityLabel {
	/**
	 * @param array<string,mixed>|null $row branch row (null/not offered = not offered at the branch)
	 * @return array{code:string,label:string,reason:?string,reason_label:?string}
	 */
	public static function for_row( ?array $row, string $lang, ?\DateTimeImmutable $now = null ): array {
		if ( null === $row || 1 !== (int) $row['is_offered'] ) {
			return [ 'code' => 'not_offered', 'label' => momos_t( 'menu.not_available', [], $lang ), 'reason' => null, 'reason_label' => null ];
		}
		$now   = $now ?? Clock::utc();
		$state = (string) $row['availability'];
		if ( 'sold_out' === $state ) {
			$until = Clock::from_mysql_utc( $row['sold_out_until'] ?? null );
			if ( null !== $until && $until > $now ) {
				return [ 'code' => 'sold_out', 'label' => momos_t( 'menu.sold_out', [], $lang ), 'reason' => null, 'reason_label' => null ];
			}
			$state = 'available';
		}
		if ( 'unavailable' === $state ) {
			$reason = $row['availability_reason'] ?? null;
			return [
				'code'         => 'unavailable',
				'label'        => momos_t( 'menu.unavailable', [], $lang ),
				'reason'       => $reason,
				'reason_label' => ( $reason && 'other' !== $reason ) ? momos_t( 'availability_reason.' . $reason, [], $lang ) : null,
			];
		}
		return [ 'code' => 'available', 'label' => momos_t( 'menu.available_today', [], $lang ), 'reason' => null, 'reason_label' => null ];
	}

	/** Label without branch context: only "Not currently available" when offered nowhere. */
	public static function without_branch( int $dish_id, string $lang ): ?array {
		return PriceResolver::prices_by_branch( $dish_id ) ? null : [ 'code' => 'not_offered', 'label' => momos_t( 'menu.not_available', [], $lang ), 'reason' => null, 'reason_label' => null ];
	}
}
