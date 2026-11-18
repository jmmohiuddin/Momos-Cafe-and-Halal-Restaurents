<?php
declare(strict_types=1);

namespace Momos\Notification\Service;

use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Fields\FieldRegistry;
use Momos\Kernel\Settings;
use Momos\Notification\Recipient\RecipientResolver;
use Momos\Notification\Repository\NotificationRepository;

/**
 * Retention and DSR helpers for the Privacy module (FR-PRV-003/004, PRV-011, BR-PRV-02, DEC-034;
 * spec §4.13). The Privacy `RetentionJob` calls run(); reservation anonymisation calls
 * anonymise_reservation(). The DSR exporter `momos-notifications` is registered here.
 */
final class Retention {
	public const RECIPIENT_PURGE_DAYS = 30;

	public static function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', [ self::class, 'register_exporter' ] );
	}

	/** Notification row retention in days (`momo_retention_notification_days`, 30–730, default 180). */
	public static function days(): int {
		$v = null !== FieldRegistry::option_page_of( 'momo_retention_notification_days' ) ? Settings::get( 'momo_retention_notification_days', 180 ) : get_option( 'momo_retention_notification_days', 180 );
		$v = (int) $v;
		return $v >= 30 && $v <= 730 ? $v : 180;
	}

	/** @return array{recipients_purged:int,rows_deleted:int} */
	public static function run(): array {
		return [
			'recipients_purged' => NotificationRepository::purge_terminal_recipients( self::RECIPIENT_PURGE_DAYS ),
			'rows_deleted'      => NotificationRepository::delete_older_than( Clock::utc()->modify( '-' . self::days() . ' days' ) ),
		];
	}

	/** Reservation anonymised (retention or DSR): recipients redacted, queued rows cancelled. */
	public static function anonymise_reservation( int $reservation_id ): int {
		return NotificationRepository::purge_recipients_for_reservation( $reservation_id );
	}

	/**
	 * @param array<string,array<string,mixed>> $exporters
	 * @return array<string,array<string,mixed>>
	 */
	public static function register_exporter( array $exporters ): array {
		$exporters['momos-notifications'] = [
			'exporter_friendly_name' => __( "Momo's notification metadata", 'momos-core' ),
			'callback'               => [ self::class, 'export' ],
		];
		return $exporters;
	}

	/**
	 * DSR export: template, language, status, timestamps of emails sent to this address — no bodies,
	 * no other recipients (FR-PRV-004).
	 *
	 * @return array{data:list<array<string,mixed>>,done:bool}
	 */
	public static function export( string $email, int $page = 1 ): array {
		global $wpdb;
		$t    = \Momos\Kernel\Db\Tables::notifications();
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, template_key, template_variant, lang, status, created_at, sent_at, failed_at FROM {$t} WHERE recipient_hash = %s AND recipient_type = 'customer' ORDER BY id ASC LIMIT 500 OFFSET %d", RecipientResolver::hash( $email ), max( 0, $page - 1 ) * 500 ), ARRAY_A ); // phpcs:ignore
		$data = [];
		foreach ( $rows as $r ) {
			$data[] = [
				'group_id'    => 'momos-notifications',
				'group_label' => __( 'Reservation emails', 'momos-core' ),
				'item_id'     => 'momos-notification-' . $r['id'],
				'data'        => [
					[ 'name' => __( 'Template', 'momos-core' ), 'value' => trim( $r['template_key'] . ' ' . (string) $r['template_variant'] ) ],
					[ 'name' => __( 'Language', 'momos-core' ), 'value' => $r['lang'] ],
					[ 'name' => __( 'Status', 'momos-core' ), 'value' => $r['status'] ],
					[ 'name' => __( 'Created (UTC)', 'momos-core' ), 'value' => $r['created_at'] ],
					[ 'name' => __( 'Sent (UTC)', 'momos-core' ), 'value' => (string) $r['sent_at'] ],
					[ 'name' => __( 'Failed (UTC)', 'momos-core' ), 'value' => (string) $r['failed_at'] ],
				],
			];
		}
		return [ 'data' => $data, 'done' => count( $rows ) < 500 ];
	}
}
