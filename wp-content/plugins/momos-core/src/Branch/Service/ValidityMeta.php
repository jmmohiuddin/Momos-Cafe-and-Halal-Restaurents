<?php
declare(strict_types=1);

namespace Momos\Branch\Service;

use Momos\Kernel\Clock\Clock;

/**
 * Validity windows of notices and offers. Staff enter JST (`momo_*_start_at` / `momo_*_end_at`,
 * "Y-m-d H:i" as edited in the admin form); every change mirrors a UTC "Y-m-d H:i:s" companion
 * (`_momo_*_start_utc` / `_momo_*_end_utc`) used for all comparisons and queries (spec §3.5/§3.6:
 * "JST input, stored UTC").
 */
final class ValidityMeta {
	/** JST meta key => UTC companion key. */
	public const MAP = [
		'momo_notice_start_at' => '_momo_notice_start_utc',
		'momo_notice_end_at'   => '_momo_notice_end_utc',
		'momo_offer_start_at'  => '_momo_offer_start_utc',
		'momo_offer_end_at'    => '_momo_offer_end_utc',
	];

	public static function register(): void {
		add_action( 'added_post_meta', [ self::class, 'mirror' ], 10, 4 );
		add_action( 'updated_post_meta', [ self::class, 'mirror' ], 10, 4 );
		add_action( 'deleted_post_meta', [ self::class, 'deleted' ], 10, 3 );
	}

	public static function mirror( int $meta_id, int $post_id, string $key, mixed $value ): void {
		if ( ! isset( self::MAP[ $key ] ) ) {
			return;
		}
		$utc = self::jst_to_utc( (string) $value );
		if ( null === $utc ) {
			delete_post_meta( $post_id, self::MAP[ $key ] );
		} else {
			update_post_meta( $post_id, self::MAP[ $key ], $utc );
		}
	}

	/** @param list<int>|int $meta_ids */
	public static function deleted( $meta_ids, int $post_id, string $key ): void {
		if ( isset( self::MAP[ $key ] ) ) {
			delete_post_meta( $post_id, self::MAP[ $key ] );
		}
	}

	/** "Y-m-d H:i[:s]" (or "T" separator) in JST → UTC MySQL datetime. */
	public static function jst_to_utc( string $jst ): ?string {
		$jst = trim( str_replace( 'T', ' ', $jst ) );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $jst ) ) {
			return null;
		}
		try {
			return Clock::mysql_utc( new \DateTimeImmutable( $jst, Clock::tz() ) );
		} catch ( \Exception $e ) {
			return null;
		}
	}

	public static function to_instant( string $jst ): ?\DateTimeImmutable {
		$utc = self::jst_to_utc( $jst );
		return null === $utc ? null : Clock::from_mysql_utc( $utc );
	}

	/** UTC instant of a post's start/end ("start"|"end"), from the companion meta. */
	public static function instant( int $post_id, string $which ): ?\DateTimeImmutable {
		$type = get_post_type( $post_id );
		$key  = '_momo_' . ( 'momo_notice' === $type ? 'notice' : 'offer' ) . '_' . $which . '_utc';
		return Clock::from_mysql_utc( (string) get_post_meta( $post_id, $key, true ) ?: null );
	}
}
