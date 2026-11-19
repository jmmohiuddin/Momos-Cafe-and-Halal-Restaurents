# Monitoring and alerting

Sources: NFR-OBS-01, FR-FND-003(f), Doc 09 TRD §6.13, Doc 11 §6.9 (SEC-080…084), access-security-privacy §12,
`Kernel\Jobs\Schedule`, `Kernel\Monitoring\AlertMailer`, `Kernel\Monitoring\StatusPanel`, `Kernel\Log\Logger`.
Three channels exist: the **external monitor** (uptime + heartbeats, owner-provided), **alert emails** sent by
momos-core, and **critical log lines** that the host's log watcher must forward.

## 1. Where to look

| Screen / command | Shows |
|---|---|
| Momo's → Settings (bottom, SA/HO): "Monitoring — scheduled jobs" | every recurring job: interval, last success (JST), state OK / Late / Not run yet |
| `wp momos jobs list` | the same table on the server (`state` = `ok` \| `late` \| `never`; late = no success for 2 × interval + 2 min) |
| Settings → "Plugin register" + admin notice | active plugins vs `config/plugin-inventory.php`, cap 15 |
| `wp momos smoke` | tables, constraints, roles, secrets, hardening constants, plugin register; in production also published samples, late jobs and failed `momos` actions in the last hour |
| Tools → Scheduled Actions (group `momos`) | Action Scheduler queue, failed actions and their logs (30 days) |
| Momo's → Notification log | per-message status, attempts, last error code (no message bodies) |
| Momo's → Audit log (SA/HO) and `wp momos audit verify` | domain/security events; integrity of the HMAC chain |

## 2. Heartbeats (dead-man's switch)

After every successful run of a recurring job (all handlers finished without throwing), `Schedule::beat()` stores the
time in option `momos_heartbeat` and sends a non-blocking GET (2 s timeout) to the job's heartbeat URL:
`MOMOS_HEARTBEAT_URL_<JOB>` where `<JOB>` is the hook without `momos_job_`, upper-cased; when that constant is not
defined, the shared `MOMOS_HEARTBEAT_URL`. The expiry job additionally pings `MOMOS_HEARTBEAT_URL_EXPIRY`.

Configure one check per critical job in the external monitor (a shared URL only proves that *some* job runs):

| Constant | Job (interval) | Alert when no ping for | Meaning of an alert | First response |
|---|---|---|---|---|
| `MOMOS_HEARTBEAT_URL_EXPIRE_PENDING` (or `…_EXPIRY`) | `momos_job_expire_pending` (5 min) | 30 min | pending requests are not expired; customers may wait on dead requests | check the crontab line runs (`grep CRON /var/log/syslog`), `wp action-scheduler run --group=momos` by hand, look for `failed` actions; `wp momos reservations expire` runs it once |
| `MOMOS_HEARTBEAT_URL_NOTIFICATION_DISPATCH` | `momos_job_notification_dispatch` (1 min) | 30 min | no email is being sent (E01/E07 stuck) | `wp momos notifications send-due`; SMTP credentials/provider status; Notification log last errors |
| `MOMOS_HEARTBEAT_URL_RETENTION` | `momos_job_retention` (03:15 JST daily) | 26 h | personal data is not being anonymised on time (PRV-012) | `wp momos privacy retention --dry-run`, then without `--dry-run`; see `retention_failed` below |
| `MOMOS_HEARTBEAT_URL_CERTIFICATE_EXPIRY` | `momos_job_certificate_expiry` (60 min) | 26 h | an expired halal certificate could stay visible | run `wp momos jobs run` once; check the Halal register |

Docs name `MOMOS_HEARTBEAT_URL_QUEUE`; this build does not read it — use `…_NOTIFICATION_DISPATCH`.
The Settings switch "Send job heartbeats to the external monitor" (`momo_heartbeat_enabled`) is not consulted by
`Schedule::beat()` in this build: heartbeats are on whenever a URL constant is defined.

## 3. Alert emails (`momos/monitoring/alert`)

`do_action( 'momos/monitoring/alert', $type, $context )` → `AlertMailer`: one `critical` log line every time, and at
most **one email per type per hour** to Settings → Monitoring → "Alert recipients" (`momo_alert_recipients`, SA only;
HO duty contact + vendor on-call, one per line; empty → the site admin email). Subject `[<host>] Alert: <type>`;
the body has type, UTC time, context keys (IDs and counts, never personal data) and a link to Settings.

| Type | Raised by | Meaning | First response |
|---|---|---|---|
| `privileged_lockout` (`user_id`) | `Access\Login\LoginThrottle` | a Super Administrator or Head Office account was locked after failed logins | call the account owner; if it was not them, treat as SEV-2 (credential attack): reset password, destroy sessions, check the audit log for `auth.*` |
| `failed_logins` (`count`) | `LoginThrottle` | more than 20 failed logins site-wide within the current hour | check WAF events and source IPs; tighten WAF rules; SEV-3 unless an account was entered |
| `access_denials` (`user_id`, `count`) | `Audit\AuditLogger` | one user hit more than 10 `access.denied` in an hour (probing other branches?) | audit log filtered by the user; talk to the user / HO; disable the account if unexplained (SEV-2) |
| `retention_failed` (`failed`, `errors`) | `Privacy\Retention\RetentionJob` | a retention batch failed; the next run resumes | `wp momos privacy retention`; read the error codes in the momos log; SEV-3, SEV-2 if it repeats |
| `retention_stale` (`hours`) | retention watch (hourly) | no successful retention run for 26 h | as above + check cron |

## 4. Critical log lines (forward with the host log watcher)

These are logged at `critical` (`Logger::critical`, action `momos/log/critical`) but **not emailed** by momos-core;
the host/log shipper must alert on `"level":"critical"` in `MOMOS_LOG_FILE` (or `momos {…}` lines in the PHP error log),
together with PHP fatals.

| Event | Meaning | First response |
|---|---|---|
| `notification.transport_smtp_auth_failed` / `_transport_not_configured` | SMTP credentials wrong or missing — no email leaves | fix secrets; `wp momos notifications send-due` |
| `notification.failure_rate` | > 5 % of ≥ 20 send attempts failed in 60 min | provider dashboard, bounces, DMARC reports |
| `notification.queue_age` | a due email has waited > 15 min | dispatcher heartbeat; cron |
| `notification.digest_failed` / `notification.digest_not_delivered` | E10 daily summary not sent | Notification log |
| `job.expiry_heartbeat_missing` | the SLA job found no expiry run for ≥ 30 min | as the expiry heartbeat above |
| `reservation.integrity.pending_past_start` | pending requests whose time passed > 1 h ago (expiry stalled) | run `wp momos reservations expire`; inform the branch |
| `captcha.degraded_submissions` / `captcha.secret_missing` | Turnstile outage mode is accepting requests / secret missing | Cloudflare status; secrets; spam watch |
| `dietary.evidence_integrity_failed` | an evidence file's SHA-256 no longer matches | treat as SEV-2 (tampering?) — restore the file from backup |
| `migration.failed` | a migration step threw | do not reopen; `wp momos migrate` output; rollback.md |
| `secret.missing` | `MOMOS_HASH_PEPPER` absent — hashing fails closed (submissions fail) | restore the secret immediately (SEV-1 if production) |
| `monitoring.alert` | mirror of every alert email | — |

## 5. External uptime monitor (owner)

Owner-provided service (account owned by Momo's, MFA): 1-minute HTTPS checks of `/`, one branch page and API-01
(`/wp-json/momos/v1/branches/<CODE>/reservation-rules`), alert after **2 consecutive failures** (NFR-AVL-01) to HO duty
contact + vendor on-call; TLS certificate expiry < 14 days; the heartbeat checks of §2. Staging is not monitored
publicly (basic auth). Backup success/failure comes from the cron mail of `bin/backup.sh` (non-zero exit) and from
the host's own backup report ([backup-restore.md](backup-restore.md)).

## 6. Logs

- **momos log**: JSON lines `{ts, level, event, request, context}` to `MOMOS_LOG_FILE` (outside the web root), else to
  the PHP error log with the prefix `momos `. Level threshold `MOMOS_LOG_LEVEL` (default `info`).
- **Redaction** (`Kernel\Log\Redactor`, SEC-081): context keys `email`, `phone`, `phone_e164`, `guest_name`, `name`,
  `token`, `password`, `special_request`, `recipient_email`, `authorization`, `x-momo-token`, `secret` become
  `[redacted]`; free text is scrubbed of 43-character tokens, email addresses and phone numbers. Callers still must
  not pass personal data. If a log line with personal data is ever found, it is an incident (privacy, SEV-2).
- **Retention** (SEC-084): rotate daily, keep 90 days (e.g. logrotate `daily`, `rotate 90`, `compress`, mode 600);
  server access logs ≤ 90 days; audit log 24 months and activity 12 months in the database (retention job); Action
  Scheduler logs 30 days.
- **Request IDs**: every log line and audit row carries the request ID — use it to join a staff report, the audit log
  and the momos log.
