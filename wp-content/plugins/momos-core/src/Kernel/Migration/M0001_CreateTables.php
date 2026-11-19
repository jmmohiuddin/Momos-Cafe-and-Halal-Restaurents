<?php
declare(strict_types=1);

namespace Momos\Kernel\Migration;

/**
 * Creates the 8 custom tables exactly as SDD §3.3.1 (dbDelta-compatible).
 */
final class M0001_CreateTables implements MigrationInterface {
	public function id(): string {
		return 'M0001_CreateTables';
	}

	public function up(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$p  = $wpdb->prefix;
		$cc = $wpdb->get_charset_collate();

		$sql   = [];
		$sql[] = "CREATE TABLE {$p}momo_branch_hours (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  branch_id bigint(20) unsigned NOT NULL,
  weekday tinyint(3) unsigned NOT NULL,
  period_no tinyint(3) unsigned NOT NULL DEFAULT 1,
  period_label varchar(20) NOT NULL DEFAULT 'all_day',
  opens_at time NOT NULL,
  closes_at time NOT NULL,
  closes_next_day tinyint(1) unsigned NOT NULL DEFAULT 0,
  last_order_at time DEFAULT NULL,
  valid_from date DEFAULT NULL,
  valid_until date DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  updated_by bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY idx_branch_weekday (branch_id,weekday,period_no),
  KEY idx_branch_validity (branch_id,valid_from,valid_until)
) ENGINE=InnoDB {$cc};";

		$sql[] = "CREATE TABLE {$p}momo_branch_closures (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  branch_id bigint(20) unsigned NOT NULL,
  closure_date date NOT NULL,
  closure_type varchar(20) NOT NULL,
  period_no tinyint(3) unsigned NOT NULL DEFAULT 0,
  period_label varchar(20) DEFAULT NULL,
  opens_at time DEFAULT NULL,
  closes_at time DEFAULT NULL,
  closes_next_day tinyint(1) unsigned NOT NULL DEFAULT 0,
  last_order_at time DEFAULT NULL,
  public_reason varchar(30) NOT NULL DEFAULT 'other',
  internal_note varchar(255) DEFAULT NULL,
  created_at datetime NOT NULL,
  created_by bigint(20) unsigned DEFAULT NULL,
  updated_at datetime NOT NULL,
  updated_by bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_branch_date_period (branch_id,closure_date,period_no),
  KEY idx_date (closure_date)
) ENGINE=InnoDB {$cc};";

		$sql[] = "CREATE TABLE {$p}momo_branch_menu_items (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  branch_id bigint(20) unsigned NOT NULL,
  dish_id bigint(20) unsigned NOT NULL,
  is_offered tinyint(1) unsigned NOT NULL DEFAULT 1,
  price_override_jpy int(10) unsigned DEFAULT NULL,
  availability varchar(20) NOT NULL DEFAULT 'available',
  sold_out_until datetime DEFAULT NULL,
  availability_reason varchar(30) DEFAULT NULL,
  sort_order smallint(6) DEFAULT NULL,
  external_ref varchar(64) DEFAULT NULL,
  version int(10) unsigned NOT NULL DEFAULT 1,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  updated_by bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_branch_dish (branch_id,dish_id),
  KEY idx_branch_offered (branch_id,is_offered,availability),
  KEY idx_dish (dish_id),
  KEY idx_soldout (availability,sold_out_until),
  KEY idx_external_ref (external_ref)
) ENGINE=InnoDB {$cc};";

		$sql[] = "CREATE TABLE {$p}momo_reservations (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  public_ref char(8) NOT NULL,
  idempotency_key char(36) NOT NULL,
  branch_id bigint(20) unsigned NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  requested_date date NOT NULL,
  requested_time time NOT NULL,
  requested_start_utc datetime NOT NULL,
  party_size tinyint(3) unsigned NOT NULL,
  guest_name varchar(100) DEFAULT NULL,
  email varchar(254) DEFAULT NULL,
  phone_e164 varchar(20) DEFAULT NULL,
  special_request text DEFAULT NULL,
  preferred_lang char(2) NOT NULL,
  source_lang char(2) NOT NULL,
  consent_version varchar(20) NOT NULL,
  consent_at datetime NOT NULL,
  token_hash char(64) DEFAULT NULL,
  token_key_version tinyint(3) unsigned NOT NULL DEFAULT 1,
  token_expires_utc datetime NOT NULL,
  handled_by bigint(20) unsigned DEFAULT NULL,
  decision_reason_code varchar(40) DEFAULT NULL,
  message_to_customer varchar(500) DEFAULT NULL,
  suggested_times text DEFAULT NULL,
  customer_notified_flag tinyint(1) unsigned NOT NULL DEFAULT 1,
  needs_action_flag tinyint(1) unsigned NOT NULL DEFAULT 0,
  captcha_status varchar(20) NOT NULL DEFAULT 'verified',
  cancelled_by varchar(10) DEFAULT NULL,
  source varchar(10) NOT NULL DEFAULT 'web',
  external_ref varchar(64) DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  confirmed_at datetime DEFAULT NULL,
  rejected_at datetime DEFAULT NULL,
  cancelled_at datetime DEFAULT NULL,
  expired_at datetime DEFAULT NULL,
  attended_at datetime DEFAULT NULL,
  version int(10) unsigned NOT NULL DEFAULT 1,
  ip_hash char(64) DEFAULT NULL,
  user_agent_hash char(64) DEFAULT NULL,
  pii_purged_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_public_ref (public_ref),
  UNIQUE KEY uq_idempotency_key (idempotency_key),
  KEY idx_branch_status_start (branch_id,status,requested_start_utc),
  KEY idx_status_start (status,requested_start_utc),
  KEY idx_email_branch_status (email(191),branch_id,status),
  KEY idx_created (created_at),
  KEY idx_requested_date (requested_date)
) ENGINE=InnoDB {$cc};";

		$sql[] = "CREATE TABLE {$p}momo_reservation_events (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  reservation_id bigint(20) unsigned NOT NULL,
  event_type varchar(30) NOT NULL,
  from_status varchar(20) DEFAULT NULL,
  to_status varchar(20) DEFAULT NULL,
  actor_type varchar(10) NOT NULL,
  actor_id bigint(20) unsigned DEFAULT NULL,
  actor_display varchar(100) DEFAULT NULL,
  reason_code varchar(40) DEFAULT NULL,
  note text DEFAULT NULL,
  payload longtext DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY idx_reservation_created (reservation_id,created_at),
  KEY idx_type_created (event_type,created_at)
) ENGINE=InnoDB {$cc};";

		$sql[] = "CREATE TABLE {$p}momo_notifications (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  uuid char(36) NOT NULL,
  dedupe_key varchar(120) NOT NULL,
  template_key varchar(10) NOT NULL,
  template_variant varchar(30) DEFAULT NULL,
  channel varchar(10) NOT NULL DEFAULT 'email',
  lang char(2) NOT NULL,
  recipient_type varchar(20) NOT NULL,
  recipient_email varchar(254) DEFAULT NULL,
  recipient_hash char(64) NOT NULL,
  recipient_masked varchar(100) NOT NULL,
  recipient_user_id bigint(20) unsigned DEFAULT NULL,
  reservation_id bigint(20) unsigned DEFAULT NULL,
  branch_id bigint(20) unsigned DEFAULT NULL,
  object_type varchar(30) DEFAULT NULL,
  object_id bigint(20) unsigned DEFAULT NULL,
  status varchar(20) NOT NULL DEFAULT 'queued',
  attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
  next_attempt_at datetime DEFAULT NULL,
  locked_at datetime DEFAULT NULL,
  provider_message_id varchar(190) DEFAULT NULL,
  last_error_code varchar(60) DEFAULT NULL,
  last_error varchar(500) DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  sent_at datetime DEFAULT NULL,
  failed_at datetime DEFAULT NULL,
  recipient_purged_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_uuid (uuid),
  UNIQUE KEY uq_dedupe (dedupe_key),
  KEY idx_status_next (status,next_attempt_at),
  KEY idx_reservation (reservation_id),
  KEY idx_branch_status (branch_id,status),
  KEY idx_created (created_at)
) ENGINE=InnoDB {$cc};";

		$sql[] = "CREATE TABLE {$p}momo_user_branches (
  user_id bigint(20) unsigned NOT NULL,
  branch_id bigint(20) unsigned NOT NULL,
  assigned_by bigint(20) unsigned DEFAULT NULL,
  assigned_at datetime NOT NULL,
  PRIMARY KEY  (user_id,branch_id),
  KEY idx_branch (branch_id)
) ENGINE=InnoDB {$cc};";

		$sql[] = "CREATE TABLE {$p}momo_audit_log (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  occurred_at datetime NOT NULL,
  actor_type varchar(10) NOT NULL,
  actor_id bigint(20) unsigned DEFAULT NULL,
  actor_role varchar(40) DEFAULT NULL,
  action varchar(60) NOT NULL,
  object_type varchar(30) NOT NULL,
  object_id bigint(20) unsigned DEFAULT NULL,
  branch_id bigint(20) unsigned DEFAULT NULL,
  changes longtext DEFAULT NULL,
  ip_truncated varchar(45) DEFAULT NULL,
  request_id char(36) DEFAULT NULL,
  row_hmac char(64) DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY idx_occurred (occurred_at),
  KEY idx_branch_time (branch_id,occurred_at),
  KEY idx_actor_time (actor_id,occurred_at),
  KEY idx_object (object_type,object_id,occurred_at),
  KEY idx_action_time (action,occurred_at)
) ENGINE=InnoDB {$cc};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
		foreach ( \Momos\Kernel\Db\Tables::ALL as $short ) {
			$table = \Momos\Kernel\Db\Tables::name( $short );
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				throw new \RuntimeException( "Table {$table} was not created" );
			}
		}
	}
}
