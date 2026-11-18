# Notification module — implementation spec (queue, customer & staff emails, digest, log, transport)

Status: build spec v1.0 for `momos-core` (PHP 8.3, namespace `Momos\Notification\…`) and theme `momos` (no email
templates live in the theme). Audience: developers who will not read the source package. Every rule is traceable to
an ID of the documentation package (Doc 04 PRD, 04A, 05, 06, 07, 08, 09 TRD, 10 SDD, 11, 12, 13, 17).
Values in ⟦…⟧ are TBD; the documented proposal is used as the default. Business facts (brand domain, addresses,
sender address, branch codes, operator name) are NEVER hard-coded: placeholders such as `{BRAND_DOMAIN}`, `SHN`
(DEC-052 example code only) and `XXXX-XXXX` are used. Japanese strings are drafts until native/legal review (GATE-04);
strings marked **[draft — new]** are not in the documents and were written for this spec.

## 0. How the documented mechanisms map to this build (IMPL-001/002/004)

| Documented mechanism | Build equivalent (this spec) |
|---|---|
| FluentSMTP + provider API connector; credentials as FluentSMTP wp-config constants | `Notification\Transport\SmtpConfigurator` on `phpmailer_init` + `wp_mail()`; SMTP submission to the provider (Amazon SES SMTP endpoint preferred, ⟦Q-030⟧); credentials in `MOMOS_SMTP_*` constants (§4.11). No SMTP plugin, no plugin log |
| FluentSMTP failure-alert / fallback connection | Optional fallback constant set `MOMOS_SMTP_FALLBACK_*`; failure alerting via momos-core log level `critical` + heartbeat monitor (§4.10) |
| FluentSMTP email log (7 days, bodies off) | Not present. `momo_notifications` is the only delivery log; no body is stored anywhere (PRV-011) |
| WPML String Translation for email strings (6 languages) | String keys of Doc 07 §6 (+ new `email.*` / `staff.email.*` keys below) resolved by the Kernel string catalogue (`languages/strings/{lang}.json`, review status per key and language). Staff strings ja/en only. Use the Kernel catalogue API whatever its final class name; the **keys** are canonical |
| ACF options page G3 `momo-settings` tab "Notifications" | Options registered in code (`register_setting`) on SCR-A15, tab "Notifications / 通知設定" (§3.3) |
| ACF repeater `momo_res_notification_recipients` (G6) | Branch post meta `momo_res_notification_recipients` = JSON `[{email, language:"ja"\|"en", active:bool}]` (owned by Branch spec §4.8) |
| ACF repeaters `momo_ho_escalation_recipients`, `momo_digest_recipients` (G3) | Options holding the same JSON row shape |
| ACF translatable `momo_sender_name` | Option `momo_sender_name` = JSON map `{ja,en,ar,ur,hi,bn}` |
| Branch/brand translated values (WPML) | Branch name/address in language L from `_momos_i18n_L` when `_momos_i18n_status_L = approved`, else the email falls back as a whole (§4.6) |
| Action Scheduler (bundled, group `momos`) | Same: `vendor/woocommerce/action-scheduler`, hooks unchanged, driven by real server cron (`DISABLE_WP_CRON`) |

## 1. Requirements covered

Priority: M = Must, S = Should, C = Could.

### 1.1 Features, functional & non-functional requirements, business rules

| ID | Statement (one line) | Pri |
|---|---|---|
| F-NOT-01 / FR-NOT-001 | Customer emails E01–E06 in `preferred_lang` (fallback en → ja, recorded), common + template content, HTML + text, `lang`/`dir`, no tracking | M (E06 S) |
| FR-NOT-005 | Subjects: brand + status phrase + reference only; no name, email, phone, date, address | M |
| F-NOT-02 / FR-NOT-002 | Staff emails E07–E11 in recipient locale (ja/en), minimal PII, deep link; branch routing only | M (E08 SLA variants, E10 S) |
| F-NOT-03 / FR-NOT-003 | Queue inside triggering transaction; async worker; 5 attempts, backoff 1/5/15/60 min; final-failure effects; never rolls back a reservation | M |
| FR-NOT-004 | Log: template, language, masked recipient, object, status, attempts, next attempt, provider message ID, last error ≤ 500; no bodies; 180-day deletion; recipient purge | M |
| FR-NOT-006 | Sending identity: brand domain From, branch Reply-To, SPF/DKIM 2048/DMARC aligned, Hostinger Mail not used | M |
| FR-I18N-005 / FR-I18N-006(d) | RTL + LTR isolation in emails; JST label only in non-ja customer emails (DEC-062) | M |
| FR-I18N-010(c)(d) / FR-I18N-011 | Staff emails ja/en by recipient locale; customer templates exist and are reviewed in every enabled language | M |
| FR-RES-017 (S) | E08 SLA reminder at SLA, HO escalation at 2 × SLA, once each, only while `pending` (job owned by Reservation spec) | S |
| FR-RES-029 (S) | Carrier-domain whitelisting guidance in E01 (and other customer emails, §4.12) when `carrier_email` | S |
| FR-RES-030 | TEST-branch reservations notify only the TEST branch's (QA) recipients | M |
| FR-DIET-002(c) | E11 certificate expiry `cert_30` / `cert_7` / `cert_expired`, independent of E10 | M |
| FR-BR-008(c) | BM branch changes (last 24 h) listed in the HO digest | M (digest itself S) |
| FR-FND-005(a) | Head Office notification email list (escalations, digests, E11) | M |
| FR-RES-023 (recipients) | 1–10 business-controlled recipients per branch (DEC-049) | M |
| FR-PRV-003 / FR-PRV-004 | Retention purge of notification rows/recipients; DSR export includes notification metadata | M / S |
| NFR-PERF-05 | Event → provider hand-off ≤ 60 s p95 (`sent_at − created_at`) | M |
| NFR-REL-01 | No lost requests; email failure never loses or rolls back a reservation | M |
| NFR-OBS-01 | Queue heartbeat; alert if no queue run for 30 min; failures visible in admin | M |
| BR-NOT-01 | Staff emails minimal PII; customer emails only that customer's data | M |
| BR-NOT-02 | Internal notes never in customer output; `message_to_customer` is the only staff text sent to customers | M |
| BR-RES-01 | No request-context term (Doc 07 §6.6) in E01, and in E04/E06 while `pending` | M |
| BR-RES-12 | Email failure never invalidates a reservation; `customer_notified_flag = 0` → staff follow-up | M |
| BR-PRV-02 | Notification log 180 days (DEC-034) | M |
| BR-PRV-04 | No personal data to machine-translation services (email strings are translated by humans only) | M |

### 1.2 Compliance controls (Doc 11)

| ID | Control (this module's part) | Pri |
|---|---|---|
| PRV-004 | Special-request text never in any email; customer emails echo only "We have received your note" | M |
| PRV-005 | No promotional content, UTM, pixels or click tracking in E01–E11 | M |
| PRV-006 | No PII in subjects or logs | M |
| PRV-008 | E07–E10: reference, branch, date/time, party size, given name (≤ 20 chars) + admin link only | M |
| PRV-009 | Customer email: exactly one To, no Cc/Bcc, Reply-To branch only, no other customer's data | M |
| PRV-010 | Staff recipients only on business-controlled domains (validation owned by Branch spec §4.8) | M |
| PRV-011 | Metadata-only log; recipient redacted on anonymisation; 180-day purge | M |
| SEC-012 | Branch isolation on the notification channel (E07/E08/E09, digests) | M |
| SEC-021 / SEC-033 | Context-correct escaping; fixed From, branch Reply-To, no user-controlled headers, tracking off | M |
| SEC-054 | SPF (provider only), DKIM 2048, DMARC `p=none` → `quarantine` → `reject` | M |
| SEC-055 | Staging mail captured (sandbox), never reaches real recipients | M |
| SEC-081 | Never log guest name, email, phone, note, tokens in notification rows/logs | M |
| CMP-004 / CMP-014 | Request wording scan of E01; E11 alerts 30/7/0 days | M |

### 1.3 User stories, screens, tasks

| ID | Summary | Pri |
|---|---|---|
| US-NOT-001 | Acknowledgement email in my language (E01) | M |
| US-NOT-002 | Confirmation with both addresses, directions, branch message (E02) | M |
| US-NOT-003 | Clear decline with reason and suggested times (E03) | M |
| US-NOT-004 | Staff alert without guest details (E07) | M |
| US-NOT-005 | Know when a guest was not reached (flag + E08 failure) | M |
| US-NOT-006 | Guest cancellation → E09 to branch, E04 to guest | M |
| US-NOT-007 | Daily digest of exceptions and branch changes (E10) | S |
| US-NOT-008 | Authenticated brand-domain sending | M |
| SCR-E01..E11 | Email templates | M (E06, E10 S; E08 SLA S) |
| SCR-A09 / SCR-A08 / SCR-A15 | Notification panel + flag; "Customer not notified" filter; Notifications settings tab | M |
| TK-10-01..09 | Queue; transport + DNS; E01–E06; E07–E11; routing; digest (S); log in detail; carrier content; deliverability tests | M (TK-10-06 S) |

Decisions applied: DEC-011 (status labels), DEC-017 (email only + badges; minimal staff PII), DEC-018 (dedicated
provider, SPF/DKIM/DMARC, carrier guidance), DEC-034 (retention), DEC-037 (staff locale), DEC-043 (no marketing),
DEC-049 (business recipients), DEC-053 (special request), DEC-054 (policy text only if supplied), DEC-058/064 (status
link), DEC-062 (JST label).

## 2. Module structure

| Class | Responsibility |
|---|---|
| `NotificationModule` | Registers hooks, Action Scheduler handlers, settings tab, admin views |
| `Queue\NotificationQueue::enqueue(NotificationRequest): ?int` | Resolves recipients, computes dedupe key/hash/mask, INSERTs one row per recipient (`INSERT IGNORE` on `uq_dedupe`; duplicate → returns null), then `AfterCommit::dispatch('momos/notification/queued', $id)`. Must be called inside the caller's transaction for reservation templates; standalone for E10/E11 |
| `Queue\Dispatcher::send(int $id)` | Claim → guard → render → transport → mark (§4.3) |
| `Queue\RetryPolicy` | Backoff table, max attempts, error classification (§4.4) |
| `Queue\QueueSweeper` | Crash recovery, re-enqueue, heartbeat, failure-rate check (§4.10) |
| `Recipient\RecipientResolver` | Branch, HO, CAP-DCA, customer recipients; locale per recipient (§4.5) |
| `Template\TemplateRegistry` | SCR-E key → template files, variants, recipient type, subject key (§5) |
| `Template\Renderer` | Locale switch (`Locale::with()` + `switch_to_locale()`), data contract per template, HTML + text, escaping, fallback (§4.6) |
| `Template\EmailFormatter` | Dates/times per Doc 07 §6.10 (ICU), JST label, `<bdi>` isolation, given-name rule |
| `Transport\TransportInterface` / `WpMailTransport` | Headers, Message-ID, multipart, error capture (`wp_mail_failed`, SMTP reply code) |
| `Transport\SmtpConfigurator` | `phpmailer_init`: SMTP host/port/TLS/auth from constants, sandbox reroute |
| `Job\DigestJob` | E10 builder (§4.8) |
| `Repository\NotificationRepository` | All SQL on `momo_notifications` (prepared statements only) |
| `Admin\NotificationPanel`, `Admin\NotificationLogScreen`, `Admin\SettingsTab` | SCR-A09 panel, log view, SCR-A15 tab (§7) |

Hooks registered: Action Scheduler `momos_send_notification` (args `[id]`), `momos_job_notification_sweeper` (every
5 min), `momos_job_daily_digest` (daily at `momo_digest_time` JST); `phpmailer_init`; `wp_mail_failed`.
Emits: `momos/notification/failed(int $notificationId, ?int $reservationId)` (consumed by Reservation: flag + event),
`momos/notification/sent(int $notificationId)`; filter `momos/notification/recipients(list<Recipient>, string $template, ?ReservationDto)`.
Callers: Reservation (T1–T6, N3, SLA job, failure follow-up), Dietary (`CertificateExpiryJob` → E11), Privacy (retention, DSR).
Notification reads other modules only through read DTOs (`ReservationReader`, `BranchReader`, `CertificateReader`, `AuditReader`).

## 3. Data model

### 3.1 Table `{prefix}momo_notifications` (ENT-17) — DDL (SDD §3.3, already in `Kernel\Migration\M0001_CreateTables`)

```sql
CREATE TABLE {$prefix}momo_notifications (
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
) ENGINE=InnoDB {$charset_collate};

ALTER TABLE {$prefix}momo_notifications
  ADD CONSTRAINT fk_momo_notif_reservation FOREIGN KEY (reservation_id)
  REFERENCES {$prefix}momo_reservations (id) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE {$prefix}momo_notifications
  ADD CONSTRAINT chk_momo_notif_status CHECK (status IN ('queued','sending','sent','failed','cancelled')),
  ADD CONSTRAINT chk_momo_notif_channel CHECK (channel IN ('email')),
  ADD CONSTRAINT chk_momo_notif_rtype CHECK (recipient_type IN ('customer','branch','head_office','user')),
  ADD CONSTRAINT chk_momo_notif_attempts CHECK (attempts <= 5);
```

All datetimes are UTC. Column semantics:

| Column | Rule |
|---|---|
| `uuid` | UUIDv4 (`Kernel\Support\Uuid`); also the Message-ID local part |
| `dedupe_key` | Per §4.2; UNIQUE → idempotent enqueue (double submit, job re-runs) |
| `template_key` / `template_variant` | `E01`…`E11` / variant code (§3.4) |
| `lang` | Language **actually rendered** (`ja,en,ar,ur,hi,bn`); set at enqueue to the resolved language, overwritten at render if a fallback happened |
| `recipient_email` | Lower-cased domain, trimmed; NULL after purge |
| `recipient_hash` | `Hash::hmac(strtolower(trim(email)), 'MOMOS_HASH_PEPPER')` (64 hex; DEC-059) — kept until row deletion |
| `recipient_masked` | `Hash::mask_email()` → `a***@d***.com`; `[purged]` after reservation anonymisation |
| `recipient_user_id` | WP user ID when the address belongs to an active staff user (locale source); else NULL |
| `reservation_id` / `branch_id` | Set for E01–E10 branch items; `branch_id` = branch post ID (also for E10 `branch`); NULL for E10 `head_office`, E11 |
| `object_type` / `object_id` | `reservation`/id (E01–E09); `notification`/failed row id (E08 `notify_failed`); `digest`/branch id or 0 (E10); `certificate`/id (E11) |
| `attempts` | Incremented at claim (0–5) |
| `provider_message_id` | `<{uuid}@{MOMOS_MAIL_DOMAIN}>` set when the provider accepted (TRD FR-NOT-006) |
| `last_error_code` / `last_error` | Code from §4.4; text redacted with `Kernel\Log\Redactor::scrub()` (no email address, no name), truncated to 500, suffixed ` [req:{request_id}]` when available |

Bodies and subjects are **never stored** (PRV-011, LC-001 REQ-RES-N6). Rendering happens at send time from current data.

### 3.2 Enumerations

| Enum | Codes |
|---|---|
| `NotificationStatus` | `queued`, `sending`, `sent`, `failed`, `cancelled` |
| `RecipientType` | `customer`, `branch` (branch recipient list), `head_office` (HO/digest list), `user` (CAP-DCA holder, `handled_by` user) |
| `Channel` | `email` (LINE/SMS Phase 2 — DEC-017) |
| Languages | `ja` (source), `en`, `ar`, `ur` (RTL), `hi`, `bn` — customer; staff `ja`, `en` |
| Status labels (DEC-011) | `pending`, `confirmed`, `rejected`, `cancelled`, `expired` (customer never sees `completed`/`no_show`; they keep the confirmed label + `status.past`) |
| Decline reason | `fully_booked`, `closed_or_private_event`, `party_size_not_supported`, `incomplete_information`, `other` |
| Staff cancel reason | `customer_request_by_phone`, `branch_unable_to_serve`, `duplicate`, `suspected_spam` (suppresses E04), `other` |
| Reservation events written on behalf of this module | `notification_failed`, `customer_contacted` (by API-13), `sla_reminder`, `sla_escalated` |

### 3.3 Settings and meta used (SCR-A15 tab "Notifications", cap `momo_manage_brand`)

| Key | Type | Req | Translatable | Validation | Default |
|---|---|---|---|---|---|
| `momo_sender_name` | JSON map lang → string ≤ 60 | Y | yes (per language in the map) | no CR/LF, no `<>@"` | `{ja: momo_trading_name}`; missing language → trading name in that language → ja value |
| `momo_ho_escalation_recipients` | JSON `[{email, language:"ja"\|"en", active}]` | ≥ 1 active row before launch | no | valid email, unique (ci), ≤ 10 rows, domain rule as branch recipients (block list + optional allow-list `momo_allowed_recipient_domains`, DEC-049/PRV-010) | `[]` ⟦HO inbox — Q-416/Q-409⟧ — the "HO notification list" of FR-FND-005 (E08 escalation, E10 HO, E11) |
| `momo_digest_recipients` | same JSON | — | no | same | `[]` — additional E10 `head_office` recipients only |
| `momo_digest_enabled` | bool | — | no | — | `1` |
| `momo_digest_time` | `HH:MM` JST | Y | no | 00:00–23:59, 5-min steps | ⟦09:00 — Q-416⟧ (PRD; TRD/SDD say 08:00 — §12) |
| `momo_carrier_domains` | text, one domain per line (owned by Reservation spec, read here) | — | no | lower-case domains | ⟦docomo.ne.jp, ezweb.ne.jp, au.com, softbank.ne.jp, i.softbank.jp, ymobile.ne.jp⟧ |
| `momo_retention_notification_days` | int (Privacy tab, read-only display) | Y | no | 180 fixed by DEC-034 | 180 |
| `momo_res_show_dont_travel` [new] | bool | — | no | — | `1` (Doc 07 open item #5 — remove if V8 test says so) |
| Branch meta `momo_res_notification_recipients` | JSON rows | ≥ 1 active when `momo_res_enabled = 1` | no | Branch spec §4.8 | `[]` ⟦branch inbox — DEC-049/Q-020⟧ |
| Branch meta `momo_branch_email` | email | Y | no | business domain | Reply-To for that branch's customer emails ⟦Q-409⟧ |

Read-only on the tab (SA): transport status (host, port, TLS, from address, sandbox on/off — never credentials), last
queue heartbeat, counts queued / failed in last 24 h.

### 3.4 Template registry (SCR-E map)

| Key | Variants | Recipient type | Language source | Trigger (owner) | Priority |
|---|---|---|---|---|---|
| E01 | — | customer | `preferred_lang` | T1 submit (Reservation `SubmissionService`) | M |
| E02 | — | customer | `preferred_lang` | T2 accept | M |
| E03 | — | customer | `preferred_lang` | T3 decline | M |
| E04 | `by_customer`, `by_branch` (× rendered wording `pending`/`confirmed` from the status before cancellation) | customer | `preferred_lang` | T4a/T6a (`by_customer`); T4b/T6b (`by_branch`) unless reason `suspected_spam` | M |
| E05 | — | customer | `preferred_lang` | T5 expiry (job or lazy) | M |
| E06 | `pending`, `confirmed`, `new_link` | customer | `preferred_lang` | N3 modify (variant by current status); `new_link` on token key re-issue (SDD §5.13) | S |
| E07 | — | branch | recipient locale | T1 | M |
| E08 | `sla_reminder` (branch + `handled_by` user), `sla_escalation` (head_office), `notify_failed` (branch) | branch / user / head_office | recipient locale | SLA job (Reservation); final failure of E01–E06 (this module) | S (`notify_failed` M) |
| E09 | — | branch | recipient locale | T4a/T6a | M |
| E10 | `branch`, `head_office` | branch / head_office | recipient locale | `DigestJob` | S |
| E11 | `cert_30`, `cert_7`, `cert_expired` | head_office / user (CAP-DCA) | recipient locale | `CertificateExpiryJob` 00:10 JST (Dietary) | M |

Files: `momos-core/templates/email/{key}-{slug}.html.php` and `.txt.php` (e.g. `e01-received`, `e10-digest`,
`e11-certificate-expiry`), partials `_layout`, `_layout-compact` (staff), `_details`, `_branch-block`, `_footer`.

### 3.5 `NotificationRequest` DTO

`templateKey` (string), `variant` (?string), `reservationId` (?int), `branchId` (?int), `objectType` (?string),
`objectId` (?int), `audience` (enum: `customer` | `branch_recipients` | `head_office` | `ho_and_dca` | `explicit`),
`explicitRecipients` (list<Recipient>, only for `explicit`), `dedupeBase` (string — §4.2), `extraUserIds` (list<int>,
e.g. `handled_by`). No body data is carried: renderers reload data by ID at send time.

## 4. Business rules and algorithms

### 4.1 Triggers (transaction contract — SDD §5.3)

| Event | Rows inserted in the same DB transaction as the state change |
|---|---|
| T1 submit | E01 × 1 (customer) + E07 × each active branch recipient |
| T2 accept | E02 |
| T3 decline | E03 |
| T4a / T6a customer cancel | E04 `by_customer` + E09 × each active branch recipient |
| T4b / T6b staff cancel | E04 `by_branch` — **not** when `reason_code = suspected_spam`; sent for `duplicate` |
| T5 expire | E05 (no staff email; listed in E10) |
| T7 / T8 attendance, N1 claim, N2 note | none |
| N3 modify | E06 `pending` or `confirmed` (by status after the change) |
| N4 final failure of E01–E06 | (after the failure, standalone transaction) E08 `notify_failed` × each active branch recipient |
| N5 SLA | E08 `sla_reminder` (branch recipients + `handled_by` if set) / `sla_escalation` (HO list) |
| Daily digest | E10 per §4.8 |
| `CertificateExpiryJob` | E11 per §4.9 |

After COMMIT (`AfterCommit::flush`): for each new row `as_enqueue_async_action('momos_send_notification', [$id], 'momos')`.
On ROLLBACK nothing is enqueued (`AfterCommit::discard`). A reservation is never rolled back or changed in status
because of an email (FR-NOT-003(d)).

### 4.2 Dedupe keys (UNIQUE `dedupe_key`)

Single-recipient keys follow SDD §5.7.2; every multi-recipient key ends with `:{recipient_hash}` (correction — see §12 C7).

| Template | Key |
|---|---|
| E01 | `res:{id}:E01` |
| E02–E05, E06 `pending`/`confirmed` | `res:{id}:{template}:{version}` (version after the transition; one per transition) |
| E06 `new_link` | `res:{id}:E06:new_link:{token_key_version}` |
| E07 | `res:{id}:E07:{recipient_hash}` |
| E09 | `res:{id}:E09:{version}:{recipient_hash}` |
| E08 | `res:{id}:sla_reminder:{recipient_hash}` · `res:{id}:sla_escalation:{recipient_hash}` · `res:{id}:notify_failed:{failed_notification_id}:{recipient_hash}` |
| E10 | `digest:{yyyy-mm-dd JST}:branch:{branch_id}:{recipient_hash}` · `digest:{yyyy-mm-dd}:head_office:{recipient_hash}` |
| E11 | `cert:{cert_id}:{30\|7\|0}:{recipient_hash}` |
| Resend (§8.3) | `resend:{original_id}:{n}` (n = 1, 2, …) |

A duplicate insert is silently ignored (no error to the caller) — this makes T1 replays, job re-runs and SLA ticks idempotent.

### 4.3 Dispatch (`Dispatcher::send`)

1. **Claim**: `UPDATE … SET status='sending', locked_at=%now, attempts=attempts+1, updated_at=%now WHERE id=%d AND status='queued' AND attempts<5 AND (next_attempt_at IS NULL OR next_attempt_at<=%now)`; 0 rows → return (another worker owns it or not due).
2. **Guards** (→ `cancelled`, no attempt counted as failure): `recipient_email IS NULL` (`recipient_purged`); E08 `sla_*` whose reservation is no longer `pending` (`not_applicable`, FR-RES-017(a)) [rule — new]; staff template whose recipient hash equals the reservation's customer email hash (`not_applicable`, Doc 11 §5.4 "never a customer address").
3. **Render** (§4.6) → subject, HTML, text. Render exception → permanent failure `render_failed`.
4. **Send** via `TransportInterface::send(Message)`; one To; headers per §4.11.
5. **Mark**: accepted → `status='sent'`, `sent_at`, `provider_message_id`, `locked_at=NULL`; emit `momos/notification/sent`. Failure → §4.4.

Action Scheduler settings: batch size 25, concurrent batches 1 (max 2 — provider rate limits), time limit 50 s,
completed-action retention 30 days, async request runner on, cron runner every minute → NFR-PERF-05.

### 4.4 Retry policy and error classification

| Attempt that failed | Next state | `next_attempt_at` |
|---|---|---|
| 1 | `queued` | now + 1 min |
| 2 | `queued` | now + 5 min |
| 3 | `queued` | now + 15 min |
| 4 | `queued` | now + 60 min |
| 5 (or any permanent error) | `failed`, `failed_at = now` | NULL |

On a retry the dispatcher schedules `as_schedule_single_action($next_attempt_at, 'momos_send_notification', [$id], 'momos')`.

| `last_error_code` | Detection | Class |
|---|---|---|
| `smtp_connect_failed` | connect error / DNS | transient |
| `smtp_timeout` | socket timeout (`MOMOS_SMTP_TIMEOUT`, default 15 s) | transient |
| `smtp_auth_failed` | 535 / AUTH error | transient + `critical` log (config problem) |
| `smtp_tempfail` | any 4xx reply | transient |
| `smtp_rejected_recipient` | 5xx at RCPT (550, 551, 553, 501…) | **permanent** |
| `smtp_rejected_message` | 5xx at MAIL/DATA (e.g. 554) | **permanent** |
| `invalid_recipient` | fails `is_email()` before sending | **permanent** |
| `transport_not_configured` | production without `MOMOS_SMTP_HOST` (PHP `mail()`/host mail is never used — DEC-018) | transient + `critical` log |
| `render_failed` | template/data exception | **permanent** |
| `worker_crash` | set by sweeper (§4.10) when a `sending` row is stale at attempt 5 | permanent |
| `recipient_purged`, `not_applicable` | guards (status `cancelled`) | — |
| `lang_fallback` | informational on a `sent` row: `last_error` = `requested=ar used=en` | — |

**Final-failure effects** (FR-NOT-003(c), BR-RES-12), emitted through `momos/notification/failed`:
- Customer template (E01–E06): Reservation sets `customer_notified_flag = 0`, inserts event `notification_failed` (payload `{notification_id, template, error_code}` — no email); this module enqueues E08 `notify_failed` to the branch recipients ("call the guest if a phone number exists"). Inbox badge "Guest not notified — call".
- Staff template (E07–E11): no cascade (no E08 for a failed E08); appears in the next E10 and in the log; counts toward the failure-rate alert.
- Never changes reservation status, never deletes the row.
- When the provider recovers, queued rows send in `created_at, id` order (sweeper and AS run due actions oldest first) (FR-NOT-003(e)).

### 4.5 Recipient resolution (DEC-049, GATE-03)

| Audience | Source | Filters |
|---|---|---|
| `customer` | `momo_reservations.email` of that reservation (one address, To only) | must not be NULL (purged → no row) |
| `branch_recipients` | Branch meta `momo_res_notification_recipients` of **the reservation's `branch_id` only** (never another branch, never a global mailbox) | `active = true`; dedupe by hash |
| `head_office` | Option `momo_ho_escalation_recipients` (+ `momo_digest_recipients` for E10 `head_office` only) | `active = true` |
| `ho_and_dca` (E11) | HO list ∪ users with capability `momo_edit_dietary_claims` (CAP-DCA) | user active (not disabled, not past `momo_access_expires_at`); user email; dedupe by hash (a person in both sources gets one email) |
| `extraUserIds` (E08 `sla_reminder`) | `handled_by` user [Doc 05 §4.3] | active, not already a branch recipient |

- TEST branch (`momo_is_test_branch = 1`): uses its own recipient list (QA inbox) — the normal per-branch rule gives FR-RES-030; TEST is excluded from the HO digest.
- Zero active recipients for E07/E09/E08 → no rows, `warning` log `notification.no_recipients` (branch code only); the request is still visible in the inbox. (Branch save rules prevent this while `momo_res_enabled = 1`.)
- Filter `momos/notification/recipients` may only remove recipients or add rows from the same sources (extension point; never used to add personal mailboxes).

**Staff language** (FR-I18N-010(d), FR-NOT-002(c), TRD §2.13): (1) address equals the `user_email` of an active WP user → that user's admin locale (`Locale::admin($userId)`: `ja` / `en`), `recipient_user_id` set; (2) else the row's `language`; (3) else `ja`.

**Customer language**: `preferred_lang` (fallback in §4.6).

### 4.6 Rendering rules

- **Language fallback (customer)**: render in `preferred_lang` if that language is publicly enabled (`Lang::is_enabled`) **and** every string key used by E01–E06 has review status `approved` in that language (LCCS); else `en` (same test), else `ja`. Record `lang` = used language and `last_error_code='lang_fallback'` + `warning` log. GATE-04 prevents this in production. Staff: missing `en` key → whole email in `ja`.
- **No mixed language**: branch name/address in L come from `_momos_i18n_L` with status `approved`; if not approved the whole email falls back as above (never mix).
- **HTML**: `<html lang="{L}" dir="{rtl for ar/ur, else ltr}">`, table layout max 600 px, readable at 320 px, inline CSS only, system font stack, buttons as bulletproof table cells ≥ 44 px tall, real text headings (no text in images); only remote image allowed: the logo on the site domain (with `alt` = brand name); dark-mode safe colours (Doc 08 tokens).
- **Isolation**: reference, phone, dates, times, prices, emails, URLs and Latin names inside `<bdi>` (or `<span dir="ltr">`); `message_to_customer` and any user text in `<div dir="auto">` with `nl2br(esc_html())`.
- **Escaping** (SEC-021/033): every variable `esc_html`/`esc_url`; text part strips tags; no variable ever reaches a header except the subject built from fixed strings + brand + branch code + reference.
- **Formats** (Doc 07 §6.10, ICU `IntlDateFormatter`, Gregorian, Western digits `-u-nu-latn`, `Asia/Tokyo`): ja long date `2026年12月3日（木）`, en `Thu 3 Dec 2026`, ar/ur/hi/bn CLDR skeleton `EEEEdMMMMy` with `-nu-latn` (bn override required); time `HH:mm` 24 h. **JST label**: appended after times in non-ja customer emails only (`en` "JST"; ar/ur/hi/bn reviewed label); ja omits it (DEC-062). Staff emails: `en` "2026-12-24 19:00 JST", `ja` "2026-12-24 19:00".
- **Phone**: ja national display, other languages international (`+81 …`), `tel:` always E.164.
- **Links** (all absolute `https://{site}`, language prefix: ja root, others `/{lang}/`): status page `{prefix}/reservation/status/{public_ref}/#t={token}` (token re-derived by Reservation `TokenService`, DEC-058/064; included only when F-RES-03 is live); new request `{prefix}/reservation/?branch={code}` (+ `&date=YYYY-MM-DD&time=HH:MM` for E03 suggestions [param names owned by Reservation spec]); branch page `{prefix}/branches/{slug}/`; privacy policy page in L; directions `https://www.google.com/maps/dir/?api=1&destination={lat},{lng}` (+`&destination_place_id={place_id}`) — the only non-brand-domain link (§12 C9); staff deep link `https://{site}/wp-admin/admin.php?page=momo-reservations&reservation={id}` (login + 2FA required, 403 if unassigned). No shorteners, no UTM, no tracking redirects, no pixels.
- **Given name** (staff, FR-NOT-002(a), Doc 11 §5.4): `trim(guest_name)` → split on first whitespace (incl. U+3000); take the first token; if no whitespace, the whole string; truncate to 20 characters (`mb_substr`). Example "Aisha Rahman" → "Aisha".
- **Special request**: never rendered in any email. Customer emails E01–E06: if non-empty, show `email.common.note_received`; staff E07: `staff.email.special_request` yes/no.
- **Request wording guard** (BR-RES-01, CMP-004): CI test renders E01 and E04/E06 `pending` in all six languages and scans HTML + text + subject against the Doc 07 §6.6 request-context list; only the negated DEC-011 label may contain "confirmed"/確定.

### 4.7 Customer email content contract (FR-NOT-001(b)(c), Doc 07 §5.35, Doc 08 §4.39)

Content order: header (logo or text wordmark) → status headline (DEC-011 label, H1) → reference → details table
(branch, date, time [+JST], party size) → template block → button → branch block (name, localised address + Japanese
address `momo_address_ja` when L ≠ ja, phone `tel:`, directions link) → carrier/help line → footer (legal operator name
only if set ⟦Q-409⟧, privacy policy link, automatic-email line). Optional fields without a value are omitted entirely
(no headings, no "TBD").

| Template | Headline key | Template block | Button |
|---|---|---|---|
| E01 | `status.pending` | `res.submitted.next`; `res.submitted.expiry` with `{time}` = start − decision cutoff (formatted with date if not same day); `res.submitted.promise` only if `momo_res_show_response_promise = 1`; `res.submitted.dont_travel` if `momo_res_show_dont_travel`; `email.e01.cancel_hint` (when status link live); policy text if the policy page is published (DEC-054); note-received line | `res.submitted.status_link` |
| E02 | `status.confirmed` | `email.e02.message_heading` + `message_to_customer` (only if set); policy text only if supplied (DEC-054; never invented grace periods/fees — BR-RES-14); `email.confirmed.allergy`; `email.e02.cancel_hint` | `email.cta.view_reservation` |
| E03 | `status.rejected` | `email.e03.reason_heading` + `decline.reason.{code}`; branch message if set; `decline.suggested` + up to 2 suggested times (each a link to the prefilled form); never internal reason text | `cta.request_other_time` |
| E04 | `status.cancelled` | `by_customer`: `email.e04.by_customer.{pending\|confirmed}`; `by_branch`: `email.e04.by_branch.{pending\|confirmed}` + `cancel.reason.{code}` + branch message if set + `email.e04.unexpected` | `cta.request_other_time` |
| E05 | `status.expired` | `email.e05.apology`; branch phone prominently; `email.e05.walk_in` only if policy allows ⟦DEC-054⟧; never blames the guest; no implication that a table is held | `cta.request_other_time` |
| E06 | current status label | before → after rows for changed fields with text `email.e06.changed`; `email.e06.reason`; wording per variant | `pending`: `res.submitted.status_link`; `confirmed`: `email.cta.view_reservation`; `new_link`: `res.submitted.status_link` |

Carrier line: `res.carrier_tip` with `{domain}` = sending domain, rendered when the reservation's `carrier_email` flag is
true (FR-RES-029) — see §12 C6. Status page `completed`/`no_show` never produce emails.

### 4.8 Daily digest (E10) — `DigestJob` (S; FR-NOT-002(e), WF-ADM-26, SDD §5.10)

Schedule: daily at `momo_digest_time` JST (⟦09:00⟧), registered with `as_schedule_cron_action` using the UTC
equivalent; re-registered when the setting changes. Skipped entirely when `momo_digest_enabled = 0` (E11 unaffected).
Window `W = [run − 24 h, run)`; `run` = scheduled time (test clock aware via `Kernel\Clock`).

| Section | Query (per branch, `branch_id` = that branch) | Shown as |
|---|---|---|
| Expired | reservations with `expired_at ∈ W` | count + list (ref, date, time, party) |
| Failed notifications | rows `status='failed'` and `failed_at ∈ W` (customer and staff) + reservations still `customer_notified_flag = 0` and not terminal | count + refs + template |
| Attendance not recorded | `status='confirmed'` and `requested_start_utc ≤ run − 24 h` and `> run − 72 h` | count + refs |
| Needs action | `needs_action_flag = 1`, status `pending`/`confirmed`, start ≥ now (closure conflicts) | count + refs |

HO variant (`head_office`) = every non-TEST branch's sections (grouped by branch code) **plus**: branch changes by
ROLE-06 users in W (audit actions `branch.hours_updated`, `branch.closure_*`, `notice.*`, availability, `branch.content_updated`:
branch, field, before → after, user display name — US-NOT-007), offers awaiting approval (`momo_offer` status `pending`),
dishes submitted for review, certificates expiring within 30 days (summary only; alerts are E11), captcha-outage
count (`captcha_status = unverified_outage` in W), SLA escalations in W still `pending`.

Rules: a branch digest is queued only when that branch has ≥ 1 item (AC-NOT-02-04); the HO digest only when any
section has ≥ 1 item. Branch digest → that branch's active recipients only (TC-ACL-008); HO digest → HO list ∪
digest list. Content = counts + references + date/time/party; **no guest names or contact data**; links go to
SCR-A08 filtered views (`admin.php?page=momo-reservations&status=…&branch=…`). The renderer recomputes W from
`created_at` of the row (no body stored). Failure of an E10 → `critical` log (monitoring), no cascade.

### 4.9 E11 certificate expiry (contract with Dietary `CertificateExpiryJob`, 00:10 JST)

Dietary calls `enqueue()` with `templateKey=E11`, variant `cert_30` (valid_until = today + 30), `cert_7` (today + 7) or
`cert_expired` (record expired by this run), `objectType=certificate`, audience `ho_and_dca`. Content: certificate
number, certifier, claim type and scope, linked branches (codes + names), valid until, consequence of expiry
(`cert_expired`: certified wording, mark and HalalDiet JSON-LD hidden; branch falls back to `no_claim`), button to
SCR-A12 record. No customer data. Independent of E10 (sent even when the digest is disabled — TC-DIET-005/TC-NOT-017).
A second run the same day sends nothing (dedupe).

### 4.10 Sweeper, monitoring, alerts (NFR-OBS-01, SEC-083)

Every 5 min (`momos_job_notification_sweeper`, safe to overlap):
1. `sending` with `locked_at < now − 10 min` → if `attempts < 5`: `queued`, `next_attempt_at = now`, `last_error_code='worker_crash'` (attempt counted); else `failed` + final-failure effects.
2. `queued`, due, and no pending AS action for the id → re-enqueue (oldest first, ≤ 200 per run).
3. Update option `momos_job_heartbeat_queue` (UTC timestamp). Monitor alerts HO + vendor when > 30 min old.
4. Failure rate: failed attempts / all attempts in the last 60 min > 5 % with ≥ 20 attempts [threshold min sample — new] → `critical` log `notification.failure_rate` (log watcher emails SA through a channel independent of the provider).
5. Oldest due `queued` row older than ⟦15 min⟧ → `critical` log `notification.queue_age` (Doc 05 WF-EXC-04 step 6).

Duplicate safety: rows are marked `sent` immediately after the transport returns success; Message-ID is stable per row
(`<{uuid}@{MOMOS_MAIL_DOMAIN}>`), so a crash between provider acceptance and the UPDATE yields at most one duplicate
with an identical Message-ID (residual risk; PRD test consideration "worker crash mid-send").

### 4.11 Sending identity and SMTP transport (FR-NOT-006, DEC-018, TRD §4.5, IMPL-004)

wp-config constants (never in DB, never logged; rotate on staff/vendor change):

| Constant | Meaning | Default |
|---|---|---|
| `MOMOS_SMTP_HOST`, `MOMOS_SMTP_PORT`, `MOMOS_SMTP_SECURE` | Provider SMTP endpoint, port, `tls` (STARTTLS) or `ssl` | —, `587`, `tls` |
| `MOMOS_SMTP_USER`, `MOMOS_SMTP_PASS` | Provider SMTP credentials | — |
| `MOMOS_SMTP_TIMEOUT` | seconds | 15 |
| `MOMOS_SMTP_FALLBACK_HOST/PORT/SECURE/USER/PASS` | Optional second connection, used for the same attempt when the primary fails with a transient connect/auth error ⟦TBD⟧ | unset |
| `MOMOS_MAIL_FROM` | From address on the brand domain | ⟦`reservations@{BRAND_DOMAIN}` / no-reply — Q-409⟧ |
| `MOMOS_MAIL_DOMAIN` | Domain for Message-ID | domain of `MOMOS_MAIL_FROM` |
| `MOMOS_MAIL_SANDBOX` | Non-production: every message rerouted to this address, subject prefixed `[STAGING]`; original recipient only as masked value in the log | dev/staging: Mailpit / test inbox; **undefined in production** |

`SmtpConfigurator` (`phpmailer_init`): `isSMTP()`, host/port/secure/auth from constants, `Timeout`, `SMTPAutoTLS = true`,
`CharSet = UTF-8`, `Encoding = base64` (or quoted-printable), `Sender` (envelope/MAIL FROM) on the provider's custom
MAIL FROM subdomain for SPF alignment ⟦bounce subdomain — DEC-018⟧, `AltBody` = text part. In production, if
`MOMOS_SMTP_HOST` is undefined, the transport refuses (`transport_not_configured`) instead of falling back to PHP mail.

Headers per message: `From: "{momo_sender_name[L]}" <MOMOS_MAIL_FROM>`; `Reply-To:` branch `momo_branch_email`
(customer emails; omitted if empty; staff emails have none); `To:` exactly one address; **no Cc/Bcc**; `Message-ID:
<{uuid}@{MOMOS_MAIL_DOMAIN}>`; `Auto-Submitted: auto-generated`; `Content-Language: {L}`; multipart/alternative
(HTML + text). No `List-Unsubscribe` (transactional), no attachments (.ics/CSV forbidden). Display names are stripped of
CR/LF and quotes (header-injection test: guest name with CR/LF + "Bcc:" adds no header — SEC-033).

DNS / provider (owner tasks, TK-10-02): SPF `include:` only the provider (+ mailbox provider), custom MAIL FROM
subdomain; DKIM 2048-bit; DMARC `p=none` + `rua` reports 2–4 weeks pre-launch → `p=quarantine` at launch → `p=reject`
after ⟦30 days⟧ of clean reports (SEC-054; PRD says 4 weeks — same intent); provider open/click tracking disabled;
provider-side log retention shortest available; provider region ⟦Amazon SES ap-northeast-1 preferred — Q-030⟧,
disclosed in the Privacy Policy. Hostinger Mail is never used for sending. Bounce webhooks are Phase 2: a hard bounce
rejected synchronously at SMTP is a permanent failure; asynchronous bounces are reviewed weekly in the provider
console (WF-EXC-04 step 7).

### 4.12 Carrier whitelisting guidance (ER-010, GAP-012, FR-RES-029, TK-10-08)

Japanese carrier mail (docomo, au, SoftBank, Y!mobile) silently drops automated/PC mail with URLs unless the sender
domain is allowed; this cannot be detected in Phase 1. Mitigations implemented here:
- `carrier_email` = email domain ∈ `momo_carrier_domains` (computed by Reservation Normalizer); E01 (and every later customer email, §12 C6) shows `res.carrier_tip` with `{domain}` = `MOMOS_MAIL_DOMAIN`'s registrable domain.
- Deliverability hygiene: aligned SPF/DKIM/DMARC, links only to the brand domain (+ Maps), no shorteners, no image-only content, plain-text part, stable From address, low link count.
- Staff side: inbox badge "Carrier email address" so staff consider phoning after accepting (Reservation spec).
- FAQ / help text (content task TK-10-08): explain allowing `@{domain}` in docomo/au/SoftBank mail filter settings and checking spam; links to carriers' official help pages are content decisions ⟦Content lead⟧.
- QA: TC-NOT-009 sends E01/E02 before and after whitelisting on real carrier test addresses ⟦test SIMs — QA lead⟧.

### 4.13 Retention and privacy (Privacy `RetentionJob`, daily 03:15 JST — contract)

| Rule | Action |
|---|---|
| Terminal row (`sent`/`failed`/`cancelled`) whose `updated_at` < now − 30 days | `recipient_email = NULL`, `recipient_purged_at = now` (masked + hash kept) |
| Reservation anonymised (retention or DSR) | its rows: `recipient_email = NULL`, `recipient_masked = '[purged]'`, `recipient_purged_at = now`; queued rows → `cancelled` (`recipient_purged`) |
| `created_at` < now − `momo_retention_notification_days` (180) | DELETE rows (batches of 500) |
| DSR export (FR-PRV-004) | include per reservation: template, language, status, created/sent/failed timestamps — no recipient of other people, no bodies |
| Staging/dev | synthetic data only; `MOMOS_MAIL_SANDBOX` mandatory (PRV-029, SEC-055) |

## 5. Templates — subjects and strings (EN / JA)

### 5.1 Subjects (no PII except reference; `{brand}` = `momo_trading_name` in L, `{ref}` = `XXXX-XXXX`)

Pattern adopted from Doc 07 §5.35/§5.36 (EN given there); JA **[draft — new]** unless noted.

| Template / variant | EN | JA |
|---|---|---|
| E01 | `{brand}: request received — not yet confirmed [{ref}]` | `{brand}：リクエスト受付・未確定［{ref}］` |
| E02 | `{brand}: your reservation is confirmed [{ref}]` | `{brand}：ご予約が店舗にて確定しました［{ref}］` |
| E03 | `{brand}: we couldn't accept your request [{ref}]` | `{brand}：リクエストをお受けできませんでした［{ref}］` |
| E04 (was pending) | `{brand}: your request was cancelled [{ref}]` | `{brand}：リクエストは取り消されました［{ref}］` |
| E04 (was confirmed) | `{brand}: reservation cancelled [{ref}]` | `{brand}：ご予約はキャンセルされました［{ref}］` |
| E05 | `{brand}: we couldn't confirm your request in time [{ref}]` | `{brand}：期限内にリクエストを確定できませんでした［{ref}］` |
| E06 `pending` / `new_link` | `{brand}: details of your request were updated [{ref}]` | `{brand}：リクエストの内容が変更されました［{ref}］` |
| E06 `confirmed` | `{brand}: your reservation details were updated [{ref}]` | `{brand}：ご予約内容が変更されました［{ref}］` |
| E07 | `[{branch_code}] New reservation request {ref}` | `[{branch_code}] 新しい予約リクエスト {ref}` |
| E08 `sla_reminder` | `[{branch_code}] Reminder: request waiting {n} min · {ref}` | `[{branch_code}] リマインダー：{n}分間未対応のリクエスト {ref}` |
| E08 `sla_escalation` | `[Escalation][{branch_code}] Request waiting {n} min · {ref}` | `[エスカレーション][{branch_code}] {n}分間未対応のリクエスト {ref}` |
| E08 `notify_failed` | `[{branch_code}] Guest not notified — please call · {ref}` | `[{branch_code}] お客様へのメール未達・お電話ください {ref}` |
| E09 | `[{branch_code}] Guest cancelled {ref}` | `[{branch_code}] お客様がキャンセルしました {ref}` |
| E10 `branch` | `[{branch_code}] Daily summary · {date}` | `[{branch_code}] 日次サマリー・{date}` |
| E10 `head_office` | `[{brand}] Daily summary · {date}` | `[{brand}] 日次サマリー・{date}` |
| E11 `cert_30` / `cert_7` | `[Halal] Certificate {cert_no} expires {date}` | `[ハラール] 認証書 {cert_no} の有効期限：{date}` |
| E11 `cert_expired` | `[Halal] Certificate {cert_no} expired — claims hidden` | `[ハラール] 認証書 {cert_no} 有効期限切れ・認証表示を非表示にしました` |

`{n}` and dates are not personal data; subjects never contain guest name, email, phone, address or requested date
(the E07 subject deliberately omits date/party — §12 C3). Non-production: `[STAGING] ` prefix.

### 5.2 Customer email strings (keys reused from Doc 07 §6 where they exist)

| Key | EN | JA |
|---|---|---|
| `status.pending` | Request received — not yet confirmed | リクエスト受付・未確定 |
| `status.confirmed` | Confirmed by the branch | 店舗にて確定済み |
| `status.rejected` | Declined | お受けできませんでした |
| `status.cancelled` | Cancelled | キャンセル済み |
| `status.expired` | Expired — not confirmed in time | 期限切れ（時間内に確定できませんでした） |
| `res.submitted.reference` | Your reference: {ref} | 受付番号：{ref} |
| `res.submitted.next` | The branch will review your request and reply by email. A table is not set aside for you until the branch accepts your request. | 店舗がリクエストを確認し、メールでご連絡します。店舗が承認するまで、お席は確保されていません。 |
| `res.submitted.expiry` | If the branch has not replied by {time}, your request expires and we will email you. | {time}までに店舗から返信がない場合、リクエストは期限切れとなり、メールでお知らせします。 |
| `res.submitted.promise` | The branch usually replies within {duration} during opening hours. | 営業時間内であれば、通常{duration}以内に店舗からご連絡します。 |
| `res.submitted.dont_travel` | Please don't travel to the branch based on this request alone. | 承認のご連絡をお受け取りになるまでは、このリクエストのみでのご来店はお控えください。 |
| `res.submitted.status_link` | View request status | リクエストの状況を確認する |
| `res.carrier_tip` | Not receiving our emails? If you use a Japanese mobile carrier address, allow mail from @{domain}. | メールが届かない場合：携帯キャリアのメールアドレスをご利用の方は「@{domain}」からのメールを受信できるよう設定してください。 |
| `email.confirmed.allergy` | If anyone in your group has a food allergy, please tell our staff when you arrive. | アレルギーをお持ちの方がいらっしゃる場合は、ご来店時にスタッフへお伝えください。 |
| `decline.reason.fully_booked` | The branch is fully booked at that time. | ご希望の時間は満席です。 |
| `decline.reason.closed_or_private_event` | The branch is closed or reserved for a private event at that time. | ご希望の時間は休業または貸切のため、お受けできません。 |
| `decline.reason.party_size_not_supported` | The branch can't seat a group of this size at that time. | ご希望の人数ではお席をご用意できません。 |
| `decline.reason.incomplete_information` | We needed more information to accept this request. | リクエストをお受けするための情報が不足していました。 |
| `decline.reason.other` | The branch couldn't accept this request. | 店舗の都合によりお受けできませんでした。 |
| `decline.suggested` | The branch suggested these times. They are not held for you — send a new request if one suits you. | 店舗から以下の時間のご提案があります。お席は確保されていませんので、ご希望の場合は改めてリクエストをお送りください。 |
| `cta.request_other_time` | Request another time | 別の日時でリクエスト |
| `cta.directions` | Directions | 道順を見る |
| `ftr.privacy` | Privacy Policy | プライバシーポリシー |
| `status.past` (not emailed; status page) | The date of this reservation has passed. | このご予約の日時は過ぎています。 |

New keys **[draft — new]** (EN derived from Doc 04/05/07 wording; JA draft, native review required):

| Key | EN | JA |
|---|---|---|
| `email.details.branch` / `.date` / `.time` / `.party` | Branch / Date / Time / Party size | 店舗 / 日付 / 時間 / 人数 |
| `email.details.party_value` (plural) | {n} person / {n} people | {n}名 |
| `email.branch.address_ja` | Address in Japanese (you can show this to a taxi driver) | (ja email: not rendered — single address) |
| `email.branch.call` | Call the branch: {phone} | 店舗へのお電話：{phone} |
| `email.common.note_received` | We have received your note. | ご要望を受け付けました。 |
| `email.cta.view_reservation` | View reservation | ご予約内容を確認する |
| `email.e01.cancel_hint` | If your plans change, you can cancel this request on the status page. | ご予定が変わった場合は、状況確認ページからこのリクエストを取り消せます。 |
| `email.e02.message_heading` | Message from the branch | 店舗からのメッセージ |
| `email.e02.cancel_hint` | If you can no longer come, please cancel on the reservation page or call the branch. | ご来店いただけなくなった場合は、確認ページからキャンセルするか、店舗へお電話ください。 |
| `email.e03.reason_heading` | Reason | 理由 |
| `email.e04.by_customer.pending` | You cancelled this request. | お客様のお手続きにより、このリクエストを取り消しました。 |
| `email.e04.by_customer.confirmed` | You cancelled this reservation. | お客様のお手続きにより、ご予約をキャンセルしました。 |
| `email.e04.by_branch.pending` | The branch cancelled this request. | 店舗がこのリクエストを取り消しました。 |
| `email.e04.by_branch.confirmed` | The branch cancelled this reservation. | 店舗がご予約をキャンセルしました。 |
| `email.e04.unexpected` | If this is unexpected, please contact the branch: {phone} | お心当たりがない場合は、店舗へご連絡ください：{phone} |
| `cancel.reason.customer_request_by_phone` | Cancelled at your request by phone. | お電話でのご依頼により取り消しました。 |
| `cancel.reason.branch_unable_to_serve` | The branch is unable to serve you at that time. | 店舗の都合により、ご希望の日時に対応できなくなりました。 |
| `cancel.reason.duplicate` | This was a duplicate of another request. | 同じ内容のリクエストが重複していたため取り消しました。 |
| `cancel.reason.other` | The branch had to cancel. | 店舗の都合により取り消しました。 |
| `email.e05.apology` | We're sorry — the branch could not reply before the deadline, so your request has expired. No table is held for you. | 申し訳ございません。期限までに店舗から返信できなかったため、リクエストは期限切れとなりました。お席は確保されていません。 |
| `email.e05.walk_in` | You may still call the branch or visit without a request. | 店舗へのお電話、または直接のご来店も可能です。 |
| `email.e06.changed` | (changed) | （変更） |
| `email.e06.reason` | These details were changed as agreed with you. | お客様とのご相談に基づき、内容を変更しました。 |
| `email.e06.new_link` | Here is your new link to check this request. Earlier links no longer work. | このリクエストを確認するための新しいリンクです。以前のリンクはご利用いただけません。 |
| `email.footer.auto` | This is an automatic email about your table request. Replies go to the branch. | このメールはお席のリクエストに関する自動送信メールです。ご返信は店舗に届きます。 |

`cancel.reason.suspected_spam` has no customer string (no E04 is sent). ar/ur/hi/bn: human-translated, native-reviewed
(DEC-027, BR-PRV-04); no machine translation of these keys without review.

### 5.3 Staff email strings (ja/en; compact layout, one primary button) — all **[draft — new]** except where noted

| Key | EN | JA |
|---|---|---|
| `staff.email.labels` (ref/branch/date/time/party/name/lang/created/deadline) | Reference / Branch / Date / Time / Party size / Guest (given name) / Reply language / Received / Expires if undecided at | 受付番号 / 店舗 / 日付 / 時間 / 人数 / お客様（名） / 返信言語 / 受付日時 / 未対応の場合の期限 |
| `staff.email.special_request` | Special request: {yes\|no} — view in admin | ご要望：{あり\|なし}（管理画面で確認） |
| `staff.email.open_admin` | Open in admin | 管理画面で開く |
| `staff.email.e07.intro` | A new reservation request was received for {branch}. It is not confirmed until you accept it. | {branch}に新しい予約リクエストが届きました。承認するまで確定していません。 |
| `staff.email.e07.target` (only if response promise configured) | Response target: within {duration} | 返信目安：{duration}以内 |
| `staff.email.e08.reminder` | This request has been waiting {n} minutes. Please accept or decline it. | このリクエストは{n}分間未対応です。承認またはお断りの対応をお願いします。 |
| `staff.email.e08.escalation` | This request at {branch} has been waiting {n} minutes (twice the response target). | {branch}のこのリクエストは{n}分間未対応です（返信目安の2倍）。 |
| `staff.warn.customer_not_notified` (Doc 10 §7.3; E08 `notify_failed` body) | The customer could not be emailed. Please phone them if a number was given. | お客様へのメール送信に失敗しました。電話番号がある場合はお電話でご連絡ください。 |
| `staff.email.e08.failed_template` | Email not delivered: {template_name} ({error}) | 未送信のメール：{template_name}（{error}） |
| `staff.email.e09.intro` | The guest cancelled this request online at {time}. | お客様が{time}にオンラインでキャンセルしました。 |
| `staff.email.e10.sections` | Expired / Failed notifications / Attendance not recorded / Needs action / Branch changes / Offers awaiting approval / Dishes awaiting review / Certificates expiring within 30 days / Spam-protection outage / Escalations still pending | 期限切れ / 送信失敗 / 来店記録なし / 要対応 / 店舗情報の変更 / 承認待ちの特典 / 確認待ちの料理 / 30日以内に期限切れの認証書 / スパム対策停止中の受付 / 未対応のエスカレーション |
| `staff.email.e11.warning` | Certificate {cert_no} ({certifier}) is valid until {date}. Upload the renewed certificate before then. | 認証書 {cert_no}（{certifier}）の有効期限は{date}です。期限までに更新後の認証書を登録してください。 |
| `staff.email.e11.expired` | Certificate {cert_no} expired. Certified wording and the mark are now hidden on the website until a new record is active. | 認証書 {cert_no} の有効期限が切れました。新しい登録が有効になるまで、ウェブサイト上の認証表示とマークは非表示です。 |

Staff emails never contain guest email, phone, surname/full name, special-request text, notes, IP data or other
reservations (PRV-008). Staff status/action labels use the Doc 04 §16.0.3 staff set (Pending / 未対応 …).

## 6. Permissions

| Role | Receives | Notification panel (A09) | Resend failed | Log view (all branches) | Notifications settings (A15) | Recipient lists of a branch (A03 tab) |
|---|---|---|---|---|---|---|
| ROLE-00 Public | E01–E06 (own reservation only) | — | — | — | — | — |
| ROLE-01 Super Admin | if listed | all | yes | yes | edit (+ technical status) | edit |
| ROLE-02 Head Office | if listed (HO list) | all | yes | yes | edit | edit |
| ROLE-03 Menu Manager | — | — | — | — | — | — |
| ROLE-04 Content Editor | — | — | — | — | — | — |
| ROLE-05 Translator | — | — | — | — | — (translates email string keys of assigned languages only) | — |
| ROLE-06 Branch Manager | if listed for own branch | assigned branches | assigned branches | — | — | read-only (assigned) |
| ROLE-07 Reservation Staff | if listed for own branch | assigned branches | assigned branches | — | — | — (sees rules summary in A09) |
| CAP-DCA holder | E11 | — | — | — | — | — |

Capability checks (server-side, every request): panel/API-11 `momo_view_reservation(id)`; resend `momo_process_reservation(id)`;
log view `momo_view_reservations` **and** `momo_access_all_branches` (HO, SA); settings `momo_manage_brand`; transport
status block `manage_options`; branch recipients `momo_edit_branch_settings` (+ assigned). Masked recipients only — no
role sees a full recipient address in notification UI.

## 7. Screens

### 7.1 SCR-A09 notification panel (reservation detail) — Must (TK-10-07)
Shows, per row of that reservation (newest first): template name (e.g. "E02 Confirmed" / 「E02 確定のお知らせ」), variant,
language (native name), masked recipient, status chip (queued / sending / sent / failed / cancelled — icon + text,
failed in error tint), attempts `n/5`, next attempt (JST), sent at, provider message ID (truncated, copy button),
last error code (localised short text, no raw provider text to BM/RS). Also visible as chips in the event timeline (Doc 08 §CMP-Timeline).
Flag block when `customer_notified_flag = 0`: CMP-FlagChip "Guest not notified — call" + `staff.warn.customer_not_notified`
+ `tel:` button if a phone exists + "Mark as contacted" (API-13 `customer_contacted`).
States: loading (skeleton within detail ≤ 2 s); empty "No emails for this request yet." / 「このリクエストのメールはまだありません。」 [draft];
error (panel-level notice, rest of detail usable); success after resend: toast "Email queued again" / 「メールを再送信の待ち行列に追加しました」 [draft].
Resend button only on the latest `failed` customer row whose template still matches the current status (§8.3).

### 7.2 SCR-A08 inbox
Filter "Customer not notified" (`customer_not_notified=1`); flag chip on cards; counts via heartbeat. (Inbox owned by Reservation spec.)

### 7.3 Notification log view [new screen, no canonical SCR ID] — `admin.php?page=momo-notifications`
"Global failed list to ROLE-02/01" (F-NOT-03 permissions). Default filter `status=failed`, last 7 days. Filters:
status, template, branch, language, date range (≤ 180 days). Columns: created (JST), template/variant, branch code,
reference (link to A09; blank for E10 HO/E11), recipient type, masked recipient, language, status, attempts, sent/failed at,
last error code. 50 rows per page, server-side pagination, read-only except Resend (same rules as §8.3). No CSV export.
Empty: "No notifications match these filters." / 「条件に合う通知はありません。」 [draft].

### 7.4 SCR-A15 tab "Notifications / 通知設定"
Fields of §3.3; per-list "Send test email" button → admin-post `momos_notification_test_send` (nonce, `momo_manage_brand`)
sends a fixed test message (no reservation data, subject `[{brand}] Test message`) synchronously to each active address of
the chosen list, result notice per masked address (sent / error code); audited `notification.test_sent` (counts, list name).
Validation errors inline: `admin.settings.err.recipient` "Use a business-controlled address (personal webmail is not allowed)."
(PRD FR-RES-023 error text). Warning chip for addresses on the block list. Saving audited (`settings.updated`, before/after, emails masked).

## 8. APIs and handlers

Error bodies and codes only from the canonical catalogue (Doc 04 §16.0.4): `{code, message, data:{status, fields}}`.

### 8.1 API-11 `GET /momos/v1/staff/reservations/{id}` (owned by Reservation spec; this module supplies `notifications`)
`notifications: [{id, template_key, template_variant, lang, status, attempts, next_attempt_at, sent_at, failed_at,
recipient_type, recipient_masked, provider_message_id, last_error_code, can_resend}]` (no `recipient_email`, no `last_error` text
for ROLE-06/07). `flags.customer_not_notified`, `flags.carrier_email`. Errors: 401/403 `rest_forbidden`, 403 `forbidden_branch`, 404 `not_found`.

### 8.2 API-13 `POST /momos/v1/staff/reservations/{id}/notes` `type: "customer_contacted"` (Reservation spec)
Resets `customer_notified_flag` to 1 and writes event `customer_contacted`; requires `version`. Errors: 400 `invalid_param`,
401/403 `rest_forbidden`, 403 `forbidden_branch`, 404 `not_found`, 409 `version_conflict`.

### 8.3 Resend failed customer email [proposal — new route; add to TRD API list before build]
`POST /momos/v1/staff/reservations/{id}/notifications/{notification_id}/resend`, header `X-WP-Nonce`, body `{}`.
Rules: `momo_process_reservation(id)`; row belongs to the reservation, `status = failed`, customer template, recipient not
purged, reservation not anonymised, and the template matches the current status (E01/E06 `pending` while pending; E02/E06
`confirmed` while confirmed; E03/E04/E05 when status is that terminal status); ≤ 3 resends per row. Creates a **new** row
(same template/variant/lang/recipient, dedupe `resend:{original_id}:{n}`, attempts 0) and enqueues it; audit
`notification.resent`. On `sent`, Reservation sets `customer_notified_flag = 1` and writes `customer_contacted` with payload
`{via:"email_resend"}` [rule — new]. Response 201 `{notification_id, status:"queued"}`.
Errors: 401/403 `rest_forbidden`, 403 `forbidden_branch`, 404 `not_found` (row not in this reservation), 409 `invalid_transition`
(not resendable), 429 `rate_limited` (⟦10/hour/user⟧).

### 8.4 Internal PHP API
`NotificationQueue::enqueue(NotificationRequest): ?int` · `NotificationRepository::forReservation(int $id): list<NotificationRow>` ·
`NotificationRepository::purgeRecipientsForReservation(int $id)` · `NotificationRepository::deleteOlderThan(DateTimeImmutable)` ·
WP-CLI (non-production + SA): `wp momo notifications send-due`, `wp momo notifications render {E01..E11} --lang=ar --fixture=…`
(writes HTML/text to stdout for snapshot tests; never with real data in production).

## 9. Acceptance criteria and tests to automate

| AC / TC | Given / When / Then (short) | Tool | Gate |
|---|---|---|---|
| AC-NOT-01-01 / TC-NOT-001 / TC-RTL-004 | E01 per language: subject+body in L, `dir=rtl` for ar/ur, LTR ref/phone, JST label non-ja only; all content items present; text part | Playwright + Mailpit; manual RTL clients | GATE-04 |
| AC-NOT-01-02 / CMP-004 | E01 (and E04/E06 pending) × 6 languages scanned → 0 request-context terms | PHPUnit (render) | GATE-04 |
| AC-NOT-01-03 / TC-NOT-007 | All templates × languages with fixture PII: subjects contain at most the reference | PHPUnit | GATE-06 |
| AC-NOT-01-04 / US-NOT-002 | Accept with message, no policy → "Message from the branch", directions link, no policy paragraph | Playwright | — |
| AC-NOT-01-05 / TC-NOT-009 | Inbox placement Gmail, Outlook, iCloud, Yahoo! JAPAN, docomo/au/SoftBank (before/after whitelisting) | manual | — |
| AC-NOT-01-06 / TC-NOT-014 / AC-RES-04-08 | Internal note never in E02 (HTML + text) | Playwright | GATE-03 |
| TC-NOT-002 | E02–E06 variants in 6 languages incl. E03 two suggestions, E05 phone, E06 pending wording "View request status" | Playwright | GATE-04 |
| AC-NOT-02-01 / TC-NOT-003 | E07 for "Aisha Rahman" contains "Aisha", party, JST time, ref, admin link; no email/phone/surname/special-request text; E07 "Special request: yes/no" | Playwright | GATE-03 |
| AC-NOT-02-02 | Recipient = staff user with `en` → English; shared inbox not a user → Japanese (row language default ja) | PHPUnit | — |
| AC-NOT-02-03 / TC-NOT-004 / TC-ACL-008 | Request at C → no recipient of A,B,D,E receives E07/E08/E09; each branch digest only own items (20 ordered pairs) | Playwright | GATE-03 |
| AC-NOT-02-04 | No items for branch B → no E10 to B | PHPUnit (CLOCK) | — |
| AC-NOT-03-01 / TC-NOT-005 / US-NOT-005 | Provider stub fails: 5 attempts at +1/+5/+15/+60 min, then `failed`, flag, `notification_failed` event, E08 `notify_failed` to branch, reservation still `pending`; second request succeeds on attempt 3 | PHPUnit | — |
| AC-NOT-03-02 | Provider recovers after 30 min → queued rows `sent` with Message-ID, in creation order | PHPUnit | — |
| AC-NOT-03-03 / TC-NOT-008 | Headers: SPF, DKIM (aligned), DMARC pass; From brand domain; Reply-To branch | manual | GATE-10 |
| AC-NOT-03-04 / TC-PRV-005 | Rows > 180 days deleted by retention job; recipient nulled after 30 days terminal | PHPUnit (CLOCK) | GATE-06 |
| AC-NOT-03-05 / TC-NOT-010 | 50–100 events: p95 `sent_at − created_at` ≤ 60 s; none queued > 5 min | PHPUnit/staging | GATE-08 |
| TC-NOT-006 / PRV-011 | Row fields present; no body anywhere in DB | PHPUnit | GATE-06 |
| TC-NOT-011 | Expire 2, fail 1, leave 1 attendance → E05 + E10 sections correct, recipient locale | PHPUnit | — |
| TC-NOT-012 / TC-NOT-015 / PRV-009 | One To, no Cc/Bcc, Reply-To branch only, no other customer's data, note echoed only as "We have received your note" | Playwright (raw MIME) | GATE-06 |
| TC-NOT-013 | Provider rejects address → `failed` immediately with `smtp_rejected_recipient`, staff flag | PHPUnit | — |
| TC-NOT-016 / PRV-005 | E01–E10 × 6 languages: no offers, featured dishes, coupon words, UTM, pixels, redirect domains | Playwright | GATE-06 |
| TC-NOT-017 / TC-DIET-005 / TC-DIET-025 | E11 `cert_30`/`cert_7`/`cert_expired` once per recipient (HO + 2 CAP-DCA, ja/en); 2nd run sends nothing; sent with E10 disabled | PHPUnit + Mailpit | — |
| TC-RSA-017 / TC-E2E-009 | "Customer not notified" visible in list + detail with phone and guidance; reservation intact | Playwright / manual | — |
| TC-RSA-021 | SLA reminder once at S, escalation once at 2S to HO; none if decided before S | PHPUnit | — |
| TC-RES-029 / US-NOT-006 | Customer cancel → E04 `by_customer` + E09 to branch | Playwright API | — |
| TC-ADM-006 / TC-ADM-010 | Each branch's recipients receive test send; webmail domain refused | manual / Playwright | GATE-10 |
| TC-PRV-008 | Grep all logs for fixture PII after regression → 0 hits | script | GATE-06 |
| SEC-033 (TC-SEC) | Guest name with CR/LF + "Bcc:" adds no header; HTML payload inert in emails | PHPUnit | — |
| Extra unit tests | dedupe idempotency (double T1 → one E01); sweeper recovers stale `sending`; guard cancels SLA reminder after accept; given-name rule (Japanese no-space name ≤ 20 chars); language fallback recorded; sandbox reroute + `[STAGING]` | PHPUnit | — |

Delivery evidence for gates is a message in a real inbox with authentication headers, never a log entry (Doc 12 §NOT).

## 10. Out of scope (Phase 1)

LINE / SMS / WhatsApp / push notifications (DEC-017; `channel` column reserved); provider bounce/complaint webhooks
(asynchronous bounces) and open/click tracking; marketing or newsletter email, consent for marketing (DEC-043);
calendar (.ics) attachments; customer accounts or notification preferences; email bodies archive; SMTP plugin
installation (FluentSMTP etc. — IMPL-004); contact-form emails (handled by the Contact spec, DEC-038 — they share only the
transport); staff "correct guest email and resend" (Doc 05 open item 6 — product decision needed); digest for ROLE-03/04.

## 11. Open TBDs (with defaults used)

| Item | Default used | Owner / ref |
|---|---|---|
| Provider | Amazon SES ap-northeast-1 via SMTP | CTO — DEC-018 / Q-030 |
| From address, sending/bounce domain, branch Reply-To addresses, legal operator name in footer | placeholders `reservations@{BRAND_DOMAIN}`; footer operator hidden while empty | Owner — Q-409 |
| HO notification list, digest time, shared-inbox language | `[]` (must be set before launch), 09:00 JST, `ja` (row language) | HO — Q-416 |
| Branch recipients per branch | branch inbox | HO — DEC-049 / Q-020 |
| Free-webmail block list / allowed domains | PRD list; allow-list empty | HO + Security — Q-421 |
| Fallback SMTP connection | none | Tech lead — TRD §4.5 |
| DMARC tightening | `reject` after ⟦30 days⟧ clean | Security — SEC-054 |
| SLA clock and SLA value (E08) | `wall_clock`, 120 min | Owner — DEC-013 / Q-402 |
| Response promise copy (`{duration}`) | not shown until approved | Owner — DEC-013 |
| Reservation policy text in E01/E02; walk-in line in E05 | not shown until supplied | Owner — DEC-054 |
| "Don't travel" line in E01 | shown | UX lead — Doc 07 open item 5 |
| JA strings and all ar/ur/hi/bn email strings | drafts | Native reviewers — GATE-04 |
| Carrier domain list, carrier help links | WF-EXC-04 list | HO / Content lead |
| Queue-age alert threshold | 15 min | Tech lead |
| Given-name rule for Japanese "family given" order (first token = family name) | rule kept as documented | Counsel/Product — Doc 11 open item |
| Carrier test SIM addresses | — | QA lead — TC-NOT-009 |

## 12. Documentation conflicts and resolutions applied in this spec

| # | Conflict | Resolution |
|---|---|---|
| C1 | Digest time: PRD FR-NOT-002(e) ⟦09:00⟧; TRD G3 / SDD §5.10 ⟦08:00⟧; Doc 05 WF-ADM-26 proposal 10:00 | Setting `momo_digest_time`, default 09:00 (PRD, Q-416) |
| C2 | Customer subject patterns differ: PRD "Momo's — … · Ref X"; SDD `[{brand}] Reservation request received … — {ref}`; Doc 07 `{Brand}: … [ref]` | Doc 07 §5.35 (string register, has pending/confirmed variants); all satisfy FR-NOT-005 |
| C3 | E07 subject: PRD FR-NOT-002(d) and TRD FR-NOT-005 "branch code, event, reference only"; SDD/Doc 07/08 add date, time, party; Doc 11 `[Momo's] New reservation request … — {code}` | PRD (d): `[{branch_code}] New reservation request {ref}` |
| C4 | HO list naming: PRD "Head Office notification email list"; TRD G3 `momo_ho_escalation_recipients` + `momo_digest_recipients`; Doc 06 `momo_ho_alert_recipients` | TRD names; escalation list = HO notification list (E08 escalation, E10 HO, E11); digest list = extra E10 HO recipients |
| C5 | Special request in customer emails: PRD silent; TK-09-27 "We have received your note" only when separate-consent switch on; PRV-004/PRV-009/TC-NOT-015 never in emails | Never rendered; non-empty → "We have received your note" always |
| C6 | Carrier tip: PRD FR-NOT-001(b) common content of all customer emails; TRD/PRD FR-RES-029 "only when `carrier_email`", E01 | Shown in all customer emails, but only when `carrier_email` is true |
| C7 | SDD dedupe keys `res:{id}:notify_failed:{notification_id}` and `res:{id}:{template}:{version}` (E09) lack the recipient → UNIQUE blocks the 2nd branch recipient; digest key lacks branch id | Multi-recipient keys append `:{recipient_hash}`; digest key includes variant + branch id (§4.2) |
| C8 | Recipient domain rule: PRD block list vs Doc 11 PRV-010 allow-list `momo_allowed_recipient_domains` | Both (as Branch spec): block list always, allow-list when non-empty |
| C9 | TRD §4.5 "links only to the brand domain" vs E02 directions link (Google Maps) required by PRD/US-NOT-002/TC-NOT-016 | Google Maps directions URL is the single permitted external link |
| C10 | DMARC ramp: PRD "quarantine → reject after 4 weeks"; TRD "none → quarantine → reject after 30 days"; Doc 11 SEC-054 none 2–4 weeks pre-launch → quarantine at launch → reject after ⟦30 days⟧ | SEC-054 sequence |
| C11 | Staff template range: TRD FR-NOT-002 "E07–E10" vs PRD "E07–E11" | E11 is a staff template with no customer data (FR-DIET-002) |
| C12 | E08 recipients: PRD/SDD branch recipients (+HO for escalation); Doc 05 §4.3 adds `handled_by` | `handled_by` added for `sla_reminder` only (deduped) |
| C13 | Retry schedule marked ⟦TBD⟧ in Doc 05 vs fixed 1/5/15/60 in PRD/SDD | 1/5/15/60 min, 5 attempts |
| C14 | FluentSMTP / WPML String Translation / ACF in TRD/SDD vs IMPL-001/004 | Mapped per §0; constants `MOMOS_SMTP_*` are new names (TRD named FluentSMTP constants) |
| C15 | Customer cancel reason labels for staff cancellations are not in Doc 07 §6 | New `cancel.reason.*` keys (draft) |
| C16 | No canonical route for "Resend" (TK-10-07) and no SCR ID for the global failed list (F-NOT-03) | Proposed route §8.3 using only catalogue error codes; log view §7.3 — both to be added to TRD/Doc 07 by change control |
