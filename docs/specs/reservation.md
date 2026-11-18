# Spec: Reservation requests (F-RES-01..12)

This is the build specification for the `Momos\Reservation` module of `momos-core`: domain, services, jobs and admin screens. It also covers the `momos/v1` reservation routes in `Momos\Api` and the reservation templates of the `momos` theme. Developers do not need to read the original documentation package.

**Sources.** Doc 04 §16.0.2–16.0.4, §16.7, §17 and §18.2/18.3. Doc 04A US-RES-001..031. Doc 05 WF-CUS-07/11/12/14, WF-ADM-10..16/26/27 and §4. Doc 06 rows 9–11. Doc 07 §4.5–4.7, §5.8–5.10, §5.23/5.24/5.32, §6.2/6.3/6.6/6.10. Doc 09 G3/G4/G6, §2.7, §3.7, §3.14 and §4.3–4.4 (API-01..04, API-10..13, API-16). Doc 10 §2.4.4, §2.4.8, §3.3–3.7, §5 and §7.2/7.3. Doc 11 §5.3–5.5, §5.8 and §6.3. Doc 12 TC-RES-*, TC-RSA-*. Doc 13 EP-09. Doc 17 DEC-011..017, 028, 048..051, 053..055, 058, 059, 062..064 and 066.

**Labels.**
- **[Doc]**: a value taken from the documentation.
- **[TBD → x]**: an open decision. Build `x` as configuration.
- **[Proposed]**: added by this spec because the documentation says nothing. Review it before release.
- **[Ruling]**: this spec's choice where the documents conflict (see §12).
- ⟦…⟧: a placeholder for a business fact. Never invent one.

Example values such as `SHN` and `06-0000-0000` are synthetic.

---

## 0. Build adaptation (IMPL-001/002/003)

| Documented mechanism | This build |
|---|---|
| ACF G6 `group_momo_branch_reservation` on `momo_branch` (WPML copy) | Native post meta on the branch post, registered with `register_post_meta`. Edited in a "Reservation settings" meta box/tab rendered by `Branch\Admin`. **Meta keys = the documented ACF names** (`momo_res_*`). The settings are language-independent and stored once. |
| ACF G3 options page `momo-settings` (global reservation settings) | WordPress options with the same names (`momo_res_global_enabled`, `momo_soft_duplicate_minutes`, …), autoload off. Edited on SCR-A15. |
| ACF repeater `momo_res_notification_recipients` | One post meta holding a JSON array `[{ "email": "...", "language": "ja"\|"en", "active": true }]`. It is validated on save. |
| `acf/validate_save_post` (`SettingsValidator`) | The `save_post_momo_branch` handler plus an `update_post_metadata` guard. An invalid save keeps the old values and shows an admin notice with the documented message. |
| WPML canonical JA post ID / `wpml_object_id` | There is one post per branch, so `branch_id` is the post ID. Repositories still verify post type and `publish` status. |
| Branch name/address per language (WPML translate fields) | `ja` reads the native fields. Other languages read `_momos_i18n_{lang}` JSON on the branch post, used only when `_momos_i18n_status_{lang} = approved` (DEC-027: no mixed language). |
| Elementor widgets `ReservationForm`, `ReservationResult`, `ReservationStatus` | `Presentation` renderers called from `momos` theme templates for the routes in §6. Assets: `assets/js/reservation-form.js`, `reservation-status.js` and `staff-inbox.js`. |
| WPML language URLs | `ja` sits at the root. `en, ar, ur, hi, bn` use `/{lang}/` prefixes, gated by `Kernel\I18n\LanguageGate` (IMPL-003). A disabled language is accepted in `lang`/`preferred_lang` only from logged-in staff. |
| WPML String Translation for UI strings | `momos-core` gettext strings, keyed by the Doc 07 §6 keys listed in §8. ar/ur/hi/bn are drafts awaiting native review (GATE-04). |
| Wordfence/Simple History/FluentSMTP | Not required (IMPL-004). 2FA, audit and SMTP live in `momos-core`. Reservation code calls only `Audit\AuditLogger`, `Notification\NotificationQueue` and `Access\BranchScope`. |
| Existing Kernel helpers | `Kernel\Support\Crockford` (`random`, `display`, `normalize`), `Hash` (`hmac`, `sha256`, `mask_email`), `Db\Transaction`, `Db\NamedLock`, `Events\AfterCommit`, `Clock\Clock`/`FrozenClock`, `Http\RequestContext` (`MOMOS_TRUSTED_IP_HEADER`), `Env::secret()`. |

---

## 1. Requirement coverage (traceability)

Priority: M = Must, S = Should, C = Could. "(S)" inside a Must feature marks a Should sub-part.

### 1.1 Features and functional requirements

| ID | Statement (one line) | Pri |
|---|---|---|
| F-RES-01 | Six-language reservation request form with live slot rules, validation, abuse controls and idempotent submission | M |
| F-RES-02 | Submission result page: "Request received — not yet confirmed", reference, next steps | M |
| F-RES-03 | Token-protected customer status page and self-cancellation | S |
| F-RES-04 | Branch-scoped staff inbox and detail, with claim and notes | M |
| F-RES-05 | Accept, Decline and Cancel through the state machine with optimistic locking and reasons | M |
| F-RES-06 | Per-branch reservation settings, server enforcement and a hidden TEST branch | M |
| F-RES-07 | Automatic expiry at the decision cutoff (job plus lazy expiry) | M |
| F-RES-08 | SLA reminder, and Head Office escalation at 2 × SLA | S |
| F-RES-09 | Attendance marking: `completed` or `no_show` | S |
| F-RES-10 | Staff modification of date, time and party size | S |
| F-RES-11 | Head Office CSV export | C |
| F-RES-12 | PII-free operations report SCR-A17 | S |
| FR-RES-001 | Form fields per §6.1. No Katakana, nationality, religion, date of birth, ID or payment fields. "No payment or deposit is required" shown. | M |
| FR-RES-002 | Client and server validation, localised. Inline errors plus a focused error summary. Values kept after errors. | M |
| FR-RES-003 | API-01 returns computed slots per date. Pickers enable only valid dates and slots. All computation in JST. | M |
| FR-RES-004 | Short notice (start < now + lead time) is refused, with a tap-to-call route | M |
| FR-RES-005 | API-02 is one transaction. Idempotency key, 10-minute soft duplicate, pending limit and the SDD §5.2 order apply. | M |
| FR-RES-006 | Result screen content, `noindex`, no third-party scripts, no banned terms, no .ics | M |
| FR-RES-007 | Turnstile, honeypot and per-IP/per-email rate limits. Captcha outage degrades to a tighter limit. | M |
| FR-RES-008 | Inbox: scoped in the data layer, default pending sorted by start, filters, search, badges, 20 per page, responsive | M |
| FR-RES-009 | Detail: all fields, timeline, notification panel, `allowed_actions`, `detail_viewed` audit | M |
| FR-RES-010 | Accept: pending → confirmed before the cutoff, optional message ≤ 500, E02 | M |
| FR-RES-011 | Decline: required reason, message ≤ 500, ≤ 2 suggested times, E03 | M |
| FR-RES-012 | Staff cancel: required reason. E04 is sent unless the reason is `suspected_spam`. Clears needs-action. | M |
| FR-RES-013 | Claim, take over and release (`handled_by`) | M |
| FR-RES-014 | Internal notes: append-only, 1–1,000 characters, never sent to customers | M |
| FR-RES-015 | Modification with re-validation. HO may override lead time, booking window and party range. Sends E06. | S |
| FR-RES-016 | Expiry job every 5 min, lazy expiry, E05, digest listing | M |
| FR-RES-017 | Reminder at SLA and escalation at 2 × SLA. Clock is `wall_clock` or `business_hours`. | S |
| FR-RES-018 | Attendance from start to start + 72 h for BM/RS. HO/SA any time after start. | S |
| FR-RES-019 | Status page `/{lang}/reservation/status/{ref}/#t={token}`. Token sent only in a header. | S |
| FR-RES-020 | Customer cancel of pending/confirmed before the customer cutoff. Sends E04 and E09. | S |
| FR-RES-021 | CSV export: HO only, reason required, ≤ 12 months, ≤ 10,000 rows, streamed, formula-safe | C |
| FR-RES-022 | Report: counts and rates, median/p90 response time, no PII, TEST branch excluded | S |
| FR-RES-023 | Per-branch settings with ranges, and the BR-RES-15 cutoff/lead-time rule | M |
| FR-RES-024 | Every submission and modification re-checks the branch and its rules inside the transaction | M |
| FR-RES-025 | Optimistic lock. `version_conflict` or `invalid_transition`, never a double transition. | M |
| FR-RES-026 | Hidden TEST branch for production smoke tests | M |
| FR-RES-027 | Live pre-submit summary including "Replies will be sent to {email}" | S |
| FR-RES-028 | Inbox polls every 60 s, shows an "n new — Show" bar and an offline banner | S |
| FR-RES-029 | Carrier-mail domain tip and an inbox "carrier email" badge | S |
| FR-RES-030 | TEST reservations notify only the QA inbox and are excluded from reports, KPIs and GA4 | M |

### 1.2 Business rules, non-functional requirements and decisions

| ID | Rule | Pri |
|---|---|---|
| BR-RES-01 | A request is never presented as confirmed before acceptance (banned-term list §8.4) | M |
| BR-RES-02 | Only users assigned to the branch (ROLE-06/07) or ROLE-01/02 may view or act on its reservations | M |
| BR-RES-03 | Validity: branch published, open and accepting; not a closure; a generated slot ≤ last order; ≥ now + lead time; ≤ window; party within range | M |
| BR-RES-04 | Decline and staff cancel need a reason code from the fixed lists | M |
| BR-RES-05 | Terminal statuses are final. A correction means a new request. | M |
| BR-RES-06 | Pending expires when now ≥ start − decision_cutoff | M |
| BR-RES-07 | Customer may cancel pending/confirmed until start − customer cutoff | M |
| BR-RES-08 | Idempotency key, 10-minute soft duplicate, max active pending per email per branch | M |
| BR-RES-09 | Attendance window: start → start + 72 h for branch staff; HO any time after start | M |
| BR-RES-10 | Parties above the max cannot be submitted online; show the phone route | M |
| BR-RES-11 | Modification needs guest agreement, a reason, re-validation and a customer email | M |
| BR-RES-12 | Email failure never invalidates a reservation; it sets a follow-up flag | M |
| BR-RES-13 | New closures never auto-cancel; affected reservations are flagged `needs_action` | M |
| BR-RES-14 | No deposits, prepayment or no-show fees | M |
| BR-RES-15 | `decision_cutoff ≤ lead_time − momo_min_decision_window_minutes` [TBD → 30] | M |
| BR-PRV-01/02/03, BR-NOT-01/02 | Minimal fields; retention [TBD → 12 months]; export by HO only; minimal PII in staff email; notes never sent to customers | M |
| NFR-REL-01 | No lost requests. Submission is atomic and idempotent. | M |
| NFR-DATA-01 | UNIQUE keys, optimistic locking, UTC storage with JST display | M |
| NFR-PERF-04 | API-02 p95 ≤ 2 s at [TBD → 20] concurrent; inbox p95 ≤ 2 s at [TBD → 10,000] rows | M |
| NFR-OBS-01 | Expiry heartbeat; alert after 30 min without a run | M |
| DEC-011/012/013/015/016/051/058/059/062/063/064/066 | Statuses; expiry; SLA; per-branch rules; status page; `public_ref`; token transport; hashed IP/UA; JST label; error catalogue; token derivation; no third-party scripts on result/status pages | — |

### 1.3 User stories, screens, APIs and test cases

- **User stories:** US-RES-001..031. Must: 001–007, 009, 012–019, 023, 024, 026, 027 and 030. Should: 008, 010, 011, 020–022, 025, 028 and 029. Could: 031.
- **Screens:** SCR-C09 form, SCR-C10 submitted and SCR-C11 status (S). SCR-A08 inbox, SCR-A09 detail, SCR-A17 report (S) and the SCR-A03 "Reservation settings" tab. Email templates SCR-E01..E10 are owned by the notification spec; their triggers are listed in §3.15.
- **API:** API-01, 02, 03 (S), 04 (S), 10, 11, 12, 13 and 16 (S), plus the admin-post export (C).
- **Security and privacy controls:** SEC-010..015, 020..027 and 031..033; PRV-001, 004, 007, 008, 012 and 018.
- **Tests:** TC-RES-001..042 and TC-RSA-001..025; key ones are in §10.

---

## 2. Data model

### 2.1 Tables (Doc 10 §3.3; created by migration M0001, constraints added by M0002)

```sql
CREATE TABLE {$prefix}momo_reservations (
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
  KEY idx_email_branch_status (email,branch_id,status),
  KEY idx_created (created_at),
  KEY idx_requested_date (requested_date)
) ENGINE=InnoDB {$charset_collate};

CREATE TABLE {$prefix}momo_reservation_events (
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
) ENGINE=InnoDB {$charset_collate};

-- M0002 (each statement runs only if the named constraint is absent from information_schema)
ALTER TABLE {$prefix}momo_reservation_events
  ADD CONSTRAINT fk_momo_res_events_reservation FOREIGN KEY (reservation_id)
  REFERENCES {$prefix}momo_reservations (id) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE {$prefix}momo_reservations
  ADD CONSTRAINT chk_momo_res_status CHECK (status IN ('pending','confirmed','rejected','cancelled','expired','completed','no_show')),
  ADD CONSTRAINT chk_momo_res_party CHECK (party_size BETWEEN 1 AND 50),
  ADD CONSTRAINT chk_momo_res_lang CHECK (preferred_lang IN ('ja','en','ar','ur','hi','bn') AND source_lang IN ('ja','en','ar','ur','hi','bn')),
  ADD CONSTRAINT chk_momo_res_cancelled_by CHECK (cancelled_by IS NULL OR cancelled_by IN ('customer','staff','system')),
  ADD CONSTRAINT chk_momo_res_captcha CHECK (captcha_status IN ('verified','unverified_outage','not_applicable')),
  ADD CONSTRAINT chk_momo_res_source CHECK (source IN ('web','staff','api')),
  ADD CONSTRAINT chk_momo_res_pii CHECK (pii_purged_at IS NOT NULL OR (guest_name IS NOT NULL AND email IS NOT NULL AND token_hash IS NOT NULL));
ALTER TABLE {$prefix}momo_reservation_events
  ADD CONSTRAINT chk_momo_evt_type CHECK (event_type IN ('created','status_changed','assigned','released','note','modified',
    'notification_failed','customer_contacted','sla_reminder','sla_escalated','closure_conflict','pii_purged')),
  ADD CONSTRAINT chk_momo_evt_actor CHECK (actor_type IN ('customer','staff','system'));
```

**Storage rules:**
- All `datetime` columns are UTC. `requested_date` and `requested_time` are JST business-local values (DEC-050). `requested_start_utc = (date + time) in Asia/Tokyo → UTC`, converted with `wp_timezone()`/`DateTimeZone('Asia/Tokyo')`.
- SQL never uses `NOW()`. Time always comes from `Kernel\Clock\Clock`.
- JSON columns (`suggested_times`, `payload`) are `text`/`longtext` validated with `Kernel\Support\Json`. They do not use the MySQL `JSON` type.
- The `email` collation is case-insensitive. The email's domain part is stored lower-cased.
- A CI test asserts that the PHP enum cases equal the CHECK lists.

### 2.2 Column rules (ENT-15)

| Column | Type / rule | Set by |
|---|---|---|
| `public_ref` | 8 characters of Crockford base32 (`0123456789ABCDEFGHJKMNPQRSTVWXYZ`) from a CSPRNG. Displayed `XXXX-XXXX` in an LTR isolate. On collision regenerate, up to 5 tries, then 500. It is an identifier, not a secret. | T1 |
| `idempotency_key` | UUIDv4 in lower-case canonical form, generated once per form load (`crypto.randomUUID()` with a fallback) | T1 |
| `branch_id` | Post ID of a published `momo_branch`. Never re-pointed; moving branch = cancel + new request. | T1 |
| `guest_name` | 1–100 characters after trim and NFC. Any script. CR/LF, control characters and `<`/`>` are rejected [Ruling C-9]. | T1 |
| `email` | ≤ 254 characters, `is_email()`, trimmed, domain lower-cased, no CR/LF | T1 |
| `phone_e164` | Optional. Native digits normalised. Parsed with libphonenumber (`giggsey`, default region JP), 8–15 digits. Stored as E.164 ≤ 20 characters. | T1 |
| `special_request` | Optional. ≤ 1,000 characters after NFC. Tags stripped; runs of more than 2 blank lines collapsed; control characters other than `\n` rejected. | T1 |
| `preferred_lang` / `source_lang` | `preferred_lang` is the email language and defaults to `lang`. `source_lang` is the form UI language. Both must be enabled languages. | T1 |
| `consent_version` / `consent_at` | `consent_version` is `momo_consent_version_current`, e.g. `PP-2026-11-v1\|RP-2026-11-v1` [TBD → format per G3 registry], ≤ 20 characters. `consent_at` is server time. | T1 |
| `token_hash`, `token_key_version`, `token_expires_utc` | See §3.6. Expiry = start + 24 h, recomputed on modification. | T1, N3 |
| `handled_by` | WP user ID or NULL | N1, T2/T3/T4b |
| `decision_reason_code` | DeclineReason or StaffCancelReason value | T3, T4b/T6b |
| `message_to_customer` | ≤ 500 characters, plain text with tags stripped, never translated | T2/T3/T4b/T6b |
| `suggested_times` | JSON `[{"date":"YYYY-MM-DD","time":"HH:MM"}]`, 0–2 items, future times, decline only | T3 |
| `customer_notified_flag` | Default 1. Set to 0 when a customer email fails permanently; reset to 1 by `customer_contacted`. | Notification module |
| `needs_action_flag` | Set to 1 by a closure conflict (§3.13). Cleared by staff cancel, a valid modification or `flag_resolved`. | Branch/N |
| `captcha_status` | `verified` \| `unverified_outage` \| `not_applicable` (`not_applicable` for TEST-branch submissions by logged-in staff [Proposed]) | T1 |
| `ip_hash`, `user_agent_hash` | `hex(HMAC-SHA256(MOMOS_HASH_PEPPER, value))`. Nulled after [TBD → 30 days]. | T1 |
| `version` | Starts at 1. +1 on every row update (transition, claim/release/flags, modification). | all |

### 2.3 Enumerations (`Momos\Reservation\Domain`, PHP backed enums)

| Enum | Values |
|---|---|
| `ReservationStatus` | `pending`, `confirmed`, `rejected`, `cancelled`, `expired`, `completed`, `no_show`. `isActive()` = pending/confirmed; the others are terminal. |
| `EventType` | `created`, `status_changed`, `assigned`, `released`, `note`, `modified`, `notification_failed`, `customer_contacted`, `sla_reminder`, `sla_escalated`, `closure_conflict`, `pii_purged` |
| `ActorType` | `customer`, `staff`, `system` |
| `DeclineReason` | `fully_booked`, `closed_or_private_event`, `party_size_not_supported`, `incomplete_information`, `other` |
| `StaffCancelReason` | `customer_request_by_phone`, `branch_unable_to_serve`, `duplicate`, `suspected_spam`, `other` |
| `StaffAction` | `accept`, `decline`, `cancel`, `mark_completed`, `mark_no_show` |
| `CaptchaStatus` | `verified`, `unverified_outage`, `not_applicable` |
| `cancelled_by` | `customer`, `staff`, `system` (the column stays NULL on expiry) |
| `Lang` (Kernel) | `ja`, `en`, `ar`, `ur`, `hi`, `bn` |

**Event payloads (JSON, no PII):**
- `created`: `{captcha, source_lang}`
- `assigned`: `{previous_handler_id, previous_handler_display}`
- `modified`: `{before:{date,time,party}, after:{…}, override_reason?}`
- `notification_failed`: `{notification_id, template}`
- `sla_*`: `{minutes_waiting}`

For T2/T3/T4b/T6b the event `note` holds a copy of `message_to_customer`. Internal notes use `event_type = note` with `note` = text. A cleared needs-action flag is a `note` event with `reason_code = flag_resolved`.

### 2.4 Branch reservation settings: post meta on `momo_branch` (G6)

All settings are language-independent and validated on save. Editing needs `momo_edit_branch_settings` (ROLE-01/02). BM/RS see them read-only. Changes are audited as `branch.settings_updated` with before/after values and emit `momos/branch/settings_changed`. They apply to new submissions and modifications only.

| Meta key | Type | Default | Range / validation |
|---|---|---|---|
| `momo_res_enabled` | bool | `0` [Ruling C-7] | Can be 1 only if: the branch is published, `momo_operating_status = open`, there is ≥ 1 active recipient, and every weekly period has `last_order_at` |
| `momo_res_party_min` | int | [TBD → 1] | 1–50 |
| `momo_res_party_max` | int | [TBD → 8] | party_min–50 |
| `momo_res_lead_time_minutes` | int | [TBD → 120] | 0–10,080 |
| `momo_res_booking_window_days` | int | [TBD → 60] | 1–365 |
| `momo_res_slot_interval_minutes` | enum | [TBD → 30] | 15 \| 30 \| 60 |
| `momo_res_decision_cutoff_minutes` | int | [TBD → 60] | 0–1,440 **and** ≤ lead_time − `momo_min_decision_window_minutes` (BR-RES-15) |
| `momo_res_response_sla_minutes` | int | [TBD → 120] | 0–1,440; 0 = off [Ruling C-6] |
| `momo_res_sla_clock` | enum | [TBD → `wall_clock`] (DEC-013/Q-402) | `wall_clock` \| `business_hours` |
| `momo_res_show_response_promise` | bool | 0 | Customer copy shows the promise only when this is 1 (DEC-013) |
| `momo_res_max_pending_per_email` | int | [TBD → 3] | 1–10 |
| `momo_res_customer_cancel_cutoff_minutes` | int | [TBD → 0] (0 = until start; Q-403) | 0–1,440 |
| `momo_res_notification_recipients` | JSON list | ⟦branch inbox — DEC-049⟧ | 0–10 rows; unique valid emails; domain not on the webmail block list; `language` ja/en; ≥ 1 active when enabled |
| `momo_res_policy_page` | page ID \| null | null | Policy text shows only if the page is published in the language (DEC-054) |

**Save messages (EN, ja draft):**
- Cutoff rule (AC-RES-06-01): "The decision cutoff must be at least {window} minutes shorter than the minimum lead time." / 「判断期限は最短受付時間より{window}分以上短く設定してください。」 [ja Proposed]
- Recipient on the block list: "Use a business-controlled address (personal webmail is not allowed)."
- Webmail block list default [TBD → `gmail.com, yahoo.co.jp, yahoo.com, outlook.com, hotmail.com, icloud.com, docomo.ne.jp, ezweb.ne.jp, au.com, softbank.ne.jp, i.softbank.jp`] (Q-421). It is held in option `momo_res_recipient_blocklist` [Proposed key] and maintained by ROLE-01.

**Branch identity meta read by this module (G4):**
- `momo_branch_code` (`^[A-Z]{3}$`, immutable once published)
- `momo_operating_status` (`open` \| `temporarily_closed` \| `closed_permanently`)
- `momo_reopen_date`
- `momo_phone_e164`, `momo_phone_display`
- `momo_address_ja`; localised address lines from the i18n JSON
- `momo_is_test_branch` (bool, SA only) [Ruling C-10]

**Global options (G3, SCR-A15):**

| Option | Default | Use |
|---|---|---|
| `momo_res_global_enabled` | 1 | Kill switch → `branch_not_accepting` |
| `momo_soft_duplicate_minutes` | 10 | BR-RES-08 |
| `momo_min_decision_window_minutes` | [TBD → 30] | BR-RES-15 |
| `momo_rate_ip_per_hour` | [TBD → 5] | `res_ip` |
| `momo_rate_email_per_hour` | [TBD → 3] | `res_email` |
| `momo_rate_ip_degraded_per_hour` | [TBD → 2] | `res_ip_degraded` |
| `momo_carrier_domains` | [TBD → docomo.ne.jp, ezweb.ne.jp, au.com, softbank.ne.jp, i.softbank.jp, ymobile.ne.jp] | FR-RES-029 |
| `momo_export_max_range_months` | [TBD → 12] | FR-RES-021 |
| `momo_export_max_rows` | [TBD → 10,000] | FR-RES-021 |
| `momo_consent_version_current` + `momo_consent_versions` | — | Consent registry |
| `momo_ho_escalation_recipients` | — | E08 escalation target |

**Secrets (constants, never in the database):**
- `MOMOS_TOKEN_KEY_V1..Vn` (32-byte keys) and the current version via `MOMOS_TOKEN_KEY_CURRENT` [Proposed]
- `MOMOS_HASH_PEPPER`
- `MOMOS_TURNSTILE_SITE_KEY`, `MOMOS_TURNSTILE_SECRET`

### 2.5 Multilingual

Reservation rows are **not translatable**. Guest text is stored and shown as entered, with `dir="auto"` and `<bdi>`. Reservation data is never sent to machine translation (BR-PRV-04).

Labels come from gettext keys (§8):
- statuses
- reasons
- `closure_reason.*`
- `period_label.*`

**Branch name/address in language L:**
- L = `ja`: the native fields.
- Otherwise: `_momos_i18n_{L}` with status `approved`.
- [Proposed] If a branch is not approved in L, it is omitted from the C09 selector in L, consistent with BR-I18N-02. The language launch gate (GATE-04) makes this a non-case in production.

Emails and the status page use the reservation's `preferred_lang`.

---

## 3. Business rules and algorithms

### 3.1 API-02 validation order (SDD §5.2, authoritative). The first failing check determines the response.

| # | Check | Failure |
|---|---|---|
| 1 | POST, `Content-Type: application/json`, body ≤ 8 KB, valid JSON | 415 `unsupported_media_type` / 413 `payload_too_large` / 400 `invalid_json` |
| 2 | `Origin` host (else `Referer` host) equals the site host | 403 `origin_rejected` |
| 3 | Rate limit `res_ip`; `res_ip_degraded` while the Turnstile outage flag was set in the last 10 min | 429 `rate_limited` + `Retry-After` |
| 4 | Honeypot `website` is empty | 403 `captcha_failed` (logged as `honeypot`; generic message) |
| 5 | Schema: types, required, enums, patterns, lengths, `additionalProperties:false` | 400 `invalid_param` + `fields` |
| 6 | `idempotency_key` already stored | **200**: same body as the original 201 with `duplicate:false`, `status_url` re-derived |
| 7 | Turnstile `siteverify`: secret and token only, no `remoteip`. Requires `success`, hostname = site, action `reservation_submit`, `challenge_ts` ≤ 300 s, 5 s timeout, one retry on network error. A network error, timeout or 5xx switches to degraded mode: `captcha_status = unverified_outage`, outage flag set, audit `security.captcha_degraded`. | 403 `captcha_failed` |
| 8 | Rate limit `res_email` (HMAC of the normalised email) | 429 |
| 9 | Normalise (§3.2) | — |
| 10 | Field rules (§2.2); `consent === true`; `lang`/`preferred_lang` enabled | 400 `invalid_param` |
| 11 | `branch_code` resolves to a published branch. TEST branch only for a logged-in user assigned to it. | 404 `branch_not_found` |
| 12 | Accepting: `operating_status = open`, `momo_res_enabled = 1`, `momo_res_global_enabled = 1` | 422 `branch_not_accepting` (+ phone) |
| 13 | `party_min ≤ party_size ≤ party_max` | 422 `party_size_out_of_range` (+ phone) |
| 14 | today(JST) ≤ date ≤ today + window | 422 `outside_booking_window` (`{from}`, `{to}`) |
| 15 | start_utc ≥ now + lead_time | 422 `lead_time_not_met` (+ phone) |
| 16 | `(date, time)` ∈ `SlotGenerator::slotsFor(branch, date)` | 409 `slot_unavailable`. `fields.time` is one of `branch_closed_on_date`, `outside_opening_hours`, `after_last_order`, `not_a_slot`. |
| 17 | `NamedLock::acquire('momo_sub_'.sha1(email.'\|'.branch_id), 3)` | 503 `service_unavailable` (retryable) |
| 18 | Soft duplicate: same email + branch + date + time, status pending **or confirmed**, `created_at ≥ now − momo_soft_duplicate_minutes` | **200** with `duplicate:true`, the existing ref, **no `status_url`** |
| 19 | Pending count for email + branch with status `pending` ≥ `momo_res_max_pending_per_email` | 409 `pending_limit_reached` (`{n}`, phone) |
| 20 | Transaction T1 (§3.4). A deadlock (MySQL 1213/1205) is retried once, then 503. | 500 `internal_error` / 503 |

Branch settings are read **inside** the submission flow with no stale cache (FR-RES-024(b)). API-16 reuses checks 9–16 with `context = staff_modify` (§3.12). API-01 describes the same rules.

**Choosing the `slot_unavailable` reason:**
- `branch_closed_on_date`: the date has no resolved periods.
- `outside_opening_hours`: the time is outside every resolved period.
- `after_last_order`: last order < time < close.
- `not_a_slot`: off the slot grid.

### 3.2 Normalisation (`Validation\Normalizer`)

- Trim all strings and apply Unicode NFC. Strip control characters as allowed per field.
- `Digits::normalize` converts these digit sets to ASCII: Arabic-Indic U+0660–0669, Extended Arabic-Indic U+06F0–06F9, Devanagari U+0966–096F, Bengali U+09E6–09EF and full-width U+FF10–FF19. It applies to phone, party size and admin time inputs. Example: "৪" → 4; "০৯০-১২৩৪-৫৬৭৮" → `+819012345678`.
- Email: trim, then lower-case the domain.
- `carrier_email` is true when the domain is in `momo_carrier_domains`. It is computed, never stored.
- Phone: libphonenumber with default region JP; "+60 12-345 6789" → `+60123456789`.
- Special request: strip tags and collapse blank lines.

### 3.3 Slot generation (`Service\SlotGenerator`, SDD §5.5): single source of truth for API-01/02/16

**Business date** *b* is the date on which a period **opens** (JST). A period with `closes_next_day = 1` produces after-midnight slots that belong to the next **calendar** date.

**Resolved periods for b**, in order of precedence:
1. A closure row for *b*. `closed_all_day` means no periods; `special_hours` means only the special periods.
2. Otherwise, weekly rows for weekday(*b*) whose validity range covers *b*. These replace the open-ended rows of that weekday.
3. Otherwise, the open-ended weekly rows.
4. Otherwise, closed.

A closure on date X removes business day X, including its tail on X+1. It does not touch the tail of X−1 that runs into X.

```text
daysFor(branch, nowUtc):
  s = settings(branch); if !accepting(branch, s) -> NotAccepting(reason, phone, reopen_date)
  today = date(nowUtc @ Asia/Tokyo); last = today + s.booking_window_days; earliest = nowUtc + s.lead_time_minutes
  buckets = { d: sortedSet() for d in today..last }
  for b in (today - 1) .. last:                       # yesterday included for after-midnight tails
    for p in resolvedPeriods(branch, b):
      open  = local(b, p.opens_at); close = local(b, p.closes_at) + (p.closes_next_day ? 1d : 0)
      lo    = local(b, p.last_order_at ?? (p.closes_at - interval)); if p.last_order_at < p.opens_at: lo += 1d
      t = open
      while t <= lo:
        d = date(t); if today <= d <= last and toUtc(t) >= earliest: buckets[d].add(time(t))
        t += s.slot_interval_minutes
  return [ Day(date=d, slots=buckets[d], closed_reason = buckets[d] empty ? closureReason(branch,d) : null) ]
```

`isValidSlot(branch, date, time, now)` evaluates only `date` and the business date before it.
- Slots are aligned to each period's `opens_at`.
- A day without slots for reasons other than a closure (lead time, for example) returns `closed_reason: null`.
- `enabled` requires a last order on every period, so the `closes − interval` fallback applies only to `special_hours` [Doc FR-RES-003(b) Rec].

**Worked examples (interval 30, lead 120):**

| # | Setup | Result |
|---|---|---|
| 1 | Wed 11:30–15:00, L.O. 14:30 | 11:30 … 14:30 (7 slots) |
| 2 | Fri 18:00–01:00 (next day), L.O. 00:30 | Fri 18:00 … 23:30; Sat 00:00 and 00:30 |
| 3 | Sat `closed_all_day`, Fri as in #2 | Sat shows only 00:00 and 00:30; `closed_reason` null |
| 4 | 31 Dec `special_hours` 12:00–16:00, L.O. 15:30 | 12:00 … 15:30 only |
| 5 | now 17:10 JST, dinner 17:00–22:00, L.O. 21:00 | Today 19:30 … 21:00 |
| 6 | now 18:10, 17:00–23:00, L.O. 22:30 (AC-RES-01-05) | First slot 20:30; last 22:30; no 23:00 |

### 3.4 Submission transaction T1 and duplicate prevention

All of the following happen in one InnoDB transaction (`Db\Transaction`):
1. INSERT the reservation with `pending`, `version=1`, `public_ref`, token hash/version/expiry, consent, languages, hashes and `captcha_status`.
2. INSERT a `created` event (actor customer, `to_status=pending`).
3. `NotificationQueue::enqueue` E01 (customer) and E07 (one per **active** recipient of that branch only; GATE-03).

After commit, `AfterCommit` enqueues the async sends and fires `momos/reservation/created`. The lock is released in `finally`. Any exception rolls back and returns 503 `service_unavailable`, with nothing stored. The transaction contains no HTTP calls, no email and no `wp_posts` writes.

**Duplicate layers:**
1. UNIQUE `idempotency_key` with replay (step 6).
2. Soft duplicate within 10 min (step 18).
3. Pending limit (step 19).
4. Named lock around check-and-insert.

Staff-side duplicates are cancelled with reason `duplicate`. E04 is sent for `duplicate` and suppressed only for `suspected_spam`.

### 3.5 `public_ref`

Generated with `Crockford::random(8)`. `Crockford::normalize(input)` accepts input with or without a hyphen or space, in any case, and maps O→0 and I/L→1. The display form is `XXXX-XXXX`. The path pattern is `^[0-9A-HJKMNP-TV-Z]{8}$` after normalisation.

### 3.6 Status token (DEC-058/DEC-064; `Service\TokenService`)

- **Derive:** `token = base64url_nopad(HMAC-SHA256(MOMOS_TOKEN_KEY_V{n}, "momos-res-token|v{n}|{id}|{public_ref}|{created_at_utc}"))`. This gives 32 bytes, 43 characters, `^[A-Za-z0-9_-]{43}$`. The token is never stored. The same link is re-derived for every later email (E02–E06) and for the idempotent replay.
- **Store:** `token_hash = hex(sha256(token))`, `token_key_version = n`, `token_expires_utc = requested_start_utc + 24 h`.
- **Link:** `{home}/{lang-prefix}reservation/status/{public_ref}/#t={token}`, where `lang` is `preferred_lang` (root for `ja`).
- **Verify (API-03/04):**
  1. `t` in the query or body → 400 `invalid_param` (`TokenHeaderGuard`, before any lookup).
  2. Missing or malformed header → 400.
  3. Normalise ref and load the row. Not found or `pii_purged_at` set → 404 `not_found`.
  4. `hash_equals(row.token_hash, sha256(token))` false → 404 `not_found` (indistinguishable from an unknown ref).
  5. `now ≥ token_expires_utc` → 410 `link_expired` (checked only after a hash match).
  6. Lazy expiry (§3.9).
- **Key rotation:** new reservations use the newest key version. Old keys are kept until all of their tokens have expired. `wp momos token reissue --key-version={n+1}` recomputes the hashes of unexpired tokens and queues E06 `new_link`.
- `Log\Redactor` removes `X-Momo-Token` from all logs.

### 3.7 Rate limits (TRD §4.3.6; `Api\RateLimit\RateLimiter`)

The limiter uses a sliding window over two fixed windows: `estimate = cur + prev × (1 − elapsed/window)`. A request is rejected when `estimate ≥ limit`.
- **Keys:** `momos_rl:{bucket}:{Hash::hmac(subject,'MOMOS_HASH_PEPPER')}:{window_start}` with TTL 2 × window. Storage is the object cache (`wp_cache_add`/`incr`), falling back to transients.
- **Client IP:** `REMOTE_ADDR`, or the CDN header only from trusted CDN ranges (`MOMOS_TRUSTED_IP_HEADER`).
- **Headers:** responses set `X-RateLimit-Limit` and `X-RateLimit-Remaining`; a 429 also sets `Retry-After` (seconds).

| Bucket | Route | Key | Default |
|---|---|---|---|
| `res_ip` | API-02 | IP | [TBD → 5/h] |
| `res_ip_degraded` | API-02 during captcha outage | IP | [TBD → 2/h] |
| `res_email` | API-02 | normalised email | [TBD → 3/h] |
| `status_ip` | API-03 | IP | [TBD → 30/h] |
| `cancel_ip` | API-04 | IP | [TBD → 10/h] |
| `staff_user` | API-10..16 | user ID | [TBD → 120/min] |
| WAF (edge, ops) | POST reservations / GET rules | IP | [TBD → 20/10 min; 60/min] |

An alert is raised when there are more than [TBD → 10] degraded submissions per hour.

### 3.8 State machine (DEC-011; `Domain\StateMachine` + `Service\ActionPolicy` + `Service\TransitionService`)

```
[*] -> pending (T1 submit)
pending   -> confirmed (T2 accept) | rejected (T3 decline) | cancelled (T4a customer / T4b staff) | expired (T5 system)
confirmed -> cancelled (T6a customer / T6b staff) | completed (T7) | no_show (T8)
rejected, cancelled, expired, completed, no_show = terminal
```

| # | From | Action (actor) | Guard (in addition to capability and branch scope) | To | Columns | Notifications | Audit |
|---|---|---|---|---|---|---|---|
| T2 | pending | accept (staff) | version match; now < start − decision_cutoff | confirmed | `confirmed_at`; `handled_by` if null; `message_to_customer` | E02 | yes |
| T3 | pending | decline (staff) | reason ∈ DeclineReason; ≤ 2 future suggested times; same time rule as T2 | rejected | `rejected_at`, reason, message, `suggested_times` | E03 | yes |
| T4a | pending | cancel (customer) | not past the decision cutoff (else lazy T5 → 409 `invalid_transition`); now < start − customer cutoff, else 409 `cancel_not_allowed` | cancelled | `cancelled_at`, `cancelled_by=customer` | E04 `by_customer` + E09 | event only |
| T4b | pending | cancel (staff) | reason ∈ StaffCancelReason | cancelled | `cancelled_at`, `cancelled_by=staff`, reason, message; `needs_action_flag=0` | E04 `by_branch` unless `suspected_spam` | yes |
| T5 | pending | expire (system) | now ≥ start − decision_cutoff | expired | `expired_at` | E05; listed in E10 | — |
| T6a | confirmed | cancel (customer) | now < start − customer cutoff, else 409 `cancel_not_allowed` | cancelled | as T4a | E04 + E09 | — |
| T6b | confirmed | cancel (staff) | as T4b | cancelled | as T4b | as T4b | yes |
| T7/T8 | confirmed | mark_completed / mark_no_show (staff) | BM/RS: start ≤ now ≤ start + 72 h; HO/SA (`momo_access_all_branches`): now ≥ start; else 422 `attendance_window` | completed / no_show | `attended_at` | none | yes |
| — | any other | — | — | — | — | — | 409 `invalid_transition` (API-12/16, API-04 after lazy expiry), or `cancel_not_allowed` (API-04 on terminal/after cutoff) |

`reason = other` requires a `message_to_customer` or an internal note, else 400 `reason_required` [TBD → TRD proposal].

**Conditional UPDATE (FR-RES-025):**

```sql
UPDATE {$prefix}momo_reservations SET status=%s, version=version+1, updated_at=%s, <timestamp col>=%s,
       handled_by=COALESCE(handled_by,%d), decision_reason_code=%s, message_to_customer=%s
 WHERE id=%d AND version=%d AND status=%s;
```

When it affects 1 row, the transaction continues: event `status_changed` (actor staff with `actor_display` snapshot), notifications, then audit `reservation.status_changed {from,to}`.

When it affects 0 rows, re-read the row:
- Status differs from the expected `from` → `InvalidTransition`, 409, with the current status.
- Same status, different version → `VersionConflict`, 409, with the current summary.

Consequence: repeating a successful action returns `invalid_transition` and sends no second email [Ruling C-2].

`ActionPolicy::allowedActions(dto, ctx, now)` returns the subset of `accept, decline, cancel, mark_completed, mark_no_show, claim, release, note, modify, mark_contacted, flag_resolved` whose guards pass. Accept and decline are never offered at or after the cutoff. The UI renders only these actions.

### 3.9 Expiry: job and lazy (FR-RES-016)

**`ExpiryService::expireIfDue(int $id): ?ReservationDto`** applies when the row is `pending` and `now ≥ requested_start_utc − decision_cutoff_minutes`, using the branch's value at run time. It runs T5 in its **own** transaction: a conditional UPDATE on `status='pending'`, event `status_changed` (actor system), and E05 with dedupe key `res:{id}:E05:{version}`. After commit it fires the hook. A 0-row result is skipped silently.

**Callers:** API-03 and API-11 first; then API-04, API-12 and API-16, which return 409 `invalid_transition` after it.

**`momos_job_expire_pending`** runs every 5 min (Action Scheduler, group `momos`, real cron every minute):
1. For each branch, including disabled ones, select at most 200 `pending` rows with `requested_start_utc ≤ now + cutoff` (index `idx_branch_status_start`).
2. Run `expireIfDue` per row; a failure in one row does not stop the batch.
3. Ping the `expiry` heartbeat (`MOMOS_HEARTBEAT_URL_EXPIRY`). A missing heartbeat for 30 min raises an alert.

Re-runs are idempotent: one event and one E05 per reservation.

### 3.10 SLA reminders (FR-RES-017, S; `Job\SlaJob` + `Service\SlaClock`)

`momos_job_sla_check` runs every 5 min over `pending` rows of branches with SLA > 0, where `elapsed = SlaClock::minutes(branch, created_at, now)`.
- **Reminder:** elapsed ≥ SLA and no `sla_reminder` event → E08 `sla_reminder` to the branch recipients plus event.
- **Escalation:** elapsed ≥ 2 × SLA and no `sla_escalated` event → E08 `sla_escalation` to `momo_ho_escalation_recipients` plus event.
- Nothing is sent once the request is not `pending`.
- Notification dedupe keys prevent repeats.

**Clock modes:**
- `wall_clock`: elapsed minutes.
- `business_hours`: only minutes inside resolved periods, including special hours and after-midnight tails. A request received while the branch is closed starts counting at the next opening. Example (AC-RES-08-03): created 23:30, closes 00:00, reopens 11:00, SLA 120 → reminder at 12:30 the next day.

The inbox `sla_state` is computed with the same clock:

| `sla_state` | Condition |
|---|---|
| `none` | SLA 0 or not pending |
| `ok` | < 75 % of SLA [Proposed] |
| `due` | 75–99 % [Proposed] |
| `overdue` | ≥ SLA |

### 3.11 Claim, notes and flags (API-13, events N1/N2/N4/N5)

| Type | Version required | Effect | Event / audit |
|---|---|---|---|
| `claim` | yes | `handled_by = me`. Takeover is allowed; the UI first asks "Claimed by {name}. Take over?". | `assigned` with the previous handler; audit `reservation.claimed` |
| `release` | yes | `handled_by = NULL` | `released` |
| `note` | no | 1–1,000 characters, plain text, tags stripped. Allowed in any status, including terminal. Append-only. | `note` with text; audit `reservation.note_added` (without the text) |
| `customer_contacted` | yes | `customer_notified_flag = 1` | `customer_contacted` |
| `flag_resolved` | yes, plus text | `needs_action_flag = 0` | `note` with `reason_code = flag_resolved` |

Claiming never changes the status and never sends email. Notes never appear in customer email, the status page, staff email or the default export (BR-NOT-02).

### 3.12 Modification (FR-RES-015, S; `Service\ModificationService`, API-16)

**Preconditions:** status `pending` or `confirmed` and before start; lazy expiry first.

**Request rules:**
- Editable: `requested_date`, `requested_time`, `party_size`. The branch cannot change; that requires cancel with reason `customer_request_by_phone` plus a new request.
- `reason = agreed_with_guest` is required, else 400 `reason_required`.
- Optional internal `note` ≤ 1,000 characters.

**Re-validation:** checks 9–16 of §3.1 with context `staff_modify`. The reservation itself is excluded from the soft-duplicate and pending-limit checks.

**Override:**
- Only for users with `momo_access_all_branches`, sending `override_rules: true` and `override_reason` (1–200 characters).
- It may waive lead time, booking window and party range.
- It never waives closures, periods or last order, slots, or a non-accepting branch.
- `override_rules` from anyone else → 403 `forbidden_field` [Ruling C-3].

**Transaction:** conditional UPDATE (version+1, date, time, `requested_start_utc`, party, `token_expires_utc = new start + 24 h`, `needs_action_flag = 0` when valid), event `modified` (before/after, override reason), audit `reservation.modified`, and E06. The E06 variant is `pending` or `confirmed` by current status, and its `pending` wording never says "reservation". The expiry threshold moves with the new start.

### 3.13 Closure impact (BR-RES-13; implements Branch's `ReservationFlagger`)

Triggers:
- `momos/branch/schedule_changed`
- operating status → `temporarily_closed`
- `momo_res_enabled → 0`

For each, `flagClosureConflicts(branchId, fromUtc, toUtc)` re-checks every `pending`/`confirmed` reservation in range with `SlotGenerator`, ignoring lead time and window. Invalid ones get `needs_action_flag = 1` (version+1) and event `closure_conflict`. There is no automatic cancellation and no customer email. Flagged reservations are listed in the inbox "Needs action" filter and repeated in the branch E10 digest until resolved.

### 3.14 TEST branch (FR-RES-026/030)

A `momo_is_test_branch = 1` branch is:
- Excluded from every public list, sitemap, hreflang, JSON-LD and the dataLayer.
- Not found for anonymous API-01/02 (404 `branch_not_found`).
- Reachable at `/reservation/?branch={code}` only by logged-in users assigned to it (or ROLE-01/02), with the banner "TEST BRANCH — not a real restaurant".

Its recipients must be test inboxes. Its rows are excluded from SCR-A17, the KPIs and the default export, and `generate_lead` is suppressed. Testers cancel their test requests after each run.

### 3.15 Notifications triggered (templates and transport: notification spec)

| Template | When it is queued |
|---|---|
| E01 | T1 (customer) |
| E07 | T1 (branch recipients: given name only, reference, date/time, party, preferred language, "has a special request" yes/no, deep link `/wp-admin/admin.php?page=momos-reservations&id={id}` [Proposed]) |
| E02 | T2 |
| E03 | T3 (reason label, message, suggested times, link `/reservation/?branch={code}`) |
| E04 | T4/T6. Variants `by_customer` and `by_branch`; none for `suspected_spam`. |
| E05 | T5 |
| E06 | N3, and token re-issue (`new_link`) |
| E08 | SLA reminder, SLA escalation, notification failure |
| E09 | T4a/T6a (branch recipients) |
| E10 | Daily digest: expired, failures, attendance due, closure conflicts |

Every queue insert happens inside the business transaction, with unique dedupe keys (`res:{id}:E01`, `res:{id}:{tpl}:{version}`, `res:{id}:sla_reminder:{rcpt}` …). After a permanent failure the notification module sets `customer_notified_flag = 0`, writes event `notification_failed` and queues E08 `notify_failed`.

---

## 4. Permissions (Doc 09 §2.7; `Access\MetaCapMapper` rule M8)

**Branch scope.** `assigned(user, branch)` holds when the user has `momo_access_all_branches`, or has a `momo_user_branches` row for the branch. In both cases the account must not be disabled or expired.

**How scope is enforced:**
- The meta caps `momo_view_reservation(id)` and `momo_process_reservation(id)` = the primitive plus `assigned(user, reservation.branch_id)`. An unknown ID resolves to `do_not_allow`.
- Every list, count, search, export and heartbeat query goes through `ReservationRepository` with an `AccessContext`. A query without scope throws.
- A denied request writes audit `access.denied` and returns no data.

| Capability / action | ROLE-01 SA | ROLE-02 HO | ROLE-03 MM | ROLE-04 CE | ROLE-05 TR | ROLE-06 BM | ROLE-07 RS |
|---|---|---|---|---|---|---|---|
| `momo_view_reservations` (inbox, API-10) | Y | Y | — | — | — | Y (b) | Y (b) |
| `momo_process_reservations` (API-12/13/16) | Y | Y | — | — | — | Y (b) | Y (b) |
| `momo_view_customer_pii` (email, phone, special request) | Y | Y | — | — | — | Y (b) | Y (b) |
| Rule override on API-16; attendance after +72 h (`momo_access_all_branches`) | Y | Y | (scope only) | (scope only) | — | — | — |
| `momo_export_reservations` (C) | Y | Y | — | — | — | — | — |
| `momo_view_reservation_reports` (SCR-A17) | Y | Y | — | — | — | Y (b) | — |
| `momo_edit_branch_settings` (G6 edit) | Y | Y | — | — | — | read (b) | summary in SCR-A09 |
| Set `momo_is_test_branch` | `manage_options` | — | — | — | — | — | — |

- (b) = assigned branches only.
- **ROLE-00** (public) uses API-01/02 and the token routes API-03/04 only.
- **CAP-DCA** grants nothing in this module.
- **Vendor accounts** (`momo_vendor = 1`) carry per-user denies for `momo_view_customer_pii`, `momo_view_reservations` and `momo_export_reservations` unless break-glass is active (≤ [TBD → 8 h]).
- **2FA** is mandatory before any staff route.
- **Staff REST** requires the cookie plus `X-WP-Nonce` (`wp_rest`).

---

## 5. API: `momos/v1` (base `/wp-json/momos/v1/`)

**Conventions:**
- JSON in UTF-8. Dates are `YYYY-MM-DD` and times `HH:MM` in JST; instants are ISO 8601 `+09:00`.
- Every response carries `X-Request-Id`.
- Caching: API-01 sends `Cache-Control: public, max-age=300, s-maxage=300`. All other routes send `no-store` and `X-Robots-Tag: noindex`.
- Body limits are 8 KB on public routes and 16 KB on staff routes.
- Controllers are thin: parse → service → DTO. Permission callbacks are explicit.

**Error envelope** (`Api\Http\ErrorResponder`; `ErrorNormaliser` re-codes core codes):

```json
{ "code": "lead_time_not_met", "message": "<localised>", "data": { "status": 422, "request_id": "uuid",
  "message_key": "res.err.time.too_soon", "fields": { "time": "out_of_range" },
  "branch_phone": { "e164": "+81600000000", "display": "06-0000-0000" } } }
```

- Field reasons are `required`, `invalid_format`, `too_long`, `out_of_range`, `not_allowed`.
- Core codes are re-coded: `rest_invalid_param`/`rest_missing_callback_param` → `invalid_param`; `rest_invalid_json` → `invalid_json`; `rest_not_logged_in` → 401 `rest_forbidden`; `rest_cookie_invalid_nonce`, or a cookie without a nonce, → 403 `rest_forbidden`.
- The message is in the request `lang` on public routes and in the user locale on staff routes.

### 5.1 Canonical error catalogue (Doc 04 §16.0.4; DEC-063). No other codes are returned.

| code | HTTP | Routes (this module) | Customer key / staff key |
|---|---|---|---|
| `invalid_param` | 400 | all | `res.err.{field}.*` |
| `invalid_json` | 400 | POST/PATCH | `res.err.system` |
| `reason_required` | 400 | 12, 16 | `staff.err.reason_required` |
| `rest_forbidden` | 401/403 | 10–16 | login redirect / access denied |
| `forbidden_branch` | 403 | 10–16 | `staff.err.forbidden_branch` |
| `forbidden_field` | 403 | 16 (override) [Ruling C-3] | `staff.err.forbidden_field` |
| `captcha_failed` | 403 | 02 | `res.err.captcha` |
| `origin_rejected` | 403 | 02, 04 | `res.err.captcha` |
| `branch_not_found` | 404 | 01, 02 | `res.err.branch.not_found` |
| `not_found` | 404 | 03, 04, 11–16 | `status.link_invalid` |
| `slot_unavailable` | 409 | 02, 16 | `res.err.date.closed` (`branch_closed_on_date`) / `res.err.time.unavailable` |
| `pending_limit_reached` | 409 | 02 | `res.err.limit.active` |
| `cancel_not_allowed` | 409 | 04 | `status.cancel.not_allowed` |
| `invalid_transition` | 409 | 04, 12, 16 | `staff.err.invalid_transition` / `staff.err.expired`; customer: `status.cancel.not_allowed` |
| `version_conflict` | 409 | 12, 13, 16 | `staff.err.version_conflict` |
| `link_expired` | 410 | 03, 04 | `status.link_invalid` |
| `payload_too_large` | 413 | POST/PATCH | `res.err.system` |
| `unsupported_media_type` | 415 | POST/PATCH | `res.err.system` |
| `branch_not_accepting` | 422 | 01 (as 200 flag), 02, 16 | `res.err.branch.unavailable` |
| `party_size_out_of_range` | 422 | 02, 16 | `res.err.party.min` / `res.err.party.max_call` |
| `lead_time_not_met` | 422 | 02, 16 | `res.err.time.too_soon` |
| `outside_booking_window` | 422 | 02, 16 | `res.err.date.window` |
| `attendance_window` | 422 | 12 | `staff.err.attendance_window` |
| `rate_limited` | 429 | 02, 03, 04, 10–16 | `res.err.rate` |
| `internal_error` | 500 | all | `res.err.system` + `res.err.reference` |
| `service_unavailable` | 503 | all | `res.err.system` + `res.err.reference` |

### 5.2 Public routes

**API-01 `GET /branches/{code}/reservation-rules?lang=`.** Public, with `permission_callback __return_true`. `code` matches `^[A-Z]{3}$`.

Response 200:

```json
{ "branch_code":"SHN","branch_name":"…","accepting":true,
  "not_accepting_reason":null|"temporarily_closed"|"reservations_disabled"|"global_disabled","reopen_date":null,
  "timezone":"Asia/Tokyo",
  "rules":{"party_min":1,"party_max":8,"lead_time_minutes":120,"booking_window_days":60,"slot_interval_minutes":30,"response_promise_minutes":null},
  "contact":{"phone_e164":"+81600000000","phone_display":"06-0000-0000"},
  "days":[{"date":"2026-12-24","slots":["17:30","18:00"],"periods":{"17:30":"dinner"},"closed_reason":null,"closed_label":null}],
  "generated_at":"2026-12-24T12:05:00+09:00" }
```

- `periods` maps each slot to its `period_label` for chip grouping [Proposed additive field].
- `response_promise_minutes` is non-null only when `momo_res_show_response_promise = 1`.
- A branch that is not accepting returns 200 with `accepting:false`.
- Errors: 400, 404 `branch_not_found`, 429, 503.
- The response contains no staff or customer data.
- The cache is purged on `schedule_changed` and `settings_changed`. The client refetches when the branch changes and when the page has been open more than 5 min.

**API-02 `POST /reservations`.** Public; controls per §3.1.

Request schema (`additionalProperties: false`):
- Required: `branch_code` `^[A-Z]{3}$`, `date`, `time` `^([01]\d|2[0-3]):[0-5]\d$`, `party_size` int 1–50, `name` 1–100, `email` ≤ 254, `lang` (enum of 6), `preferred_lang` (enum), `consent` bool, `idempotency_key` uuid, `captcha_token` ≤ 2,048.
- Optional: `phone` string\|null ≤ 32, `special_request` string\|null ≤ 1,000, `website` (honeypot) ≤ 200.
- `special_request_consent` bool is accepted only when the option `momo_special_request_separate_consent = 1` [TBD → off; DEC-053/Q-410]. When on, it is required for a non-empty special request.

Response **201**:

```json
{ "reference":"7Q2KX9MD","reference_display":"7Q2K-X9MD","status":"pending",
  "status_label":"Request received — not yet confirmed","duplicate":false,"message":"…",
  "summary":{"branch_code":"SHN","branch_name":"…","branch_address":"…","branch_address_ja":"…","date":"2026-12-24","time":"19:00","timezone":"Asia/Tokyo","party_size":4,"guest_name":"…"},
  "email_masked":"g***@example.com","decision_deadline":"2026-12-24T18:00:00+09:00",
  "branch_contact":{"phone_e164":"+81600000000","phone_display":"06-0000-0000"},
  "response_promise_minutes":null,"status_url":"https://…/en/reservation/status/7Q2KX9MD/#t=…",
  "carrier_email":false,"email_delivery_tip":null }
```

- `status_url` is present only while F-RES-03 is live.
- **200 replay** (same key): the identical body.
- **200 soft duplicate:** `duplicate:true`, the existing reference, no `status_url`; the message key is `res.info.duplicate`.
- Errors: as in §3.1.
- Logging covers error codes, route, branch_code, internal id and duration. It never includes PII. There is no audit entry; the event row is the record.

**API-03 `GET /reservations/{public_ref}`** (S). Header `X-Momo-Token` is required; optional `?lang`.

Response 200:

```json
{ "reference_display":"7Q2K-X9MD","status":"confirmed","status_label":"Confirmed by the branch","status_note":null|"The date of this reservation has passed.",
  "branch":{"code":"SHN","name":"…","address":"…","address_ja":"…","phone_e164":"…","phone_display":"…","directions_url":"…"},
  "date":"2026-12-24","time":"19:00","timezone":"Asia/Tokyo","party_size":4,
  "guest_given_name":"Aisha","guest_name":"Aisha Rahman","email_masked":"g***@example.com",
  "special_request_received":true,"message_from_branch":null,"decline_reason_label":null,"suggested_times":[],
  "cancelled_by":null,"can_cancel":true,"cancel_deadline":"2026-12-24T19:00:00+09:00",
  "history":[{"status":"pending","at":"…"},{"status":"confirmed","at":"…"}] }
```

- `completed`/`no_show` are exposed as `status:"confirmed"` with `status_note` (customers never see attendance).
- The history excludes staff identities and notes.
- Phone and special-request text are never returned.
- The page renders `guest_given_name` only until Q-426 is decided [Ruling C-8].
- Errors: 400, 404 `not_found`, 410, 429, 503.

**API-04 `POST /reservations/{public_ref}/cancel`** (S). Header token, Origin check, body `{"lang":"en"}` (`additionalProperties:false`).

Processing order:
1. Verify the token (§3.6).
2. Lazy expiry; expired → 409 `invalid_transition`.
3. Terminal status, including an already-cancelled request → 409 `cancel_not_allowed` [Ruling C-1].
4. now ≥ start − customer cutoff → 409 `cancel_not_allowed`.
5. T4a/T6a.

The response is 200 with the API-03 shape (`status:"cancelled"`, `can_cancel:false`). Errors: 400, 403 `origin_rejected`, 404, 409, 410, 429, 503.

### 5.3 Staff routes (cookie + `X-WP-Nonce`; rate bucket `staff_user`)

**API-10 `GET /staff/reservations`.** Permission `momo_view_reservations`.

Query parameters:
- `branch` (CSV of codes; outside scope → 403 `forbidden_branch`)
- `status` (CSV, default `pending`)
- `date_from`, `date_to`
- `q`: 2–100 characters. A reference with or without the hyphen matches exactly (case-insensitive); otherwise a guest-name substring, matched case-insensitively after NFKC.
- `flags`: CSV of `needs_action, not_notified, overdue, unclaimed`
- `has_special_request` (bool)
- `handled_by`: `me` \| `none`
- `since` (ISO instant; returns rows created or changed since then plus header `X-Momo-New-Count`)
- `sort`: `requested_start` \| `created_at`
- `order`: `asc` \| `desc`
- `page`; `per_page` 1–50, default 20

Response 200: headers `X-WP-Total` and `X-WP-TotalPages`. Each row is:

```json
{"id":1842,"reference_display":"7Q2K-X9MD","branch_code":"SHN","branch_name":"…","status":"pending","requested_date":"2026-12-24","requested_time":"19:00","party_size":4,"guest_name":"…","guest_given_name":"…","preferred_lang":"en","handled_by":null|{"id":37,"name":"Staff A"},"created_at":"…","sla_state":"due","flags":{"needs_action":false,"customer_not_notified":false,"carrier_email":false,"has_special_request":true,"captcha_unverified":false},"version":1}
```

List rows never include email, phone or special-request text. The response may add `counts` per status in scope through the header `X-Momo-Status-Counts` (JSON) [Proposed].

**API-11 `GET /staff/reservations/{id}`.** Permission `momo_view_reservation(id)`. Lazy expiry is applied first.

The response contains the list fields plus:
- PII, only with `momo_view_customer_pii`: `email`, `phone_e164`, `special_request`.
- `source_lang`, `consent_version`, `consent_at`, `decision_reason_code`, `message_to_customer`, `suggested_times`.
- The lifecycle timestamps, `cancelled_by`, `captcha_status`.
- `events[]`: type, from, to, `actor_display`, reason, note, at.
- `notifications[]`: template, variant, language, status, attempts, `sent_at`, `recipient_masked`, `last_error`.
- `allowed_actions[]`.
- `branch_settings_summary`: party_min/max, lead time, cutoff, window.

Audit `reservation.detail_viewed`, de-duplicated per user per reservation per 30 min. Errors: 401/403, 403 `forbidden_branch` (audited, no body data), 404.

**API-12 `POST /staff/reservations/{id}/transitions`.** Permission `momo_process_reservation(id)`.

Body:

```json
{"action":"accept|decline|cancel|mark_completed|mark_no_show","version":1,"reason_code":"…","message_to_customer":"≤500","suggested_times":[{"date":"…","time":"…"}]}
```

- `suggested_times` is accepted only with `decline`, at most 2, and must be future. They are not validated as slots; the UI only warns when a time is outside opening hours.
- Response 200: `{"id":1842,"status":"confirmed","version":2,"confirmed_at":"…","handled_by":{"id":37,"name":"Staff A"},"notifications_queued":["E02"]}`.
- Errors: 400 `invalid_param`/`reason_required`, 401/403, 403 `forbidden_branch`, 404, 409 `invalid_transition`/`version_conflict` (body `data.current` = `{status, version, handled_by, updated_at, updated_by_display}`), 422 `attendance_window`, 429.

**API-13 `POST /staff/reservations/{id}/notes`.** Body `{"type":"note|claim|release|customer_contacted|flag_resolved","text":"≤1000","version":3}` (rules in §3.11).
- Response 201: `{"event_id":9921,"type":"note","at":"…","actor":"Staff A","version":3}`.
- Errors: 400, 401/403, 403, 404, 409 `version_conflict`.

**API-16 `PATCH /staff/reservations/{id}`** (S). Body:

```json
{"version":2,"requested_date":"2026-12-25","requested_time":"18:30","party_size":5,"reason":"agreed_with_guest","note":null,"override_rules":false,"override_reason":null}
```

- Response 200: the detail plus `notifications_queued:["E06"]`.
- Errors: 400 `invalid_param`/`reason_required`, 401/403, 403 `forbidden_branch`/`forbidden_field`, 404, 409 `invalid_transition`/`version_conflict`/`slot_unavailable`, 422 `branch_not_accepting`/`party_size_out_of_range`/`lead_time_not_met`/`outside_booking_window`.

**Export (C): `admin-post.php?action=momos_export`** (not REST). Requirements: capability `momo_export_reservations`, a nonce, filters `branch[]`, `status[]`, `date_from`, `date_to` (≤ `momo_export_max_range_months`), `reason` (1–200, required), and `include_special_requests` (bool, default false).

- **Row cap:** above the cap, the export is refused with "Narrow the filters — more than {n} rows".
- **Columns:** reference, branch_code, requested_date, requested_time, party_size, status, preferred_lang, created_at (JST), decision_at, decision_reason_code, guest_name, email, phone_e164 (+ special_request).
- **Format:** UTF-8 with BOM, streamed to `php://output` and never written to disk. Cells starting with `= + - @`, TAB or CR get a `'` prefix.
- **File name:** `momo-reservations-{codes}-{YYYYMMDD}-{YYYYMMDD}.csv`.
- **Exclusions:** TEST rows are excluded unless explicitly filtered; purged rows export without PII.
- **Audit:** `reservation.exported` (filters, rows, reason, notes flag), plus `reservation.export_with_notes` when special requests are included.

---

## 6. Customer screens (theme `momos`; mobile-first; `novalidate` with localised errors)

### 6.1 SCR-C09 Reservation form: `/{lang}/reservation/` (`?branch={code}` prefill; canonical without the query)

No breadcrumb and no mobile action bar.

**Order:**
1. H1 `res.title`
2. `res.intro`
3. Promise, only if configured: `res.submitted.promise`
4. Branch
5. Date
6. Time
7. Party
8. Name
9. Email
10. Phone
11. Special request
12. Reply language
13. Policy text, when the policy page is published
14. Summary `res.summary.title`
15. `res.notice.collection`
16. Consent
17. "No payment or deposit is required" (`res.no_payment`) [Proposed key; ja 「お支払いやデポジットは不要です。」 draft]
18. Submit `res.submit`
19. `res.call_alt`

| Field | Control | Rules / keys |
|---|---|---|
| Branch | `<select>` in HO order. Branches not accepting show "(not accepting online requests)" disabled. | Required. Changing the branch clears date and time and announces `res.info.branch_changed`. |
| Date | "Today"/"Tomorrow" radio chips (shown only when bookable) plus a `<select>` grouped by month (`<optgroup>`) from API-01 `days`. Closures show the suffix "(closed)" and are disabled. Today is disabled with "(too soon — please call)" when it has no slot. | `res.err.date.required` / `.closed` / `.window` / `.too_soon` |
| Time | Radio chips grouped by period label (lunch/dinner/all day); arrow keys move between chips. With no slots: INFO "No times available on this date". | `res.err.time.required` / `.unavailable` / `.too_soon` |
| Party | Chips party_min..party_max when max ≤ 8, otherwise a stepper with `inputmode="numeric"`. A "More than {max}?" link shows `res.err.party.max_call` with a `tel:` link and disables submit (A2). | `res.err.party.required` / `.min` / `.max_call` |
| Name | text, `autocomplete="name"`, `dir="auto"`; hint `res.hint.name` | `res.err.name.required` / `.length` |
| Email | `type="email"`, `autocomplete="email"`, `dir="ltr"`; hint `res.hint.email` | `res.err.email.required` / `.format` |
| Phone | `type="tel"`, `inputmode="tel"`, `dir="ltr"`; label `res.label.phone`; hint `res.hint.phone` | `res.err.phone.format` |
| Special request | textarea, `dir="auto"`, max 1,000, counter from 900 characters; hint `res.hint.note`; `res.hint.advance_order` when arriving from an advance-order dish | `res.err.note.length` |
| Reply language | `<select>` of enabled languages in native names; default = page language | — |
| Consent | Checkbox, unticked. Label `res.label.consent` with both policy links in the same language (new tab, labelled). | `res.err.consent.required` |
| Honeypot | `name="website"`, visually hidden, `tabindex="-1"`, `autocomplete="off"`, `aria-hidden="true"` | — |
| Turnstile | Managed/invisible, action `reservation_submit`, page language; loaded on this page only | — |

**States:**

| State | Behaviour |
|---|---|
| Loading | Slot skeleton while API-01 loads (≤ 2 s). On submit, the button shows `res.submit.busy` and is `aria-busy`. Submit is disabled only while sending. |
| Empty | No accepting branch: a notice plus branch phones instead of the form. Preselected branch not accepting: `res.err.branch.unavailable` and the fields are disabled. Unknown or unpublished `?branch`: the field is left empty with no error. |
| Errors | Inline validation on blur after the first edit, and on submit. Server field errors map to the same keys. `res.err.summary` sits at the top with `role="alert"`, receives focus and lists links to the fields. Values are always kept, including across a language switch. SYSTEM errors appear in a banner above the button with the phone number and `res.err.reference`. |
| Server outcomes | 409 `slot_unavailable`: the time is cleared and the slots reloaded. 422: POLICY-CALL messages with `tel:` (A1/A2/A3). 409 `pending_limit_reached`: submit disabled. 403 `captcha_failed`: Turnstile re-runs. 429: `res.err.rate`. Network failure or 5xx: `res.err.system` and Retry with the **same** idempotency key. A second failure shows the phone number prominently. |
| Success | Only on 201 with `duplicate:false`: push `generate_lead` once (beacon; navigate after the callback or ≤ 500 ms). Then store the response in `sessionStorage["momo_last_request"]` and navigate to C10 (PRG; no PII in the URL). On 200 (replay or duplicate) store it and navigate without the analytics push. |

**Summary panel (FR-RES-027):** live and read-only. It shows the branch, date, time (with "JST" in non-ja languages), party size and "Replies will be sent to: {email}" (email in large text, LTR isolate). It is part of the submit area's description and is not a separate step.

**Responsive layout:** one column at 360 px; two columns for name/email/phone from 768 px; a sticky summary column from 1024 px.

**TEST branch:** banner "TEST BRANCH — not a real restaurant".

### 6.2 SCR-C10 Submitted: `/{lang}/reservation/submitted/`

Page headers and loading:
- `noindex`; excluded from sitemaps.
- `Referrer-Policy: no-referrer`.
- No GTM/GA4, Turnstile or any third-party script.
- Rendered client-side from `sessionStorage` (kept until the tab closes, so a refresh shows the same content).

Content, in order:
1. Hourglass icon and H1 `res.submitted.heading`, announced with `role="status"` or focus.
2. `res.submitted.reference` with `XXXX-XXXX` (LTR, `aria-label` read in character groups) and a Copy button. Copying announces "Reference copied" (`res.copied` [Proposed key]).
3. Summary: branch name, address (localised plus Japanese), date, time (+ JST in non-ja), party size and name, then `res.submitted.email_to` with the masked email.
4. Next steps: `res.submitted.next`; `res.submitted.expiry` with `{time}` = `decision_deadline`; `res.submitted.promise` only if configured; `res.submitted.dont_travel`.
5. `res.submitted.status_link` button, when `status_url` is present.
6. Branch phone as a `tel:` link.
7. `res.carrier_tip` only when `carrier_email = true`, with `{domain}` = the sending domain.

Variants:
- `duplicate:true`: the same layout with the heading `res.info.duplicate`.
- Empty (no session data): `res.submitted.generic` plus a link to the branch directory, with no error.

Never shown: an `.ics` file or "add to calendar". No Doc 07 §6.6 request-context term may appear.

### 6.3 SCR-C11 Status (S): `/{lang}/reservation/status/{public_ref}/#t={token}`

Page shell:
- Non-personalised and the same for every visitor.
- Headers: `Cache-Control: no-store, private`, `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex, nofollow`.
- No third-party scripts.
- The rewrite rule is registered by momos-core, with a language prefix for non-ja.

Script flow:
1. Read the `#t` fragment.
2. Call `history.replaceState` to remove it.
3. Store it in `sessionStorage["momo_status_token:{ref}"]`. The language switcher re-attaches it as `#t=` on the target URL, never as a query parameter.
4. Call API-03 with `X-Momo-Token`.

**States:**

| State | Behaviour |
|---|---|
| Loading | Skeleton the size of the status card |
| No JavaScript | `status.no_js` with the branch phone; no data |
| 404 / 410 | `status.link_invalid`; no data and no reference lookup |
| Success | H1 badge with the status label (text, not colour) and a one-sentence meaning (Doc 05 §4.7 drafts). Then: reference; details (branch, date, time with JST for non-ja, party, given name); `message_from_branch`; for `rejected`, the reason label `decline.reason.*`, `decline.suggested` and each suggested time linking to C09 prefilled ("Request another time"); for `cancelled`, by you or by the branch; for `expired`, the call suggestion; branch address (localised plus Japanese), phone and directions; policy text when published. `completed`/`no_show` show the confirmed label plus `status.past`. |
| Cancel | Shown when `can_cancel`. CTA is `status.cancel.cta.pending` or `.confirmed` by status. It opens an inline panel (not a modal) that receives focus: `status.cancel.confirm` with [`status.cancel.keep`] [`status.cancel.yes`]. Success updates the badge to "Cancelled" (announced) and shows the branch phone. A 409 response reloads the data and shows `status.cancel.not_allowed` as INFO. |

---

## 7. Admin screens (wp-admin "Reservations" menu, label 予約リクエスト / Reservations, with a pending-count badge)

### 7.1 SCR-A08 Inbox: `admin.php?page=momos-reservations` (JS app on API-10)

**Header:** branch scope chip (assigned branch or "All branches" for HO), filter chips [Pending] [Today] [Upcoming] [More], and search, which runs on submit.

**Defaults:** status `pending`, sorted by requested start ascending. Saved views: "Upcoming confirmed", and "Attendance due" (confirmed, start passed, within 72 h).

**Rows/cards:**
- Requested date/time (largest), party, guest given name (`dir="auto"`), branch code, status badge (staff label).
- Age since created, plus an "Overdue" badge when `sla_state = overdue`.
- "Claimed by {name}" or "Unclaimed".
- Flag icons, each with a text label: needs action, customer not notified, carrier email, has special request, unverified captcha.

**Layout:** cards below 768 px, a table from 1024 px. Tap targets are ≥ 44 px [Ruling C-12].

**Polling (FR-RES-028, S):** every 60 s, call API-10 with `since`, keeping the visible rows in place. Show the "{n} new — Show" bar (`role="status"`). An offline banner pauses polling.

**Menu badge:** WP Heartbeat (`heartbeat_received`) returns `{pending, overdue}` counts in scope every 60 s.

**States:**
- Empty: "No requests waiting." with links "Upcoming confirmed" and "Attendance due".
- Loading: skeleton.
- Error: banner "Couldn't load requests. Retry."
- 403 `forbidden_branch` for an out-of-scope filter.

**HO only (C):** an "Export CSV" dialog using the current filters, with a required reason, the date range limit, "Include special requests" (off) and row cap messages.

### 7.2 SCR-A09 Detail: `admin.php?page=momos-reservations&id={id}` (API-11)

Back to the inbox keeps the filters. Layout: stacked with a sticky bottom action bar [Accept] [Decline] [More ▾] at 360 px; two columns from 1024 px.

Content:
1. Status badge and reference.
2. Date/time, party, branch.
3. Guest name, phone (`tel:`), email (`mailto:`) and preferred language (native name plus admin-language name).
4. Special request (`dir="auto"`, escaped) or "No special request".
5. Flags, each with its next step (`staff.warn.customer_not_notified`, `staff.warn.needs_action_closure`, `staff.warn.captcha_unverified`).
6. Handled by, with [Claim].
7. Timeline, newest first, including notification rows.
8. Notes with [Add internal note] (counter, 1,000).
9. Read-only rules summary.

Actions open a bottom sheet (mobile) or a side panel (desktop). Focus moves to the sheet heading, Esc closes it and focus returns to the trigger. Each sheet states the consequence, e.g. "{Guest} will receive an email in {language}." Buttons are disabled while a request is in flight.

| Sheet | Fields |
|---|---|
| Accept | Summary, optional message (0/500) with the guidance "Write in simple English or Japanese; it is sent as written", [Accept and email guest] |
| Decline | Reason radio (required; staff labels below), message ≤ 500, up to 2 suggested times (date and time selects) with an "outside opening hours" warning |
| Cancel request | Reason radio (required). For `suspected_spam`: "No email will be sent to the guest." |
| Change details (S) | Date/time slot picker (same generator), party size; fixed reason "Agreed with guest"; note. HO only: "Override rules" plus a reason ≤ 200. |
| Attended / No-show (S) | Confirm. Shown only inside the window. |

**Results:**
- Success: toast "Accepted — email queued to guest" (`role="status"`); the badge and timeline update.
- 409 `version_conflict`: `staff.err.version_conflict`, then reload. UX copy: "This request was updated by {name} at {time}. The latest version is shown."
- 409 `invalid_transition`: `staff.err.invalid_transition`, or after lazy expiry `staff.err.expired`.
- 400 `reason_required`: the reason group is highlighted.
- 422 `attendance_window`: the window is explained.
- 403: the access denied page.
- Accept and Decline are hidden after the cutoff.

**Staff reason labels (EN, ja draft) [Proposed ja]:**

| Code | EN | ja |
|---|---|---|
| `fully_booked` | Fully booked | 満席 |
| `closed_or_private_event` | Closed or private event | 休業・貸切 |
| `party_size_not_supported` | Party size not supported | 人数に対応できない |
| `incomplete_information` | Incomplete information | 情報不足 |
| `other` | Other | その他 |
| `customer_request_by_phone` | Cancelled at guest's request by phone | お客様から電話でのキャンセル依頼 |
| `branch_unable_to_serve` | Branch unable to serve | 店舗都合で対応不可 |
| `duplicate` | Duplicate request | 重複リクエスト |
| `suspected_spam` | Suspected spam | スパムの疑い |

### 7.3 SCR-A17 Reservation report (S): `admin.php?page=momos-reservation-report`

**Access:** `momo_view_reservation_reports` with scope applied. A BM sees only assigned branches; RS has no access.

**Filters:** date range by created or requested date, branch (in scope), language.

**Metrics** per branch, language and ISO week (JST), computed from rows and events so they survive PII purge:

| Metric | Definition |
|---|---|
| Requests created | Count of rows |
| Counts by final status | Per status |
| Acceptance rate | confirmed ÷ (confirmed + rejected + expired) |
| Expiry rate | expired ÷ (confirmed + rejected + expired) |
| No-show rate | no_show ÷ (completed + no_show) |
| Response time | Median and p90 minutes from `created_at` to the first `status_changed` to confirmed/rejected (event query Q11; percentiles in PHP) |
| Decisions within SLA | Share of decisions made within the SLA |

**Display:** KPI tiles plus tables. Every chart has a table. Definitions are shown under the table. A zero denominator shows "Not enough data for this period", never 0 %.

TEST branch rows are excluded. No names, emails, phones or notes appear anywhere on the screen.

### 7.4 SCR-A03 "Reservation settings" tab

Fields per §2.4. HO edits; BM sees the tab read-only for assigned branches. The cutoff rule and recipient domain validation show inline admin notices.

---

## 8. Microcopy (Doc 07 §6.2/6.3 keys; text domain `momos-core`; ja = draft, native/legal review required)

### 8.1 Form (C09)

| Key | EN | JA |
|---|---|---|
| `res.title` | Request a table | 予約リクエスト |
| `res.intro` | Your request is sent to the branch. The branch will reply by email. | リクエストは店舗に送信され、店舗からメールでご連絡します。 |
| `res.label.branch` / `.date` / `.time` / `.party` | Branch / Date / Time / Number of people | 店舗 / 日付 / 時間 / 人数 |
| `res.label.name` / `res.hint.name` | Your name / As you'd like the branch to call you. Any language is fine. | お名前 / 店舗でお呼びするお名前をご入力ください。どの言語・文字でも構いません。 |
| `res.label.email` / `res.hint.email` | Email / We send the branch's reply to this address. | メールアドレス / 店舗からの返信をこのアドレスにお送りします。 |
| `res.label.phone` | Phone (recommended) | 電話番号（推奨） |
| `res.hint.phone` | Include your country code, e.g. +60 12 345 6789. Japanese numbers may start with 0. The branch may call you if email doesn't reach you. | 海外の番号は国番号から入力してください（例：+60 12 345 6789）。国内の番号は0から始めて構いません。メールが届かない場合、店舗からお電話することがあります。 |
| `res.label.note` / `res.hint.note` | Special request (optional) / e.g., high chair, celebration, seating preference. Please tell staff directly about serious allergies. | ご要望（任意） / 例：子ども用椅子、お祝い、お席のご希望など。重いアレルギーについては、ご来店時にスタッフへ直接お伝えください。 |
| `res.hint.advance_order` | {dish} needs to be ordered at least {hours} hours ahead. Mention it here. | {dish}は{hours}時間前までのご注文が必要です。こちらにご記入ください。 |
| `res.label.lang` | Language for our emails | メールの言語 |
| `res.summary.title` | Check your request | リクエスト内容の確認 |
| `res.notice.collection` (legal) | We use these details only to handle this reservation request and to contact you about it. They are shared only with the branch you choose. See our Privacy Policy. | ご入力いただいた情報は、この予約リクエストの対応およびご連絡のみに使用し、選択された店舗にのみ共有します。詳しくはプライバシーポリシーをご覧ください。 |
| `res.label.consent` (legal) | I agree to the Privacy Policy and the Reservation Policy. | プライバシーポリシーおよび予約ポリシーに同意します。 |
| `res.submit` / `res.submit.busy` | Send request / Sending… | リクエストを送信 / 送信中… |
| `res.call_alt` | Can't find a suitable time? Call {branch}: {phone} | ご希望の時間がない場合は{branch}（{phone}）へお電話ください。 |
| `res.err.summary` | Please correct {n} item(s) below. | 以下の{n}件の項目をご確認ください。 |
| `res.err.branch.required` / `.unavailable` | Choose a branch. / {branch} is not taking online requests right now. Please call {phone}. | 店舗を選択してください。 / {branch}は現在オンラインでのリクエストを受け付けていません。{phone}へお電話ください。 |
| `res.err.branch.not_found` | This branch could not be found. Please choose a branch from the list. | この店舗は見つかりませんでした。一覧から店舗を選んでください。 |
| `res.err.date.required` / `.closed` / `.window` | Choose a date. / {branch} is closed on this date. Choose another date. / You can request up to {days} days ahead. | 日付を選択してください。 / この日は{branch}の休業日です。別の日をお選びください。 / {days}日先までリクエストできます。 |
| `res.err.date.too_soon` = `res.err.time.too_soon` | This is too soon to request online. Please call {branch}: {phone}. | オンラインでのリクエスト受付時間を過ぎています。{branch}（{phone}）へお電話ください。 |
| `res.err.time.required` / `.unavailable` | Choose a time. / This time is no longer available. Choose another time. | 時間を選択してください。 / この時間は選択できなくなりました。別の時間をお選びください。 |
| `res.err.party.required` / `.min` / `.max_call` | Enter the number of people. / The minimum for online requests is {min}. / For more than {max} people, please call {branch}: {phone}. | 人数を入力してください。 / オンラインでのリクエストは{min}名様からです。 / {max}名様を超える場合は、{branch}（{phone}）へお電話でご相談ください。 |
| `res.err.name.required` / `.length` | Enter your name. / Your name can be up to 100 characters. | お名前を入力してください。 / お名前は100文字以内で入力してください。 |
| `res.err.email.required` / `.format` | Enter your email address. / Enter an email address like name@example.com. | メールアドレスを入力してください。 / メールアドレスを name@example.com の形式で入力してください。 |
| `res.err.phone.format` | Enter a phone number with country code, e.g. +60 12 345 6789, or a Japanese number starting with 0. | 国番号付き（例：+60 12 345 6789）または0から始まる国内の電話番号を入力してください。 |
| `res.err.note.length` | Your request can be up to 1,000 characters. | ご要望は1,000文字以内で入力してください。 |
| `res.err.consent.required` | Tick the box to agree to the Privacy Policy and the Reservation Policy. | プライバシーポリシーおよび予約ポリシーへの同意にチェックを入れてください。 |
| `res.err.limit.active` | You already have {n} requests waiting at this branch. Please wait for a reply or call {phone}. | この店舗への返信待ちのリクエストが既に{n}件あります。返信をお待ちいただくか、{phone}へお電話ください。 |
| `res.err.rate` | Too many requests from this connection. Please try again in a few minutes or call {phone}. | 短時間に多くのリクエストが送信されました。数分後に再度お試しいただくか、{phone}へお電話ください。 |
| `res.err.captcha` | We couldn't verify your request. Please try again. | リクエストを確認できませんでした。もう一度お試しください。 |
| `res.err.system` / `res.err.reference` | Your request was not sent. Please try again. If it keeps failing, call {phone}. / Error reference: {request_id} | リクエストを送信できませんでした。もう一度お試しください。解決しない場合は{phone}へお電話ください。 / エラー番号：{request_id} |
| `res.info.duplicate` | We already received this request. Your reference is {ref}. | このリクエストは既に受け付けています。受付番号は{ref}です。 |
| `res.info.branch_changed` | Date and time were cleared because you changed the branch. | 店舗を変更したため、日付と時間をリセットしました。 |

### 8.2 Result and status (C10/C11)

| Key | EN | JA |
|---|---|---|
| `status.pending` | Request received — not yet confirmed | リクエスト受付・未確定 |
| `status.confirmed` | Confirmed by the branch | 店舗にて確定済み |
| `status.rejected` | Declined | お受けできませんでした |
| `status.cancelled` | Cancelled | キャンセル済み |
| `status.expired` | Expired — not confirmed in time | 期限切れ（時間内に確定できませんでした） |
| `status.past` | The date of this reservation has passed. | このご予約の日時は過ぎています。 |
| `res.submitted.heading` | Request received — not yet confirmed | リクエストを受け付けました（まだ確定していません） |
| `res.submitted.reference` | Your reference: {ref} | 受付番号：{ref} |
| `res.submitted.email_to` | We will email {masked_email}. | {masked_email}宛てにメールでご連絡します。 |
| `res.submitted.next` | The branch will review your request and reply by email. A table is not set aside for you until the branch accepts your request. | 店舗がリクエストを確認し、メールでご連絡します。店舗が承認するまで、お席は確保されていません。 |
| `res.submitted.expiry` | If the branch has not replied by {time}, your request expires and we will email you. | {time}までに店舗から返信がない場合、リクエストは期限切れとなり、メールでお知らせします。 |
| `res.submitted.dont_travel` | Please don't travel to the branch based on this request alone. | 承認のご連絡をお受け取りになるまでは、このリクエストのみでのご来店はお控えください。 |
| `res.submitted.promise` | The branch usually replies within {duration} during opening hours. | 営業時間内であれば、通常{duration}以内に店舗からご連絡します。 |
| `res.submitted.generic` | If you sent a request, check your email for the reference number. If you did not receive an email, contact the branch. | リクエストを送信された方は、メールに記載の受付番号をご確認ください。メールが届いていない場合は店舗へお問い合わせください。 |
| `res.submitted.status_link` | View request status | リクエストの状況を確認する |
| `res.carrier_tip` | Not receiving our emails? If you use a Japanese mobile carrier address, allow mail from @{domain}. | メールが届かない場合：携帯キャリアのメールアドレスをご利用の方は「@{domain}」からのメールを受信できるよう設定してください。 |
| `status.cancel.cta.pending` / `.confirmed` | Cancel this request / Cancel this reservation | このリクエストを取り消す / このご予約をキャンセルする |
| `status.cancel.confirm` | Cancel your request for {date}, {time}? The branch will be told. | {date} {time}のリクエストを取り消しますか？店舗にお知らせします。 |
| `status.cancel.keep` / `.yes` | Keep it / Yes, cancel | 取り消さない / 取り消す |
| `status.link_invalid` | This link is no longer valid. If you need help, contact the branch. | このリンクは無効です。お問い合わせは店舗までご連絡ください。 |
| `status.cancel.not_allowed` | This request can no longer be cancelled online. Please call {phone}. | このリクエストはオンラインでは取り消しできなくなりました。{phone}へお電話ください。 |
| `status.no_js` | Please contact the branch: {phone} | 店舗へお問い合わせください：{phone} |
| `decline.reason.fully_booked` | The branch is fully booked at that time. | ご希望の時間は満席です。 |
| `decline.reason.closed_or_private_event` | The branch is closed or reserved for a private event at that time. | ご希望の時間は休業または貸切のため、お受けできません。 |
| `decline.reason.party_size_not_supported` | The branch can't seat a group of this size at that time. | ご希望の人数ではお席をご用意できません。 |
| `decline.reason.incomplete_information` | We needed more information to accept this request. | リクエストをお受けするための情報が不足していました。 |
| `decline.reason.other` | The branch couldn't accept this request. | 店舗の都合によりお受けできませんでした。 |
| `decline.suggested` | The branch suggested these times. They are not held for you — send a new request if one suits you. | 店舗から以下の時間のご提案があります。お席は確保されていませんので、ご希望の場合は改めてリクエストをお送りください。 |

### 8.3 Staff (admin ja/en)

**Status labels:**

| Status | EN | JA |
|---|---|---|
| pending | Pending | 未対応 |
| confirmed | Accepted | 承認済み |
| rejected | Declined | お断り済み |
| cancelled | Cancelled | キャンセル済み |
| expired | Expired | 期限切れ |
| completed | Attended | 来店済み |
| no_show | No-show | 無断キャンセル |

**Action buttons:**

| EN | JA |
|---|---|
| Accept | 承認する |
| Decline | お断りする |
| Cancel request | キャンセルする |
| Claim | 担当する |
| Change details | 変更する |
| Attended | 来店済みにする |
| No-show | 無断キャンセルにする |

**Messages (`staff.*`, Doc 10 §7.3):**

| Key | EN | JA |
|---|---|---|
| `staff.err.version_conflict` | This request was updated by someone else. The latest information has been loaded. | このリクエストは他のスタッフによって更新されました。最新の情報を表示しています。 |
| `staff.err.invalid_transition` | This action is no longer possible because the request is now "{status}". | このリクエストは現在「{status}」のため、この操作はできません。 |
| `staff.err.expired` | This request has expired because it was not decided in time. | このリクエストは期限内に対応されなかったため、期限切れになりました。 |
| `staff.err.forbidden_branch` | You do not have access to this branch. | この店舗へのアクセス権限がありません。 |
| `staff.err.forbidden_field` | You are not allowed to change: {field}. | 次の項目を変更する権限がありません：{field} |
| `staff.err.reason_required` | Please select a reason. | 理由を選択してください。 |
| `staff.err.attendance_window` | Attendance can be recorded from the reservation time until 72 hours later. Please ask Head Office. | 来店記録は予約時刻から72時間後まで入力できます。本部へご依頼ください。 |
| `staff.warn.customer_not_notified` | The customer could not be emailed. Please phone them if a number was given. | お客様へのメール送信に失敗しました。電話番号がある場合はお電話でご連絡ください。 |
| `staff.warn.needs_action_closure` | This reservation falls on a closure or changed hours. Please contact the guest. | このご予約は休業日または変更後の営業時間外です。お客様へご連絡ください。 |
| `staff.warn.captcha_unverified` | Received while spam protection was unavailable — check before accepting. | スパム対策が利用できない間に受信しました。承認前に内容をご確認ください。 |

Note validation: "Enter a note of up to 1,000 characters." Message length: "The message can be up to 500 characters." Suggested times: "Enter up to two future times."

### 8.4 Banned terms (BR-RES-01). CI scans the `.po` files of pending-context keys (C09, C10, C11 pending, E01, and E04/E06 while pending).

| Language | Banned terms |
|---|---|
| en | booked, you're booked, reservation confirmed, booking confirmed, confirm table reservation, guarantee your seating, instant seating, your table is reserved, reservation complete, your reservation |
| ja | 予約完了, 予約確定, 予約が確定, 予約を確定, ご予約を承りました, このご予約, 席を予約 (as a status) |
| ar | تأكيد الحجز, تم تأكيد الحجز |
| ur, hi, bn | ⟦TBD — native reviewers, GATE-04⟧ |

The only permitted uses of "confirmed" / 確定 are the negated label `status.pending` and `res.submitted.heading`.

### 8.5 Formatting (Doc 07 §6.10)

Use `Intl`/ICU with `-u-ca-gregory-nu-latn`. Digits are always Western; `bn` needs an override.

| Lang | Long date | Short date | Time zone label |
|---|---|---|---|
| ja | 2026年12月3日（木） | 12/3（木） | none (DEC-062) |
| en | Thu 3 Dec 2026 | Thu 3 Dec | "19:00 JST" |
| ar/ur/hi/bn | CLDR skeleton `EEEEdMMMMy` | `EEEdMMM` | translated label ⟦native reviewer⟧ |

Phone numbers display in national format in ja and international format elsewhere, inside an LTR isolate. Admin tables use ISO dates.

---

## 9. Jobs, hooks, audit and logging

**Jobs** (Action Scheduler, group `momos`; registered by M0006):

| Job | Schedule | Behaviour |
|---|---|---|
| `momos_job_expire_pending` | every 5 min | heartbeat `expiry` |
| `momos_job_sla_check` | every 5 min | SLA reminders/escalations |
| `momos_job_daily_digest` | [TBD → 08:00 JST] | owned by Notification |
| `momos_job_integrity_check` | 03:45 JST | flags `pending` rows past start by > 1 h, and enabled branches without recipients or a last order |

Retention (Privacy module, 03:15 JST):
- Anonymise PII [TBD → 12 months] after `requested_date`: `guest_name`, `email`, `phone_e164`, `special_request`, `message_to_customer`, `token_hash`, `ip_hash` and `user_agent_hash` become NULL, and `pii_purged_at` is set. Event notes are nulled and a `pii_purged` event is written.
- Null `ip_hash`/`user_agent_hash` after [TBD → 30 days].

**Domain hooks** (fired after commit):
- `momos/reservation/created(ReservationDto)`
- `momos/reservation/status_changed(dto, from, to, Actor)`
- `momos/reservation/modified(dto, before, after, Actor)`

The module consumes `momos/notification/failed` (sets the flag) and `momos/branch/schedule_changed` (§3.13).

**Audit actions:**
- `reservation.status_changed` (staff only), `reservation.claimed`, `reservation.note_added`, `reservation.modified`, `reservation.detail_viewed`, `reservation.exported`, `reservation.export_with_notes`
- `access.denied`, `security.captcha_degraded`, `branch.settings_updated`

An audit failure inside a business transaction fails the whole transaction.

**Logging:** one line per error response and per staff mutation: `request_id`, route ID, method, status, ms, user ID, branch_code, code. Never logged: bodies, names, emails, phones, special requests, tokens, captcha tokens or raw IPs.

---

## 10. Acceptance criteria and tests to automate

**Key acceptance criteria.** All values are synthetic; the default settings apply unless stated.

| AC | Given / When / Then |
|---|---|
| AC-RES-01-01 | Valid submission with phone "+60 12-345 6789" → 201; `pending` row with `phone_e164 = +60123456789`; 1 `created` event; E01 and E07 queued |
| AC-RES-01-04/05 | Period 17:00–23:00, L.O. 22:30 → slots 17:00 … 22:30, no 23:00. Lead 120 at 18:10 → first slot 20:30. |
| AC-RES-01-06 | Crafted time 18:15 → 409 `slot_unavailable`, `fields.time = not_a_slot`, no row |
| AC-RES-01-07 | max 8: "More than 8" → call message and submit disabled. Crafted 9 → 422 `party_size_out_of_range`. |
| AC-RES-01-08/09/10 | Same key twice → 1 row, same ref, 1 E01. Same data within 5 min from a new load → 200 `duplicate:true`. 4th pending at A with limit 3 → 409; branch B accepted. |
| AC-RES-01-11/12/14 | 6th submission from one IP in an hour → 429 with `Retry-After`, localised. Honeypot → 403 `captcha_failed`, no row. DB down → 503, nothing stored, form keeps values. |
| AC-RES-01-17 | Bengali digits: party "৪" → 4; phone "০৯০-১২৩৪-৫৬৭৮" → `+819012345678` |
| AC-RES-02-01..04 | C10 shows heading, ref, summary with JST, computed expiry, phone and carrier tip; no .ics. 0 banned terms in any language. No PII or token in the URL. `no-referrer`. No third-party requests. Cleared storage → generic text. |
| AC-RES-03-01..07 | Status shows the pending label, JST and Cancel, with no phone or note. Cancel of a confirmed request 3 h before start → `cancelled` by customer, E04 + E09. After start → 409 `cancel_not_allowed`. Wrong token → 404. Expired token → 410. `?t=` → 400 and the token appears only in the header. Rejected with 2 times → reason, message, times and "Request another time". |
| AC-RES-04-01..10 | RS sees only branch A, and the badge counts only A. B detail → 403 `forbidden_branch` plus `access.denied`. Search "7kq2m9xr" and "ﾔﾏﾀﾞ"→"ヤマダ" (NFKC). SLA 120, 121 min → Overdue. 390 px usable. `<script>` shown literally. Takeover recorded. Note never in E02. `detail_viewed` audited. |
| AC-RES-05-01..09 | Accept v1 → confirmed v2, E02 with message, audit. Double accept → one transition and one email; the second user sees the conflict. Decline without reason → 400. `suspected_spam` → no E04. Accept on rejected → 409 `invalid_transition`. Accept 2 min after cutoff → expired (system), E05, 409. BM of A acting on B → 403. |
| AC-RES-06-01..06 | min window 30: lead 60 with cutoff 40 refused; cutoff 30 saves. party_max 8→6 → options end at 6, crafted 7 → 422. Disabled branch → 422 while staff can still act. Gmail recipient refused. TEST hidden. BM read-only. |
| AC-RES-07-01..04 | Cutoff 60, start 19:00 → expired between 18:00 and 18:04. Re-run → still 1 event and 1 E05. 30 min without a heartbeat → alert. Status page shows expired with no cancel. |
| AC-RES-08-01..03 | SLA 120 (wall clock) created 10:00 → reminder at 12:00–12:04, escalation at 14:00–14:04. Accepted at 11:30 → nothing sent. Business-hours example per §3.10. |
| AC-RES-09-01..03 | No-show at 20:30 for 19:00 → `no_show`, no email. 18:00 → 422. Start + 73 h: RS → 422, HO → OK. |
| AC-RES-10-01..04 | 4 people at 19:00 → 5 at 19:30: stays confirmed, `modified` event, E06. Lead-time breach: RS → 422; HO with override → OK with the reason recorded. After last order → 409 even for HO. Closure → refused. Moved 2 h later → not expired at the old cutoff. |
| AC-RES-11-01/02, AC-RES-12-01..03 | BM export → 403. `=HYPERLINK` is prefixed with `'` and audited. 6 confirmed/2 rejected/2 expired → acceptance 60 % and expiry 20 %. BM sees only A. Counts unchanged after anonymisation. |

**Tests to automate:**

| Area | TC IDs |
|---|---|
| P0 submission (PHPUnit integration + contract) | TC-RES-001, 003–011, 013–015, 017, 018, 020–022, 025, 030, 039, 040 |
| P0 staff and state machine | TC-RSA-001, 003–006, 011–014, 016, 017, 022, 023, 025; TC-RES-037 |
| P1 | TC-RES-012, 019, 023, 026–029, 032–036, 038, 041, 042; TC-RSA-002, 007–010, 019–021, 024 |
| P2 | TC-RES-016, 024; TC-RSA-015, 018 |

**Test infrastructure:**
- State-matrix unit tests: 7 states × 6 actions, with lazy expiry.
- Frozen-clock tests (`MOMOS_FROZEN_NOW`/`FrozenClock`) at the cutoff ± 1 min, lead time ± 1 min, the last window day + 1, 31 Dec → 1 Jan, after-midnight periods and SLA boundaries.
- Concurrency tests with parallel API-12 calls and parallel API-02 calls using the same key and email.
- A CI check of enum ↔ CHECK parity.
- A CI route audit (every route has a `permission_callback`).
- A CI banned-term scan.
- A contract test of code and status for every error in §5.1.
- A performance seed of 10,000 rows (inbox p95 ≤ 2 s).
- No-third-party-request checks on C10 and C11.

---

## 11. Out of scope (Phase 1) and open items

**Out of scope:**
- Table or capacity inventory and automatic "fully booked" (DEC-015).
- Deposits, payments and no-show fees (BR-RES-14).
- Customer accounts (DEC-041).
- LINE/SMS/WhatsApp (DEC-017).
- A cross-branch no-show list or blocking.
- A staff "create reservation" screen. Interim procedure: staff use the public form with the guest's verbal consent (Q-412).
- A `/reservation/groups/` page.
- Reopening terminal requests.
- Moving a reservation to another branch.
- Calendar (.ics) files.
- A reference lookup form on the status page.
- Five Star fallback (DEC-048; only if chosen).
- E01–E11 template content and transport (notification spec).
- Hours/closures editing (branch spec).

**Open TBDs.** Build the documented default as configuration for each.

| Item | Owner / decision | Default to build |
|---|---|---|
| Per-branch values (Q-401) | Owner/BMs | party 1–8, lead 120, window 60, interval 30, cutoff 60, max pending 3 |
| SLA value and clock; customer promise wording (DEC-013/Q-402) | Owner | 120 min, `wall_clock`, promise off. Choose SLA ≤ (lead − cutoff) ÷ 2 so escalation precedes expiry. |
| Customer cancel cutoff (Q-403) | Owner | 0 (until start) |
| Reservation policy text (DEC-054/Q-404) | Owner + counsel | show nothing until supplied |
| Rate limits (Q-411) | Tech lead | 5/h IP, 3/h email, 2/h degraded, 30/h status, 10/h cancel, 120/min staff |
| Status-page name element (Q-426) | Counsel | given name only |
| Given-name rule in staff email (Q-413) | Security + counsel | first token ≤ 20 characters, else first 20 characters |
| Special-request separate consent and retention (DEC-053/Q-410) | Counsel | switch off; 12-month retention |
| Webmail block list (Q-421); carrier domains | HO | lists in §2.4 |
| Export: build it, max rows, columns (Q-417/DEC-048) | Owner | build behind the capability; 12 months; 10,000 rows |
| Retention 12 months (DEC-034), IP hash 30 days | Counsel | as stated |
| JA strings and ar/ur/hi/bn translations, including banned terms (GATE-04) | Native reviewers | drafts |
| "After midnight" slot label | Doc 07 | show the calendar date and time; `period_label` grouping only |
| Digest time; HO escalation list (Q-416) | HO | 08:00 JST |
| `sla_state` `due` threshold | Product | 75 % [Proposed] |

---

## 12. Documentation conflicts and rulings

| # | Conflict | Ruling in this spec |
|---|---|---|
| C-1 | API-04 when the request is already cancelled: TRD API-04 says "repeating a successful cancel returns 200"; Doc 04 §16.0.4, Doc 11 §5.8 and TC-RSA-012 say a terminal status → 409 `cancel_not_allowed` | 409 `cancel_not_allowed`, because the canonical catalogue wins (DEC-063). The UI reloads and shows "Cancelled". |
| C-2 | A repeated identical staff action after success: Doc 04 FR-RES-025(c) says `invalid_transition`; TC-RSA-014 expects `version_conflict` | The SDD §5.6.3 algorithm decides (status changed → `invalid_transition`). TC-RSA-014 needs correcting. Both outcomes send no second email. |
| C-3 | Override by a non-HO user on API-16: TRD says 403 `forbidden_field` (not yet in the Doc 04 catalogue for API-16); TC-RSA-009 expects 403 `rest_forbidden` | `forbidden_field`. Add API-16 to the catalogue row and correct TC-RSA-009. |
| C-4 | Validation order: TRD API-02 starts with the rate limit; SDD §5.2 starts with transport and Origin checks | SDD §5.2 (declared authoritative) |
| C-5 | `customer_notified_flag`: Doc 05 §4.2 says 0 until E01 is sent; SDD DDL defaults to 1, set to 0 on failure | The SDD DDL (default 1) |
| C-6 | `response_sla_minutes` range: PRD 15–1,440; G6 0–1,440 with 0 = off | G6 |
| C-7 | `reservations_enabled` default: PRD proposal true; G6 default 0 with an enablement guard | 0, with the guard |
| C-8 | Status-page PII: PRD says full name + masked email; Doc 11 and TRD say given name only until Q-426 | API returns both; the page renders the given name only |
| C-9 | Guest name with `<` or `>`: PRD says HTML is stripped; Doc 11 §6.3.1 says rejected | Reject (`invalid_param`). Special request: strip tags. |
| C-10 | TEST flag name: PRD `is_test`, backlog `momo_is_test`, TRD G4 `momo_is_test_branch` | `momo_is_test_branch` |
| C-11 | API-10 filter names: PRD lists "has special request" and handled by me/unclaimed/anyone; TRD uses `flags=needs_action,not_notified,overdue,unclaimed`, `has_special_request` and `handled_by=me\|none` | TRD names |
| C-12 | Tap targets: PRD/UX say ≥ 44 px; TC-RSA-002 says ≥ 24 px | 44 px |
| C-13 | API-16 payload: Doc 05 N3 uses `date`, `time`, `guest_agreed`; TRD uses `requested_date`, `requested_time`, `reason: agreed_with_guest` | TRD |
| C-14 | Customer copy variants: PRD quotes cancel-dialog, rate-limit and pending-limit texts that differ from Doc 07 §6 | Doc 07 §6 keys (single register, R2-12) |
| C-15 | HMAC key: SDD and Doc 11 name the pepper `MOMOS_HASH_PEPPER`; the existing `Kernel\Support\Hash::hmac()` defaults to `MOMOS_HMAC_KEY` | Reservation code passes `'MOMOS_HASH_PEPPER'` explicitly. Align the Kernel default. |
| C-16 | Small report cells: PRD shows cells with fewer than 3 records; UX shows "Not enough data" | Show every count. Show "Not enough data for this period" only for a zero denominator. |
