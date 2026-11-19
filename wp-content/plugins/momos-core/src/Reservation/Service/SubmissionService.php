<?php
declare(strict_types=1);

namespace Momos\Reservation\Service;

use Momos\Branch\Repository\BranchRepository;
use Momos\Branch\Repository\BranchSettingsRepository;
use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Db\NamedLock;
use Momos\Kernel\Db\Transaction;
use Momos\Kernel\Events\AfterCommit;
use Momos\Kernel\Log\Logger;
use Momos\Kernel\Support\Crockford;
use Momos\Kernel\Support\Hash;
use Momos\Reservation\Domain\Actor;
use Momos\Reservation\Domain\CaptchaStatus;
use Momos\Reservation\Domain\EventType;
use Momos\Reservation\Domain\ReservationError;
use Momos\Reservation\Domain\ReservationStatus;
use Momos\Reservation\Repository\EventRepository;
use Momos\Reservation\Repository\ReservationRepository;
use Momos\Reservation\Support\Options;
use Momos\Reservation\Validation\FieldValidator;
use Momos\Reservation\Validation\Normalizer;

/**
 * API-02 domain part (F-RES-01, FR-RES-005/024, SDD §5.2 checks 9–20, spec §3.1/§3.4).
 * Transport, origin, rate limits, honeypot, schema, idempotency replay and captcha (checks 1–8) run in
 * Api\Controller\PublicReservationController before submit() is called.
 */
final class SubmissionService {
	/**
	 * Check 6: an already stored idempotency key → original row (replayed as 200).
	 *
	 * @return array<string,mixed>|null
	 */
	public static function replay( string $idempotency_key ): ?array {
		return ReservationRepository::find_by_idempotency_key( $idempotency_key );
	}

	/**
	 * Resolve a branch code for a submitter (check 11): published branch; TEST branch only for a
	 * logged-in user assigned to it (FR-RES-026).
	 *
	 * @return array<string,mixed>|null
	 */
	public static function resolve_branch( string $code, int $user_id ): ?array {
		$branch = BranchRepository::find_by_code( $code, true );
		if ( null === $branch || 'publish' !== $branch['status'] ) {
			return null;
		}
		if ( $branch['is_test'] && ! ( $user_id && momos_user_can_branch( (int) $branch['id'], $user_id ) && user_can( $user_id, 'momo_view_reservations' ) ) ) {
			return null;
		}
		return $branch;
	}

	/**
	 * Checks 9–20 and transaction T1.
	 *
	 * @param array<string,mixed> $input schema-valid request body
	 * @param array{captcha_status:string,ip:?string,user_agent:string,user_id:int} $ctx
	 * @return array{http:int,row:array<string,mixed>,duplicate:bool}
	 * @throws ReservationError
	 */
	public static function submit( array $input, array $ctx ): array {
		$now     = Clock::utc();
		$user_id = (int) $ctx['user_id'];

		// 9–10. Normalise + field rules.
		$checked = FieldValidator::submission( $input, $user_id > 0 && user_can( $user_id, 'momo_view_reservations' ) );
		if ( $checked['errors'] ) {
			throw ReservationError::invalid( $checked['errors'] );
		}
		$v = $checked['values'];

		// 11. Branch.
		$branch = self::resolve_branch( (string) $input['branch_code'], $user_id );
		if ( null === $branch ) {
			throw new ReservationError( 'branch_not_found', [ 'branch_code' => 'not_allowed' ] );
		}
		$branch_id = (int) $branch['id'];

		// 12–16. Rules with fresh settings (FR-RES-024(b)).
		$settings = BranchSettingsRepository::get( $branch_id );
		$start    = BookingRules::check( $branch, $settings, $v['date'], $v['time'], (int) $v['party_size'], $now );

		// 17. Per email + branch lock around check-and-insert.
		$lock = 'momo_sub_' . sha1( Normalizer::email_key( $v['email'] ) . '|' . $branch_id );
		if ( ! NamedLock::acquire( $lock, 3 ) ) {
			throw new ReservationError( 'service_unavailable', [], [ 'retryable' => true ] );
		}
		try {
			// Concurrent request with the same key won the race → replay.
			$existing = ReservationRepository::find_by_idempotency_key( (string) $input['idempotency_key'] );
			if ( null !== $existing ) {
				return [ 'http' => 200, 'row' => $existing, 'duplicate' => false ];
			}

			// 18. Soft duplicate (pending or confirmed, within momo_soft_duplicate_minutes).
			$since = Clock::mysql_utc( $now->modify( '-' . max( 0, Options::int( 'momo_soft_duplicate_minutes' ) ) . ' minutes' ) );
			$dup   = ReservationRepository::find_soft_duplicate( $v['email'], $branch_id, $v['date'], $v['time'], $since );
			if ( null !== $dup ) {
				return [ 'http' => 200, 'row' => $dup, 'duplicate' => true ];
			}

			// 19. Pending limit per email per branch.
			$max = (int) $settings['max_pending_per_email'];
			if ( ReservationRepository::count_pending( $v['email'], $branch_id ) >= $max ) {
				throw new ReservationError( 'pending_limit_reached', [], [ 'branch_phone' => SlotGenerator::branch_phone( $branch ), 'args' => [ 'n' => $max ] ] );
			}

			// 20. Transaction T1 (one deadlock retry).
			$row = [
				'idempotency_key'        => strtolower( (string) $input['idempotency_key'] ),
				'branch_id'              => $branch_id,
				'status'                 => ReservationStatus::Pending->value,
				'requested_date'         => $v['date'],
				'requested_time'         => $v['time'] . ':00',
				'requested_start_utc'    => Clock::mysql_utc( $start ),
				'party_size'             => (int) $v['party_size'],
				'guest_name'             => $v['guest_name'],
				'email'                  => $v['email'],
				'phone_e164'             => $v['phone_e164'],
				'special_request'        => $v['special_request'],
				'preferred_lang'         => $v['preferred_lang'],
				'source_lang'            => $v['source_lang'],
				'consent_version'        => Options::consent_version(),
				'consent_at'             => Clock::mysql_utc( $now ),
				'token_key_version'      => TokenService::current_key_version(),
				'token_expires_utc'      => Clock::mysql_utc( $start->modify( '+24 hours' ) ),
				'customer_notified_flag' => 1,
				'needs_action_flag'      => 0,
				'captcha_status'         => CaptchaStatus::is_valid( $ctx['captcha_status'] ) ? $ctx['captcha_status'] : CaptchaStatus::Verified->value,
				'source'                 => 'web',
				'created_at'             => Clock::mysql_utc( $now ),
				'updated_at'             => Clock::mysql_utc( $now ),
				'version'                => 1,
				'ip_hash'                => null !== $ctx['ip'] && '' !== $ctx['ip'] ? Hash::hmac( (string) $ctx['ip'], 'MOMOS_HASH_PEPPER' ) : null,
				'user_agent_hash'        => '' !== $ctx['user_agent'] ? Hash::hmac( (string) $ctx['user_agent'], 'MOMOS_HASH_PEPPER' ) : null,
			];
			for ( $attempt = 1; ; $attempt++ ) {
				try {
					$id = self::t1( $row );
					break;
				} catch ( \RuntimeException $e ) {
					$msg = $e->getMessage();
					if ( str_contains( $msg, 'uq_idempotency_key' ) ) {
						$existing = ReservationRepository::find_by_idempotency_key( $row['idempotency_key'] );
						if ( $existing ) {
							return [ 'http' => 200, 'row' => $existing, 'duplicate' => false ];
						}
					}
					if ( 1 === $attempt && ( str_contains( $msg, 'Deadlock' ) || str_contains( $msg, 'Lock wait timeout' ) ) ) {
						continue;
					}
					Logger::error( 'reservation.submit_failed', [ 'branch_id' => $branch_id, 'error' => substr( $msg, 0, 200 ) ] );
					throw new ReservationError( 'service_unavailable' );
				}
			}
		} finally {
			NamedLock::release( $lock );
		}

		$stored = ReservationRepository::find( $id );
		if ( null === $stored ) {
			throw new ReservationError( 'internal_error' );
		}
		return [ 'http' => 201, 'row' => $stored, 'duplicate' => false ];
	}

	/**
	 * T1: INSERT reservation (public_ref with ≤ 5 collision retries), token hash, `created` event; the
	 * `momos/reservation/created` hook fires after commit (Notification queues E01/E07).
	 *
	 * @param array<string,mixed> $row
	 */
	private static function t1( array $row ): int {
		return Transaction::run(
			static function () use ( $row ): int {
				global $wpdb;
				$id = 0;
				for ( $try = 0; $try < 5 && ! $id; $try++ ) {
					$ref = Crockford::random( 8 );
					if ( ReservationRepository::ref_exists( $ref ) ) {
						continue;
					}
					$row['public_ref'] = $ref;
					$row['token_hash'] = hash( 'sha256', random_bytes( 32 ) ); // replaced below once the id is known.
					try {
						$id = ReservationRepository::insert( $row );
					} catch ( \RuntimeException $e ) {
						if ( ! str_contains( $e->getMessage(), 'uq_public_ref' ) ) {
							throw $e;
						}
					}
				}
				if ( ! $id ) {
					throw new \RuntimeException( 'public_ref collisions' );
				}
				$token = TokenService::derive( $id, (string) $row['public_ref'], (string) $row['created_at'], (int) $row['token_key_version'] );
				$wpdb->update( \Momos\Kernel\Db\Tables::reservations(), [ 'token_hash' => TokenService::hash( $token ) ], [ 'id' => $id ] );
				EventRepository::add(
					$id,
					EventType::Created,
					Actor::customer(),
					null,
					ReservationStatus::Pending->value,
					null,
					null,
					[ 'captcha' => $row['captcha_status'], 'source_lang' => $row['source_lang'] ]
				);
				AfterCommit::dispatch( 'momos/reservation/created', $id );
				return $id;
			}
		);
	}
}
