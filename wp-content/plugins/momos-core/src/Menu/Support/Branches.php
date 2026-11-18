<?php
declare(strict_types=1);

namespace Momos\Menu\Support;

use Momos\Kernel\I18n\Lang;
use Momos\Kernel\I18n\Translations;

/**
 * Read-only branch facts needed by Menu/Dietary. Uses the Branch module's repository when present
 * (docs/CONTRACTS.md) and falls back to the documented G4 meta keys otherwise, so both modules can be
 * built in parallel.
 */
final class Branches {
	public const POST_TYPE = 'momo_branch';

	/** @return array{id:int,code:string,status:string,is_test:bool,operating_status:string}|null */
	public static function info( int $branch_id ): ?array {
		if ( $branch_id <= 0 ) {
			return null;
		}
		if ( class_exists( \Momos\Branch\Repository\BranchRepository::class ) ) {
			$b = \Momos\Branch\Repository\BranchRepository::get( $branch_id );
			if ( null === $b ) {
				return null;
			}
			return [
				'id'               => $branch_id,
				'code'             => (string) ( $b['code'] ?? '' ),
				'status'           => (string) ( $b['status'] ?? '' ),
				'is_test'          => ! empty( $b['is_test'] ),
				'operating_status' => (string) ( $b['operating_status'] ?? 'open' ),
			];
		}
		$post = get_post( $branch_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}
		$op = (string) get_post_meta( $branch_id, 'momo_operating_status', true );
		return [
			'id'               => $branch_id,
			'code'             => (string) get_post_meta( $branch_id, 'momo_branch_code', true ),
			'status'           => (string) $post->post_status,
			'is_test'          => '1' === (string) get_post_meta( $branch_id, 'momo_is_test_branch', true ),
			'operating_status' => '' === $op ? 'open' : $op,
		];
	}

	public static function code( int $branch_id ): string {
		return (string) ( self::info( $branch_id )['code'] ?? '' );
	}

	/** Branch post ID by code (any status), or null. */
	public static function id_by_code( string $code ): ?int {
		$code = strtoupper( trim( $code ) );
		if ( ! preg_match( '/^[A-Z0-9]{2,10}$/', $code ) ) {
			return null;
		}
		$ids = get_posts(
			[
				'post_type'        => self::POST_TYPE,
				'post_status'      => [ 'publish', 'draft', 'pending', 'private' ],
				'meta_key'         => 'momo_branch_code', // phpcs:ignore
				'meta_value'       => $code, // phpcs:ignore
				'fields'           => 'ids',
				'posts_per_page'   => 1,
				'no_found_rows'    => true,
				'suppress_filters' => true,
			]
		);
		return $ids ? (int) $ids[0] : null;
	}

	/** Published (not necessarily public) branch: valid target for staff menu edits (TEST included). */
	public static function is_published( int $branch_id ): bool {
		$i = self::info( $branch_id );
		return null !== $i && 'publish' === $i['status'];
	}

	/**
	 * Public branch: published, not TEST, not permanently closed (FR-MENU-001, PriceResolver).
	 * $for_selector also excludes temporarily closed branches (PRD test note).
	 */
	public static function is_public( int $branch_id, bool $for_selector = false ): bool {
		$i = self::info( $branch_id );
		if ( null === $i || 'publish' !== $i['status'] || $i['is_test'] || 'closed_permanently' === $i['operating_status'] ) {
			return false;
		}
		return ! ( $for_selector && 'temporarily_closed' === $i['operating_status'] );
	}

	/** @return list<int> published branch IDs (TEST included only on request) ordered by menu_order/title. */
	public static function published_ids( bool $include_test = false ): array {
		if ( class_exists( \Momos\Branch\Repository\BranchRepository::class ) ) {
			return array_map( 'intval', \Momos\Branch\Repository\BranchRepository::published_ids( $include_test ) );
		}
		$ids = get_posts(
			[
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 200,
				'orderby'        => [ 'menu_order' => 'ASC', 'title' => 'ASC' ],
				'no_found_rows'  => true,
			]
		);
		$ids = array_map( 'intval', $ids );
		if ( ! $include_test ) {
			$ids = array_values( array_filter( $ids, static fn( int $id ) => '1' !== (string) get_post_meta( $id, 'momo_is_test_branch', true ) ) );
		}
		return $ids;
	}

	/** Branch name in a language ('' when not approved there — DEC-027). */
	public static function name( int $branch_id, ?string $lang = null ): string {
		if ( class_exists( \Momos\Branch\Repository\BranchRepository::class ) ) {
			return \Momos\Branch\Repository\BranchRepository::name( $branch_id, $lang );
		}
		$lang = $lang ?? Lang::SOURCE;
		return Translations::post( $branch_id, 'title', $lang );
	}

	/** Public URL of the branch page in a language. */
	public static function url( int $branch_id, string $lang ): string {
		$post = get_post( $branch_id );
		return $post ? \Momos\Kernel\I18n\Url::to( '/branches/' . $post->post_name . '/', $lang ) : '';
	}
}
