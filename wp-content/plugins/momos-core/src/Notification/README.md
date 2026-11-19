# Notification

**Purpose.** All e-mail of the platform (spec `docs/specs/notifications.md`): a queue in `momo_notifications`
processed by an Action Scheduler worker, SMTP and log transports, customer templates E01–E06 and staff
templates E07–E11, recipient routing per branch, the E10 daily digest, the notification log, resend and
retention helpers.

## Public services
- `Notifier::queue( string $template_key, array $recipient, array $context, string $dedupe_key ): ?int`
  (`docs/CONTRACTS.md`) and `Queue\NotificationQueue::enqueue()`.
- `Template\TemplateRegistry` (E01 received · E02 confirmed · E03 declined · E04 cancelled · E05 expired ·
  E06 updated · E07 new request · E08 attention · E09 guest cancelled · E10 digest · E11 certificate expiry),
  `Template\Renderer` (HTML + text, per language).
- `Recipient\RecipientResolver` (branch recipients, HO fallback), `Service\ResendService`,
  `Service\Retention`, `Repository\NotificationRepository` (purge/delete/export helpers).

## Hooks
- Fires: `momos/notification/queued`, `…/sent`, `…/failed`, `…/resend_sent`; filters `…/recipients`,
  `…/transport`, `…/admin_reservation_url`, `…/lang_ready`, `…/e05_walk_in_allowed`.
- Consumes: `momos/reservation/created|status_changed|modified|sla_reminder|sla_escalated|token_reissued`,
  `momos/admin/reservation_detail` (panel on SCR-A09). E11 is queued by Dietary's certificate expiry job.

## Data
Table `momo_notifications` (template, language, status, attempts, unique `dedupe_key`, `recipient_hash` /
`recipient_masked`; the address itself is purged by retention — `recipient_purged_at`). Dev/CI: `MOMOS_MAIL_TRANSPORT=log` writes `.eml` files to `MOMOS_MAIL_CATCHER_DIR`; never sends.

## Capabilities
`momo_view_notification_log` (HO) for **Momo's → Notification log** (`momo-notifications`);
`momo_process_reservations` + branch scope for resend.

## Jobs · REST · CLI
- Action `momos_send_notification` (worker), `momos_job_notification_sweeper`, `momos_job_daily_digest`.
- REST `POST /momos/v1/staff/reservations/{id}/notifications/{notification_id}/resend`.
- `wp momos notifications send-due|sweep|digest|retention|render`.

## Tests
`Notification*Test.php`.
