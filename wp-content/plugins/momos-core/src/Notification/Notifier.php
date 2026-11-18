<?php
declare(strict_types=1);

namespace Momos\Notification;

use Momos\Kernel\I18n\Lang;
use Momos\Notification\Queue\NotificationQueue;
use Momos\Notification\Recipient\RecipientResolver;
use Momos\Notification\Template\TemplateRegistry;

/**
 * Cross-module contract (docs/CONTRACTS.md): enqueue one notification for one recipient, deduped by
 * `$dedupe_key`. Returns the notification id, or null when deduped or invalid. The worker sends it.
 *
 * $recipient = ['type' => 'customer'|'branch'|'head_office'|'user', 'email' => …, 'lang' => …, 'user_id' => ?int]
 * $context   = reservation_id, branch_id, variant, object_type, object_id (certificate_id for E11)
 */
final class Notifier {
	/**
	 * @param array<string,mixed> $recipient
	 * @param array<string,mixed> $context
	 */
	public static function queue( string $template_key, array $recipient, array $context, string $dedupe_key ): ?int {
		$def = TemplateRegistry::get( $template_key );
		if ( null === $def ) {
			return null;
		}
		$email = RecipientResolver::normalize( (string) ( $recipient['email'] ?? '' ) );
		$type  = (string) ( $recipient['type'] ?? '' );
		if ( '' === $email || ! is_email( $email ) ) {
			return null;
		}
		if ( 'staff' === $def['audience'] ) {
			if ( 'customer' === $type ) {
				return null; // Staff templates never go to a customer address (Doc 11 §5.4).
			}
			$r = RecipientResolver::staff( $type, $email, (string) ( $recipient['lang'] ?? 'ja' ) );
			if ( ! empty( $recipient['user_id'] ) && null === $r['user_id'] ) {
				$r['user_id'] = (int) $recipient['user_id'];
			}
		} else {
			$lang = (string) ( $recipient['lang'] ?? 'ja' );
			$r    = [ 'type' => 'customer', 'email' => $email, 'lang' => Lang::is_valid( $lang ) ? $lang : 'ja', 'user_id' => null ];
		}
		$object_type = isset( $context['object_type'] ) ? (string) $context['object_type'] : ( isset( $context['certificate_id'] ) ? 'certificate' : ( isset( $context['reservation_id'] ) ? 'reservation' : null ) );
		$object_id   = isset( $context['object_id'] ) ? (int) $context['object_id'] : ( isset( $context['certificate_id'] ) ? (int) $context['certificate_id'] : ( isset( $context['reservation_id'] ) ? (int) $context['reservation_id'] : null ) );
		return NotificationQueue::insert(
			$template_key,
			$r,
			$dedupe_key,
			isset( $context['variant'] ) ? (string) $context['variant'] : null,
			isset( $context['reservation_id'] ) ? (int) $context['reservation_id'] : null,
			isset( $context['branch_id'] ) ? (int) $context['branch_id'] : null,
			$object_type,
			$object_id
		);
	}
}
