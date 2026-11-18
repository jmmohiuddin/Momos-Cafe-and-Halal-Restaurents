# momos/v1 REST API — reservations (API-01..04, API-10..13, API-16)

Base URL: `https://{host}/wp-json/momos/v1/`. Implementation: `wp-content/plugins/momos-core/src/Api/**`
(controllers) on top of `src/Reservation/**` (services). Spec: `docs/specs/reservation.md` §5.
The Presentation team builds SCR-C09/C10/C11 against the public routes below; the staff screens
(SCR-A08/A09) are server-rendered admin pages that use the same services and are progressively enhanced
with API-10 polling.

## Conventions

| Item | Rule |
|---|---|
| Format | JSON, UTF-8. Dates `YYYY-MM-DD`, times `HH:MM`, both **JST business-local**; instants ISO 8601 with `+09:00`. |
| Language | Public routes: `lang` (query or body) selects the message language; staff routes: the user's admin locale (ja/en). |
| Headers on every response | `X-Request-Id` (quote it in support requests). API-01: `Cache-Control: public, max-age=300, s-maxage=300`; every other route: `Cache-Control: no-store, private` + `X-Robots-Tag: noindex`. Rate-limited routes add `X-RateLimit-Limit` / `X-RateLimit-Remaining`; a 429 adds `Retry-After` (seconds). |
| POST/PATCH bodies | `Content-Type: application/json` (else 415), body ≤ 8 KB public / 16 KB staff (else 413), a JSON object (else 400 `invalid_json`). |
| Public POST | `Origin` (else `Referer`) host must equal the site host → else 403 `origin_rejected`. No CORS headers are ever added. |
| Status token | Only in the `X-Momo-Token` header (43 chars `[A-Za-z0-9_-]`). A `t` query/body parameter is rejected with 400 before any lookup (DEC-058). |
| Staff auth | WordPress login cookie + `X-WP-Nonce` (`wp_rest`). Missing login → 401 `rest_forbidden`; cookie without nonce / no capability → 403 `rest_forbidden`; reservation outside the user's branches → 403 `forbidden_branch` (audited, no data). |

### Error envelope (DEC-063)

```json
{ "code": "lead_time_not_met",
  "message": "This is too soon to request online. Please call 心斎橋店: 06-0000-0000.",
  "data": { "status": 422, "request_id": "0b6c…", "message_key": "res.err.time.too_soon",
            "fields": { "time": "out_of_range" },
            "branch_phone": { "e164": "+81600000000", "display": "06-0000-0000" } } }
```

`fields` reasons: `required`, `invalid_format`, `too_long`, `out_of_range`, `not_allowed` (slot errors use
`branch_closed_on_date`, `outside_opening_hours`, `after_last_order`, `not_a_slot` in `fields.time`).
409 conflicts on staff routes carry `data.current = {status, version, handled_by, updated_at, updated_by_display}`.
`data.args` carries placeholder values for `outside_booking_window` (`from`, `to`, `days`),
`pending_limit_reached` (`n`) and `party_size_out_of_range` (`min`, `max`).

| code | HTTP | Routes |
|---|---|---|
| `invalid_param` | 400 | all |
| `invalid_json` | 400 | POST/PATCH |
| `reason_required` | 400 | 12, 16 |
| `rest_forbidden` | 401/403 | 10–16 |
| `forbidden_branch` | 403 | 10–16 |
| `forbidden_field` | 403 | 16 (override by non-HO) |
| `captcha_failed` | 403 | 02 |
| `origin_rejected` | 403 | 02, 04 |
| `branch_not_found` | 404 | 01, 02 |
| `not_found` | 404 | 03, 04, 11–16 |
| `slot_unavailable` | 409 | 02, 16 |
| `pending_limit_reached` | 409 | 02 |
| `cancel_not_allowed` | 409 | 04 |
| `invalid_transition` | 409 | 04, 12, 16 |
| `version_conflict` | 409 | 12, 13, 16 |
| `link_expired` | 410 | 03, 04 |
| `payload_too_large` | 413 | POST/PATCH |
| `unsupported_media_type` | 415 | POST/PATCH |
| `branch_not_accepting` | 422 | 02, 16 (API-01 answers 200 with `accepting:false`) |
| `party_size_out_of_range` | 422 | 02, 16 |
| `lead_time_not_met` | 422 | 02, 16 |
| `outside_booking_window` | 422 | 02, 16 |
| `attendance_window` | 422 | 12 |
| `rate_limited` | 429 | 02, 03, 04, 10–16 |
| `internal_error` | 500 | all |
| `service_unavailable` | 503 | all (retry with the **same** idempotency key) |

Core codes are normalised (`rest_invalid_param` → `invalid_param`, `rest_invalid_json` → `invalid_json`,
`rest_not_logged_in` → 401 `rest_forbidden`, `rest_cookie_invalid_nonce` → 403 `rest_forbidden`).

Rate limits (sliding window, defaults configurable as options): `res_ip` 5/h, `res_ip_degraded` 2/h (while a
captcha outage was seen in the last 10 min), `res_email` 3/h, `status_ip` 30/h, `cancel_ip` 10/h,
`staff_user` 120/min.

---

## API-01 `GET /branches/{code}/reservation-rules?lang=en`

Public. `code` = `^[A-Z]{3}$`. A TEST branch answers 404 except to logged-in users assigned to it.

```json
{ "branch_code": "SHN", "branch_name": "Shinsaibashi", "accepting": true,
  "not_accepting_reason": null, "reopen_date": null, "is_test": false, "timezone": "Asia/Tokyo",
  "rules": { "party_min": 1, "party_max": 8, "lead_time_minutes": 120, "booking_window_days": 60,
             "slot_interval_minutes": 30, "response_promise_minutes": null },
  "contact": { "phone_e164": "+81600000000", "phone_display": "06-0000-0000" },
  "days": [
    { "date": "2026-12-24", "slots": ["17:30","18:00"], "periods": {"17:30":"dinner","18:00":"dinner"},
      "closed_reason": null, "closed_label": null },
    { "date": "2026-12-30", "slots": [], "periods": {}, "closed_reason": "maintenance", "closed_label": "Maintenance" },
    { "date": "2026-12-27", "slots": [], "periods": {}, "closed_reason": "closed", "closed_label": "Closed" } ],
  "generated_at": "2026-12-24T12:05:00+09:00" }
```

- `days` covers today … today + window (JST) with lead time applied; empty when `accepting:false`.
- `closed_reason`: closure public reason (`public_holiday|private_event|maintenance|staff_shortage|other`),
  `closed` for a regular closed weekday, `null` when the day only lost its slots to lead time (render
  "(too soon — please call)").
- `not_accepting_reason`: `temporarily_closed` | `reservations_disabled` | `global_disabled`.
- Slots after midnight belong to the next calendar date (e.g. Fri 18:00–01:00 → Sat `00:00`, `00:30`).

## API-02 `POST /reservations`

Request (`additionalProperties:false`):

```json
{ "branch_code": "SHN", "date": "2026-12-24", "time": "19:00", "party_size": 4,
  "name": "Aisha Rahman", "email": "aisha@example.com", "phone": "+60 12-345 6789",
  "special_request": "High chair please", "lang": "en", "preferred_lang": "en", "consent": true,
  "idempotency_key": "6f1c2a1e-6b1d-4f7a-9a54-0d2b5c1f9e11", "captcha_token": "<turnstile>", "website": "" }
```

- `party_size` may be an integer or a digit string (native digits accepted: `"৪"` → 4); `phone` optional,
  any format libphonenumber can parse with default region JP (stored E.164); `special_request` ≤ 1,000
  (tags stripped); `website` is the honeypot (must be empty); `special_request_consent` only when the
  option `momo_special_request_separate_consent` is on.
- Generate `idempotency_key` once per form load (`crypto.randomUUID()`), reuse it for retries.
- Check order (SDD §5.2): transport → origin → IP limit → honeypot → schema → idempotent replay →
  captcha → email limit → field rules → branch → accepting → party → window → lead time → slot →
  lock → soft duplicate → pending limit → insert.

Response **201**:

```json
{ "reference": "7Q2KX9MD", "reference_display": "7Q2K-X9MD", "status": "pending",
  "status_label": "Request received — not yet confirmed", "duplicate": false,
  "message": "The branch will review your request and reply by email. …",
  "summary": { "branch_code": "SHN", "branch_name": "…", "branch_address": "…", "branch_address_ja": "…",
               "date": "2026-12-24", "time": "19:00", "timezone": "Asia/Tokyo", "party_size": 4, "guest_name": "Aisha Rahman" },
  "email_masked": "a***@example.com", "decision_deadline": "2026-12-24T18:00:00+09:00",
  "branch_contact": { "phone_e164": "+81600000000", "phone_display": "06-0000-0000" },
  "response_promise_minutes": null, "carrier_email": false, "email_delivery_tip": null,
  "status_url": "https://example.com/en/reservation/status/7Q2KX9MD/#t=…" }
```

- **200 replay** (same key, same email and branch): identical body. The same key with another email/branch → 400
  `fields.idempotency_key = not_allowed`.
- **200 soft duplicate** (same email + branch + date + time within 10 min, pending/confirmed): `duplicate:true`,
  the existing reference, `message` = `res.info.duplicate`, **no `status_url`**.
- Push `generate_lead` only on 201. Store the body in `sessionStorage["momo_last_request"]` and navigate to C10.
- `carrier_email:true` → show `email_delivery_tip`.

## API-03 `GET /reservations/{public_ref}?lang=en` — header `X-Momo-Token`

`public_ref` accepts `7Q2K-X9MD`, lower case and O/I/L confusables.

```json
{ "reference_display": "7Q2K-X9MD", "status": "confirmed", "status_label": "Confirmed by the branch",
  "status_note": null,
  "branch": { "code": "SHN", "name": "…", "address": "…", "address_ja": "…", "phone_e164": "…",
              "phone_display": "…", "directions_url": "https://www.google.com/maps/dir/?api=1&destination=34.67,135.50" },
  "date": "2026-12-24", "time": "19:00", "timezone": "Asia/Tokyo", "party_size": 4,
  "guest_given_name": "Aisha", "guest_name": "Aisha Rahman", "email_masked": "a***@example.com",
  "special_request_received": true, "message_from_branch": "See you!", "decline_reason_label": null,
  "suggested_times": [], "cancelled_by": null, "can_cancel": true,
  "cancel_deadline": "2026-12-24T19:00:00+09:00",
  "history": [ { "status": "pending", "at": "…" }, { "status": "confirmed", "at": "…" } ] }
```

- `completed`/`no_show` are shown as `confirmed` + `status_note` (`status.past`); render `guest_given_name` only (C-8).
- `cancelled_by`: `customer` | `branch` | `system`. Rejected: `decline_reason_label`, `suggested_times`
  (`[{date,time}]`, link each to C09 with `?branch={code}&date=…&time=…`).
- Errors: 400 (missing/malformed header, `t` parameter), 404 `not_found` (unknown ref **or** wrong token),
  410 `link_expired` (start + 24 h passed), 429. Lazy expiry runs first.

## API-04 `POST /reservations/{public_ref}/cancel` — header `X-Momo-Token`, body `{"lang":"en"}`

200 with the API-03 shape (`status:"cancelled"`, `can_cancel:false`). 409 `invalid_transition` when the
request expired during this call; 409 `cancel_not_allowed` when already terminal (incl. already cancelled)
or at/after start − customer cutoff; 403 `origin_rejected`; 404; 410; 429.

---

## API-10 `GET /staff/reservations`

Capability `momo_view_reservations`, scoped to the user's branches. Query: `branch` (CSV codes),
`status` (CSV or `all`, default `pending`), `date_from`, `date_to`, `q` (2–100; reference with/without hyphen
or guest-name substring after NFKC), `flags` (CSV `needs_action,not_notified,overdue,unclaimed`),
`has_special_request` (`1`/`0`), `handled_by` (`me`|`none`), `since` (ISO instant → header
`X-Momo-New-Count`), `sort` (`requested_start`|`created_at`), `order`, `page`, `per_page` (1–50, default 20).

Headers: `X-WP-Total`, `X-WP-TotalPages`, `X-Momo-Status-Counts` (JSON per status in scope).

```json
[ { "id": 1842, "reference_display": "7Q2K-X9MD", "branch_code": "SHN", "branch_name": "…",
    "status": "pending", "requested_date": "2026-12-24", "requested_time": "19:00", "party_size": 4,
    "guest_name": "Aisha Rahman", "guest_given_name": "Aisha", "preferred_lang": "en", "handled_by": null,
    "created_at": "2026-12-20T10:00:00+09:00", "sla_state": "due",
    "flags": { "needs_action": false, "customer_not_notified": false, "carrier_email": false,
               "has_special_request": true, "captcha_unverified": false, "test_branch": false },
    "version": 1 } ]
```

Never contains email, phone or special-request text.

## API-11 `GET /staff/reservations/{id}`

List fields plus (PII only with `momo_view_customer_pii`) `email`, `phone_e164`, `special_request`;
`source_lang`, `consent_version`, `consent_at`, `decision_reason_code`, `message_to_customer`,
`suggested_times`, `requested_start`, `decision_deadline`, lifecycle timestamps, `cancelled_by`,
`captcha_status`, `events[]` (newest first: `type, from, to, actor_type, actor_display, reason, note, payload, at`),
`notifications[]` (`template, variant, language, status, attempts, recipient_type, recipient_masked, sent_at, failed_at, last_error`),
`allowed_actions[]` (subset of `accept, decline, cancel, mark_completed, mark_no_show, claim, release, note, modify, mark_contacted, flag_resolved`)
and `branch_settings_summary`. Audits `reservation.detail_viewed` (once per user per reservation per 30 min).

## API-12 `POST /staff/reservations/{id}/transitions`

```json
{ "action": "decline", "version": 1, "reason_code": "fully_booked", "message_to_customer": "Sorry!",
  "suggested_times": [ { "date": "2026-12-25", "time": "19:00" } ], "note": "optional internal note" }
```

- `accept` (message optional) · `decline` (reason ∈ `fully_booked, closed_or_private_event,
  party_size_not_supported, incomplete_information, other`; ≤ 2 future suggested times) · `cancel`
  (reason ∈ `customer_request_by_phone, branch_unable_to_serve, duplicate, suspected_spam, other`) ·
  `mark_completed` / `mark_no_show` (start ≤ now ≤ start + 72 h; HO any time after start).
- `reason_code = other` needs `message_to_customer` or `note` → else 400 `reason_required`.
- Accept/decline at or after start − decision cutoff → the request expires lazily → 409 `invalid_transition`
  with `data.message_key = staff.err.expired`.

Response 200:

```json
{ "id": 1842, "status": "rejected", "version": 2, "handled_by": { "id": 37, "name": "Staff A" },
  "notifications_queued": ["E03"], "rejected_at": "2026-12-20T10:05:00+09:00" }
```

`notifications_queued` is `[]` for `cancel` with `suspected_spam` and for attendance.

## API-13 `POST /staff/reservations/{id}/notes`

`{"type":"note|claim|release|customer_contacted|flag_resolved","text":"≤1000","version":3}` — `version`
required except for `note`; `text` required for `note` and `flag_resolved`. Response 201:
`{"event_id":9921,"type":"claim","at":"…","actor":"Staff A","version":4}`. Claiming somebody else's request
is a takeover (the UI confirms first; the event records the previous handler).

## API-16 `PATCH /staff/reservations/{id}`

```json
{ "version": 2, "requested_date": "2026-12-25", "requested_time": "18:30", "party_size": 5,
  "reason": "agreed_with_guest", "note": null, "override_rules": false, "override_reason": null }
```

Re-validates accepting/party/window/lead time/slot. `override_rules:true` (+ `override_reason` 1–200) only for
`momo_access_all_branches` users (else 403 `forbidden_field`) and waives party range, window and lead time —
never closures, opening periods, last order or slot grid. Response 200 = API-11 detail +
`"notifications_queued":["E06"]`.

## CSV export (not REST): `POST /wp-admin/admin-post.php?action=momos_export`

Capability `momo_export_reservations`, nonce `momos_export`, `branch[]`, `status[]`, `date_from`, `date_to`
(≤ 12 months), `reason` (1–200, required), `include_special_requests`. UTF-8 BOM CSV streamed, formula-safe,
≤ 10,000 rows, TEST rows only when the TEST branch is selected explicitly; audited `reservation.exported`
(+ `reservation.export_with_notes`).

## Domain hooks (after commit)

`momos/reservation/created (int $id)`, `momos/reservation/status_changed (int $id, string $from, string $to, array $actor)`,
`momos/reservation/modified (int $id, array $before, array $after, array $actor)`, `momos/reservation/sla_reminder (int $id)`,
`momos/reservation/sla_escalated (int $id)`, `momos/reservation/closure_conflict (int $id)`,
`momos/reservation/token_reissued (int $id)`.
