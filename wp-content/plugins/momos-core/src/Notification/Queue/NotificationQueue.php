<?php
declare(strict_types=1);

namespace Momos\Notification\Queue;

use Momos\Kernel\Events\AfterCommit;
use Momos\Kernel\Log\Logger;
use Momos\Kernel\Support\Uuid;
use Momos\Notification\Recipient\RecipientResolver;
use Momos\Notification\Repository\NotificationRepository;
use Momos\Notification\Support\ReservationReader;
use Momos\Notification\Template\TemplateRegistry;

/**
 * Enqueue (F-NOT-03 / FR-NOT-003, spec §2 and §4.1–4.2). One row per recipient, idempotent on
 * `dedupe_key`. When called inside the caller's transaction the rows commit or roll back with it;
 * `momos/notification/queued` is dispatched after commit so the async send starts only for committed
 * rows (AfterCommit::discard on ROLLBACK).
 */
final class NotificationQueue {
	/**
	 * Resolve recipients and insert rows. Returns the ids of NEW rows (duplicates are skipped).
	 *
	 * @return list<int>
	 */
	public static function enqueue( NotificationRequest $req ): array {
		$def = TemplateRegistry::get( $req->template_key );
		if ( null === $def ) {
			throw new \InvalidArgumentException( 'Unknown template ' . $req->template_key );
		}
		$reservation = null !== $req->reservation_id ? ReservationReader::find( $req->reservation_id ) : null;
		$recipients  = self::recipients( $req, $reservation );
		$recipients  = RecipientResolver::filter( $recipients, $req->template_key, $reservation );
		if ( ! $recipients ) {
			if ( 'customer' !== $req->audience ) {
				Logger::warning( 'notification.no_recipients', [ 'template' => $req->template_key, 'branch_code' => self::branch_code( $req->branch_id ) ] );
			}
			return [];
		}
		$multi = 'customer' !== $req->audience;
		$ids   = [];
		foreach ( $recipients as $r ) {
			$key = $multi ? $req->dedupe_base . ':' . self::hash_suffix( $r['email'] ) : $req->dedupe_base;
			$id  = self::insert( $req->template_key, $r, $key, $req->variant, $req->reservation_id, $req->branch_id, $req->object_type, $req->object_id );
			if ( null !== $id ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/** Convenience: first new id or null (spec signature `enqueue(NotificationRequest): ?int`). */
	public static function enqueue_one( NotificationRequest $req ): ?int {
		$ids = self::enqueue( $req );
		return $ids[0] ?? null;
	}

	/**
	 * Insert a single row for an already-resolved recipient. Returns null on duplicate dedupe key or
	 * invalid input.
	 *
	 * @param array{type:string,email:string,lang:string,user_id:?int} $r
	 */
	public static function insert( string $template_key, array $r, string $dedupe_key, ?string $variant = null, ?int $reservation_id = null, ?int $branch_id = null, ?string $object_type = null, ?int $object_id = null ): ?int {
		$email = RecipientResolver::normalize( (string) ( $r['email'] ?? '' ) );
		if ( '' === $email || ! in_array( $r['type'] ?? '', NotificationRepository::RECIPIENT_TYPES, true ) || '' === $dedupe_key || strlen( $dedupe_key ) > 120 ) {
			Logger::warning( 'notification.invalid_request', [ 'template' => $template_key ] );
			return null;
		}
		$id = NotificationRepository::insert(
			[
				'uuid'              => Uuid::v4(),
				'dedupe_key'        => $dedupe_key,
				'template_key'      => $template_key,
				'template_variant'  => null === $variant || '' === $variant ? null : mb_substr( $variant, 0, 30 ),
				'lang'              => (string) ( $r['lang'] ?? 'ja' ),
				'recipient_type'    => (string) $r['type'],
				'recipient_email'   => mb_substr( $email, 0, 254 ),
				'recipient_hash'    => RecipientResolver::hash( $email ),
				'recipient_masked'  => RecipientResolver::mask( $email ),
				'recipient_user_id' => isset( $r['user_id'] ) && $r['user_id'] ? (int) $r['user_id'] : null,
				'reservation_id'    => $reservation_id,
				'branch_id'         => $branch_id,
				'object_type'       => $object_type,
				'object_id'         => $object_id,
			]
		);
		if ( null !== $id ) {
			AfterCommit::dispatch( 'momos/notification/queued', $id );
		}
		return $id;
	}

	/** 32 hex characters of the recipient hash for multi-recipient dedupe keys (spec §4.2, C7). */
	public static function hash_suffix( string $email ): string {
		return substr( RecipientResolver::hash( $email ), 0, 32 );
	}

	/**
	 * @param array<string,mixed>|null $reservation
	 * @return list<array{type:string,email:string,lang:string,user_id:?int}>
	 */
	private static function recipients( NotificationRequest $req, ?array $reservation ): array {
		switch ( $req->audience ) {
			case 'customer':
				$c = $reservation ? RecipientResolver::customer( $reservation ) : null;
				return $c ? [ $c ] : [];
			case 'branch_recipients':
				$branch = $req->branch_id ?? (int) ( $reservation['branch_id'] ?? 0 );
				$out    = $branch ? RecipientResolver::branch( $branch ) : [];
				foreach ( $req->extra_user_ids as $uid ) {
					$u = RecipientResolver::user( (int) $uid );
					if ( $u && ! in_array( $u['email'], array_column( $out, 'email' ), true ) ) {
						$out[] = $u;
					}
				}
				return $out;
			case 'head_office':
				return RecipientResolver::head_office();
			case 'head_office_digest':
				return RecipientResolver::head_office( true );
			case 'ho_and_dca':
				return RecipientResolver::ho_and_dca();
			default:
				return array_values( $req->explicit_recipients );
		}
	}

	private static function branch_code( ?int $branch_id ): string {
		return $branch_id ? (string) get_post_meta( $branch_id, 'momo_branch_code', true ) : '';
	}
}
