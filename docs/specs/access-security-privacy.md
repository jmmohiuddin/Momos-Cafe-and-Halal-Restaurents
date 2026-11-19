# Access, audit, security and privacy — implementation spec

Status: build spec v1.0 for `momos-core` (PHP 8.3, namespaces `Momos\Access`, `Momos\Audit`, `Momos\Privacy`,
plus `Momos\Api` guards and `Momos\Presentation\Http\HeaderPolicy`) and theme `momos`.
Audience: developers who will not read the documentation package. Every rule is traceable to an ID in Doc 04
(PRD), 04A, 05, 06, 07, 09 (TRD), 10 (SDD), 11 (Security & Compliance), 12 (QA), 13 (Backlog) or 17 (Decisions).
Values in ⟦…⟧ are TBD; the documented proposal is the default to build with and must be read from settings,
never hard-coded where a setting is named. Business facts (operator name, privacy contact, hosting country,
staff names) are never hard-coded: use placeholders such as `{OPERATOR_NAME}`, `{PRIVACY_CONTACT_EMAIL}`.
Doc 11 is canonical for security values (SEC-001/004/005 values, CSP string, retention); Doc 04 §16.0.4 is
canonical for error codes (DEC-063); TRD §2.7.2 is the authoritative capability grant table; SDD §2.4.7 is
the authoritative audit-action catalogue.

## 0. How the documented mechanisms map to this build (IMPL-001…004)

| Documented mechanism | Build equivalent (this spec) |
|---|---|
| Wordfence Login Security TOTP 2FA, "require 2FA for all roles", recovery codes | `Access\TwoFactor\*` in momos-core: RFC 6238 TOTP, enforced for every logged-in user (all accounts are staff) |
| Wordfence brute-force lockout, "immediately lock out invalid usernames", leaked-password check | `Access\Login\LoginThrottle` + `Access\Login\PasswordPolicy` (§5.3, §5.4) |
| Wordfence file-integrity scan / malware scan | Weekly `wp core verify-checksums` + momos-core manifest check (§12); malware scanning = host service (IMPL-004) |
| Activity-log plugin (Simple History / WP Activity Log) for WP-level events [WP]/[SEC] | Same `momo_audit_log` table, "activity-class" actions (§6.4) purged after 12 months (PD-18) |
| UpdraftPlus encrypted off-site backups | Host backups + off-site storage are an **operational prerequisite** (IMPL-004); momos-core provides the restore hooks of §11 |
| FluentSMTP email logging off | Notification transport in momos-core (notification spec); this spec only forbids bodies in logs |
| ACF G3 options page `momo-settings`, tabs Privacy / Monitoring | `Kernel\Fields\OptionsPage` + field groups with the **same option names** (`momo_retention_*`, …); read via `Kernel\Settings::get()` |
| ACF "user profile section" `UserBranchFields` | Native `show_user_profile`/`edit_user_profile` fields rendered by `Access\Admin\UserBranchFields` |
| WPML "hidden languages", WPML strings for notices/consent | Kernel i18n layer: strings in `languages/strings/{lang}/privacy.php` via `momos_t()`; ja is source, others draft until native/counsel review (IMPL-003) |
| Elementor Role Manager, Elementor Pro form validation hook for contact Turnstile | Not applicable (no Elementor); contact form is a momos-core form — same controls apply (Turnstile, Origin, consent) |
| Rank Math author sitemaps/archives disabled | `Seo` module sitemap provider list excludes users; author archives redirected (§9.2) |

URL model: Japanese at root, `/en/ /ar/ /ur/ /hi/ /bn/` prefixes (DEC-003, IMPL-002). Admin UI languages ja / en_US only (DEC-037).

## 1. Requirements covered (traceability)

Priority: M = Must, S = Should, C = Could.

### 1.1 Features, FRs, business rules, NFRs

| ID | Statement (one line) | Pri |
|---|---|---|
| F-ADM-01 / FR-ADM-001 | Role-aware dashboard widgets (SCR-A02), counts scoped to assigned branches, each links to a filtered list | S |
| F-ADM-02 / FR-ADM-002 | User management: create, disable (never delete), exactly one role, branches (≥1 for ROLE-06/07), admin language, CAP-DCA; HO cannot touch ROLE-01 | M |
| FR-ADM-003 | Capability + branch scope on every screen, REST route, AJAX, admin-post and query; deny → 403 + audit `access.denied` | M |
| F-ADM-03 / FR-ADM-004 | Append-only audit log with masked before/after, truncated IP, viewer SCR-A16 (SA/HO only), 24-month purge | M |
| FR-ADM-006 | 2FA forced at first login; idle timeout ⟦8 h⟧; throttling; sessions destroyed on role/branch change and disable | M |
| FR-ADM-007 | Vendor/offshore accounts named, time-boxed (`momo_access_expires_at` at authentication), 2FA, logged | M |
| FR-ADM-008 | Role change, branch-assignment change or disable revokes all of that user's sessions immediately; audited | M |
| F-SEC-01 / FR-SEC-001 | TOTP 2FA mandatory for all staff roles before any admin/REST access; recovery codes; ROLE-01/02 reset (audited) | M |
| FR-SEC-002 | Login throttling, lockout, generic errors, captcha after failures | M |
| FR-SEC-003 | WAF in front of production; XML-RPC blocked | M |
| FR-SEC-004 | `DISALLOW_FILE_EDIT` + `DISALLOW_FILE_MODS` true in production; auto-updates off; changes only via pipeline (DEC-065) | M |
| FR-SEC-005 | Security headers incl. canonical CSP (Report-Only ⟦2 weeks⟧ → enforced), `no-referrer` on result/status pages | M |
| FR-SEC-006 | Nonces on all authenticated state changes; public writes use Turnstile + Origin + idempotency + rate limits | M |
| FR-SEC-007 | Server-side allow-list validation, context escaping, prepared SQL, WPCS security sniffs | M |
| FR-SEC-008 | Secrets in environment/wp-config outside the repo; rotated on staff/vendor change | M |
| FR-SEC-009 / FR-SEC-010 | Monthly updates via staging/pipeline, critical ≤ 72 h; weekly vulnerability scan (ops; §12) | M |
| F-SEC-02 / FR-SEC-011 | Backups per DEC-033 (daily full, DB every 4 h, 30 days, off-site, encrypted, RPO ≤ 4 h, RTO ≤ 8 h, restore tests) | M |
| F-SEC-03 / FR-SEC-012 | Monitoring: uptime, job heartbeat (30 min), notification failure > 5 %/h, fatals, TLS < 14 days | S |
| FR-SEC-013 | Incident-response runbook and tabletop (process; Doc 11 §7) | M |
| F-PRV-01 / FR-PRV-001 | Privacy Policy content (counsel; page content, not code) | M |
| FR-PRV-002 | Notice at collection + unticked required consent; `consent_version` + `consent_at` stored | M |
| FR-PRV-005 | No PII in URLs (except opaque token in fragment), logs, analytics, email subjects, audit (masked) | M |
| F-PRV-02 / FR-PRV-003 | Daily retention/anonymisation job (03:15 JST) | M |
| F-PRV-03 / FR-PRV-004 | DSR tool SCR-A19: search, export, correct, anonymise; audited `dsr.*` | S |
| FR-FND-002(b)(c) | Staging: synthetic data only, `noindex`, HTTP auth | M |
| FR-FND-004 | HTTPS only, TLS ≥ 1.2, HSTS ramp | M |
| BR-ADM-01 | Individual accounts; disabled, never deleted; no shared logins | M |
| BR-ADM-02 | 2FA mandatory before any admin access | M |
| BR-ADM-03 | Role/branch changes only by HO or SA; audited; HO cannot elevate to SA | M |
| BR-RES-02 | Only users assigned to the reservation's branch (ROLE-06/07) or ROLE-01/02 may view/act | M |
| BR-PRV-01 | Collect only FR-RES-001 / FR-CNT-001 fields | M |
| BR-PRV-02 | Retention: PII ⟦12 months⟧ after date; notifications 180 d; audit 24 months; backups 30 d | M |
| BR-PRV-03 | Reservation export only by Head Office; BM cannot export | M |
| BR-PRV-04 | No personal data to MT/AI services | M |
| NFR-SEC-01 | OWASP Top 10:2025 coverage; 2FA 100 %; securityheaders ≥ B (A after CSP enforce); SSL Labs A; 0 High/Critical | M |
| NFR-PRV-01 | Notice in 6 languages; consent version on 100 % of rows; retention 100 % in time-travel test | M |
| NFR-OBS-01 / NFR-AVL-01 / NFR-BCK-01 | Error log outside web root, heartbeat alert 30 min, uptime alerts; backup targets above | M |
| DEC-024, 032, 033, 034, 035, 041, 043, 059, 065 | Roles; security baseline; backups; retention; offshore vendor; no customer accounts; no marketing opt-in; IP truncation + HMAC; pipeline-only changes | — |

### 1.2 Doc 11 controls implemented in code (configuration/process controls listed for completeness)

| IDs | Topic | Where |
|---|---|---|
| SEC-001…008 | 2FA, named accounts, passwords, throttling, sessions, disable, role governance, vendor access | §5 |
| SEC-010…015 | Central authorisation, scoped repositories, every channel, denial behaviour, non-exposure, isolation suite | §4 |
| SEC-020…028, 031…033 | Validation, encoding, CSRF, REST, abuse, tokens/SQL, uploads, errors/caching/email | §7 |
| SEC-029, SEC-030 | Headers and CSP | §8 |
| SEC-040…043 | wp-config, attack surface, web-server rules, editing/installs | §9 |
| SEC-050…056, 060…065, 070, 090/091, 100…104, 110…112 | TLS, WAF, DB user, hosting, DNS/email auth, staging, encryption, plugins, secrets, backups, testing, incidents | §9.4, §11, §12 (mostly ops) |
| SEC-080…084 | Events logged, never-logged list, audit integrity, monitoring, log retention | §6, §12 |
| PRV-001…009 | Minimisation, notice, consent versioning, special request, purpose, no PII leakage, status pages, staff/customer emails | §10 (emails/status: notification and reservation specs) |
| PRV-010…018 | Recipient domains, notification log, retention settings + job, hashes, contact not stored, DSR, export | §10 |
| PRV-019…027, 030…033 | Policy pages, translations, external transmission, analytics, script governance, processors, cross-border, MT, training, printing, backups | §10.6 (content/process; code hooks only) |
| PRV-028, PRV-029 | Offshore vendor no routine PII access; non-production synthetic only | §5.6, §9.3 |
| CMP-001, 002, 003, 005, 036, 037 | Operator identity, external transmission, reservation policy, legal pages, legal monitoring, EEA/UK consent | Content/process; consent registry §10.2 |

### 1.3 Stories, screens, APIs, tests

| Kind | IDs |
|---|---|
| User stories | US-ADM-001 (M), 002 (M), 003 (M), 004 (M), 005 (S), 007 (M), 008 (M); US-SEC-001 (M), 002 (M), 003 (S); US-PRV-001 (M), 002 (M), 003 (S), 004 (M); US-RES-019 (M) |
| Screens | SCR-A01 Login + 2FA, SCR-A02 Dashboard, SCR-A10 Users & branch assignments, SCR-A15 Settings (Privacy, Monitoring, Security tabs), SCR-A16 Audit log, SCR-A19 DSR tool; customer SCR-C09/C12 notice + consent blocks |
| API | No new staff REST routes. API-17 `POST /momos/v1/csp-report` (C). Guards shared by API-01…17 (§7.4). Admin-post handlers in §14 |
| Backlog | TK-11-01…14, TK-12-01…19, TK-14-05, TK-14-11 |
| Tests | TC-ACL-001…024, TC-ADM-001/002/004/005/008/009/011, TC-SEC-001…030, TC-PRV-001…018, TC-BCK-001…005, TC-WP-004/011/012/013/014 (§16) |

## 2. Data model

### 2.1 Roles (`Access\Roles\RoleInstaller`, migration M0004; re-synced on every momos-core version change)

| Role ID | Name (en / ja) | WP role slug | Branch-scoped | Accounts |
|---|---|---|---|---|
| ROLE-00 | Public Visitor | — (no account, DEC-041) | — | — |
| ROLE-01 | Super Administrator / スーパー管理者 | `administrator` (core role + momos caps) | No | ≤ 2 ⟦Momo's IT owner + vendor technical lead — DEC-024⟧; vendor account dormant by default |
| ROLE-02 | Head Office Administrator / 本部管理者 | `momo_head_office` | No | ⟦TBD — Q-025/Q-026⟧ |
| ROLE-03 | Menu Manager / メニュー管理者 | `momo_menu_manager` | No | ⟦TBD⟧ |
| ROLE-04 | Content Editor / コンテンツ編集者 | `momo_content_editor` | No | ⟦TBD⟧ |
| ROLE-05 | Translator / 翻訳者 | `momo_translator` | No (language-scoped in i18n layer) | ⟦one per language⟧ |
| ROLE-06 | Branch Manager / 店長 | `momo_branch_manager` | **Yes** (`momo_user_branches`) | ⟦one per branch⟧ |
| ROLE-07 | Reservation Staff / 予約担当スタッフ | `momo_reservation_staff` | **Yes** | ⟦named per branch⟧ |
| CAP-DCA | Dietary Claims Approver (flag, not a role) | per-user cap `momo_edit_dietary_claims` = true | — | Default one HO user + Owner (Q-007); never vendor |

Site settings forced: `users_can_register = 0`, `default_role = ''` (no role), single site (no Multisite, TC-SEC-021).
`subscriber`, `contributor`, `author`, `editor` core roles are removed by `RoleInstaller` (no account may hold them).

### 2.2 User meta (all keys exact; written only by `Access\*`)

| Key | Type | Required | Validation / default | Writable by |
|---|---|---|---|---|
| `locale` (core) | `ja` \| `en_US` | yes | default `ja` (DEC-037) | `momo_manage_staff`; the user (own profile) |
| `momo_phone` | string E.164 ≤ 20 | no | libphonenumber, region JP | `momo_manage_staff`; the user |
| `momo_account_disabled` | `'1'` or absent | — | absent = active | `momo_manage_staff` (HO not on ROLE-01, except vendor window §5.6) |
| `momo_vendor` | `'1'` or absent | — | set only by ROLE-01 non-vendor | ROLE-01 |
| `momo_access_expires_at` | UTC `Y-m-d H:i:s` | vendor: yes when enabled | ≤ now + ⟦72 h⟧ (DEC-035) | `momo_manage_staff` |
| `momo_breakglass_until` | UTC datetime | no | ≤ now + ⟦8 h⟧ | ROLE-01 non-vendor |
| `momo_breakglass_ref` | text ≤ 100, no PII | with break-glass | Owner approval reference | ROLE-01 non-vendor |
| `momo_2fa_secret` | string `v1:` + base64(nonce‖ciphertext) | after enrolment | sodium secretbox, key §5.2 | system |
| `momo_2fa_enrolled_at` | UTC datetime | after enrolment | — | system |
| `momo_2fa_last_step` | int (TOTP time-step) | — | replay guard | system |
| `momo_2fa_recovery` | JSON list of `wp_hash_password()` hashes | after enrolment | 10 at enrolment; used entries removed | system |
| `momo_2fa_challenge` | JSON {hash, expires_utc, redirect_to, attempts, enrol} | transient state | 5 min TTL | system |
| `momo_last_login_at` | UTC datetime | — | [IMPL] for the quarterly access review | system |
| `wp_capabilities[momo_edit_dietary_claims]` | true | — | CAP-DCA rules §5.7 | §5.7 |
| `wp_capabilities[<vendor deny cap>]` | false | vendor | `momo_view_customer_pii`, `momo_view_reservations`, `momo_export_reservations`, `momo_manage_dsr` | `RoleInstaller`/`ProfileGuard` |

Session-token meta (core `WP_Session_Tokens`, via `attach_session_information`): `momo_last_activity` (UTC epoch,
updated at most every 300 s), `momo_2fa` (`1` once the second factor passed). Users cannot edit their own role,
branches, disabled flag, expiry, vendor flag or CAP-DCA (SEC-007, LC-001 SEC-B05).

### 2.3 Custom tables (SDD §3.3.1, created by M0001 — already present in `Kernel\Migration\M0001_CreateTables`)

```sql
CREATE TABLE {$prefix}momo_user_branches (
  user_id bigint(20) unsigned NOT NULL,
  branch_id bigint(20) unsigned NOT NULL,
  assigned_by bigint(20) unsigned DEFAULT NULL,
  assigned_at datetime NOT NULL,
  PRIMARY KEY  (user_id,branch_id),
  KEY idx_branch (branch_id)
) ENGINE=InnoDB {$charset_collate};

CREATE TABLE {$prefix}momo_audit_log (
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
) ENGINE=InnoDB {$charset_collate};
-- M0002:
ALTER TABLE {$prefix}momo_audit_log
  ADD CONSTRAINT chk_momo_audit_actor CHECK (actor_type IN ('staff','system','customer'));
```

| Table | Rules |
|---|---|
| `momo_user_branches` (ENT-19) | `branch_id` = canonical `momo_branch` post ID (any status; the hidden TEST branch only for QA accounts). Rows exist only for users holding ROLE-06/07; changing a user to a non-scoped role deletes the rows (audited `user.branch_unassigned`). Rows are **kept** when the user is disabled (inactive via account status; re-enabling restores scope only after HO review — SEC-006, TC-ACL-011). Users never write their own rows. Retained with the account. |
| `momo_audit_log` (ENT-20) | `occurred_at` UTC. `changes` = JSON validated by `Kernel\Support\Json`, masked at write (§6.2). `ip_truncated` IPv4 `/24`, IPv6 `/48` (DEC-059), e.g. `203.0.113.0/24`, `2001:db8:1::/48`. Repository exposes insert, filtered read and purge only. Optional DB triggers rejecting UPDATE and DELETE of rows younger than 730 days ⟦verify host supports triggers⟧. Retention 24 months (activity-class 12 months, §6.4). |

### 2.4 Settings (G3 options page `momo-settings`, SCR-A15; option names exact)

| Tab / option | Type | Default | Validation | Edit cap |
|---|---|---|---|---|
| Privacy · `momo_retention_pii_months` | int | ⟦12⟧ (DEC-034) | 1–60 [IMPL bound] | `momo_manage_brand` (SA/HO), audited `settings.updated` |
| Privacy · `momo_retention_ip_hash_days` | int | ⟦30⟧ | 1–365 | same |
| Privacy · `momo_retention_notification_days` | int | 180 | 30–730 | same |
| Privacy · `momo_retention_audit_months` | int | 24 | 12–84 | same |
| Privacy · `momo_retention_activity_months` [IMPL] | int | 12 (PD-18) | 3–24 | same |
| Privacy · `momo_consent_versions` | JSON list (§10.2) | one placeholder row (M0005) | §10.2 | same |
| Privacy · `special_request_separate_consent` | bool | **false** | — | same |
| Monitoring · `momo_heartbeat_enabled` | bool | true | — | `manage_options` (SA) |
| Monitoring · `momo_alert_recipients` [IMPL] | list of email | empty → falls back to `admin_email` | business domains (DEC-049) | `manage_options` |
| Security · `momo_csp_mode` [IMPL] | `report_only` \| `enforce` | `report_only` | switch logged in release log (SEC-030) | `manage_options` |
| Security · `momo_hsts_max_age` [IMPL] | `300` \| `86400` \| `31536000` | `300` | ramp SEC-050 | `manage_options` |
| Security · `momo_login_lock_invalid_username` [IMPL] | bool | true (SEC-004) | — | `manage_options` |

Secrets are never options (§9.3). Doc 06 shows retention values "read-only" on SCR-A15 while Doc 11 §5.6 makes
them editable by ROLE-01/02 — this spec follows Doc 11 (see §17).

## 3. Capability grant table (TRD §2.7.2 — authoritative; `config/capabilities.php`)

Legend: Y granted · Y(b) granted, object checks limited to assigned branches · U per user only · opt optional per-user grant · — not granted.

| Capability | SA | HO | MM | CE | TR | BM | RS |
|---|---|---|---|---|---|---|---|
| `read` | Y | Y | Y | Y | Y | Y | Y |
| `upload_files` | Y | Y | Y | Y | — | Y | — |
| `edit_posts`, `edit_others_posts`, `edit_published_posts`, `publish_posts`, `delete_posts`, `delete_others_posts`, `delete_published_posts`, `manage_categories` | Y | Y | — | Y | — | — | — |
| `edit_pages`, `edit_others_pages`, `edit_published_pages`, `publish_pages`, `delete_pages`, `delete_others_pages`, `delete_published_pages` | Y | Y | — | Y | — | — | — |
| `momo_publish_legal_pages` | Y | Y | — | — | — | — | — |
| `edit_theme_options` (menus only; `customize` → `manage_options`) | Y | Y | — | — | — | — | — |
| `list_users`, `create_users`, `edit_users`, `promote_users` | Y | Y | — | — | — | — | — |
| `delete_users`, `remove_users` (blocked in UI, §5.5) | Y | — | — | — | — | — | — |
| `manage_options`, `activate_plugins`, `install_plugins`, `update_plugins`, `delete_plugins`, `switch_themes`, `edit_themes`, `install_themes`, `update_core`, `export`, `import`, `unfiltered_html`*, `unfiltered_upload`, `edit_dashboard` | Y | — | — | — | — | — | — |
| `create_/publish_/delete_/delete_published_/delete_others_/read_private_/edit_private_/delete_private_momo_branches` | Y | Y | — | — | — | — | — |
| `edit_momo_branches`, `edit_others_momo_branches`, `edit_published_momo_branches` | Y | Y | — | Y | — | Y(b) | — |
| `momo_edit_branch_identity`, `momo_edit_branch_settings` | Y | Y | — | — | — | — | — |
| `momo_edit_branch_hours` | Y | Y | — | — | — | Y(b) | — |
| notice caps (`create_`, `edit_`, `edit_others_`, `edit_published_`, `publish_`, `delete_`, `delete_others_`, `delete_published_momo_notices`) | Y | Y | — | Y | — | Y(b) | — |
| `create_momo_offers`, `edit_momo_offers`, `edit_others_momo_offers` | Y | Y | — | Y | — | Y(b) | — |
| `edit_published_`, `publish_`, `delete_`, `delete_others_`, `delete_published_`, `read_private_momo_offers` | Y | Y | — | — | — | — | — |
| `create_`, `edit_`, `edit_others_`, `edit_published_`, `read_private_momo_dishes` | Y | Y | Y | — | — | — | — |
| `publish_momo_dishes` | Y | Y | opt | — | — | — | — |
| `delete_`, `delete_others_`, `delete_published_momo_dishes` | Y | Y | — | — | — | — | — |
| `momo_manage_menu_categories`, `momo_edit_branch_menu`, `momo_edit_branch_prices` | Y | Y | Y | — | — | — | — |
| `momo_edit_branch_availability` | Y | Y | Y | — | — | Y(b) | Y(b) ⟦Q-023 proposal yes⟧ |
| `momo_edit_dietary_claims` (CAP-DCA) | U | U | — | — | — | — | — |
| `create_`, `edit_`, `edit_others_`, `edit_published_`, `publish_momo_certificates` (effective only with CAP-DCA, M4) | Y | Y | — | — | — | — | — |
| `read_private_momo_certificates` | Y | Y | Y | — | — | Y(b) | — |
| `momo_manage_certificates` | Y | Y | — | — | — | — | — |
| `momo_manage_brand` | Y | Y | — | — | — | — | — |
| `momo_view_reservations`, `momo_process_reservations`, `momo_view_customer_pii` | Y | Y | — | — | — | Y(b) | Y(b) |
| `momo_export_reservations` | Y | Y | — | — | — | — | — |
| `momo_view_reservation_reports` | Y | Y | — | — | — | Y(b) | — |
| `momo_manage_staff`, `momo_view_audit_log`, `momo_manage_dsr`, `momo_manage_languages` | Y | Y | — | — | — | — | — |
| `momo_translate` | Y | Y | Y | Y | Y | — | — |
| `momo_access_all_branches` (scope only, grants no action) | Y | Y | Y | Y | — | — | — |
| [IMPL] `momo_approve_translations`, `momo_preview_languages` | Y | Y | — | — | Y | — | — |
| [IMPL] `momo_view_notification_log` | Y | Y | — | — | — | — | — |

\* `DISALLOW_UNFILTERED_HTML` removes `unfiltered_html` from every role in production (SEC-040).
Vendor accounts (`momo_vendor = 1`) always carry per-user `false` for six capabilities (`vendor_denies` in
`config/capabilities.php`, SEC-008, CHG-010/QA-001): the four PII caps `momo_view_customer_pii`, `momo_view_reservations`,
`momo_export_reservations`, `momo_manage_dsr`, plus `momo_process_reservations` and `momo_view_notification_log` (so a
vendor cannot modify, decide, annotate or resend reservations or read the notification log). Break-glass (§5.6) lifts
only `momo_view_customer_pii` and `momo_view_reservations` (`RoleInstaller::BREAKGLASS_LIFTS`); the other four stay denied.
A vendor also cannot hand these capabilities to someone else (QA-064): `editable_roles` drops every role whose capability
set holds a vendor-withheld cap (the six above + `momo_manage_staff`, `MetaCapMapper::vendor_withheld_caps()`), so a vendor
can neither create nor promote an account into such a role, and `edit_user`/`promote_user` on a non-vendor account that
holds one of them maps to `do_not_allow`. CI dumps the effective
capability set of a seeded user per role and compares it with this table (SEC-007, SEC-100, TC-ACL-001).
Doc 05 §1.12 differs in three cells (BM `momo_view_audit_log`, MM/CE `momo_translate`, `momo_manage_certificates` as "DCA");
this TRD table wins (CON-019; §17).

## 4. Authorisation and branch isolation

### 4.1 Scope resolution (`Access\BranchScope`, `Access\AccessContext`)
- `assigned(user, branch)` ≡ user **not disabled** ∧ **not past** `momo_access_expires_at` ∧ (`momo_access_all_branches` ∨ row (`user_id`, canonical `branch_id`) in `momo_user_branches`). Cached per request only; cache cleared on `momos/user/assignment_changed`.
- `momos_user_branch_ids()` (Kernel helper) returns `null` (= all), a list, or `[]` (disabled/expired/no rows); Access implements the `momos/access/user_branch_ids` short-circuit filter.
- `AccessContext` (immutable: `userId`, `roles`, `allBranches`, `branchIds`, `can()`); repositories listing or counting branch-owned data **require** it (or `SystemContext` for jobs/CLI) and throw `UnscopedQueryException` otherwise. They append `branch_id IN (…)` built from int-cast IDs with placeholders. Counts, badges and totals use the same scoped query (no foreign counts).
- Fail closed: any exception while resolving scope → `do_not_allow` + log ERROR `access.map_failed`.

### 4.2 Meta-capability rules (`Access\MetaCapMapper` on `map_meta_cap`, priority 10; `user_has_cap` is not used)

| # | Meta cap (args) | Resolution |
|---|---|---|
| M1 | `edit_post`/`delete_post`/`publish_post` on `momo_branch` | Core mapping; then `assigned(user, branch)` else `do_not_allow` |
| M2 | `momo_edit_branch` (branch_id, aspect ∈ hours, settings, menu, availability, prices) | Aspect primitive `momo_edit_branch_{aspect}` + `assigned` |
| M3 | `edit_post`/`publish_post`/`delete_post` on `momo_notice`, `momo_offer` | Without `momo_access_all_branches`: scope must be `branches` and every scope branch assigned; for offers, editing a published/scheduled offer without `publish_momo_offers` → `do_not_allow` |
| M4 | any edit/publish on `momo_certificate`; G2/G7/G10 claim fields; allergen terms | Core/primitive + `momo_edit_dietary_claims` |
| M5 | `publish_post`, or edit/delete of a published page with `momo_page_type = legal` | + `momo_publish_legal_pages` |
| M6 | theme template objects (no Elementor library in this build) | `manage_options` |
| M7 | `customize` | `manage_options` |
| M8 | `momo_view_reservation` / `momo_process_reservation` (reservation_id) | `momo_view_reservations` / `momo_process_reservations` + `assigned(user, reservation.branch_id)`; unknown ID → `do_not_allow` |
| M9 | `edit_user`/`delete_user`/`promote_user` (target) | Target `administrator` and actor lacks `manage_options` → `do_not_allow` (exception: vendor access window action, §5.6). Actor = target for role/branch/disabled/expiry/vendor/CAP-DCA fields → blocked by `ProfileGuard`. `editable_roles` removes `administrator` for non-SA. `delete_user`/`remove_user` → `do_not_allow` for everyone in web requests (BR-ADM-01) |
| M10 | `momo_edit_branch_menu_item` (branch_id, dish_id, fields) | offered → `momo_edit_branch_menu`; price → `momo_edit_branch_prices`; availability → `momo_edit_branch_availability`; all + `assigned` |
| M11 | `momo_download_evidence` (certificate_id) | `momo_manage_certificates` + `momo_edit_dietary_claims` |
| M12 | `read_post` on `momo_certificate` | `read_private_momo_certificates`; without all-branches: a linked branch (or a branch offering a linked dish) must be assigned |

Every request-originated denial in M1–M12 writes audit `access.denied` (never the request body).

### 4.3 Enforcement points (SDD §6.3 E1–E14 + Doc 11 §6.2) — hiding menus is cosmetic only

| # | Channel | Check | Denial |
|---|---|---|---|
| E1 | REST API-10…16 | `permission_callback` + M8/M10 + scoped repository; `branch_code` filter must be ⊆ allowed | 403 `forbidden_branch` / `rest_forbidden` / `forbidden_field`, no record fields |
| E2 | Admin pages SCR-A04, A07, A08, A09, A16, A17, A19 | `Guard\AdminScreenGuard` on `current_screen`/`load-*`: capability + validation of every `branch`/`id` param | `wp_die()` 403, generic localised text |
| E3 | Core post editor for `momo_branch`, `momo_notice`, `momo_offer`, `momo_certificate` | M1/M3/M4/M12; `pre_get_posts` narrows list tables; `views_edit-*` counts filtered | 403 |
| E4 | Field saves (field layer) | Per-field `edit_cap` + module guards; forbidden fields left unchanged | Unchanged + `access.denied` |
| E5 | `admin-post.php` handlers (hours, closures, export, evidence, DSR, users) | Nonce + `current_user_can(meta cap, id)` | 403 |
| E6 | `admin-ajax.php` / Heartbeat | Same authorisation function as REST; badge counts via scoped repository | 403 / own counts only |
| E7 | Core REST `wp/v2/*` | GET-only for `momo_*` CPTs (`CoreRestGuard`), no custom-table exposure; `/wp/v2/users*` 401 unauthenticated; `momo_certificate` `show_in_rest => false` | 405 `method_not_allowed` / 401 |
| E8 | Export (FR-RES-021) | `momo_export_reservations`; filters ⊆ allowed | 403 |
| E9 | Notifications | Recipients resolved from the reservation's branch only | Branch A never receives branch B mail |
| E10 | Front end / theme | No template, shortcode, query or widget reads reservation tables | — |
| E11 | WP-CLI | PII-reading commands need `--user` with `momo_view_customer_pii` or are SA maintenance commands; output masked by default | — |
| E12 | Self-elevation | `ProfileGuard` (role, branches, disabled, expiry, vendor, CAP-DCA); vendor denies re-applied on every `profile_update`/`set_user_role` | Field ignored + `access.denied` |
| E13 | Dashboard SCR-A02 | Every widget reads through scoped repositories | No foreign data |
| E14 | Claim register SCR-A12 | M12 + `pre_get_posts` | 403 |
| — | Bulk actions | Per-record meta cap inside the loop | Record skipped + reported + audited |
| — | DSR SCR-A19 | `momo_manage_dsr` | 403 |
| — | Audit viewer SCR-A16 | `momo_view_audit_log` (SA/HO); BM/RS see reservation history only in the SCR-A09 event timeline | 403 |

Denial audit row: `action = access.denied`, `object_type`/`object_id`/`branch_id` of the requested object,
`changes = {"cap": "...", "channel": "rest|admin|ajax|admin-post|field", "route": "API-11|screen id"}`.
Alert when one user produces > ⟦10⟧ denials in an hour (SEC-013). Unauthenticated calls to staff routes → 401, not audited.

## 5. Authentication, sessions and account lifecycle

### 5.1 Login flow (SCR-A01, WF-ADM-01)
1. `wp-login.php` username/email + password. "Remember me" hidden (CSS) and ignored server-side (§5.5).
2. Disabled (`momo_account_disabled = 1`) or expired (`momo_access_expires_at < now`) → `authenticate` filter priority 99 returns the **same generic error** as a wrong password; audit `auth.disabled_login`.
3. Password OK → no auth cookie survives: on `wp_login` the just-created cookie/session is cleared and a challenge is stored (`momo_2fa_challenge`: SHA-256 of a 32-byte random token, `expires_utc = now + 5 min`, `redirect_to` preserved, `attempts = 0`); browser goes to `wp-login.php?action=momos_2fa` with the token in a POST hidden field (never in the URL).
4. Not enrolled → enrolment wizard (§5.2) in the same pre-auth state; enrolled → code form. Grace period **0** (SEC-001).
5. Success → `wp_set_auth_cookie( $id, false )`, session meta `momo_2fa = 1`, `momo_last_login_at` stamped, activity `auth.login_succeeded`, `login_redirect`: ROLE-06/07 → SCR-A08 filtered to pending for their branches; others → SCR-A02; an explicit `redirect_to` (e.g. E07 deep link to SCR-A09) wins.
6. Defence in depth (`Access\TwoFactor\Gate`): on `admin_init`, `rest_authentication_errors` (logged-in cookie requests) and `admin-ajax`, a session without `momo_2fa = 1` is destroyed → admin: redirect to login; REST: 401 `rest_forbidden`. XML-RPC is off; Application Passwords are off.

### 5.2 TOTP 2FA (SEC-001, FR-SEC-001, BR-ADM-02)

| Item | Rule |
|---|---|
| Algorithm | RFC 6238 TOTP, HMAC-SHA1, 6 digits, 30 s period; accept time-steps T−1, T, T+1; reject a step ≤ `momo_2fa_last_step` (replay) |
| Secret | 20 bytes `random_bytes()`, shown as RFC 4648 base32 (groups of 4) and as `otpauth://totp/{ISSUER}:{user_login}?secret=…&issuer={ISSUER}&algorithm=SHA1&digits=6&period=30`; `{ISSUER}` = brand trading name setting (placeholder until supplied) |
| QR code | Rendered **locally** (server-side SVG or self-hosted JS); no third-party QR service (PRV-024, CSP). Library choice needs CTO approval (no new deps rule) ⟦TBD⟧; manual key entry always available |
| Storage | `momo_2fa_secret` = `v1:` + base64(nonce‖`sodium_crypto_secretbox`); key = `hash_hkdf('sha256', MOMOS_2FA_KEY, 32, 'momos-2fa')` [IMPL constant]; if absent, derive from `AUTH_KEY . SECURE_AUTH_KEY` and show an SA notice |
| Enrolment | Show key + QR → user enters a current code → show **10 recovery codes once** (`XXXX-XXXX`, Crockford base32, 40 bits each) with required tick "I have saved these codes" → stamp `momo_2fa_enrolled_at`; activity `user.2fa_enrolled` |
| Code entry | `autocomplete="one-time-code"`, `inputmode="numeric"`, paste allowed (WCAG 3.3.8); "Use a recovery code" link; recovery code is single-use (hash removed), activity `user.2fa_recovery_used`; remaining count shown in the profile |
| Attempts | ≤ 5 wrong codes per challenge → challenge deleted, back to step 1; every wrong code counts as a login failure (§5.3) |
| Lost device | ROLE-01/02 (HO not for ROLE-01 targets) presses "Reset two-factor" on SCR-A10 after out-of-band identity check by a known phone number or in person; deletes secret/recovery/enrolled_at, destroys all sessions, audit `user.2fa_reset`; user re-enrols at next login |
| Shared devices | Each person enrols on their own authenticator ⟦Owner — LC-001-Q010⟧ |
| Out of scope | Passkeys / WebAuthn (FR-SEC-001 mentions "or passkey"; Doc 11 SEC-001 is TOTP — Phase 1 = TOTP) |

### 5.3 Throttling and lockout (`Access\Login\LoginThrottle`; SEC-004 canonical values)

| Bucket (store per SDD §5.14, keys `HMAC('rl', subject)`) | Rule | Effect |
|---|---|---|
| `login_ip` | ⟦10⟧ failures (password or 2FA) per IP within 15 min | IP locked ⟦60 min⟧ |
| `login_user` | ⟦10⟧ failures per existing account within 15 min (AC-SEC-01-02) | Account locked ⟦60 min⟧ from any IP; alert if ROLE-01/02 |
| invalid username | Attempt with a non-existent username/email | IP locked immediately when `momo_login_lock_invalid_username` (default on, SEC-004) |
| captcha step (`Access\LoginCaptcha`, S) | From the 3rd failure per IP within 15 min | Turnstile required on the login form (satisfies FR-SEC-002 "progressive delay/captcha") |
| `lostpw_ip` [IMPL proposal] | ⟦5⟧ lost-password requests per IP per hour | Generic confirmation shown, no email sent |
| Edge WAF | `POST /wp-login.php` ⟦30/min/IP⟧ (SEC-051) | 429 at edge (infra) |

- One generic error for unknown user, wrong password, disabled, expired (`login_errors` filter): `admin.login.err.generic`. Lockout message states when to retry (`admin.login.err.locked`, §15.1).
- Activity rows: `auth.login_failed` (user_id if the account exists, else null; **never** the typed username), `auth.lockout` (bucket, until). Raw IPs are never stored; audit rows carry `ip_truncated`.
- Alerts (§12): > 20 failed logins/hour site-wide; any lockout of a ROLE-01/02 account.
- No admin IP allow-list; no HTTP auth on `/wp-admin/` in production.

### 5.4 Password policy (SEC-003; `Access\Login\PasswordPolicy`)
- Length 12–128 characters; no composition rules; no forced rotation; core hashing.
- Breached/common passwords rejected: bundled offline list of the most common passwords (no network) as baseline; optional k-anonymity range check against a breach corpus only if the host allows the outbound host and counsel/security lead approve ⟦TBD — Security lead⟧. Enforced on `user_profile_update_errors`, `validate_password_reset`, user creation.
- `password_reset_expiration` = 3,600 s; reset keys single-use (core). After a reset: all sessions destroyed; activity `auth.password_reset`.
- Messages: `admin.pw.err.length`, `admin.pw.err.breached` (§15.1).

### 5.5 Session policy (SEC-005, FR-ADM-006/008; `Access\Session\SessionPolicy`)

| Rule | Value / mechanism |
|---|---|
| Absolute lifetime | ⟦14 h⟧ via `auth_cookie_expiration` for every user, regardless of `rememberme` |
| Idle timeout | ⟦8 h⟧: `momo_last_activity` refreshed ≤ every 5 min on admin/REST/AJAX requests; exceeded → `wp_destroy_current_session()`, clear cookie, admin redirect `wp-login.php?reauth=1`, REST 401 |
| Destroy all sessions (`WP_Session_Tokens::destroy_all`) | Role change (`set_user_role`, `add_user_role`, `remove_user_role`), branch assignment change (`momos/user/assignment_changed`), disable, vendor window expiry, password change/reset, 2FA reset |
| Cookies | `Secure`, `HttpOnly` (core) + `SameSite=Lax`: `send_auth_cookies` returns false and momos-core re-emits the same cookies with `setcookie()` options incl. `samesite` (TC-SEC-019); `FORCE_SSL_ADMIN` |
| Concurrency | Multiple sessions allowed (phone + PC); listed in profile ("Log out everywhere else", core) |
| Effect | The next request of a revoked session is refused and shows the login page (AC-ADM-02-03, TC-ADM-009) |

### 5.6 Account lifecycle and vendor access (SEC-002/006/008, DEC-035, FR-ADM-007)

| Event | Behaviour | Audit |
|---|---|---|
| Create (SCR-A10) | Username not in deny-list (`admin`, `administrator`, `momo`, `^branch\d+$`, `test`, `staff`, `user`) and unique; display name ≠ login; business email; exactly one role; ROLE-06/07 need ≥ 1 branch; set-password email (ja/en per locale, link ≤ 1 h). First login forces 2FA | `user.created`, `user.branch_assigned` per branch |
| Role change | `promote_users` + M9; sessions destroyed; if new role is not scoped, branch rows deleted | `user.role_changed` {from,to} |
| Branch change | Insert/delete rows (`assigned_by`, `assigned_at` UTC); fire `momos/user/assignment_changed`; sessions destroyed | `user.branch_assigned` / `user.branch_unassigned` (branch_id set) |
| Disable | `momo_account_disabled = 1`; sessions destroyed; rows kept (inactive); login refused generically; open reservations show "(disabled user)" as handler; warn if the user's email is in any branch `notification_recipients` or HO list | `user.disabled` |
| Enable | Clear flag; HO reviews kept branch rows before saving | `user.enabled` |
| Delete | Not offered for anyone (row action removed, M9 `do_not_allow`); WP-CLI only outside production | — |
| Vendor account | `momo_vendor = 1` set by ROLE-01 (Momo's); created/kept **disabled**; per-user denies on the six `vendor_denies` caps (§3, CHG-010) applied by `RoleInstaller::sync()` and `ProfileGuard` | `user.updated` [IMPL] |
| Vendor access window | Action "Enable vendor access" (cap `momo_manage_staff`, allowed on vendor-flagged users even when ROLE-01 — narrow exception to M9): sets `momo_access_expires_at` ≤ now + ⟦72 h⟧ and enables. Enforced in `authenticate`; `momos_job_access_expiry` (hourly + single action at expiry) re-disables and destroys sessions. Denies stay in force | `user.access_window_set` {expires_at} |
| Break-glass | ROLE-01 non-vendor only, Owner approval reference required, duration ≤ ⟦8 h⟧ (9 h refused, TK-11-14); removes the denies for `momo_view_customer_pii` and `momo_view_reservations` only — **export and DSR stay denied** (TC-ACL-022); single scheduled action + hourly job restore them | `vendor.breakglass_granted` {until, ref} / `vendor.breakglass_revoked` |
| Leaver | Disabled the same working day (HO procedure) | `user.disabled` |
| Quarterly access review | ROLE-02 + Owner; SCR-A10 "Access review" CSV (C): login, display name, role, branch codes, status, vendor, 2FA enrolled, last login — no customer PII | `user.list_exported` [IMPL] |

`momos_job_access_expiry`: hourly, idempotent, named lock; selects users with `momo_access_expires_at < now` and not
disabled → disable + destroy sessions + audit; users with `momo_breakglass_until < now` → re-apply denies + audit revoke.

### 5.7 CAP-DCA grant rules (FR-ADM-002(b), TRD §2.7.1)
Grantable by ROLE-01 or ROLE-02 (never to oneself) to ROLE-02 users (default), or to a ROLE-01 non-vendor user
on explicit Owner request (reference text required); never to vendor accounts or other roles. `RoleInstaller::sync()`
never removes per-user CAP-DCA. Audit `user.cap_dca_granted` / `user.cap_dca_revoked`.

## 6. Audit (`Momos\Audit`)

### 6.1 Writing (`AuditLogger::record(AuditEntry)`, entry point `momos_audit()` → action `momos/audit/log`)
1. `action` must be in the catalogue (§6.3/§6.4); unknown → `InvalidArgumentException` (dev/staging) / log ERROR and drop (production).
2. `occurred_at` = `Clock::mysql_utc()`; `actor_type` staff (logged-in user) / system (jobs, CLI) / customer (token holder; rarely used — customer actions are reservation events); `actor_id`; `actor_role` = first role slug at write time.
3. `changes` masked (§6.2), JSON ≤ 64 KB; `ip_truncated` from `Kernel\Http\RequestContext` client IP (trusted-proxy rules SDD §5.14) via `Hash::truncate_ip()`; `request_id` from `X-Request-Id` context.
4. `row_hmac` (S) = HMAC-SHA256(pepper, purpose `audit`, canonical JSON of all other columns); `wp momos audit verify` recomputes.
5. Inside a business transaction a failed audit insert fails the transaction (no unaudited decision); outside (e.g. `reservation.detail_viewed`) → log ERROR, request continues.

### 6.2 Masking and exclusions (SEC-081, FR-ADM-004(c))
- `changes` format: `{"<field>": {"from": <v>, "to": <v>}}` for changed fields only.
- PII keys are replaced by `"[masked]"` in both `from` and `to` (the row records only that the field changed): `guest_name, name, email, phone, phone_e164, special_request, note, message_to_customer, recipient_email, user_email, momo_phone`.
- Never stored in any log/audit/error log/analytics/email subject: guest name, email, phone, special-request text, internal note text, status tokens or token hashes, passwords, 2FA secrets/codes/recovery codes, session cookies, API keys, Turnstile tokens, full IPs, request bodies of API-02/04, typed login usernames.
- Enforced by the masking deny-list here and `Kernel\Log\Redactor` (key removal + email/phone/43-char base64url pattern masking); QA seeds unique markers and scans all tables and logs (TC-PRV-008).

### 6.3 Authoritative action catalogue (SDD §2.4.7; any other name is rejected)
`access.denied`; `auth.disabled_login`; `user.created`, `user.role_changed`, `user.branch_assigned`, `user.branch_unassigned`,
`user.disabled`, `user.enabled`, `user.access_window_set`, `user.cap_dca_granted`, `user.cap_dca_revoked`, `user.2fa_reset`;
`vendor.breakglass_granted`, `vendor.breakglass_revoked`; `language.enabled`, `language.disabled`; `branch.identity_updated`,
`branch.settings_updated`, `branch.hours_updated`, `branch.closure_created`, `branch.closure_updated`, `branch.closure_deleted`,
`branch.status_changed`, `branch.content_updated`; `notice.published`, `notice.updated`, `notice.trashed`; `offer.submitted`,
`offer.published`, `offer.updated`, `offer.withdrawn`, `offer.trashed`; `dish.published`, `dish.updated`, `dish.retired`,
`dish.reference_price_changed`; `menu_item.offered_changed`, `menu_item.price_changed`, `menu_item.availability_changed`,
`menu_item.auto_reset`; `dietary.statement_updated`, `dietary.branch_claims_updated`, `dietary.dish_claims_updated`,
`dietary.review_required_set`; `certificate.created`, `certificate.updated`, `certificate.status_changed`, `certificate.expired`,
`certificate.evidence_downloaded`; `reservation.status_changed`, `reservation.claimed`, `reservation.note_added`,
`reservation.modified`, `reservation.detail_viewed` (de-duplicated per user per reservation per 30 min), `reservation.exported`,
`reservation.export_with_notes`; `dsr.searched`, `dsr.exported`, `dsr.corrected`, `dsr.anonymized`; `security.captcha_degraded`;
`settings.updated`; `brand.updated`; `retention.run`, `privacy.retention_failed`; `migration.applied`.
Doc 05 variants (`user.role_assigned`, `user.capability_granted`, `dsr.anonymised`, `momo_disabled`) map to the names above.

### 6.4 [IMPL] activity-class extension (replaces the activity-log/security plugin; CTO to add to the catalogue)
Retention `momo_retention_activity_months` (12, PD-18): `auth.login_succeeded`, `auth.login_failed`, `auth.lockout`,
`auth.password_reset`, `user.2fa_enrolled`, `user.2fa_recovery_used`, `user.updated` (email/display name/locale/vendor flag; values masked),
`wp.plugin_activated`, `wp.plugin_deactivated`, `wp.theme_switched`, `wp.core_updated`, `wp.option_updated` (allow-list of core options:
`siteurl`, `home`, `users_can_register`, `default_role`, `admin_email`, `blog_public`), `security.file_integrity_failed`,
`security.rate_limited` (aggregated per hour and bucket), `audit.exported`, `user.list_exported`.
These rows use the same table, masking and IP rules; `object_type` `auth`/`user`/`wp`/`security`/`audit`.

### 6.5 Retention and access
The only DELETE paths are the retention job (§10.4 steps 5–6). No UI/REST edits or deletes (AC-ADM-03-03 → 404/403).
Readers: SCR-A16 (SA/HO). BM/RS see a reservation's history only as the SCR-A09 event timeline (ENT-16, CON-019).
Audit export (C): ROLE-01 only (`manage_options`), CSV streamed, formula-escaped, audited `audit.exported`.

## 7. Application security (SEC-020…028, 031…033)

### 7.1 Input validation (server authoritative)
- Every REST route declares `args` (`type`, `required`, `enum`/`pattern`, `minLength`/`maxLength`, `minimum`/`maximum`, `format`), `sanitize_callback`, `validate_callback`; public bodies `additionalProperties: false`; domain validation in services. Field rules of API-02/03/04 (name, email, phone, special request, consent, token header) are in the reservation spec and Doc 11 §6.3.1 and are not repeated.
- Admin forms/admin-post: validate every field type and range; times `HH:MM` 00:00–23:59; dates via `DateTimeImmutable::createFromFormat` + error check; text `sanitize_text_field` / `sanitize_textarea_field` with control characters stripped; URLs `esc_url_raw` + scheme allow-list `https`, `tel`, `mailto`; HTML only in post content via kses (nobody holds `unfiltered_html` in production).
- Staff free text: `message_to_customer` ≤ 500, internal note ≤ 1,000, DSR/export reason ≤ 200 (no PII), break-glass reference ≤ 100.
- SQL: `$wpdb->prepare()` for every value; table names from `Kernel\Db\Tables`; ORDER BY/direction from allow-lists; int lists via `array_map('intval', …)` + placeholders; schema only via migrations.

### 7.2 Output encoding (SDD §6.5)

| Context | Rule |
|---|---|
| HTML text / attribute / URL | `esc_html()` / `esc_attr()` / `esc_url()` |
| JS data | `wp_json_encode()` into `data-*` (escaped) or `wp_add_inline_script`; never string concatenation; no PII in `wp_localize_script` on public pages |
| Rich statement texts | `wp_kses()` allow-list `<strong>`, `<em>`, `<br>` |
| Customer-entered text in staff screens | Text only, `dir="auto"` inside `<bdi>`; notes `nl2br(esc_html())` |
| Email | HTML part escapes all variables; text part strips tags; no variable in headers; CR/LF stripped in subjects |
| CSV | Cells starting with `=`, `+`, `-`, `@`, TAB, CR prefixed with `'`; RFC 4180 quoting; UTF-8 BOM |
| JSON API / admin JS | Plain-text strings; clients use `textContent`/`createElement`, never `innerHTML` with untrusted data |
| Errors | Localised generic message + `request_id`; never SQL, stack traces, paths (`WP_DEBUG_DISPLAY` false; `zend.exception_ignore_args=On`) |

### 7.3 CSRF and request integrity (SEC-022)
- Admin forms/admin-post: `wp_nonce_field()` + `check_admin_referer()`, action `momos_{action}_{object_id}`; missing/invalid → 403, no change (AC-SEC-01-04). A valid nonce never substitutes for the capability + scope check (TC-ACL-006).
- Staff REST: cookie + `X-WP-Nonce` (`wp_rest`); no session → 401 `rest_forbidden`; valid auth cookie with missing/invalid nonce → 403 `rest_forbidden` (`Api\Http\ErrorNormaliser`).
- Public writes (API-02, API-04, contact): `Content-Type: application/json` (else 415), `Origin` (or `Referer`) host = site host (else 403 `origin_rejected`), Turnstile (API-02/contact) or status token (API-04), idempotency key (API-02). No CORS headers ever.

### 7.4 REST route rules (SEC-023)
- Every `register_rest_route` sets an explicit `permission_callback`; `__return_true` only for API-01, API-02, API-03, API-04, API-17 (controls inside). CI route audit fails on any `momos/v1` route without callback/schema.
- `CoreRestGuard`: write methods on core `wp/v2/momo_*` → 405 `method_not_allowed`; `/wp/v2/users*` → 401 unauthenticated; comments routes removed.
- Headers: `momos/v1` (except API-01) `Cache-Control: no-store` + `X-Robots-Tag: noindex` + `nosniff`; every response `X-Request-Id`.
- Body limits: 8 KB public, 16 KB staff → 413 `payload_too_large`.

### 7.5 Error codes used by this area (Doc 04 §16.0.4; no other codes)

| HTTP | code | When | Message (EN intent) |
|---|---|---|---|
| 400 | `invalid_param` | Schema/format violation; token sent as `t` | "Please check the highlighted fields." |
| 401/403 | `rest_forbidden` | 401 no session; 403 bad/missing nonce or missing capability | "You do not have permission to do this." |
| 403 | `forbidden_branch` | Object/filter outside assigned branches | "You do not have access to this branch." |
| 403 | `forbidden_field` | Field not permitted for the user | "You cannot change this field." |
| 403 | `origin_rejected` / `captcha_failed` | Public write integrity | "We could not verify your request. Please try again." |
| 404 | `not_found` | Unknown or not-visible record | "We could not find this request…" |
| 405 | `method_not_allowed` | Write on read-only core route | — |
| 413 / 415 | `payload_too_large` / `unsupported_media_type` | Body limits / content type | generic |
| 429 | `rate_limited` (+ `Retry-After`) | Any bucket incl. `staff_user` ⟦120/min⟧ | "Too many requests. Please try again later…" |
| 500 / 503 | `internal_error` / `service_unavailable` | Unexpected / dependency failure | "Something went wrong…" (+ `request_id`) |

### 7.6 Rate-limit store (SDD §5.14) and abuse signals (PRV-014, DEC-059)
Sliding-window estimate over two fixed windows; keys `momos_rl:{bucket}:{HMAC('rl', subject)}:{window_start}`; object cache
(`wp_cache_add`/`wp_cache_incr`) else transients; TTL = 2 × window. Client IP from `REMOTE_ADDR`, or the CDN client-IP header
only when the request comes from the configured CDN IP ranges. Reservation rows store only `ip_hash`/`user_agent_hash` =
HMAC-SHA256 with the pepper (64 hex; must differ from unkeyed SHA-256, TC-PRV-017). Bucket values: reservation spec / TRD §4.3.6.

### 7.7 Uploads (SEC-028, FR-ADM-005 — `Kernel\Media\UploadPolicy`, cross-reference)
MIME allow-list JPEG, PNG, WebP (PDF only through the certificate evidence handler); SVG and AVIF rejected for every role;
size ≤ ⟦5 MB⟧; no public upload fields on any form; evidence files outside the web root or behind deny-all, served only by
the authenticated handler (M11) with `Content-Disposition: attachment`, `nosniff`, `Cache-Control: no-store`.

## 8. Security headers and CSP (SEC-029/030, FR-SEC-005; `Presentation\Http\HeaderPolicy`)

Global headers are set at the web server/CDN (`config/server/`) so cached responses carry them; `HeaderPolicy`
(`send_headers`, `login_init`, `admin_init`, `rest_post_dispatch`) sets route-specific ones and acts as fallback.

| Context | Headers (exact values) |
|---|---|
| All HTTPS responses | `Strict-Transport-Security: max-age={momo_hsts_max_age}` (ramp 300 at go-live → 86400 after 7 clean days → 31536000 after 30 days; `includeSubDomains` only after every subdomain is HTTPS-only; no preload) · `X-Content-Type-Options: nosniff` · `X-Frame-Options: SAMEORIGIN` · `Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=(), browsing-topics=()` · `Cross-Origin-Opener-Policy: same-origin` · remove `X-Powered-By`, `X-Pingback` and server version tokens |
| Public pages | `Referrer-Policy: strict-origin-when-cross-origin` · `Content-Security-Policy-Report-Only` (mode `report_only`) **or** `Content-Security-Policy` (mode `enforce`) = canonical string below |
| `/reservation/status/*`, `/reservation/submitted/` (all languages) | Public headers + `Referrer-Policy: no-referrer` · `Cache-Control: no-store, private` · `X-Robots-Tag: noindex, nofollow`; no third-party script output (DEC-066) |
| `/wp-json/momos/v1/*` (except API-01) | `Cache-Control: no-store, private` · `X-Robots-Tag: noindex` · `nosniff`; no CORS headers |
| `wp-admin`, `wp-login.php` | `Content-Security-Policy: frame-ancestors 'self'; object-src 'none'; base-uri 'self'` · `Referrer-Policy: same-origin` · momos admin pages `Cache-Control: no-store`; no third-party scripts or origins (PRV-024, TC-PRV-018) |
| Staging | All of the above + `X-Robots-Tag: noindex, nofollow` + HTTP Basic auth |

Canonical CSP (Doc 11 §6.4 — the only copy; change it only through the PRV-024 process):
```text
default-src 'self'; script-src 'self' 'unsafe-inline' https://*.googletagmanager.com https://challenges.cloudflare.com; style-src 'self' 'unsafe-inline'; img-src 'self' data: https://*.google-analytics.com https://*.googletagmanager.com; font-src 'self' data:; connect-src 'self' https://*.google-analytics.com https://*.analytics.google.com https://*.googletagmanager.com https://challenges.cloudflare.com; frame-src https://challenges.cloudflare.com https://www.google.com; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'; upgrade-insecure-requests; report-uri /wp-json/momos/v1/csp-report
```
- Sent Report-Only from launch; switched to enforced after ⟦2 weeks⟧ of production traffic with no unresolved violations (switch recorded in the release log, TC-SEC-030). `'unsafe-eval'` never allowed; nonce/`strict-dynamic` deferred (+90 days review).
- API-17 `POST /momos/v1/csp-report` (C): `application/csp-report` or `application/reports+json`, ≤ 8 KB, bucket `csp_report_ip` ⟦30/h⟧; reduce document URI to a path template (`{lang}`, `{slug}`, no query/fragment) and blocked URI to its origin; increment a counter (directive, origin, template, day) in a non-autoloaded option kept 30 days; store no IP/UA/full URL/body; always `204`.
- Self-hosted fonts and map preview; GTM in-page blocklist `customScripts, nonGoogleScripts, nonGooglePixels, nonGoogleIframes` before the snippet (analytics spec).

## 9. WordPress hardening, configuration and secrets

### 9.1 wp-config (SEC-040, TRD §6.4; generated from `config/wp-config.template.php`)

| Constant | Local | Staging | Production |
|---|---|---|---|
| `WP_ENVIRONMENT_TYPE` (+ optional `MOMOS_ENV`) | `local` | `staging` | `production` |
| `DISALLOW_FILE_EDIT` | true | true | true |
| `DISALLOW_FILE_MODS` | false | true | **true** (DEC-065) |
| `AUTOMATIC_UPDATER_DISABLED` | — | true | **true** |
| `DISALLOW_UNFILTERED_HTML` | — | true | **true** |
| `FORCE_SSL_ADMIN` | false | true | true |
| `WP_DEBUG` / `WP_DEBUG_DISPLAY` / `WP_DEBUG_LOG` | true / true / path | true / false / path | **false / false** / path outside web root |
| `SCRIPT_DEBUG` | true | false | false |
| `DISABLE_WP_CRON` (server cron every minute: `wp cron event run --due-now`) | optional | **true** | **true** |
| `WP_POST_REVISIONS` / `EMPTY_TRASH_DAYS` | 10 / 30 | 10 / 30 | 10 / 30 |
| `DB_*`, 8 keys/salts, non-default `$table_prefix` | env | secret | secret |
| `MOMOS_HASH_PEPPER` (32 random bytes) | env | secret | secret |
| `MOMOS_TOKEN_KEY_V1` (32-byte, base64; versioned) | env | secret | secret |
| `MOMOS_TURNSTILE_SITE_KEY` / `MOMOS_TURNSTILE_SECRET` | test | staging | production |
| `MOMOS_2FA_KEY` [IMPL] (32 random bytes) | env | secret | secret |
| `MOMOS_EVIDENCE_DIR`, `MOMOS_LOG_FILE` | path | path | outside web root (evidence included in backups) |
| `MOMOS_MAIL_SANDBOX` | test address | test inbox | **undefined** |
| `MOMOS_HEARTBEAT_URL_EXPIRY`, `MOMOS_HEARTBEAT_URL_QUEUE` (+ `_RETENTION`, `_CERTIFICATE` [IMPL]) | — | optional | set |

Secrets come from `getenv()` or `../secrets/momos-secrets.php` (400/440); read only via `Kernel\Env::secret()`. Note: the
current kernel `Hash::hmac()` reads `MOMOS_HMAC_KEY`; the docs name the pepper `MOMOS_HASH_PEPPER` — support both (alias) and
use purpose prefixes `ip`, `ua`, `email`, `rl`, `audit` (§17).

### 9.2 Attack-surface reduction (SEC-041/043; `Access\Hardening`)
- XML-RPC: `xmlrpc_enabled` → false, remove `X-Pingback`, deny `POST /xmlrpc.php` at server/WAF (403); pingbacks/trackbacks off.
- User enumeration: `/?author=N` and author archives → 301 to home; `/wp/v2/users*` → 401 unauthenticated; users sitemap provider removed; oEmbed `author_name`/`author_url` removed; `login_errors` generic.
- Comments disabled site-wide: `comments_open`/`pings_open` false, comment feeds and REST routes removed, admin menu hidden.
- `pre_option_users_can_register` → 0; `pre_option_default_role` → ''; `wp_is_application_passwords_available` → false.
- Version disclosure: remove `wp_generator`, strip core `ver=` query args where feasible (own assets use content-hash versions); `readme.html`, `license.txt`, `wp-admin/install.php` denied at server.
- Admin chrome: core dashboard widgets (Welcome, Events & News, Quick Draft, Site Health) removed for all except ROLE-01; front-end admin bar hidden for ROLE-05…07; no third-party origins on admin screens (no Gravatar: `get_avatar` returns a local placeholder; `pre_option_show_avatars` → 0).
- Plugin/theme events logged (`wp.*` activity rows); file edits/installs impossible in production (`DISALLOW_FILE_*`).

### 9.3 Web-server rules (SEC-042) — shipped in `config/server/`
```apache
Options -Indexes
RedirectMatch 403 /\.(?!well-known/)
<FilesMatch "^(wp-config\.php|readme\.html|license\.txt|xmlrpc\.php|debug\.log|\.user\.ini|php\.ini)$">
  Require all denied
</FilesMatch>
<FilesMatch "\.(sql|bak|old|orig|swp|zip|tar|gz|7z|log|env|ini|sh|yml|yaml|lock)$">
  Require all denied
</FilesMatch>
# /wp-content/uploads/.htaccess and the evidence directory
<FilesMatch "\.(php\d?|phtml|phar|pl|py|cgi|sh)$">
  Require all denied
</FilesMatch>
```
Plus blocking direct requests to `wp-includes/*.php` and `wp-admin/includes/`; `.git`, `composer.*` denied; permissions
dirs 755, files 644, `wp-config.php` 400/440 (600 if required), above web root where supported. Staging: Basic auth +
`noindex`, separate DB/keys, outbound email captured, synthetic data only (PRV-029, SEC-055, FR-FND-002).

### 9.4 Database user (SEC-052, SDD §6.8)
Runtime user `momos_app`: SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES, CREATE TEMPORARY TABLES,
LOCK TABLES on the site schema only; optional `momos_deploy` adds DROP (migrations, deploy window only); TRIGGER only if the
§2.3 audit triggers are used; never GRANT, FILE, SUPER, PROCESS; no internet access to the DB.

## 10. Privacy

### 10.1 Notice at collection and consent (PRV-002/003/004, FR-PRV-002, DEC-053)
- Block placed directly above the submit button on SCR-C09 (reservation) and SCR-C12 (contact), in the page language; links open the same-language Privacy Policy / Reservation Policy; focus restored on return (WCAG 3.3.7).
- Checkbox required, **unticked**, never restored after a language switch; no pre-ticked boxes; no marketing opt-in exists (DEC-043).
- Server: API-02 `consent !== true` → 400 `invalid_param` with `fields.consent = "required"`; when `special_request_separate_consent` is on and `special_request` is non-empty, `special_request_consent !== true` → 400 (`fields.special_request_consent`).
- Contact form: submissions not stored (DEC-038); the email to HO includes "Consent: yes — {consent_key}"; no IP/UA metadata (TC-PRV-015).
- Strings: §15.2 (all are counsel drafts; the JA master governs; human translation only; no MT for legal text, BR-PRV-04).

### 10.2 Consent registry (`Privacy\Consent\ConsentRegistry`, ENT-24)
Option `momo_consent_versions` (JSON list), edited on SCR-A15 Privacy tab by SA/HO, audited `settings.updated`:

| Field | Type | Rule |
|---|---|---|
| `consent_key` | string ≤ 20 | `^PP\d+\.\d+-RP\d+\.\d+$`, e.g. `PP1.0-RP1.0`; unique |
| `privacy_policy_version`, `reservation_policy_version` | string | e.g. `1.0`; must equal the version labels on the legal pages |
| `privacy_page_revision_id`, `reservation_policy_revision_id` | int | page revision IDs at publication |
| `text_sha256` | object {lang: hex64} | SHA-256 of the rendered `res.label.consent` string per enabled language |
| `effective_from` | UTC datetime (JST input) | active row = latest `effective_from ≤ now` |
| `created_by` | user ID | system |

`ConsentRegistry::current()` returns the active key → `momo_reservations.consent_version` (varchar 20); `consent_at` = server UTC.
Saving a legal page whose version label has no registry row is blocked with an admin notice (TK-12-16). Earlier reservations keep
their key (AC-PRV-01-02).

### 10.3 Minimisation and leakage rules (PRV-001/005/006/007/008, FR-PRV-005, BR-PRV-01)
- Forms collect exactly FR-RES-001 / FR-CNT-001; unknown properties → 400. Never: religion, nationality, Katakana name, ID numbers, date of birth, payment data, structured health data.
- No PII in URLs (status token only in the URL fragment and `X-Momo-Token` header, DEC-058), page titles, analytics payloads, application/error logs, email subjects, audit `changes`, 404 logs.
- Reservation PII lives only in the custom tables; no CPT/option/transient holds it; no search, feed, sitemap, core REST or theme query exposes it (SEC-014).
- `/reservation/submitted/` and `/reservation/status/*` load no third-party resource (DEC-066) and send the §8 headers.
- No personal data is sent to MT/AI services; reservation tables are never registered as translatable (BR-PRV-04, PRV-030).
- Analytics opt-out (PRV-022, owned by the analytics spec): cookie `momo_analytics_optout=1` (1 year, Secure, SameSite=Lax) or `navigator.globalPrivacyControl === true` → the loader never injects GTM; opt-out deletes `_ga`, `_ga_*`.

### 10.4 Retention and anonymisation job (FR-PRV-003, PRV-012/013, DEC-034; `Privacy\Retention\RetentionJob`)
Hook `momos_job_retention`, daily **03:15 JST** (`15 18 * * *` UTC), group `momos`; named lock `momos_job_retention`; resumable.

| Step | Action |
|---|---|
| 1 | Acquire lock; exit if held |
| 2 | Reservations with `pii_purged_at IS NULL` and `requested_date < today_JST − momo_retention_pii_months` (Q9), batches of 500 by `id`, **one transaction per reservation** via `Anonymizer::anonymizeReservation($id, 'retention')`. Future `requested_date` is never selected |
| 3 | `ip_hash = NULL, user_agent_hash = NULL` where `created_at < now − momo_retention_ip_hash_days` |
| 4 | Notifications: `recipient_email = NULL`, `recipient_masked = '[purged]'`, `recipient_purged_at = now` 30 days after terminal status; delete rows with `created_at < now − momo_retention_notification_days` (batches) |
| 5 | Delete `momo_audit_log` rows with `occurred_at < now − momo_retention_audit_months` (batches) — the only audit delete path |
| 6 | [IMPL] Delete activity-class rows (§6.4) older than `momo_retention_activity_months`; delete CSP counters > 30 days |
| 7 | Purge Action Scheduler logs older than 30 days |
| 8 | Audit `retention.run` (actor system) with `changes` = counts per step, duration, settings snapshot (no PII); set heartbeat `retention` |
| 9 | On error: roll back the current reservation only, continue; at the end write `privacy.retention_failed` (error code only), alert HO privacy lead + vendor (§12); next run resumes |

`Anonymizer::anonymizeReservation(int $id, string $reason)` (single routine for retention, DSR, eraser):
`momo_reservations`: `guest_name, email, phone_e164, special_request, message_to_customer, token_hash, ip_hash, user_agent_hash` → NULL,
`pii_purged_at = now`; `momo_reservation_events`: `note` → NULL and free-text payload values removed; `momo_notifications` of the
reservation: as step 4; insert event `pii_purged` (system, reason). Kept: branch, date, time, party size, languages, status,
timestamps, reason codes, `consent_version`/`consent_at`, `token_expires_utc`. Admin screens display "—" for purged fields;
purged reservations are not found by status link or DSR search (AC-PRV-02-02). Alerts if no successful run for 26 h.
Former staff accounts' personal fields: anonymisation period ⟦TBD — Owner + Counsel; LC-001 proposes employment + 1 year⟧ — not built in Phase 1.

### 10.5 DSR tool (FR-PRV-004, PRV-016/017; `Privacy\Dsr\*`, SCR-A19)
- Search: one input; a value matching the reference pattern (8 Crockford chars, optional hyphen, normalised) searches `public_ref`; otherwise it must be a valid email (trimmed, case-insensitive exact match). No partial match, no browsing. Results across all branches, purged rows excluded. Audit `dsr.searched` with `changes = {"by": "email|reference", "results": n}` — never the searched value.
- Export: streams `momo-dsr-export-{YYYYMMDD-HHMMSS}.json` (no personal data in the file name), never stored on disk (Doc 07). Content = output of the registered exporters `momos-reservations` (branch name/code, date, time JST, party size, status, language, name, email, phone, special request, message to customer, consent key/time, lifecycle timestamps) and `momos-notifications` (template, language, status, sent time; no bodies) plus customer-visible event types/timestamps; staff internal notes excluded ⟦TBD — Counsel⟧. Audit `dsr.exported` {count}.
- Correct: guest name, email, phone, preferred language of selected reservations (any status); reason required (≤ 200, no PII); validation as API-02; audit `dsr.corrected` with field names and `[masked]` values.
- Anonymise: one reservation per confirmation; the user types the reference to confirm; text states irreversibility. Blocked while the reservation is `pending`/`confirmed` with `requested_start_utc > now` → message + link to SCR-A09 to cancel first (reason `other`, note "DSR", no customer email unless chosen) (AC-PRV-03-02). Calls the Anonymizer with reason `dsr`; audit `dsr.anonymized`.
- Core integration: exporter/eraser registered via `wp_privacy_personal_data_exporters` / `_erasers`; the eraser anonymises (never deletes) and reports active future reservations as retained. Core Tools → Export/Erase screens stay SA-only (core `manage_options` mapping).
- All handlers: admin-post + nonce + `momo_manage_dsr` (vendor accounts always denied); BM → 403 (AC-PRV-03-03).
- Procedure (offline, PD-30): register entry, identity verification (one-time code to the stored email + one reference), acknowledgement ⟦3 business days⟧, completion target ⟦14 days⟧, fee ⟦free⟧ — counsel/Owner.

### 10.6 Content and process items with code hooks
Privacy Policy (SCR-C15, PRV-019 checklist P-1…P-17), Reservation Policy (SCR-C16, CMP-003: items without approved value are not shown),
External Transmission page (SCR-C17, PRV-021, opt-out control) are legal pages (`momo_page_type = legal`, publish needs
`momo_publish_legal_pages`, version label + last updated). Processor register, cross-border routes, training, printed lists,
DSR register and incident records are HO/counsel processes (PRV-025…032, SEC-110…112) — no code.

## 11. Backup and restore (F-SEC-02, DEC-033, SEC-090/091, PRV-033)
Operational prerequisite (IMPL-004): host daily full backup + off-site copy in Momo's-owned storage (daily full incl.
`MOMOS_EVIDENCE_DIR`, DB every 4 h, 30-day lifecycle, AES-256 / provider encryption with Momo's-held key, ≤ 2 named people
with MFA, write-only uploader credentials, never under the web root); RPO ≤ 4 h, RTO ≤ 8 h; restore drill pre-launch
(GATE-07) and quarterly; pre-deploy `wp db export` snapshot. momos-core responsibilities:
- `wp momos smoke` verifies tables, constraints, roles, settings, cron last run < 2 min, queue health.
- Restore runbook step: site stays in maintenance (503) until `wp momos jobs run momos_job_retention` has completed, so past-retention data restored from backup is re-anonymised before reopening (PRV-033, TC-BCK-005); notification queue paused to avoid resending (TC-BCK-003).
- Backup failure alerts reach ROLE-01 and vendor within 1 h (from the backup tool/host).

## 12. Monitoring and alerting (F-SEC-03, FR-SEC-012, NFR-OBS-01, SEC-083; `Kernel\Monitoring\*`)
- Heartbeat: option `momos_heartbeat` = {job: last_success_utc}; after each successful run `HeartbeatPinger` sends a non-blocking GET (2 s timeout) to `MOMOS_HEARTBEAT_URL_{JOB}`; the external dead-man's switch alerts on missing pings. SCR-A15 Monitoring tab shows each job's last success and "Late" when older than its threshold.
- Alerts sent by momos-core via the notification transport to `momo_alert_recipients` (HO duty contact + vendor on-call ⟦TBD — Owner⟧); at most one email per signal per hour [IMPL]; bodies name signal, time, actor/object ID — never PII.

| Signal | Threshold | Source |
|---|---|---|
| Uptime (home, one branch page, API-01) | down > 2 consecutive 1-min checks | External monitor |
| Expiry job / notification queue heartbeat | no run for 30 min | Dead-man's switch |
| Retention / certificate-expiry job | no success for 26 h; any retention failure | Dead-man's switch; momos-core |
| Failed logins | > 20/hour site-wide; any ROLE-01/02 lockout | `LoginThrottle` |
| New administrator, role change, plugin/theme activation | any | Access / activity rows |
| File integrity | any change outside a deploy window (weekly `wp core verify-checksums` + SHA-256 manifest of momos-core/theme files built in CI, checked daily 03:45 JST) | `security.file_integrity_failed` |
| TLS certificate | < 14 days to expiry | Monitor |
| Reservation submissions | > 5 × trailing 7-day hourly baseline | momos-core |
| Email failures | > 5 % in an hour, or any customer email failing after 5 attempts | Notification log |
| Access denials | > ⟦10⟧ per user per hour | Audit |
| Captcha degraded | > ⟦10⟧ per hour | Audit |
| PHP fatal / `critical` log line | any | Log watcher (host) |
| Backup | any failed or missing backup | Backup tool/host |

Log retention (SEC-084): activity 12 months; PHP error and momos-core logs ⟦90 days⟧ rotated daily (outside web root);
server access logs ≤ 90 days; audit 24 months; CSP counters 30 days; Action Scheduler logs 30 days.

## 13. Screens

### 13.1 Admin screens

**SCR-A01 Login + 2FA** (`wp-login.php`, `?action=momos_2fa`) — Logo, ja/en selector, username/email, password, "Log in",
"Lost password"; step 2: 6-digit code, "Use a recovery code"; first login: enrolment wizard (key + QR → code → recovery codes →
"I have saved these codes"). States: loading (button busy) · error (generic) · locked (retry time) · success (redirect, `redirect_to`
preserved through 2FA). Works at 360 px; labels visible; paste allowed.

**SCR-A02 Dashboard** (S; `wp_dashboard_setup`, widget registered only if the user holds the capability; every count scoped; each links to the pre-filtered list; counts load ≤ 2 s with 10,000 reservations; empty widget shows positive text)

| Widget | Visible to | Content | Link |
|---|---|---|---|
| My pending requests | RS/BM (b), HO/SA | `pending` count + 3 oldest/soonest, SLA-overdue count (red + text) | A08 filtered |
| Needs action | RS/BM (b), HO | `needs_action_flag` or `customer_notified_flag = 0` | A08 filtered |
| Attendance to record | RS/BM (b) | confirmed, start in the past, within 72 h, no attendance | A08 filtered |
| Dishes awaiting review | HO (publishers); MM own | dishes in `pending` | A06 |
| Translation gaps | HO, MM, CE | per enabled language, LCCS % | A11 |
| Expiring certificates | CAP-DCA holders, HO | active, `valid_until` ≤ today + 30; expired last 7 days | A12 |
| Allergen data not verified | CAP-DCA, HO, MM | dishes with `not_verified` or review flag | A06 filtered |
| Recent changes | HO, SA | last 20 audit entries (branch changes by BM, last 7 days) | A16 filtered |
| Offers awaiting approval | HO; CE/BM own submissions | offers in `pending` | A20 filtered |
| Offers ending soon | HO, CE, BM (b) | published offers ending ≤ 7 days | A20 filtered |
| Failed notifications | HO, SA | failed in last 24 h | A08 filtered |
| Demo content remaining | HO, MM, CE | `momo_is_sample` items, illustrative images in use | A06/A18 |
| My translation jobs | TR | own open jobs | A11 |

**SCR-A10 Users & branch assignments** (native Users screens + `Access\Admin\UserBranchFields`; `momo_manage_staff`)
- List: added columns Role, Branches (codes), 2FA (Enrolled / Not enrolled), Status (Active / Disabled / Vendor until {time}), Last login; filters by branch, role, status; row actions Edit, Disable/Enable, Reset two-factor; **no Delete** (bulk delete removed).
- Add/Edit: username, email, display name, role (single select; `administrator` absent for HO), Branches (fieldset with legend, checkboxes of canonical branches; shown and required for ROLE-06/07), Admin language (日本語 / English), Phone (optional), CAP-DCA checkbox (§5.7 eligibility), Account disabled; SA-only: Vendor account flag, Break-glass (until ≤ 8 h + approval reference); HO/SA on vendor users: "Enable vendor access until" (≤ 72 h).
- Own profile: role, branches, disabled, vendor, CAP-DCA rendered read-only; crafted POSTs ignored + `access.denied`.
- BM: read-only "Assigned staff" panel on own branch edit screen (name, role of active users) — Branch module `BranchStaffPanel`.
- States: success notice ("User updated." / 「ユーザーを更新しました。」 core); validation errors §13.3; warning when disabling a user whose email is in a recipient list; audit row per change.

**SCR-A15 Settings — Privacy, Monitoring, Security tabs** — Privacy: retention values (§2.4), separate-consent switch, consent registry table with "Add version"; Monitoring (SA): heartbeat status table (job, last success JST, threshold, OK/Late), alert recipients, "Send test alert"; Security (SA): CSP mode, HSTS step, invalid-username lock switch. Every save audited `settings.updated` (before/after).

**SCR-A16 Audit log** (`admin.php?page=momos-audit`; `momo_view_audit_log`)
- Filters: date range (JST; default last 7 days; end ≥ start), actor (incl. disabled users, "System"), action (grouped by prefix), object type, object ID, branch. Read-only; 50 per page; `ORDER BY id DESC` (Q14); must stay responsive at 100k rows.
- Columns: Time (JST `Y-m-d H:i:s`), Actor (display name, "(disabled)" suffix, or "System"), Role, Action (label + key), Object (type #id, linked only if the viewer may open it), Branch (code), Changes (`field: before → after`, masked), IP (truncated), Request ID.
- States: empty "No entries for these filters" / 「該当する記録はありません」 [JA draft]; error notice with `request_id`. BM/RS/MM/CE/TR → 403 + `access.denied`.

**SCR-A19 Data-subject requests** (`admin.php?page=momos-dsr`; `momo_manage_dsr`; menu "Data requests / 個人情報の開示等請求")
- Search box (email or reference) → results table: reference, branch code, requested date/time (JST), status, language, created; checkboxes; actions Export, Correct, Anonymise.
- States: empty "No records found." (neutral; no existence hints beyond the result) · success notices per action · blocked anonymise (active future reservation) · error with `request_id`. Export streamed.

### 13.2 Customer blocks (SCR-C09, SCR-C12) — strings in §15.2; notice above submit, consent checkbox, special-request hint.

## 14. Handlers and endpoints

| Handler | Method / action | Capability + nonce | Result |
|---|---|---|---|
| 2FA challenge | `POST wp-login.php?action=momos_2fa` (challenge token in body) | valid challenge | cookie set / generic error |
| 2FA enrolment | `POST wp-login.php?action=momos_2fa_enrol` | valid challenge (`enrol=true`) | recovery codes shown once |
| Reset 2FA | `admin-post.php?action=momos_user_2fa_reset` | `edit_user` (M9) + `momos_user_2fa_reset_{id}` | audit `user.2fa_reset` |
| Disable / enable | `admin-post.php?action=momos_user_status` | `edit_user` + nonce | §5.6 |
| Vendor window | `admin-post.php?action=momos_vendor_window` | `momo_manage_staff`, target vendor | `user.access_window_set` |
| Break-glass | `admin-post.php?action=momos_breakglass` | `manage_options`, actor not vendor | `vendor.breakglass_*` |
| Access review CSV (C) | `admin-post.php?action=momos_access_review` | `momo_manage_staff` | streamed CSV |
| Audit export (C) | `admin-post.php?action=momos_audit_export` | `manage_options` | streamed CSV, `audit.exported` |
| DSR search / export / correct / anonymise | `admin-post.php?action=momos_dsr_{search,export,correct,anonymize}` | `momo_manage_dsr` + nonce | §10.5 |
| CSP reports (C) | `POST /wp-json/momos/v1/csp-report` (API-17) | public, rate-limited | `204` |

## 15. Microcopy (keys: Doc 07 §6 where they exist, else `<module>.<area>.<item>`; JA marked [draft] is not from the docs and needs native review)

### 15.1 Staff / admin

| Key | English | Japanese |
|---|---|---|
| `staff.err.forbidden_branch` | You do not have access to this branch. | この店舗へのアクセス権限がありません。 |
| `staff.err.forbidden_field` | You are not allowed to change: {field}. | 次の項目を変更する権限がありません：{field} |
| `admin.err.forbidden_requests` (A09 403 page) | You don't have access to this branch's requests. | この店舗のリクエストへのアクセス権限がありません。[draft] |
| `admin.login.err.generic` | Incorrect username or password. | ユーザー名またはパスワードが正しくありません。[draft] |
| `admin.login.err.locked` | Too many failed attempts. Please try again after {time}. | ログインの失敗が多すぎます。{time}以降に再度お試しください。[draft] |
| `admin.2fa.prompt` | Enter the 6-digit code from your authenticator app. | 認証アプリに表示されている6桁のコードを入力してください。[draft] |
| `admin.2fa.err.invalid` | That code is not valid. Please try again. | コードが正しくありません。もう一度お試しください。[draft] |
| `admin.2fa.recovery_link` | Use a recovery code | リカバリーコードを使用する [draft] |
| `admin.2fa.saved_codes` | I have saved these codes | これらのコードを保存しました [draft] |
| `admin.user.err.username` | Choose a different username. | 別のユーザー名を選択してください。[draft] |
| `admin.user.err.branch_required` | Assign at least one branch. | 少なくとも1つの店舗を割り当ててください。[draft] |
| `admin.user.no_branch` | No branch is assigned to your account. Please contact Head Office. | アカウントに店舗が割り当てられていません。本部へお問い合わせください。[draft] |
| `admin.pw.err.length` | Use 12 to 128 characters. | 12〜128文字で入力してください。[draft] |
| `admin.pw.err.breached` | This password is too common or has appeared in a data breach. Choose another. | このパスワードは一般的すぎるか、流出が確認されています。別のパスワードを選択してください。[draft] |
| `admin.vendor.err.window` | Vendor access can be enabled for at most {hours} hours. | ベンダーのアクセスは最長{hours}時間まで有効にできます。[draft] |
| `admin.audit.empty` | No entries for these filters | 該当する記録はありません [draft] |
| `admin.dsr.blocked_active` | Cancel the active request first. Anonymisation is not possible while a future request is pending or confirmed. | 先に有効なリクエストをキャンセルしてください。未来の受付中・確定済みリクエストがある間は匿名化できません。[draft] |
| `admin.dsr.confirm` | This cannot be undone. Type the reference {ref} to anonymise this record. | この操作は取り消せません。匿名化するには参照番号 {ref} を入力してください。[draft] |
| `admin.dsr.empty` | No records found. | 該当するデータはありません。[draft] |

Admin notices use WordPress classes (`notice-error` with `role="alert"`, `notice-warning`, `notice-success`).

### 15.2 Customer privacy strings (counsel drafts; JA master governs)

| Key | English | Japanese |
|---|---|---|
| `res.notice.collection` | We use the details you provide to handle your reservation request at the branch you select and to contact you about it. See our Privacy Policy for details, including how to request access, correction or deletion. | ご入力いただいた情報は、選択された店舗でのご予約リクエストの受付・対応およびそれに関するご連絡のために利用します。開示・訂正・削除等のご請求方法を含む詳細は、プライバシーポリシーをご確認ください。 |
| `res.label.consent` | I agree to the Privacy Policy and the Reservation Policy. I understand this is a request and is not confirmed until the branch accepts it. If I mention allergies or health needs in my request, they will be used only to prepare for my visit. | プライバシーポリシーおよび予約ポリシーに同意します。本リクエストは店舗が承諾するまで確定しないことを理解しました。特記事項にアレルギーや健康に関する情報を記載した場合、その情報はご来店の準備のためにのみ利用されることに同意します。 |
| `res.err.consent.required` | Tick the box to agree to the Privacy Policy and the Reservation Policy. | プライバシーポリシーおよび予約ポリシーへの同意にチェックを入れてください。 |
| `res.hint.note` | e.g., high chair, celebration, seating preference. Please tell staff directly about serious allergies. | 例：子ども用椅子、お祝い、お席のご希望など。重いアレルギーについては、ご来店時にスタッフへ直接お伝えください。 |
| `consent.special_request` (switch on, note non-empty) | I agree that the health or allergy information in my note will be used only to prepare for my visit. | 特記事項に記載した健康・アレルギーに関する情報が、ご来店の準備のためにのみ利用されることに同意します。 |
| `notice.contact` | We use the details you provide only to respond to your inquiry. See our Privacy Policy for details. | ご入力いただいた情報は、お問い合わせへの回答のためにのみ利用します。詳しくはプライバシーポリシーをご確認ください。 |
| `consent.contact` | I agree to the Privacy Policy. | プライバシーポリシーに同意します。 |
| `ftr.privacy` / `ftr.res_policy` / `ftr.ext_trans` / `ftr.analytics` | Privacy Policy / Reservation Policy / External Transmission & Cookies / Analytics settings | プライバシーポリシー／予約ポリシー／外部送信・Cookieについて／アクセス解析の設定 |

ar/ur/hi/bn: draft translations in the string catalogs, human/native + counsel review before the language is enabled (IMPL-003, PRV-020).

## 16. Acceptance criteria and tests to automate

| Area | Acceptance (Given/When/Then, condensed) | Tests |
|---|---|---|
| Capability matrix | Effective caps per role = §3 table exactly | TC-ACL-001 (PHPUnit), AC-ADM-02-05 |
| Isolation | BM/RS of QAA: lists, counts, search, direct URLs, REST, AJAX, replayed forms, export, notifications, branch edits, offers, dashboard, cert register for QAB → 403/empty, no data, `access.denied` row, 0 DB changes | TC-ACL-002…010, 012, 021, 024 (GATE-03, CI on every staging deploy, 30 ordered branch pairs incl. TEST) |
| User management | HO cannot select/assign ROLE-01 (crafted → 403 + audit); RS without branch → "Assign at least one branch"; own role/branches/CAP-DCA not editable; no delete action | TC-ACL-013, AC-ADM-02-01/06, US-ADM-001 |
| Disable / role / branch change | Two sessions; disable or change → next request on both refused; re-enable restores kept rows; transfer A→B: A URLs 403 | TC-ACL-011, TC-ADM-009, AC-ADM-02-03/04 |
| 2FA | New account: no admin page/staff REST before enrolment; per role; recovery code single use; reset audited | TC-SEC-001, TC-ADM-004, AC-SEC-01-01 |
| Throttling | 10 failures → lockout for configured period + SA/HO alert; identical generic errors; invalid username lock | TC-SEC-002, AC-SEC-01-02 |
| Passwords | 11/129-char and breached rejected; 12 accepted; reset link 59 min works once, 61 min/reuse fails | TC-SEC-023 |
| Sessions | No Remember-me; cookie ≤ ⟦14 h⟧; idle ⟦8 h⟧; destroyed on password change and 2FA reset; HttpOnly/Secure/SameSite | TC-SEC-024, TC-SEC-019 |
| Vendor | Expired vendor login refused generically; enabled vendor still 403 on detail/export/DSR; break-glass: detail readable, export/DSR 403; 9 h grant refused; auto-revoke audited | TC-ADM-008, TC-ACL-022, TC-SEC-022 |
| Audit | Price override 1,500→1,700 → one row with actor, branch, before/after; DSR email correction masked; no edit/delete path; BM/RS/MM 403 on SCR-A16; every §6.9 event writes one complete row | TC-ADM-002, TC-ACL-018, TC-ADM-011, AC-ADM-03-01…04 |
| Abuse hashes / IP | 0 raw IPs in `momo_*` tables; hashes differ from unkeyed SHA-256; audit IP `/24`, `/48` | TC-PRV-017, TC-SEC-018 |
| Headers / CSP | HSTS per ramp; canonical CSP string Report-Only then enforced; status/submitted pages `no-referrer`, `no-store`, `noindex`, 0 third-party requests; wp-admin 0 third-party origins | TC-SEC-004, 029, 030, TC-PRV-016, 018 |
| CSRF / origin | Staff POST without nonce → 403 no change; cross-origin/non-JSON public writes → 403 `origin_rejected` / 415 | TC-SEC-006, TC-SEC-027 |
| Injection / errors | XSS payloads inert everywhere; SQLi none; DB fault → generic error + `request_id`, no partial rows | TC-SEC-007, 008, 020 |
| Hardening | wp-config values; XML-RPC 403; users endpoint 401; no author archives; App Passwords/comments/pingbacks off; no generator; sensitive files denied; PHP not executed in uploads | TC-WP-013, TC-SEC-003, 009, 010, 025, TC-ACL-020 |
| Consent | Unticked; no consent → 400 `fields.consent`; v1→v2 registry change: new rows store v2, old keep v1 | TC-PRV-001, 002, AC-PRV-01-01/02 |
| No PII leakage | Markers absent from URLs, Referer, logs, subjects, dataLayer, audit (masked) after a full cycle | TC-PRV-007, 008, 014 |
| Retention | Time-travel: 13-month-old rows anonymised (fields NULL, notes cleared, `pii_purged_at`), counts unchanged, `retention.run` row; boundary days (retention −1/0/+1); injected DB error → alert + backlog completed next run; future reservations untouched; notification 180 d / audit 24 m purge | TC-PRV-004, 005, AC-PRV-02-01…03 |
| DSR | 3 reservations for one email across 2 branches found + exported + audited; confirmed future blocks anonymise; BM 403; email case/whitespace variants | TC-PRV-006, AC-PRV-03-01…03 |
| Jobs / monitoring | Cron stopped on staging → alert ≤ 35 min; alert drill per signal | TC-WP-004, TC-SEC-016, 026, AC-SEC-03-01/02 |
| Backup | Daily + 4-hourly off-site; restore ≤ 8 h; retention re-run before reopening; no duplicate emails | TC-BCK-001…005 |
| CI gates | WPCS security sniffs, PHPStan, route audit, capability dump, `composer audit`, gitleaks, ≥ ⟦70 %⟧ coverage for Access | TC-SEC-028, TC-WP-011 |

## 17. Out of scope, open TBDs and documentation conflicts

**Out of scope (Phase 1):** customer accounts (DEC-041); passkeys/WebAuthn; marketing consent (DEC-043); application-level
column encryption of email/phone/notes (SEC-056 deferred); nonce-based CSP / Trusted Types; automated anonymisation of former
staff accounts; malware scanning, WAF, TLS, DNS/email authentication, backups and uptime monitoring as products (host/ops,
IMPL-004); penetration test execution (SEC-104, budget TBD); incident runbook content (SEC-110…112, process).

**Open TBDs (build with the proposal):** SEC-004 lockout ⟦10 failures / 15 min → 60 min⟧; SEC-005 idle ⟦8 h⟧ / absolute ⟦14 h⟧;
vendor window ⟦72 h⟧; break-glass ⟦8 h⟧; retention PII ⟦12 months⟧, IP hashes ⟦30 days⟧ (counsel, DEC-034); IP truncation
⟦counsel, Q-420⟧; denial alert ⟦10/user/h⟧; CSP Report-Only ⟦2 weeks⟧; staff `staff_user` ⟦120/min⟧; DSR timelines
⟦3 business days / 14 days / free⟧; internal notes in DSR export ⟦counsel⟧; breached-password source ⟦Security lead⟧; QR library
⟦CTO⟧; alert recipients and chat channel ⟦Owner⟧; SA account holders and staff mapping (DEC-024, Q-025/026); upload limit ⟦5 MB⟧;
[IMPL] items (activity-class actions, `MOMOS_2FA_KEY`, settings marked [IMPL]) need CTO ratification.

**Conflicts found (resolution used here):**
1. Lockout: PRD FR-SEC-002 "progressive delay after 5, lockout ⟦30 min⟧" and Doc 05 vs Doc 11 SEC-004 "10 in 15 min → 60 min" — Doc 11 canonical; account-level lock added for AC-SEC-01-02.
2. Audit masking: PRD FR-ADM-004(c) partial masks (`a***@d***.com`, last 4 digits) vs Doc 11 SEC-081 `"[masked]"` vs SDD §7.5 `"[redacted]"` — `"[masked]"` used (strictest, Doc 11); kernel `Redactor` currently writes `[redacted]` in logs (acceptable for logs).
3. PRD FR-ADM-004(a) "actor name snapshot" — no column in the DDL; viewer resolves names from never-deleted users.
4. Disable: Doc 05 WF-ADM-17 "removes branch assignments" (and meta `momo_disabled`) vs Doc 11 SEC-006/TRD/SDD/TC-ACL-011 "rows kept", `momo_account_disabled` — rows kept.
5. Break-glass scope: Doc 11 SEC-008/TRD say the denies (all four) are removed vs backlog TK-11-14 / TC-ACL-022 "export and DSR stay denied" — stricter test version used.
6. Vendor window: SEC-008 "enabled by HO" vs M9 "HO cannot edit ROLE-01" — narrow exception for vendor-flagged users only (§5.6).
7. Doc 05 §1.12 grants BM `momo_view_audit_log` (b) and differs on `momo_translate`/`momo_manage_certificates` — TRD §2.7.2 used (CON-019).
8. Denial alert threshold: Doc 05 WF-EXC-06 "≥ 5/h" vs Doc 11 SEC-013 "> 10/h" — Doc 11.
9. Consent registry: Doc 11 §4.3 `momo_consent_registry` with key `PP1.0-RP1.0` vs TRD G3 `momo_consent_version_current` (`C20261201`) + `momo_consent_versions` vs SDD ENT-24 — option `momo_consent_versions` with Doc 11 key format and fields (TK-12-16, PRV-003).
10. Consent/notice wording: Doc 07 `res.label.consent` / `res.notice.collection` (short) vs Doc 11 §4.2 drafts (with DEC-053 special-care clause) — Doc 11 wording under Doc 07 keys; counsel to confirm.
11. Retention settings: Doc 06 "read-only" on SCR-A15 vs Doc 11 §5.6 editable by ROLE-01/02 — editable, audited.
12. DSR export: TRD FR-PRV-004 "JSON/HTML zip produced by core tools" (stored in uploads) vs Doc 07 "export streamed (not stored)" — streamed JSON; core tools SA-only.
13. DSR anonymise with active future reservation: Doc 05 WF-ADM-24 "tool warns that it will be cancelled" vs PRD AC-PRV-03-02 "requires cancellation first" — PRD.
14. Headers: SDD §6.11 Permissions-Policy lacks `browsing-topics=()` and COOP; wp-admin `Referrer-Policy: same-origin` (SDD) vs CSP frame-ancestors (Doc 11) — union of both applied.
15. DB privileges: Doc 11 SEC-052 includes DROP for the runtime user vs SDD §6.8 runtime without DROP + separate deploy user — SDD (least privilege).
16. Pepper constant: docs `MOMOS_HASH_PEPPER` with purpose prefixes vs current kernel `Hash::hmac()` using `MOMOS_HMAC_KEY` — alias both, add purposes; test-clock option `momos_test_clock` (docs) vs `momos_frozen_now` (kernel).
17. Audit catalogue has no names for WP-level events formerly in the activity-log plugin, nor for `audit.exported`/access-review export — [IMPL] extension §6.4 proposed.
18. FR-FND-004(b) "HSTS ≥ 6 months after a 2-week verification" vs Doc 11 SEC-050 ramp (300 → 86,400 → 31,536,000) — Doc 11 ramp.
