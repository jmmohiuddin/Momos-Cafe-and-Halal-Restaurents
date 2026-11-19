# Updates and patching (FR-SEC-009)

Sources: Doc 09 TRD §6.3 and §6.11, Doc 11 SEC-060…064, DEC-032, DEC-065, IMPL-001/004, `composer.json`,
`wp-content/plugins/momos-core/composer.json`, `config/plugin-inventory.php`, `.github/workflows/security-weekly.yml`.
Releases themselves follow [deployment.md](deployment.md) §5.

## 1. What can change and where it is pinned

| Component | Pinned in | Production rule |
|---|---|---|
| WordPress core | `composer.json` → `johnpbloch/wordpress-core` **7.1.5** (exact; CI and PHPUnit run against it) | production core = this version; automatic and dashboard updates are off (`AUTOMATIC_UPDATER_DISABLED`, `WP_AUTO_UPDATE_CORE=false`, `DISALLOW_FILE_MODS`) |
| momos-core runtime libraries | `wp-content/plugins/momos-core/composer.json` + `composer.lock` (Action Scheduler ^3.9, libphonenumber-for-php-lite ^8.13) | shipped inside the release zip (`composer install --no-dev` in `bin/build-release.sh`) |
| Tooling (PHPUnit, PHPStan, PHPCS, WP-CLI) | root `composer.json` / `composer.lock` (dev only) | never deployed |
| E2E tooling | `tests/e2e/package-lock.json` | never deployed |
| Plugins / themes | `config/plugin-inventory.php` (the plugin register) | only momos-core (mu-loader) + theme `momos`; nothing else is installed (IMPL-001/004); `wp momos smoke` fails on any active plugin missing from the register or on > 15 plugins |
| PHP | host | 8.3; 8.4 after all components support it and a ≥ 1-week staging soak |
| Server rules | `config/server/` | shipped in the release (`server/`) |

## 2. Cadence

| Kind | When | Path |
|---|---|---|
| Monthly maintenance window | ⟦day/time — CTO; proposal: 2nd Tuesday, 15:00–17:00 JST, outside lunch/dinner service⟧ | WordPress minor, library updates, PHP patch releases → staging → production by tag |
| Critical / actively exploited security fix (any component) | **≤ 72 h** after the vendor fix is published (WAF virtual patch immediately when one exists, ≤ 24 h) | emergency release, abbreviated regression (§4) |
| Other security fixes | ≤ 14 days | next window or earlier |
| WordPress major | 1–2 weeks after release | full regression incl. RTL (ar, ur) |
| Vulnerability monitoring | weekly + vendor feeds | `Security (weekly)` workflow: `composer audit` (blocking), `npm audit` (tests/e2e), OWASP ZAP baseline of a throw-away CI site, WPScan when `WPSCAN_API_TOKEN` exists; findings triaged within 2 business days. Scanning staging/production needs the Owner's written authorisation |

## 3. Procedure (monthly or emergency)

1. **Check**: `COMPOSER_ALLOW_SUPERUSER=1 composer audit --locked` and `composer audit --locked -d wp-content/plugins/momos-core`;
   `composer outdated --direct`; the WordPress release notes / security advisories; the PHP changelog.
2. **Change in a branch** `fix/update-<what>`:
   - WordPress core: set the new exact version of `johnpbloch/wordpress-core` in `composer.json`,
     `composer update johnpbloch/wordpress-core`; `bin/dev-setup.sh` syncs `.wp/` to it.
   - momos-core libraries: `composer update <package> -d wp-content/plugins/momos-core` (no new dependency without the
     CTO, docs/ENGINEERING.md §5).
   - Update `version` and `last_review` in `config/plugin-inventory.php` (also refresh `last_review` of every row each
     monthly cycle, even without a version change).
3. **CI** must be green: static gates, the full PHPUnit suite, Playwright (incl. RTL, a11y and the isolation specs).
4. **Staging**: deploy the RC tag ([deployment.md](deployment.md) §5); run the post-update regression (TRD §6.10 item 3):
   home in all enabled languages, ar and ur pages, a dish, a branch, a category, offers, reservation submit → staff email →
   accept → customer email, admin login with 2FA, cache purge after a price change. Emergency releases may limit this
   to items 3, 7 and 11 of TRD §6.10 (regression subset, headers/TLS, post-deploy smoke).
5. **Production** in the window (emergency: as soon as staging passes). WordPress core on the server is replaced with
   the same pinned version (host panel or `wp core update --version=<pin>` by the deploy user — `DISALLOW_FILE_MODS`
   blocks the dashboard, not the deploy process; confirm the method on staging), then the release zip.
6. **After**: `wp core version` = pin; `wp core verify-checksums`; `wp momos smoke`; record versions, date and approver in
   the release notes and the maintenance log.

## 4. Emergency (≤ 72 h) checklist

- [ ] Advisory read; affected component and version confirmed against the pins above
- [ ] WAF/CDN virtual patch or rule (if available) — within 24 h
- [ ] Indicators of compromise checked (audit log, new users, file checksums — [incident-response.md](incident-response.md) §5);
      if exploited here → incident runbook
- [ ] Fix branch, CI green, RC on staging, regression subset passed
- [ ] Production deploy, smoke, record; HO informed

## 5. Plugin register (FR-FND-006, SEC-060)

`config/plugin-inventory.php` lists every component with slug, version, purpose, owner, licence, data sent to third
parties and `last_review`. It is shown in Settings ("Plugin register") and checked by `StatusPanel::unregistered_plugins()`
(admin notice + `wp momos smoke` failure). A new plugin needs: a change-register entry and CTO approval, a register row in
the same PR, no third-party data without counsel review, and the total stays ≤ 15. Removing a component: delete its row
in the same release.
