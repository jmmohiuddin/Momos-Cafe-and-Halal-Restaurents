# Deployment

How this codebase is installed and released. Sources: Doc 09 TRD §6.1–§6.7 and §6.10, Doc 10 SDD §8, Doc 11 §6.8,
`bin/build-release.sh`, `config/wp-config.production.sample.php`, `config/server/README.md`. Rollback is in
[rollback.md](rollback.md); backups are in [backup-restore.md](backup-restore.md).

## 1. Hosting prerequisites

| Item | Requirement | Where it is used / checked |
|---|---|---|
| PHP | 8.3 (8.4 after a staging soak, TRD §6.11). Extensions `intl mbstring sodium openssl json fileinfo` + `mysqli zip gd` | `Kernel\DependencyCheck` (admin notice, `wp momos smoke` fails) |
| Database | MariaDB 10.11 or MySQL 8.0/8.4, InnoDB, `utf8mb4`. DB user limited to `SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, LOCK TABLES, REFERENCES` (SDD §6.8) | `wp momos migrate` creates the 8 tables and FK/CHECK constraints; smoke checks both |
| WordPress | Core **7.1.5**, the version pinned in `composer.json` (`johnpbloch/wordpress-core`); never a different version than CI tested | [updates-and-patching.md](updates-and-patching.md) |
| Web server | LiteSpeed/Apache 2.4 (Hostinger) or nginx. Install `server/*.conf` from the release (HTTPS + canonical-host 301, deny rules, no PHP in uploads) | `config/server/README.md` |
| TLS / CDN / WAF | TLS 1.2/1.3, Full (strict) origin TLS when a CDN is used (never "Flexible"); WAF with XML-RPC blocked | Host/CDN; GATE-07 |
| Real cron | `DISABLE_WP_CRON = true` and a server crontab entry every minute (§3) | Jobs page in Settings → Monitoring; `wp momos jobs list` |
| Transactional email | SMTP relay of the email provider (DEC-018), From address on the brand domain with SPF, DKIM and DMARC aligned | `WpMailTransport` refuses to send in production without SMTP config |
| Cloudflare Turnstile | Production site key + secret for the reservation and contact forms | `DependencyCheck` (smoke fails without them) |
| Google Tag Manager | Container ID `GTM-…` (constant `MOMOS_GTM_ID` or Settings → Analytics) | No GTM is loaded when absent or invalid |
| Off-site storage | Momo's-owned bucket (rclone remote) + GPG public key for encrypted backups | [backup-restore.md](backup-restore.md) |
| Uptime monitor | External HTTP monitor + heartbeat (dead-man's switch) URLs | [monitoring.md](monitoring.md) |
| Shell | SSH + WP-CLI 2.11+ for the deploy user; `gzip`, `tar`, `sha256sum`, `gpg`, `rclone`, `flock` | deploy and backup scripts |

## 2. Configuration and secrets (names only — values live in the host secret store / password manager)

`wp-config.php` is made from `config/wp-config.production.sample.php`; it reads every value with `getenv()`.
Secrets are never committed, never stored in options, never sent by email or chat (Doc 11 §6.8).

| Constant | Required | Notes |
|---|---|---|
| `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_HOST` (env `MOMOS_DB_*`), `$table_prefix` (env `MOMOS_TABLE_PREFIX`, not `wp_`) | yes | unique per environment |
| `AUTH_KEY`, `SECURE_AUTH_KEY`, `LOGGED_IN_KEY`, `NONCE_KEY`, `AUTH_SALT`, `SECURE_AUTH_SALT`, `LOGGED_IN_SALT`, `NONCE_SALT` (env `WP_*`) | yes | rotating them logs everyone out |
| `MOMOS_TOKEN_KEY_V1` (+ `MOMOS_TOKEN_KEY_V2…V9`, `MOMOS_TOKEN_KEY_CURRENT`) | yes | status-link HMAC key, 32 bytes base64 (DEC-064); rotation in [incident-response.md](incident-response.md) §5 |
| `MOMOS_HASH_PEPPER` (legacy alias `MOMOS_HMAC_KEY` — define only one) | yes | IP/UA/email/rate-limit/audit-chain HMAC; hashing **fails closed** without it |
| `MOMOS_2FA_KEY` | yes | encrypts TOTP secrets; without it an admin notice warns and a salt-derived key is used |
| `MOMOS_TURNSTILE_SITE_KEY`, `MOMOS_TURNSTILE_SECRET` | yes | production is always `live` mode |
| `MOMOS_MAIL_TRANSPORT` = `smtp`, `MOMOS_SMTP_HOST`, `MOMOS_SMTP_PORT`, `MOMOS_SMTP_SECURE`, `MOMOS_SMTP_USER`, `MOMOS_SMTP_PASS` | yes | optional `MOMOS_SMTP_TIMEOUT`, one-shot fallback relay `MOMOS_SMTP_FALLBACK_HOST/PORT/SECURE/USER/PASS` |
| `MOMOS_MAIL_FROM` | yes | the From address read by `Notification\Transport\MailIdentity`. Without it mail goes out as `reservations@<site host>`. Optional `MOMOS_MAIL_BOUNCE` (envelope sender), `MOMOS_MAIL_DOMAIN` (Message-ID domain) |
| `MOMOS_EVIDENCE_DIR` | recommended | certificate evidence outside the web root (default `{ABSPATH}/../momos-private/evidence`); must be in backups |
| `MOMOS_LOG_FILE`, `MOMOS_LOG_LEVEL` | recommended | JSON-lines log outside the web root (else PHP error log) |
| `MOMOS_CACHE_ADAPTER` (`litespeed` \| `cloudflare` \| `null`), `MOMOS_CF_ZONE_ID`, `MOMOS_CF_API_TOKEN` | per host | page-cache purges (`Kernel\Cache\CacheInvalidator`) |
| `MOMOS_TRUSTED_IP_HEADER` + `MOMOS_TRUSTED_PROXIES` | behind a CDN | both or neither (smoke fails on the header alone) |
| `MOMOS_HEARTBEAT_URL`, `MOMOS_HEARTBEAT_URL_<JOB>`, `MOMOS_HEARTBEAT_URL_EXPIRY` | yes (prod) | see [monitoring.md](monitoring.md) §2 |
| `MOMOS_GTM_ID` | optional | wins over the Settings value |
| `WP_ENVIRONMENT_TYPE` / `MOMOS_ENV`, `DISALLOW_FILE_EDIT`, `DISALLOW_FILE_MODS`, `DISALLOW_UNFILTERED_HTML`, `AUTOMATIC_UPDATER_DISABLED`, `WP_AUTO_UPDATE_CORE=false`, `FORCE_SSL_ADMIN`, `WP_DEBUG*`, `DISABLE_WP_CRON` | fixed | as in the sample; smoke fails if the three `DISALLOW_*` are missing in production |
| `MOMOS_MAIL_SANDBOX`, `MOMOS_FROZEN_NOW`, `MOMOS_TURNSTILE_MODE=test` | **never in production** | sandbox is ignored in production; staging only |

## 3. Server cron

```cron
# Every minute: Action Scheduler (group momos) then due WP-Cron events. flock prevents overlapping runs.
* * * * *  flock -n /tmp/momos-cron.lock sh -c 'wp --path=/var/www/html action-scheduler run --group=momos --quiet && wp --path=/var/www/html cron event run --due-now --quiet'
# Backups (server clock in UTC): DB every 4 h, full daily 04:45 JST — see backup-restore.md
15 */4 * * *  /opt/momos-ops/bin/backup.sh --db-only
45 19 * * *   /opt/momos-ops/bin/backup.sh
```

`MAILTO=` on the crontab goes to the ops mailbox so a non-zero exit is noticed. Recurring jobs (registered by the
modules with `Kernel\Jobs\Schedule`): `momos_job_notification_dispatch` 1 min · `expire_pending`, `sla_check`,
`notification_sweeper` 5 min · `offer_expiry`, `soldout_reset` 15 min · `access_expiry`, `certificate_expiry`,
`reservation_integrity`, `retention_watch` 60 min · `audit_retention`, `branch_integrity` daily · `retention` 03:15 JST ·
`seo_404_retention` 04:20 JST. Check with `wp momos jobs list` (states `ok` / `late` / `never`).

## 4. First install (production or staging)

1. Create the database and least-privilege user; put the secrets into the host secret store (§2).
2. Install WordPress core at the pinned version: `wp core download --version=7.1.5 --locale=ja`; create `wp-config.php`
   from the sample; `wp core install --url=https://<host> --title="Momo's Cafe & Halal Restaurant" --admin_user=<named
   SA login, not admin> --admin_email=<IT owner>`.
3. Build and unpack the release (§5 step 1–3), then `wp option update timezone_string Asia/Tokyo`,
   `wp rewrite structure '/news/%postname%/'`, `wp theme activate momos`.
4. `wp momos migrate` (exits non-zero while anything is pending) → `wp momos jobs sync` → `wp rewrite flush`.
5. Install the server rules (`config/server/README.md` "Release install") and the crontab (§3).
6. Settings (Momo's → Settings, SA): alert recipients, HSTS ramp start (300), CSP report-only, GTM ID, retention values
   approved by counsel; Languages tab: ja + en only until GATE-04; Notifications: HO escalation list.
7. `wp momos privacy consent activate <PPx.y-RPx.y>` once counsel has approved the texts (IMPL-006).
8. Copy `bin/backup.sh` and `bin/restore.sh` of the same tag to the ops directory (outside the web root) and run one
   backup by hand. The release zip does not contain `bin/`.
9. `wp momos smoke` and the HTTP checks of §6 step 9. Never seed demo content in production (`wp momos seed` refuses).

## 5. Release by tag (through staging)

| # | Step | Command / action |
|---|---|---|
| 1 | Tag `vX.Y.Z-rc.N`; CI green (static, PHPUnit, E2E) | GitHub Actions `CI` |
| 2 | Build the artifact for the target host | `MOMOS_CANONICAL_HOST=<host> bin/build-release.sh` → `build/momos-<version>.zip` + `.sha256`. Staging gets its own build (its own host). Regenerate the maintenance page first if brand/languages changed: `bin/wp momos maintenance-page` |
| 3 | Deploy to **staging**, run steps 5–10 there, run the release validation checklist (TRD §6.10) | sign-off by tech lead (+ HO for content changes) |
| 4 | Production pre-checks | latest backup < 4 h (`ls -t $MOMOS_BACKUP_DIR \| head -1`); HO informed of any window |
| 5 | Pre-deploy snapshot | `/opt/momos-ops/bin/backup.sh --db-only` (kept 30 days) |
| 6 | Maintenance page **only** for a non-additive migration | `wp maintenance-mode activate` (serves `wp-content/maintenance.php`, 503 + `Retry-After`) |
| 7 | Upload and switch | upload the zip to `releases/<tag>/`, `sha256sum -c momos-<version>.zip.sha256`, unzip, switch the `mu-plugins`, `plugins/momos-core`, `themes/momos` symlinks to the new release; install changed `server/*.conf` |
| 8 | Schema and jobs | `wp momos migrate` then `wp momos jobs sync` (both must exit 0) |
| 9 | Caches | `wp cache flush`; `wp momos cache purge --all` (LiteSpeed tags or Cloudflare URLs, per `MOMOS_CACHE_ADAPTER`); CDN purge in the CDN console if no adapter |
| 10 | Maintenance off | `wp maintenance-mode deactivate` |
| 11 | Smoke | `wp momos smoke` (tables, constraints, roles, secrets, hardening constants, plugin register ≤ 15, no published samples, no late job, no failed job in the last hour) · `wp momos content sample-check` (exit 0) · HTTP: `curl -sI https://<host>/`, `/en/`, one branch, one dish, `/wp-json/momos/v1/branches/<CODE>/reservation-rules` (API-01) → 200; `curl -sI https://<host>/readme.html` → 403; `curl -sI http://<host>/` → one 301 · TEST-branch reservation smoke (Doc 12 §8.5, ≤ 15 min) |
| 12 | Record | tag, time, approver, migration type, rollback target in the release notes; notify HO |

Never: copy the staging database to production after launch (erases live reservations), edit code on the server,
install or activate a plugin outside the release (`DISALLOW_FILE_MODS` blocks it; the plugin register must list it).

## 6. Staging rules (FR-FND-002, TRD §6.1)

- `WP_ENVIRONMENT_TYPE=staging` (and `MOMOS_ENV=staging`): every response carries `X-Robots-Tag: noindex, nofollow`
  (`Access\Http\SecurityHeaders` / SEO module, IMPL-008) and robots.txt disallows all; also `wp option update blog_public 0`.
- HTTP basic auth in front of the whole site: install `config/server/htaccess-staging-auth.conf` (Apache/LiteSpeed) or
  `config/server/nginx-staging-auth.conf` (nginx) with the htpasswd path filled in. Equivalent manual rules — Apache/LiteSpeed:
  `AuthType Basic` / `AuthName "Staging"` / `AuthUserFile <path outside web root>/.htpasswd` / `Require valid-user`
  at the top of `.htaccess`; nginx: `auth_basic "Staging"; auth_basic_user_file <path>;` in the server block.
  Keep `/.well-known/acme-challenge/` open. Playwright against staging needs `httpCredentials`.
- Email: define `MOMOS_MAIL_SANDBOX` (a test inbox). Outside production every subject gets the `[STAGING] ` prefix and,
  with the sandbox set, every message is rerouted to it (`MailIdentity::finalize`). **Without the sandbox constant,
  staging mail goes to the real recipient addresses** — never leave it undefined.
- Turnstile staging keys (or `MOMOS_TURNSTILE_MODE=test`); GTM preview / GA4 test property only; separate secrets.
- Data: synthetic only. A production copy may be used only after `wp momos sanitize --yes` (refused in production;
  rewrites guest names/emails/phones, notification recipients, user emails and passwords, removes 2FA secrets and
  sessions, audits `environment.sanitized`, fails if any real email remains). Better: never copy production at all.

## 9. Preview / staging site on shared hosting (Hostinger "Import WordPress website")

`MOMOS_EXPORT_URL=https://<site>.hostingersite.com bin/export-site.sh [--no-photos]` builds `build/export-<host>/`:
`site.zip` (WordPress core, momos-core with production dependencies, theme, mu-loader, this site's media, and a
**staging** `wp-config.php` with random salts and secrets), `dump.sql` (freshly seeded fictional demo content with the
target URL), and `credentials.txt` (random passwords for every account — hand over privately). The preview never
sends email (log transport, files under `../momos-private/mail`), uses the Turnstile test mode, and is noindex.
Import both files with the host's WordPress importer (it fills the DB_* values; if not, edit `wp-config.php`).
Verified locally: import into a clean directory + empty database → all customer pages 200 in six languages,
`X-Robots-Tag: noindex, nofollow`, admin login with a generated password; the development password is refused.
This is a preview only — production follows sections 1–7 and docs/RELEASE_CHECKLIST.md.
