<?php
declare(strict_types=1);

namespace Momos\Menu\Service;

use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Db\Transaction;
use Momos\Kernel\Events\AfterCommit;
use Momos\Menu\ContentTypes;
use Momos\Menu\Domain\Availability;
use Momos\Menu\Domain\AvailabilityReason;
use Momos\Menu\Repository\BranchMenuItemRepository;
use Momos\Menu\Support\Branches;
use Momos\Menu\Support\CachePurge;
use Momos\Menu\Support\MenuItemException;

/**
 * Branch menu writes (FR-MENU-006, BR-MENU-01..04, spec §3.7, API-15, SCR-A07).
 *
 * Field-level permissions (M10): offered → momo_edit_branch_menu; price_override_jpy →
 * momo_edit_branch_prices; availability/sold_out_until/availability_reason →
 * momo_edit_branch_availability. Branch scope via momos_user_can_branch(). Optimistic locking via
 * `version` (0 = create). Every changed field is audited inside the transaction; the domain hook
 * `momos/branch_menu_item/updated (array{branch_id:int,dish_id:int,fields:list<string>,id:int})` fires after commit.
 */
final class BranchMenuService {
	public const FIELDS = [ 'version', 'offered', 'price_override_jpy', 'availability', 'sold_out_until', 'availability_reason', 'confirm_large_change', 'confirm_variant_overlap' ];

	public const FIELD_CAPS = [
		'offered'             => 'momo_edit_branch_menu',
		'price_override_jpy'  => 'momo_edit_branch_prices',
		'availability'        => 'momo_edit_branch_availability',
		'sold_out_until'      => 'momo_edit_branch_availability',
		'availability_reason' => 'momo_edit_branch_availability',
	];

	/** @return array{offered:bool,price:bool,availability:bool} */
	public static function permissions( int $user_id, int $branch_id ): array {
		$in_scope = momos_user_can_branch( $branch_id, $user_id );
		return [
			'offered'      => $in_scope && user_can( $user_id, 'momo_edit_branch_menu' ),
			'price'        => $in_scope && user_can( $user_id, 'momo_edit_branch_prices' ),
			'availability' => $in_scope && user_can( $user_id, 'momo_edit_branch_availability' ),
		];
	}

	/**
	 * Apply a PATCH to the row of (branch, dish).
	 *
	 * @param array<string,mixed> $input API-15 body
	 * @return array<string,mixed> presented row
	 * @throws MenuItemException
	 */
	public static function update( int $branch_id, int $dish_id, array $input, int $user_id ): array {
		$in   = self::validate_input( $input );
		$dish = get_post( $dish_id );
		if ( ! Branches::is_published( $branch_id ) ) {
			throw new MenuItemException( 'branch_not_found', 404, __( 'Branch not found.', 'momos-core' ) );
		}
		if ( ! $dish || ContentTypes::DISH !== $dish->post_type || 'publish' !== $dish->post_status ) {
			throw new MenuItemException( 'not_found', 404, __( 'Dish not found.', 'momos-core' ) );
		}
		if ( ! momos_user_can_branch( $branch_id, $user_id ) ) {
			momos_audit( 'access.denied', 'branch_menu_item', null, $branch_id, [ 'reason' => 'forbidden_branch', 'dish_id' => $dish_id ] );
			throw new MenuItemException( 'forbidden_branch', 403, __( 'You do not have access to this branch.', 'momos-core' ) );
		}

		$result = Transaction::run(
			static function () use ( $branch_id, $dish_id, $in, $user_id ): array {
				$row = BranchMenuItemRepository::find( $branch_id, $dish_id, true );
				return self::apply( $branch_id, $dish_id, $row, $in, $user_id );
			}
		);
		return $result;
	}

	/**
	 * @param array<string,mixed>|null $row current row (locked)
	 * @param array<string,mixed>      $in  validated input
	 * @return array<string,mixed>
	 */
	private static function apply( int $branch_id, int $dish_id, ?array $row, array $in, int $user_id ): array {
		$version = (int) $in['version'];
		if ( 0 === $version && null !== $row ) {
			throw new MenuItemException( 'version_conflict', 409, self::conflict_message(), [], [ 'row' => self::present( $row, $dish_id ) ] );
		}
		if ( null === $row && $version > 0 ) {
			throw new MenuItemException( 'version_conflict', 409, self::conflict_message(), [], [ 'row' => null ] );
		}
		if ( null === $row && ! array_key_exists( 'offered', $in ) ) {
			// Rows are created only through the "offered" field (momo_edit_branch_menu, spec §11 C6).
			throw new MenuItemException( 'not_found', 404, __( 'This dish is not configured for this branch.', 'momos-core' ) );
		}
		if ( null !== $row && $version !== (int) $row['version'] ) {
			throw new MenuItemException( 'version_conflict', 409, self::conflict_message(), [], [ 'row' => self::present( $row, $dish_id ) ] );
		}

		$current = $row ?? [
			'is_offered'          => 0,
			'price_override_jpy'  => null,
			'availability'        => 'available',
			'sold_out_until'      => null,
			'availability_reason' => null,
		];
		$now = Clock::utc();

		// ---- requested changes (only fields that differ from the current row count as changed).
		$new     = [
			'is_offered'          => (int) $current['is_offered'],
			'price_override_jpy'  => $current['price_override_jpy'],
			'availability'        => (string) $current['availability'],
			'sold_out_until'      => $current['sold_out_until'],
			'availability_reason' => $current['availability_reason'],
		];
		$changed = [];
		if ( array_key_exists( 'offered', $in ) && ( (int) $in['offered'] !== (int) $current['is_offered'] || null === $row ) ) {
			$new['is_offered'] = $in['offered'] ? 1 : 0;
			$changed[]         = 'offered';
		}
		if ( array_key_exists( 'price_override_jpy', $in ) && $in['price_override_jpy'] !== $current['price_override_jpy'] ) {
			$new['price_override_jpy'] = $in['price_override_jpy'];
			$changed[]                 = 'price_override_jpy';
		}
		$avail_touched = array_key_exists( 'availability', $in ) || array_key_exists( 'sold_out_until', $in ) || array_key_exists( 'availability_reason', $in );
		if ( $avail_touched ) {
			$availability = (string) ( $in['availability'] ?? $current['availability'] );
			$until        = $current['sold_out_until'];
			$reason       = $current['availability_reason'];
			$fields       = [];
			if ( Availability::Available->value === $availability ) {
				if ( array_key_exists( 'sold_out_until', $in ) && null !== $in['sold_out_until'] ) {
					$fields['sold_out_until'] = 'not_allowed';
				}
				$until  = null;
				$reason = null;
			} elseif ( Availability::SoldOut->value === $availability ) {
				if ( array_key_exists( 'sold_out_until', $in ) && null !== $in['sold_out_until'] ) {
					$u = $in['sold_out_until'];
					if ( ! SoldOutPolicy::is_valid_until( $u, $now ) ) {
						$fields['sold_out_until'] = 'out_of_range';
					}
					$until = Clock::mysql_utc( $u );
				} elseif ( array_key_exists( 'availability', $in ) && ( 'sold_out' !== $current['availability'] || array_key_exists( 'sold_out_until', $in ) ) ) {
					$until = Clock::mysql_utc( SoldOutPolicy::default_until( $branch_id, $now ) );
				} elseif ( null === $until || Clock::from_mysql_utc( (string) $until ) <= $now ) {
					$until = Clock::mysql_utc( SoldOutPolicy::default_until( $branch_id, $now ) );
				}
				$reason = array_key_exists( 'availability_reason', $in ) ? $in['availability_reason'] : ( 'sold_out' === $current['availability'] ? $reason : null );
				$reason = $reason ?? AvailabilityReason::SoldOutToday->value;
			} else { // unavailable — never auto-resets.
				if ( array_key_exists( 'sold_out_until', $in ) && null !== $in['sold_out_until'] ) {
					$fields['sold_out_until'] = 'not_allowed';
				}
				$until  = null;
				$reason = array_key_exists( 'availability_reason', $in ) ? $in['availability_reason'] : ( 'unavailable' === $current['availability'] ? $reason : null );
				if ( null === $reason ) {
					$fields['availability_reason'] = 'required';
				}
			}
			if ( $fields ) {
				$msg = isset( $fields['sold_out_until'] ) && 'out_of_range' === $fields['sold_out_until'] ? __( 'Choose a time within the next 7 days.', 'momos-core' ) : __( 'Some values are not valid.', 'momos-core' );
				throw new MenuItemException( 'invalid_param', 400, $msg, $fields );
			}
			if ( $availability !== $current['availability'] || $until !== $current['sold_out_until'] || $reason !== $current['availability_reason'] ) {
				$new['availability']        = $availability;
				$new['sold_out_until']      = $until;
				$new['availability_reason'] = $reason;
				$changed[]                  = 'availability';
			}
		}

		if ( ! $changed ) {
			return self::present( $row, $dish_id );
		}

		// ---- M10 field-level capabilities.
		$forbidden = [];
		foreach ( $changed as $field ) {
			if ( ! user_can( $user_id, self::FIELD_CAPS[ $field ] ) ) {
				$forbidden[ $field ] = 'forbidden';
			}
		}
		if ( null === $row && ! user_can( $user_id, self::FIELD_CAPS['offered'] ) ) {
			$forbidden['offered'] = 'forbidden';
		}
		if ( $forbidden ) {
			momos_audit( 'access.denied', 'branch_menu_item', $row ? (int) $row['id'] : null, $branch_id, [ 'reason' => 'forbidden_field', 'dish_id' => $dish_id, 'fields' => array_keys( $forbidden ) ] );
			throw new MenuItemException( 'forbidden_field', 403, __( 'You cannot change this field.', 'momos-core' ), $forbidden );
		}

		// ---- business confirmations.
		$reference = self::reference_price( $dish_id );
		if ( in_array( 'price_override_jpy', $changed, true ) && empty( $in['confirm_large_change'] ) ) {
			$prev = $current['price_override_jpy'] ?? $reference;
			$next = $new['price_override_jpy'] ?? $reference;
			if ( null !== $prev && null !== $next && $prev > 0 && abs( $next - $prev ) / $prev > 0.3 ) {
				throw new MenuItemException( 'invalid_param', 400, __( 'The price changes by more than 30 %. Please confirm the change.', 'momos-core' ), [ 'confirm_large_change' => 'required' ] );
			}
		}
		if ( in_array( 'offered', $changed, true ) && 1 === $new['is_offered'] && empty( $in['confirm_variant_overlap'] ) && self::variant_overlap( $branch_id, $dish_id ) ) {
			throw new MenuItemException( 'invalid_param', 400, __( 'The base dish or one of its variants is already offered at this branch. Please confirm.', 'momos-core' ), [ 'offered' => 'not_allowed' ] );
		}

		// ---- write.
		if ( null === $row ) {
			$id = BranchMenuItemRepository::insert(
				$branch_id,
				$dish_id,
				[
					'offered'             => (bool) $new['is_offered'],
					'price_override_jpy'  => $new['price_override_jpy'],
					'availability'        => $new['availability'],
					'sold_out_until'      => $new['sold_out_until'],
					'availability_reason' => $new['availability_reason'],
				],
				$user_id
			);
			if ( 0 === $id ) {
				$cur = BranchMenuItemRepository::find( $branch_id, $dish_id );
				throw new MenuItemException( 'version_conflict', 409, self::conflict_message(), [], [ 'row' => $cur ? self::present( $cur, $dish_id ) : null ] );
			}
		} else {
			$id = (int) $row['id'];
			if ( ! BranchMenuItemRepository::update_if_version( $id, (int) $row['version'], $new, $user_id ) ) {
				$cur = BranchMenuItemRepository::find( $branch_id, $dish_id );
				throw new MenuItemException( 'version_conflict', 409, self::conflict_message(), [], [ 'row' => $cur ? self::present( $cur, $dish_id ) : null ] );
			}
		}

		// ---- audit per changed field (no PII).
		foreach ( $changed as $field ) {
			[ $action, $before, $after ] = match ( $field ) {
				'offered'            => [ 'menu_item.offered_changed', (bool) $current['is_offered'], (bool) $new['is_offered'] ],
				'price_override_jpy' => [ 'menu_item.price_changed', $current['price_override_jpy'], $new['price_override_jpy'] ],
				default              => [
					'menu_item.availability_changed',
					[ 'availability' => $current['availability'], 'sold_out_until' => $current['sold_out_until'], 'reason' => $current['availability_reason'] ],
					[ 'availability' => $new['availability'], 'sold_out_until' => $new['sold_out_until'], 'reason' => $new['availability_reason'] ],
				],
			};
			momos_audit( $action, 'branch_menu_item', $id, $branch_id, [ 'dish_id' => $dish_id, 'before' => $before, 'after' => $after, 'created' => null === $row ] );
		}

		$saved = BranchMenuItemRepository::find_by_id( $id );
		AfterCommit::dispatch( 'momos/branch_menu_item/updated', [ 'branch_id' => $branch_id, 'dish_id' => $dish_id, 'fields' => $changed, 'id' => $id ] );
		CachePurge::dish( $dish_id );
		return self::present( $saved, $dish_id );
	}

	/**
	 * Validate/normalise the API-15 body (`additionalProperties: false`).
	 *
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public static function validate_input( array $input ): array {
		$fields = [];
		foreach ( array_keys( $input ) as $k ) {
			if ( ! in_array( $k, self::FIELDS, true ) ) {
				$fields[ (string) $k ] = 'not_allowed';
			}
		}
		$out = [];
		if ( ! array_key_exists( 'version', $input ) ) {
			$fields['version'] = 'required';
		} elseif ( ! is_int( $input['version'] ) && ! ( is_string( $input['version'] ) && ctype_digit( $input['version'] ) ) ) {
			$fields['version'] = 'invalid_format';
		} else {
			$out['version'] = (int) $input['version'];
		}
		foreach ( [ 'offered', 'confirm_large_change', 'confirm_variant_overlap' ] as $b ) {
			if ( array_key_exists( $b, $input ) ) {
				$v = $input[ $b ];
				if ( is_bool( $v ) ) {
					$out[ $b ] = $v;
				} elseif ( in_array( $v, [ 0, 1, '0', '1', 'true', 'false' ], true ) ) {
					$out[ $b ] = in_array( $v, [ 1, '1', 'true' ], true );
				} else {
					$fields[ $b ] = 'invalid_format';
				}
			}
		}
		if ( array_key_exists( 'price_override_jpy', $input ) ) {
			$v = $input['price_override_jpy'];
			if ( null === $v || '' === $v ) {
				$out['price_override_jpy'] = null;
			} elseif ( is_int( $v ) || ( is_string( $v ) && ctype_digit( $v ) ) ) {
				$v = (int) $v;
				if ( $v < 0 || $v > 100000 ) {
					$fields['price_override_jpy'] = 'out_of_range';
				} else {
					$out['price_override_jpy'] = $v;
				}
			} elseif ( is_numeric( $v ) && (int) $v < 0 ) {
				$fields['price_override_jpy'] = 'out_of_range';
			} else {
				$fields['price_override_jpy'] = 'invalid_format';
			}
		}
		if ( array_key_exists( 'availability', $input ) ) {
			if ( ! in_array( $input['availability'], Availability::values(), true ) ) {
				$fields['availability'] = 'invalid_format';
			} else {
				$out['availability'] = $input['availability'];
			}
		}
		if ( array_key_exists( 'availability_reason', $input ) ) {
			$v = $input['availability_reason'];
			if ( null === $v || '' === $v ) {
				$out['availability_reason'] = null;
			} elseif ( ! in_array( $v, AvailabilityReason::values(), true ) ) {
				$fields['availability_reason'] = 'invalid_format';
			} else {
				$out['availability_reason'] = $v;
			}
		}
		if ( array_key_exists( 'sold_out_until', $input ) ) {
			$v = $input['sold_out_until'];
			if ( null === $v || '' === $v ) {
				$out['sold_out_until'] = null;
			} else {
				$dt = self::parse_datetime( (string) $v );
				if ( null === $dt ) {
					$fields['sold_out_until'] = 'invalid_format';
				} else {
					$out['sold_out_until'] = $dt;
				}
			}
		}
		if ( $fields ) {
			throw new MenuItemException( 'invalid_param', 400, __( 'Some values are not valid.', 'momos-core' ), $fields );
		}
		return $out;
	}

	/** ISO 8601 / "Y-m-d H:i" (JST when no offset is given). */
	public static function parse_datetime( string $v ): ?\DateTimeImmutable {
		$v = trim( $v );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?$/', $v ) ) {
			return null;
		}
		try {
			$has_tz = (bool) preg_match( '/(Z|[+-]\d{2}:?\d{2})$/', $v );
			$dt     = $has_tz ? new \DateTimeImmutable( $v ) : new \DateTimeImmutable( str_replace( 'T', ' ', $v ), Clock::tz() );
			return $dt->setTimezone( new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $e ) {
			return null;
		}
	}

	public static function reference_price( int $dish_id ): ?int {
		$v = (string) get_post_meta( $dish_id, 'momo_reference_price_jpy', true );
		return '' === $v ? null : (int) $v;
	}

	/** Base and variant should not both be offered at one branch (spec §3.9). */
	public static function variant_overlap( int $branch_id, int $dish_id ): bool {
		$dish    = get_post( $dish_id );
		$related = [];
		if ( $dish && (int) $dish->post_parent > 0 ) {
			$related[] = (int) $dish->post_parent;
			$related   = array_merge( $related, get_posts( [ 'post_type' => ContentTypes::DISH, 'post_parent' => (int) $dish->post_parent, 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => 100 ] ) );
		}
		$related = array_merge( $related, get_posts( [ 'post_type' => ContentTypes::DISH, 'post_parent' => $dish_id, 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => 100 ] ) );
		$related = array_values( array_diff( array_unique( array_map( 'intval', $related ) ), [ $dish_id ] ) );
		if ( ! $related ) {
			return false;
		}
		$offered = BranchMenuItemRepository::offered_dish_ids( $branch_id );
		return (bool) array_intersect( $related, $offered );
	}

	/**
	 * API/grid representation of a row (`sold_out_until` ISO 8601 +09:00).
	 *
	 * @param array<string,mixed>|null $row
	 * @return array<string,mixed>|null
	 */
	public static function present( ?array $row, int $dish_id ): ?array {
		if ( null === $row ) {
			return null;
		}
		$ref   = self::reference_price( $dish_id );
		$until = Clock::from_mysql_utc( $row['sold_out_until'] ?? null );
		$upd   = Clock::from_mysql_utc( $row['updated_at'] ?? null );
		$user  = ! empty( $row['updated_by'] ) ? get_userdata( (int) $row['updated_by'] ) : false;
		return [
			'offered'             => 1 === (int) $row['is_offered'],
			'price_override_jpy'  => $row['price_override_jpy'],
			'effective_price_jpy' => $row['price_override_jpy'] ?? $ref,
			'availability'        => (string) $row['availability'],
			'sold_out_until'      => $until ? $until->setTimezone( Clock::tz() )->format( 'c' ) : null,
			'availability_reason' => $row['availability_reason'],
			'version'             => (int) $row['version'],
			'updated_at'          => $upd ? $upd->setTimezone( Clock::tz() )->format( 'c' ) : null,
			'updated_by_name'     => $user ? $user->display_name : null,
		];
	}

	private static function conflict_message(): string {
		return __( 'This record was changed by someone else. The latest version is shown — please review and try again.', 'momos-core' );
	}
}
