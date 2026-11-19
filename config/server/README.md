# Web-server rules (`config/server/`)

Rules that WordPress cannot enforce itself because they must run before PHP: HTTPS and canonical host
(FR-FND-004, Doc 11 SEC-050, TC-SEC-005) and the deny rules for files that must never be served (SEC-028,
SEC-042, access-security-privacy §9.3). The development server (`bin/serve.sh`) applies the same deny rules
in `bin/router.php`; it has no TLS, so the HTTPS rules are verified on staging (TC-SEC-005, GATE-07).

| File | Server | Installed as |
|---|---|---|
| `htaccess-root.conf` | Apache 2.4 / LiteSpeed (Hostinger) | top of `/.htaccess`, **above** `# BEGIN WordPress` |
| `htaccess-uploads.conf` | Apache / LiteSpeed | `wp-content/uploads/.htaccess` (the release zip already contains it) |
| `htaccess-deny-all.conf` | Apache / LiteSpeed | `.htaccess` of a private directory that has to live in the web root (evidence fallback, backups, logs) |
| `nginx-momos.conf` | nginx | `include` inside the HTTPS `server { … }` block of the canonical host, before `location ~ \.php$` |
| `nginx-redirects.conf` | nginx | `include` at `http { }` level (port-80 server and the HTTPS alias server) |
| `htaccess-staging-auth.conf` | Apache / LiteSpeed | **staging only**: very top of `/.htaccess`; replace `REPLACE_WITH_ABSOLUTE_HTPASSWD_PATH` with the absolute path of an htpasswd file outside the web root |
| `nginx-staging-auth.conf` | nginx | **staging only**: `include` in the staging HTTPS `server { }` block; same placeholder |

## HTTPS and canonical host

- Every `http://` request and every non-canonical host name (`www.`, the bare IP, an old domain pointed at the
  site) answers with **one** `301` to `https://<canonical host><same path and query>` — no chain with the
  WordPress normalisation 301 (lowercase/trailing slash) because that one runs on the HTTPS host only.
- The canonical host is the host of `siteurl`/`home`. `bin/build-release.sh` fills the placeholders
  `__MOMOS_CANONICAL_HOST__` / `__MOMOS_ALIAS_HOSTS__` in `server/*.conf` of the release:

  ```bash
  MOMOS_CANONICAL_HOST=example.com bin/build-release.sh           # aliases: www.example.com
  MOMOS_CANONICAL_HOST=staging.example.com bin/build-release.sh   # staging build (its own host)
  MOMOS_CANONICAL_HOST=example.com MOMOS_ALIAS_HOSTS="www.example.com old-domain.example" bin/build-release.sh
  ```

  Without `MOMOS_CANONICAL_HOST` the build warns and the placeholders stay; never install a file that still
  contains `__MOMOS_`. Staging uses its own build (its own host) — the production rule would send staging
  traffic to production.
- `/.well-known/acme-challenge/` stays reachable over HTTP for certificate renewal.
- TLS terminates on the origin (Hostinger) or the CDN with **Full (strict)** origin TLS, so the origin sees
  `HTTPS=on`. A CDN "Flexible" mode (HTTP to the origin) is not allowed: the redirect would loop and
  WordPress could not detect HTTPS (`is_ssl()`). TLS 1.2/1.3 only, SSL Labs grade A (SEC-050) — host/CDN setting.

## HSTS — one source

`Strict-Transport-Security` is sent by the application only: `Momos\Access\Http\SecurityHeaders` adds
`max-age=<G3 ramp>` (300 → 86,400 after 7 clean days → 31,536,000 after 30 days; Settings → Security) to every
response that WordPress serves over HTTPS (`is_ssl()`). The server files deliberately do **not** set HSTS, so
the ramp is changed in one place and a server/CDN value can never contradict it. If a CDN is configured to add
HSTS anyway, it must use the same value and no `includeSubDomains`/`preload` until SEC-050 allows it.
`tests/phpunit/integration/KernelServerConfigTest.php` guards this (redirect present, no HSTS in these files,
HSTS header only on HTTPS).

## Release install (TRD §6.7)

1. `MOMOS_CANONICAL_HOST=<host> bin/build-release.sh` → `build/momos-<version>.zip` (contains `server/`).
2. Apache/LiteSpeed: paste `server/htaccess-root.conf` above `# BEGIN WordPress` in `/.htaccess` (replace the
   previous Momo's block); `wp-content/uploads/.htaccess` comes with the zip. nginx: copy `server/nginx-*.conf`
   to the server config, include them as in the table, `nginx -t && systemctl reload nginx`.
3. Check (staging first, then production):
   `curl -sI http://<host>/menu/` and `curl -sI https://www.<host>/menu/` → one `301` to `https://<host>/menu/`;
   `curl -sI https://<host>/` → `200` with `Strict-Transport-Security: max-age=<ramp>`;
   `curl -sI https://<host>/readme.html` → `403`.
