<?php
declare(strict_types=1);

namespace Momos\Branch\Repository;

use Momos\Branch\Domain\OperatingStatus;
use Momos\Kernel\I18n\Lang;
use Momos\Kernel\I18n\Translations;

/**
 * Branch reader (spec §2 BranchReader, docs/CONTRACTS.md). Canonical branch ID = post ID of the
 * single `momo_branch` post (IMPL-002). No hard-coded branch lists anywhere (FR-BR-011).
 */
final class BranchRepository {
	/**
	 * @return array{id:int,code:string,name:string,slug:string,status:string,operating_status:string,is_test:bool,phone_e164:string,phone_display:string,email:string,address_ja:string,lat:?float,lng:?float,concept:string,reopen_date:?string,place_id:string,menu_order:int}|null
	 */
	public static function get( int $branch_id ): ?array {
		$p = get_post( $branch_id );
		if ( ! $p || 'momo_branch' !== $p->post_type ) {
			return null;
		}
		$m      = static fn( string $k ): string => (string) get_post_meta( $p->ID, $k, true );
		$status = $m( 'momo_operating_status' );
		return [
			'id'               => (int) $p->ID,
			'code'             => $m( 'momo_branch_code' ),
			'name'             => (string) $p->post_title,
			'slug'             => (string) $p->post_name,
			'status'           => (string) $p->post_status,
			'operating_status' => OperatingStatus::is_valid( $status ) ? $status : OperatingStatus::Open->value,
			'is_test'          => '1' === $m( 'momo_is_test_branch' ),
			'phone_e164'       => $m( 'momo_phone_e164' ),
			'phone_display'    => $m( 'momo_phone_display' ),
			'email'            => $m( 'momo_branch_email' ),
			'address_ja'       => $m( 'momo_address_ja' ),
			'lat'              => '' !== $m( 'momo_lat' ) ? (float) $m( 'momo_lat' ) : null,
			'lng'              => '' !== $m( 'momo_lng' ) ? (float) $m( 'momo_lng' ) : null,
			'concept'          => $m( 'momo_concept_label' ),
			'reopen_date'      => '' !== $m( 'momo_reopen_date' ) ? $m( 'momo_reopen_date' ) : null,
			'place_id'         => $m( 'momo_place_id' ),
			'menu_order'       => (int) $p->menu_order,
		];
	}

	/**
	 * Published branch IDs ordered by menu_order, then title (all operating statuses).
	 *
	 * @return list<int>
	 */
	public static function published_ids( bool $include_test = false ): array {
		$args = [
			'post_type'        => 'momo_branch',
			'post_status'      => 'publish',
			'posts_per_page'   => 200,
			'orderby'          => [ 'menu_order' => 'ASC', 'title' => 'ASC' ],
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => true,
		];
		if ( ! $include_test ) {
			$args['meta_query'] = [ self::not_test_clause() ]; // phpcs:ignore
		}
		return array_map( 'intval', get_posts( $args ) );
	}

	/**
	 * Branches eligible for public lists in a language (directory, footer, selectors; spec §6.1):
	 * published ∩ status ∈ {open, temporarily_closed} ∩ not test ∩ translated(lang).
	 *
	 * @return list<int>
	 */
	public static function public_ids( ?string $lang = null ): array {
		return array_values( array_filter( self::published_ids( false ), static fn( $id ) => self::is_public( $id, $lang ) ) );
	}

	/** Public visibility predicate (BranchVisibility::isPublic). */
	public static function is_public( int $branch_id, ?string $lang = null ): bool {
		$b = self::get( $branch_id );
		if ( ! $b || 'publish' !== $b['status'] || $b['is_test'] || OperatingStatus::ClosedPermanently->value === $b['operating_status'] ) {
			return false;
		}
		return Translations::post_visible( $branch_id, $lang ?? Lang::SOURCE );
	}

	/** Find by 3-letter code; TEST branches only when $include_test (FR-RES-026). */
	public static function find_by_code( string $code, bool $include_test = false ): ?array {
		$code = strtoupper( trim( $code ) );
		if ( ! preg_match( '/^[A-Z]{3}$/', $code ) ) {
			return null;
		}
		$ids = get_posts(
			[
				'post_type'        => 'momo_branch',
				'post_status'      => [ 'publish', 'draft', 'pending', 'private' ],
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_key'         => 'momo_branch_code', // phpcs:ignore
				'meta_value'       => $code, // phpcs:ignore
			]
		);
		$b   = $ids ? self::get( (int) $ids[0] ) : null;
		if ( ! $b || ( $b['is_test'] && ! $include_test ) ) {
			return null;
		}
		return $b;
	}

	/** Translated name; '' when not approved in $lang (DEC-027). */
	public static function name( int $branch_id, ?string $lang = null ): string {
		return Translations::post( $branch_id, 'title', $lang );
	}

	public static function is_test( int $branch_id ): bool {
		return '1' === (string) get_post_meta( $branch_id, 'momo_is_test_branch', true );
	}

	public static function operating_status( int $branch_id ): string {
		$s = (string) get_post_meta( $branch_id, 'momo_operating_status', true );
		return OperatingStatus::is_valid( $s ) ? $s : OperatingStatus::Open->value;
	}

	/** Is any reservation stored for the branch (code immutability, delete guard)? */
	public static function has_reservations( int $branch_id ): bool {
		global $wpdb;
		$t = \Momos\Kernel\Db\Tables::reservations();
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$t} WHERE branch_id = %d LIMIT 1", $branch_id ) ); // phpcs:ignore
	}

	/** @return array<string,mixed> */
	private static function not_test_clause(): array {
		return [
			'relation' => 'OR',
			[ 'key' => 'momo_is_test_branch', 'compare' => 'NOT EXISTS' ],
			[ 'key' => 'momo_is_test_branch', 'value' => '1', 'compare' => '!=' ],
		];
	}
}
