<?php
declare(strict_types=1);

namespace Momos\Branch\Job;

use Momos\Branch\Query\OfferQuery;
use Momos\Branch\Service\NoticeService;

/**
 * `momos_purge_scheduled` single actions at notice/offer start and end (spec §4.10/§4.11):
 * purge the affected caches so cached pages show/hide the item within the minute.
 */
final class ScheduledPurgeJob {
	public const HOOK = 'momos_purge_scheduled';

	public static function register(): void {
		add_action( self::HOOK, [ self::class, 'run' ] );
	}

	public static function run( mixed $post_id = 0 ): void {
		$id = (int) ( is_array( $post_id ) ? ( $post_id['post_id'] ?? 0 ) : $post_id );
		if ( ! $id ) {
			return;
		}
		$type = get_post_type( $id );
		if ( 'momo_notice' === $type ) {
			NoticeService::purge( $id );
		} elseif ( 'momo_offer' === $type ) {
			OfferQuery::sync_visibility( $id );
		}
	}
}
