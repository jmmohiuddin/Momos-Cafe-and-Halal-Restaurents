<?php
declare(strict_types=1);

namespace Momos\Notification\Template;

use Momos\Kernel\I18n\Translations;
use Momos\Kernel\I18n\Url;

/**
 * Reservation policy line (DEC-054, BR-RES-14): rendered only when the branch has a published policy
 * page with an approved title in the email language — never invented grace periods or fees.
 */
final class Policy {
	/**
	 * @param array<string,mixed> $settings branch G6 settings (policy_page = published page id or null)
	 * @return array<string,mixed>|null links block
	 */
	public static function block( array $settings, string $lang, callable $t ): ?array {
		$page = (int) ( $settings['policy_page'] ?? 0 );
		if ( $page <= 0 || 'publish' !== get_post_status( $page ) ) {
			return null;
		}
		$title = trim( Translations::post( $page, 'title', $lang ) );
		if ( '' === $title ) {
			return null;
		}
		$url = Url::localize( (string) get_permalink( $page ), $lang );
		return [ 'type' => 'links', 'heading' => $t( 'email.policy.heading' ), 'items' => [ [ $title, $url ] ] ];
	}
}
