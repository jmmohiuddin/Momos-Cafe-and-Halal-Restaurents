<?php
declare(strict_types=1);

namespace Momos\Kernel\Migration;

/**
 * Foreign keys and CHECK constraints (SDD §3.3.2). Each constraint is added only when absent.
 */
final class M0002_Constraints implements MigrationInterface {
	public function id(): string {
		return 'M0002_Constraints';
	}

	/** @return array<string, array{0:string,1:string}> name => [table short, definition] */
	public static function constraints(): array {
		global $wpdb;
		$p = $wpdb->prefix;
		return [
			'fk_momo_res_events_reservation' => [ 'reservation_events', "FOREIGN KEY (reservation_id) REFERENCES {$p}momo_reservations (id) ON DELETE RESTRICT ON UPDATE RESTRICT" ],
			'fk_momo_notif_reservation'      => [ 'notifications', "FOREIGN KEY (reservation_id) REFERENCES {$p}momo_reservations (id) ON DELETE RESTRICT ON UPDATE RESTRICT" ],
			'chk_momo_hours_weekday'         => [ 'branch_hours', 'CHECK (weekday BETWEEN 1 AND 7)' ],
			'chk_momo_hours_period'          => [ 'branch_hours', 'CHECK (period_no BETWEEN 1 AND 6)' ],
			'chk_momo_hours_label'           => [ 'branch_hours', "CHECK (period_label IN ('lunch','dinner','all_day','other'))" ],
			'chk_momo_hours_validity'        => [ 'branch_hours', 'CHECK (valid_from IS NULL OR valid_until IS NULL OR valid_until >= valid_from)' ],
			'chk_momo_clos_type'             => [ 'branch_closures', "CHECK (closure_type IN ('closed_all_day','special_hours'))" ],
			'chk_momo_clos_reason'           => [ 'branch_closures', "CHECK (public_reason IN ('public_holiday','private_event','maintenance','staff_shortage','other'))" ],
			'chk_momo_clos_shape'            => [ 'branch_closures', "CHECK ((closure_type = 'closed_all_day' AND period_no = 0 AND opens_at IS NULL AND closes_at IS NULL) OR (closure_type = 'special_hours' AND period_no BETWEEN 1 AND 6 AND opens_at IS NOT NULL AND closes_at IS NOT NULL))" ],
			'chk_momo_bmi_availability'      => [ 'branch_menu_items', "CHECK (availability IN ('available','sold_out','unavailable'))" ],
			'chk_momo_bmi_reason'            => [ 'branch_menu_items', "CHECK (availability_reason IS NULL OR availability_reason IN ('sold_out_today','seasonal','ingredient_unavailable','advance_order_only','other'))" ],
			'chk_momo_bmi_price'             => [ 'branch_menu_items', 'CHECK (price_override_jpy IS NULL OR price_override_jpy <= 100000)' ],
			'chk_momo_bmi_until'             => [ 'branch_menu_items', "CHECK (sold_out_until IS NULL OR availability = 'sold_out')" ],
			'chk_momo_res_status'            => [ 'reservations', "CHECK (status IN ('pending','confirmed','rejected','cancelled','expired','completed','no_show'))" ],
			'chk_momo_res_party'             => [ 'reservations', 'CHECK (party_size BETWEEN 1 AND 50)' ],
			'chk_momo_res_lang'              => [ 'reservations', "CHECK (preferred_lang IN ('ja','en','ar','ur','hi','bn') AND source_lang IN ('ja','en','ar','ur','hi','bn'))" ],
			'chk_momo_res_cancelled_by'      => [ 'reservations', "CHECK (cancelled_by IS NULL OR cancelled_by IN ('customer','staff','system'))" ],
			'chk_momo_res_captcha'           => [ 'reservations', "CHECK (captcha_status IN ('verified','unverified_outage','not_applicable'))" ],
			'chk_momo_res_source'            => [ 'reservations', "CHECK (source IN ('web','staff','api'))" ],
			'chk_momo_res_pii'               => [ 'reservations', 'CHECK (pii_purged_at IS NOT NULL OR (guest_name IS NOT NULL AND email IS NOT NULL AND token_hash IS NOT NULL))' ],
			'chk_momo_evt_type'              => [ 'reservation_events', "CHECK (event_type IN ('created','status_changed','assigned','released','note','modified','notification_failed','customer_contacted','sla_reminder','sla_escalated','closure_conflict','pii_purged'))" ],
			'chk_momo_evt_actor'             => [ 'reservation_events', "CHECK (actor_type IN ('customer','staff','system'))" ],
			'chk_momo_notif_status'          => [ 'notifications', "CHECK (status IN ('queued','sending','sent','failed','cancelled'))" ],
			'chk_momo_notif_channel'         => [ 'notifications', "CHECK (channel IN ('email'))" ],
			'chk_momo_notif_rtype'           => [ 'notifications', "CHECK (recipient_type IN ('customer','branch','head_office','user'))" ],
			'chk_momo_notif_attempts'        => [ 'notifications', 'CHECK (attempts <= 5)' ],
			'chk_momo_audit_actor'           => [ 'audit_log', "CHECK (actor_type IN ('staff','system','customer'))" ],
		];
	}

	public function up(): void {
		global $wpdb;
		$existing = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = %s',
				DB_NAME
			)
		);
		foreach ( self::constraints() as $name => [ $short, $definition ] ) {
			if ( in_array( $name, $existing, true ) ) {
				continue;
			}
			$table = \Momos\Kernel\Db\Tables::name( $short );
			$ok    = $wpdb->query( "ALTER TABLE {$table} ADD CONSTRAINT {$name} {$definition}" ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( false === $ok ) {
				throw new \RuntimeException( "Constraint {$name} failed: " . $wpdb->last_error );
			}
		}
	}
}
