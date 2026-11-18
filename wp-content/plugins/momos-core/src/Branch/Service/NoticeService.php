<?php
declare(strict_types=1);

namespace Momos\Branch\Service;

use Momos\Branch\Fields\NoticeFields;
use Momos\Branch\PostType\BranchScopeTaxonomy;
use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Jobs\Schedule;

/**
 * Notice lifecycle (FR-BR-009, spec §4.10): draft → publish without approval (BM may publish
 * own-branch notices), audit `notice.published|updated|trashed`, cache purge now and at start/end
 * (`momos_purge_scheduled` single actions).
 */
final class NoticeService {
	/** @var array<int,bool> notices published in this request (finalised after meta is saved) */
	private static array $just_published = [];

	public static function register(): void {
		add_action( 'transition_post_status', [ self::class, 'transition' ], 10, 3 );
		add_action( 'save_post', [ self::class, 'after_save' ], 20, 2 );
		add_action( 'momos/fields/saved', [ self::class, 'fields_saved' ], 10, 5 );
	}

	/** Runs after FieldRegistry stored meta/terms (save_post priority 10). */
	public static function after_save( int $post_id, \WP_Post $post ): void {
		if ( 'momo_notice' !== $post->post_type || empty( self::$just_published[ $post_id ] ) ) {
			return;
		}
		unset( self::$just_published[ $post_id ] );
		momos_audit( 'notice.published', 'notice', $post_id, self::first_branch( $post_id ), self::audit_context( $post_id ) );
		self::schedule_purges( $post_id );
		self::purge( $post_id );
	}

	public static function transition( string $new, string $old, \WP_Post $post ): void {
		if ( 'momo_notice' !== $post->post_type || $new === $old ) {
			return;
		}
		if ( 'publish' === $new ) {
			self::$just_published[ $post->ID ] = true;
			return;
		}
		if ( 'trash' === $new ) {
			momos_audit( 'notice.trashed', 'notice', $post->ID, self::first_branch( $post->ID ), self::audit_context( $post->ID ) );
		} elseif ( 'publish' === $old ) {
			momos_audit( 'notice.updated', 'notice', $post->ID, self::first_branch( $post->ID ), [ 'post_status' => [ $old, $new ] ] + self::audit_context( $post->ID ) );
		}
		if ( 'publish' === $new || 'publish' === $old ) {
			self::purge( $post->ID );
		}
	}

	/** @param array<string, array{0:mixed,1:mixed}> $changes */
	public static function fields_saved( string $type, int|string $object_id, string $group_key, array $changes, int $user_id ): void {
		if ( NoticeFields::GROUP !== $group_key || ! $changes || 'publish' !== get_post_status( (int) $object_id ) ) {
			return;
		}
		$id = (int) $object_id;
		if ( ! empty( self::$just_published[ $id ] ) ) {
			return; // Audited as notice.published in after_save().
		}
		momos_audit( 'notice.updated', 'notice', $id, self::first_branch( $id ), [ 'fields' => array_keys( $changes ) ] + self::audit_context( $id ) );
		self::schedule_purges( $id );
		self::purge( $id );
	}

	public static function schedule_purges( int $id ): void {
		$now = Clock::utc();
		foreach ( [ 'start', 'end' ] as $which ) {
			$at = ValidityMeta::instant( $id, $which );
			if ( null !== $at && $at > $now ) {
				Schedule::at( $at, 'momos_purge_scheduled', [ $id ] );
			}
		}
	}

	public static function purge( int $id ): void {
		$tags = [];
		if ( 'site_wide' === get_post_meta( $id, 'momo_notice_scope', true ) ) {
			$tags[] = 'momo-global';
		}
		foreach ( BranchScopeTaxonomy::branch_ids_of_post( $id ) as $b ) {
			$tags[] = 'momo-branch-' . $b;
		}
		$tags[] = 'momo-branches';
		do_action( 'momos/cache/purge', $tags );
	}

	/** @return array<string,mixed> */
	private static function audit_context( int $id ): array {
		$u = wp_get_current_user();
		return [
			'scope'    => (string) get_post_meta( $id, 'momo_notice_scope', true ),
			'branches' => BranchScopeTaxonomy::branch_ids_of_post( $id ),
			'role'     => $u && $u->roles ? implode( ',', $u->roles ) : 'system',
		];
	}

	private static function first_branch( int $id ): ?int {
		$b = BranchScopeTaxonomy::branch_ids_of_post( $id );
		return $b ? $b[0] : null;
	}
}
