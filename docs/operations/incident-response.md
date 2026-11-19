# Incident response (FR-SEC-013)

Sources: Doc 11 §7 (SEC-110…112), §6.8 (secrets), ER-003 (APPI timelines), Doc 12 §8.6, DEC-035. Values in ⟦…⟧ need
Owner approval (Doc 11 D6); the contact sheet is filled by the Owner and kept offline + in the password manager.
Monitoring signals that start an incident: [monitoring.md](monitoring.md). Restores: [backup-restore.md](backup-restore.md).

## 1. Severity

| Level | Examples | Response start | Owner informed |
|---|---|---|---|
| SEV-1 Critical | confirmed/suspected unauthorised access to or disclosure of reservation/contact personal data (any number); malware, defacement or admin takeover; active exploitation; allergen error that may have caused harm; reservation submission down > ⟦1 h⟧ in opening hours; `secret.missing` in production | ⟦≤ 1 h, any time⟧ | immediately |
| SEV-2 High | exploited vulnerability in an installed component (not yet here); email delivery failing for all customers > ⟦2 h⟧; one misdirected email with a customer's data; wrong halal claim, certificate mark or offer price published; two consecutive backup failures; unexplained `access_denials` or `privileged_lockout` alert | ⟦≤ 4 h business hours⟧ | same day |
| SEV-3 Medium | suspicious activity without compromise (enumeration, `failed_logins` alert); one `retention_failed`; DMARC failure spike; captcha degraded > 1 h | next business day | weekly report |
| SEV-4 Low | informational findings, false positives | planned work | monthly report |

Anything involving personal data is at least SEV-2 until assessed.

## 2. Contacts (Owner to fill — never commit real numbers here; keep the filled sheet offline)

| Role | Responsibility | Name | Phone | Email | Backup |
|---|---|---|---|---|---|
| Incident Lead | runs this runbook, containment, recovery | ⟦vendor technical lead⟧ | ⟦ ⟧ | ⟦ ⟧ | Momo's ROLE-01 ⟦ ⟧ |
| Business Owner | approves maintenance mode, notices, public statements, break-glass | ⟦Owner⟧ | ⟦ ⟧ | ⟦ ⟧ | ⟦ ⟧ |
| Privacy Lead | breach assessment, PPC report, individual notices, DSR | ⟦Head Office⟧ | ⟦ ⟧ | ⟦ ⟧ | ⟦ ⟧ |
| Japanese counsel | reportability, report content, notices, police/regulator liaison | ⟦ ⟧ | ⟦ ⟧ | ⟦ ⟧ | — |
| IR / forensics firm | exfiltration, ransom, hosting compromise | ⟦ ⟧ | ⟦ ⟧ | ⟦ ⟧ | — |
| Hosting security contact | server logs, isolation, snapshots | ⟦DEC-019 host⟧ | ⟦ ⟧ | ⟦ ⟧ | — |
| Email provider / mailbox support | message tracing, account lock-down | ⟦DEC-018 / DEC-049⟧ | ⟦ ⟧ | ⟦ ⟧ | — |
| Halal officer / food-safety owner | claim or allergen incidents | ⟦D2⟧ | ⟦ ⟧ | ⟦ ⟧ | — |
| Branch managers | phone customers, offline lists (PRV-032) | per branch ⟦ ⟧ | ⟦ ⟧ | ⟦ ⟧ | — |
| PPC online report form | — | link ⟦ ⟧ | — | — | — |

Reviewed quarterly and at every staff/vendor change (GATE-10 drill evidence).

## 3. Runbook

**T0** = the moment anyone at Momo's or the vendor first learns of a possible incident. Record it immediately; it starts
the APPI clock (weekends included).

| Step | Actions | Target |
|---|---|---|
| 1 Detect, triage | open the incident record (ID, T0, reporter, symptoms); classify (§1); call Incident Lead + Business Owner; Privacy Lead if personal data may be involved | SEV-1 ≤ 1 h after T0 |
| 2 Contain | as needed, §5: maintenance mode, destroy sessions, disable accounts, rotate secrets, WAF block / virtual patch, vendor access off. **Before cleaning**, take a forensic copy | same day |
| 3 Assess | which records, how many individuals, which branches, special-care content, purpose (attack?), still ongoing? Counsel decides reportability (§4) | decision ≤ ⟦48 h⟧ after T0 |
| 4 Notify | PPC, individuals, public notice, host/provider/police per §4 | APPI timelines |
| 5 Recover | clean code ([rollback.md](rollback.md)), restore if integrity is in doubt, patch, verify checksums, retention run, 14 days heightened monitoring; reopen with Business Owner approval | RTO ≤ 8 h |
| 6 Document | timeline, decisions, evidence locations, reports filed, notices sent | continuous |
| 7 Review | lessons learned, update risks (Doc 11 §11) and this runbook | ≤ 14 days after closure |

## 4. Personal-data breach (APPI Art. 26)

1. **Scope with the code's own records** (no personal data copied into the incident record — counts and IDs only):
   - who viewed or exported what: Audit log (SA/HO) filtered by actor/time — `reservation.detail_viewed`,
     `reservation.exported` / `reservation.export_with_notes` (filters + row counts), `dsr.*`, `access.denied`,
     `user.*`, `vendor.breakglass_*`, `auth.*`; `wp momos audit verify` proves the log was not altered;
   - affected reservations: `wp db query "SELECT COUNT(*), COUNT(DISTINCT branch_id), SUM(special_request IS NOT NULL AND special_request <> '') FROM <prefix>momo_reservations WHERE <window/branch condition> AND pii_purged_at IS NULL"`;
   - emails: Notification log (masked recipients) + provider logs for misdirected mail.
2. **Reportable?** (counsel decides; PPC Rules): special-care personal information involved (e.g. health/allergy text in
   a special request), risk of financial damage (none expected — no payment data), unauthorised purpose (attack,
   insider theft), or more than 1,000 persons.
3. **Timelines** (ER-003): PPC **preliminary report promptly — guidance ≈ 3–5 days from T0**; **final report within
   30 days** (**60 days** when an unauthorised purpose is involved). Individuals notified promptly in their
   `preferred_lang`; public notice on the website when individuals cannot be reached.
4. **PPC report content** ⟦counsel verify⟧: overview; data items; number of persons; cause; secondary damage (risk);
   response to individuals; public announcement; recurrence prevention; other matters.
5. Also inform: host, email provider, police (criminal acts), affected vendors. The vendor must notify Momo's within
   24 h of awareness and never notifies authorities or individuals on Momo's behalf (SEC-112 clause).
6. Not reportable: record the decision and rationale in the incident record.

## 5. Security incident — technical steps

**Maintenance and evidence**
- `wp maintenance-mode activate`; publish the prepared "online reservations unavailable — please call" notice (§6).
- Forensic copy before any cleanup: `MOMOS_BACKUP_DIR=/forensic/<incident> /opt/momos-ops/bin/backup.sh` (dump,
  uploads, evidence) + web/WAF/PHP/momos logs + `releases/` directory listing with checksums; store isolated.
- Code integrity: `wp core verify-checksums`; compare `wp-content/plugins/momos-core`, `themes/momos`, `mu-plugins`
  with the release zip of the deployed tag (`sha256sum -c momos-<version>.zip.sha256`, unzip, `diff -r`).

**Revoke sessions and accounts**
- All sessions: `for id in $(wp user list --field=ID); do wp user session destroy "$id" --all; done`
  (rotating the WordPress salts below also invalidates every login cookie).
- One account: Users → Disable (sets `momo_account_disabled`, destroys sessions, audit `user.disabled`).
- Vendor: let the access window expire or disable now; break-glass is revoked in the user screen (audit
  `vendor.breakglass_revoked`); `momos_job_access_expiry` re-applies the vendor denies hourly.

**Verify the audit chain**: `wp momos audit verify` → `Success: N rows verified.` A failure lists row IDs whose HMAC
does not match (altered or inserted rows): keep the forensic copy, treat as SEV-1, continue with `--from=<id>` to find
where the chain is intact again.

**Rotate secrets** (host secret store + password manager; record each rotation in the maintenance log):

| Secret | How | Side effects |
|---|---|---|
| DB password | new password at the host, update `MOMOS_DB_PASSWORD`, reload PHP | none |
| WordPress keys/salts (8) | new values (`https://api.wordpress.org/secret-key/1.1/salt/`) into `WP_*` env | everyone is logged out |
| `MOMOS_TOKEN_KEY_Vn` | see "Token key rotation" below | old status links stop working; customers get a new link |
| `MOMOS_HASH_PEPPER` (only on suspected compromise) | before: `wp momos audit verify` and note the last audit ID; set the new pepper; after: `wp momos audit verify --from=<first new ID>` | rows written before the rotation can only be verified with the old pepper (keep it sealed); rate-limit counters reset; email-hash lookups (notification `recipient_hash`, dedupe keys) of older rows no longer match |
| `MOMOS_2FA_KEY` | new 32 random bytes | stored TOTP secrets can no longer be decrypted: every staff user must be reset (Users → Reset two-factor, audit `user.2fa_reset`) and re-enrol — do the SA first: `wp eval '\Momos\Access\TwoFactor\TwoFactor::reset( <SA user ID> );'` |
| `MOMOS_TURNSTILE_SECRET` / site key | Cloudflare dashboard → rotate, update env | none |
| `MOMOS_SMTP_USER` / `MOMOS_SMTP_PASS` (+ fallback) | email provider console | test with the TEST-branch smoke |
| `MOMOS_CF_API_TOKEN`, heartbeat URLs, GTM/GA4/Search Console/registrar/host accounts | each provider; remove unknown users | — |

**Token key rotation** (`Reservation\Service\TokenService`, DEC-064). Status links carry
`HMAC-SHA256(MOMOS_TOKEN_KEY_V{n}, …)`; the database stores only `sha256(token)` and `token_key_version`.
1. Generate the next key: `openssl rand -base64 32` → define `MOMOS_TOKEN_KEY_V2` (keep `V1` defined) and
   `MOMOS_TOKEN_KEY_CURRENT=2`. New reservations now use version 2.
2. **Compromise**: `wp momos token reissue --key-version=2` — recomputes the stored hash of every unexpired, non-purged
   reservation still on another version (5,000 per run: repeat until `Re-issued 0 status tokens`), so the old links
   answer 404 at once, and fires `momos/reservation/token_reissued` → one E06 "new link" email per reservation.
   Check: `wp db query "SELECT COUNT(*) FROM <prefix>momo_reservations WHERE token_key_version = 1 AND token_expires_utc > UTC_TIMESTAMP() AND pii_purged_at IS NULL"` → 0. Then remove `MOMOS_TOKEN_KEY_V1` (emails for older rows simply omit the link). If the
   pepper store was exposed too, rotate `MOMOS_HASH_PEPPER`. Review API-03/04 access logs for the exposure window.
3. **Planned rotation** (no compromise): steps 1 only; keep `V1` until no V1 token is unexpired (tokens expire 24 h after
   the reservation time; the query above → 0, at most booking window + 2 days), then remove it.

**Recover**: clean release ([rollback.md](rollback.md)), restore if needed ([backup-restore.md](backup-restore.md) — the
restore runs the retention job), patch ([updates-and-patching.md](updates-and-patching.md)), `wp momos smoke`, then
14 days of daily review of alerts, audit log and WAF events.

**Other playbooks** (Doc 11 §7.4): misdirected email — ask the recipient to delete, count persons, counsel;
exposed export file — revoke the share, row count from the `reservation.exported` audit row; spam flood — tighten WAF and
rate limits, staff cancel with reason `suspected_spam` (no customer email); wrong halal claim / price / allergen —
correction procedures of Doc 11 §8.8/§8.10/§9.7 (SEV-1 if harm is possible); lost staff device — destroy that user's
sessions and reset two-factor.

## 6. Communication templates (counsel approves the final wording; Japanese governs; translate to all enabled languages)

**Internal alert (email/chat to the contact list)**
> [SEV-x] <short title> — T0 <JST time>. Status: investigating / contained / resolved. Impact: <service, branches,
> number of reservations — no names>. Next update: <time>. Incident Lead: <name>.

**Customers — notice of a personal-data incident (ja/en drafts)**
> 【重要】個人情報に関するお知らせ — Momo's Cafe & Halal Restaurant をご利用いただき、ありがとうございます。<日付>、当社の予約受付システムにおいて、<事象の概要>が発生し、お客様の<対象となる情報：氏名・メールアドレス・電話番号・ご予約内容 等>が<第三者に閲覧された可能性>があることが判明しました。現時点で<二次被害は確認されていません>。当社は<対応内容>を実施しました。ご不明な点は<窓口・電話番号・受付時間>までお問い合わせください。ご迷惑とご心配をおかけしましたことを深くお詫び申し上げます。
>
> Important notice about your personal information — On <date> we found that <summary of the event> in our online
> reservation system may have exposed your <name, email address, phone number, reservation details>. We have
> <actions taken>. We have no evidence of misuse so far. For questions contact <contact, phone, hours>. We sincerely
> apologise for the concern this causes.

**Website — service notice (6 languages, prepared before launch, Doc 12 §8.4)**
> Online reservation requests are temporarily unavailable. Please call the branch directly: <branch list with phone>.
> オンライン予約リクエストは現在ご利用いただけません。各店舗へお電話でお問い合わせください。

**PPC preliminary report** — use the PPC online form; content per §4 step 4; attach the incident timeline. Final report
by day 30 (60).
