<?php
declare(strict_types=1);

namespace Momos\Api\RateLimit;

use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Support\Hash;
use Momos\Reservation\Support\Options;

/**
 * Sliding-window rate limiter over two fixed windows (TRD §4.3.6, spec §3.7):
 * estimate = cur + prev × (1 − elapsed/window); a request is rejected when estimate ≥ limit.
 * Keys `momos_rl:{bucket}:{hmac(subject)}:{window_start}` (subject = IP, normalised email or user id,
 * never stored in clear) with TTL 2 × window, in the persistent object cache when available, else in
 * transients. Only allowed requests are counted.
 */
final class RateLimiter {
	/** bucket => [option name of the limit, window seconds] */
	public const BUCKETS = [
		'res_ip'          => [ 'momo_rate_ip_per_hour', 3600 ],
		'res_ip_degraded' => [ 'momo_rate_ip_degraded_per_hour', 3600 ],
		'res_email'       => [ 'momo_rate_email_per_hour', 3600 ],
		'status_ip'       => [ 'momo_rate_status_ip_per_hour', 3600 ],
		'cancel_ip'       => [ 'momo_rate_cancel_ip_per_hour', 3600 ],
		'staff_user'      => [ 'momo_rate_staff_per_minute', 60 ],
	];

	/** @var array{limit:int,remaining:int}|null last result for X-RateLimit-* headers */
	public static ?array $last = null;

	/**
	 * Count a hit when allowed.
	 *
	 * @return array{allowed:bool,limit:int,remaining:int,retry_after:int}
	 */
	public static function hit( string $bucket, string $subject ): array {
		[ $option, $window ] = self::BUCKETS[ $bucket ];
		$limit   = max( 1, Options::int( $option ) );
		$now     = Clock::utc()->getTimestamp();
		$start   = intdiv( $now, $window ) * $window;
		$subject = Hash::hmac( $subject, 'MOMOS_HASH_PEPPER' );
		$cur_key = 'momos_rl:' . $bucket . ':' . substr( $subject, 0, 32 ) . ':' . $start;
		$prv_key = 'momos_rl:' . $bucket . ':' . substr( $subject, 0, 32 ) . ':' . ( $start - $window );
		$cur     = self::get( $cur_key );
		$prev    = self::get( $prv_key );
		$elapsed = ( $now - $start ) / $window;
		$est     = $cur + $prev * ( 1 - $elapsed );
		if ( $est >= $limit ) {
			$retry      = max( 1, $start + $window - $now );
			self::$last = [ 'limit' => $limit, 'remaining' => 0 ];
			return [ 'allowed' => false, 'limit' => $limit, 'remaining' => 0, 'retry_after' => $retry ];
		}
		self::incr( $cur_key, 2 * $window );
		$remaining  = max( 0, (int) floor( $limit - $est - 1 ) );
		self::$last = [ 'limit' => $limit, 'remaining' => $remaining ];
		return [ 'allowed' => true, 'limit' => $limit, 'remaining' => $remaining, 'retry_after' => 0 ];
	}

	private static function get( string $key ): int {
		if ( wp_using_ext_object_cache() ) {
			return (int) wp_cache_get( $key, 'momos_rl' );
		}
		return (int) get_transient( self::tkey( $key ) );
	}

	private static function incr( string $key, int $ttl ): void {
		if ( wp_using_ext_object_cache() ) {
			if ( ! wp_cache_add( $key, 1, 'momos_rl', $ttl ) ) {
				wp_cache_incr( $key, 1, 'momos_rl' );
			}
			return;
		}
		$t = self::tkey( $key );
		set_transient( $t, (int) get_transient( $t ) + 1, $ttl );
	}

	/** Transient names are limited to 172 characters. */
	private static function tkey( string $key ): string {
		return 'momos_rl_' . md5( $key );
	}

	/** Reset (tests / CLI). */
	public static function reset( string $bucket, string $subject ): void {
		[ , $window ] = self::BUCKETS[ $bucket ];
		$now     = Clock::utc()->getTimestamp();
		$start   = intdiv( $now, $window ) * $window;
		$subject = substr( Hash::hmac( $subject, 'MOMOS_HASH_PEPPER' ), 0, 32 );
		foreach ( [ $start, $start - $window ] as $s ) {
			$key = 'momos_rl:' . $bucket . ':' . $subject . ':' . $s;
			wp_cache_delete( $key, 'momos_rl' );
			delete_transient( self::tkey( $key ) );
		}
	}
}
