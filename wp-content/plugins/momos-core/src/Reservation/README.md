# Reservation

**Purpose.** Reservation **requests** (not instant bookings; F-RES-01…12, `docs/specs/reservation.md`): booking
rules and slot generation from branch hours, submission with idempotency, the state machine
(pending → confirmed / rejected / cancelled / expired → completed / no_show), staff actions with SLA clock and
flags, customer modification/cancellation through a signed status link, notes, reports and CSV export, and the
customer screens C09–C11 (`Front\CustomerPages`, theme templates).

## Public services
- `Repository\ReservationRepository` (`find()`, `find_by_ref()`, scoped lists), `Repository\EventRepository`.
- `Service\SubmissionService::submit()` / `replay()`, `Service\SlotGenerator` (`days()`, `slots_on()`,
  `is_valid_slot()`, `closed_reason()`), `Service\BookingRules`.
- `Service\TransitionService` (`staff()`, `customer_cancel()`, `attendance_open()`), `Service\ModificationService`,
  `Service\NoteService`, `Service\ExpiryService`, `Service\SlaClock`, `Service\FlagService`.
- `Service\TokenService` (`status_url()`, `verify()`, `reissue()` — HMAC tokens, `MOMOS_TOKEN_KEY_V{n}`).
- `Service\ReservationAccess` / `ActionPolicy` — branch scope + capability for every staff read/action.

## Hooks
- Fires after commit (`docs/CONTRACTS.md`): `momos/reservation/created`, `…/status_changed`, `…/modified`,
  `…/sla_reminder`, `…/sla_escalated`, `…/closure_conflict`, `…/token_reissued`, `…/integrity_checked`;
  `momos/admin/reservation_detail` on SCR-A09; `momos/cache/purge`.
- Consumes: `momos/branch/schedule_changed|settings_changed|accepting_changed`, filter `momos/branch/slot_valid`,
  `momos/notification/failed`, `momos/notification/resend_sent`, `momos/presentation/switcher_visible`.

## Data
Tables `momo_reservations` (PII columns nulled by Privacy retention; `pii_purged_at`) and
`momo_reservation_events` (append-only history). Public reference: 8-char Crockford code. Settings per branch
(Branch) and global (Settings → Reservations).

## Capabilities
`momo_view_reservations`, `momo_process_reservations`, meta caps `momo_view_reservation` /
`momo_process_reservation` (branch scope), `momo_view_customer_pii`, `momo_export_reservations`,
`momo_view_reservation_reports`, `momo_access_all_branches` (HO view across branches).

## Admin screens · REST · CLI
- **Reservations → Inbox** (`momos-reservations`, list + detail SCR-A08/A09), **Reservations → Report**
  (`momos-reservation-report`), CSV export (admin-post `momos_export`, row/range limits), staff actions
  (admin-post `momos_reservation_action`).
- REST routes are registered by the Api module (`docs/api/momos-v1.md`).
- `wp momos token reissue --key-version=<n>`, `wp momos reservations expire`, `wp momos reservations sla`.

## Jobs
`momos_job_expire_pending`, `momos_job_sla_check`, `momos_job_reservation_integrity`.

## Tests
`Reservation*Test.php`; E2E `reservation-lifecycle.spec.ts`.
