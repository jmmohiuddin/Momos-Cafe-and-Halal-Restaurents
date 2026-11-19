<?php
declare(strict_types=1);

namespace Momos\Dietary\Job;

use Momos\Dietary\ContentTypes;
use Momos\Dietary\Guard\ClaimFieldGuard;
use Momos\Dietary\Service\CertificateService;
use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Db\NamedLock;
use Momos\Kernel\Log\Logger;
use Momos\Kernel\Support\Hash;

/**
 * Certificate expiry and E11 alerts (FR-DIET-002(b)(c), BR-DIET-02, Doc 11 §8.5, spec §3.14,
 * TC-DIET-004/005, TC-NOT-017). Business time: 00:10 JST; the job is scheduled hourly and is idempotent
 * (conditional status change + notification dedupe keys `cert:{id}:{30|7|0}:{recipient}`), so the first
 * run after 00:00 JST performs the day's work and later runs send nothing new. Heartbeat option
 * `momos_job_heartbeat_certificate_expiry` (alert when older than 26 h — Monitoring).
 */
final class CertificateExpiryJob {
	public const HOOK     = 'momos_job_certificate_expiry';
	public const INTERVAL = 3600;

	/** @return array{expired:list<int>,alerts:int} */
	public static function run(): array {
		$result = [ 'expired' => [], 'alerts' => 0 ];
		if ( ! NamedLock::acquire( self::HOOK, 0 ) ) {
			return $result;
		}
		try {
			$today = Clock::jst()->format( 'Y-m-d' );
			$d30   = Clock::jst()->modify( '+30 days' )->format( 'Y-m-d' );
			$d7    = Clock::jst()->modify( '+7 days' )->format( 'Y-m-d' );
			$ids   = get_posts(
				[
					'post_type'      => ContentTypes::CERTIFICATE,
					'post_status'    => 'any',
					'posts_per_page' => 500,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_query'     => [ [ 'key' => 'momo_cert_status', 'value' => 'active' ] ], // phpcs:ignore
				]
			);
			foreach ( $ids as $id ) {
				$id    = (int) $id;
				$until = (string) get_post_meta( $id, 'momo_cert_valid_until', true );
				if ( '' === $until ) {
					continue;
				}
				if ( $until < $today ) {
					$expired = ClaimFieldGuard::system( static fn() => CertificateService::expire( $id ) );
					if ( $expired ) {
						$result['expired'][] = $id;
						$result['alerts']   += self::alert( $id, 'cert_expired', '0' );
					}
				} elseif ( $until === $d30 ) {
					$result['alerts'] += self::alert( $id, 'cert_30', '30' );
				} elseif ( $until === $d7 ) {
					$result['alerts'] += self::alert( $id, 'cert_7', '7' );
				}
			}
			update_option( 'momos_job_heartbeat_certificate_expiry', Clock::mysql_utc(), false );
			if ( $result['expired'] ) {
				Logger::info( 'dietary.certificates_expired', [ 'ids' => $result['expired'] ] );
			}
		} catch ( \Throwable $e ) {
			Logger::error( 'dietary.certificate_expiry_failed', [ 'error' => $e->getMessage() ] );
		} finally {
			NamedLock::release( self::HOOK );
		}
		return $result;
	}

	/**
	 * Recipients: HO notification list (`momo_ho_escalation_recipients`, active rows) ∪ users with CAP-DCA,
	 * deduplicated by email; locale per recipient (ja/en).
	 *
	 * @return list<array{type:string,email:string,lang:string,user_id:?int}>
	 */
	public static function recipients(): array {
		$out  = [];
		$list = \Momos\Kernel\Settings::get( 'momo_ho_escalation_recipients', [] );
		if ( is_string( $list ) ) {
			$list = json_decode( $list, true );
		}
		foreach ( is_array( $list ) ? $list : [] as $r ) {
			$email = strtolower( trim( (string) ( $r['email'] ?? '' ) ) );
			if ( is_email( $email ) && ! empty( $r['active'] ) ) {
				$out[ $email ] = [ 'type' => 'head_office', 'email' => $email, 'lang' => 'en' === ( $r['language'] ?? 'ja' ) ? 'en' : 'ja', 'user_id' => null ];
			}
		}
		foreach ( get_users( [ 'capability' => ClaimFieldGuard::CAP, 'fields' => [ 'ID', 'user_email' ] ] ) as $u ) {
			$email  = strtolower( (string) $u->user_email );
			$active = (bool) apply_filters( 'momos/access/user_active', true, (int) $u->ID );
			if ( ! $active || ! is_email( $email ) || isset( $out[ $email ] ) ) {
				continue;
			}
			$out[ $email ] = [ 'type' => 'user', 'email' => $email, 'lang' => \Momos\Kernel\I18n\Locale::admin( (int) $u->ID ), 'user_id' => (int) $u->ID ];
		}
		return array_values( $out );
	}

	/** Queue E11 for every recipient; returns the number of new notifications. */
	private static function alert( int $cert_id, string $variant, string $days ): int {
		$sent = 0;
		foreach ( self::recipients() as $r ) {
			$dedupe  = 'cert:' . $cert_id . ':' . $days . ':' . substr( Hash::hmac( $r['email'] ), 0, 16 );
			$context = [
				'certificate_id' => $cert_id,
				'variant'        => $variant,
				'object_type'    => 'certificate',
				'object_id'      => $cert_id,
				'branch_ids'     => CertificateService::branch_ids( $cert_id ),
			];
			if ( class_exists( \Momos\Notification\Notifier::class ) ) {
				$id = \Momos\Notification\Notifier::queue( 'E11', $r, $context, $dedupe );
				if ( null !== $id ) {
					++$sent;
				}
			} else {
				Logger::warning( 'dietary.e11_notifier_missing', [ 'certificate_id' => $cert_id, 'variant' => $variant ] );
			}
			/** Fired for each E11 alert (after the Notifier call, deduped there). */
			do_action( 'momos/certificate/expiry_alert', $cert_id, $variant, $r, $dedupe );
		}
		return $sent;
	}
}
