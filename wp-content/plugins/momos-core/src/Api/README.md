# Api

**Purpose.** The `momos/v1` REST API (TRD §4.3, SDD §2, `docs/api/momos-v1.md`): route registration, transport
guards (JSON body, Origin, status-token header) in SDD §5.2 order, error normalisation to the canonical
catalogue (DEC-063), response headers (`X-Request-Id`, `no-store`, `noindex`, rate-limit headers), one log line
per error response and per staff mutation (no bodies, no PII), and core REST hardening (`CoreRestGuard`: user
enumeration closed).

## Routes
| Method | Route | Controller | Auth |
|---|---|---|---|
| GET | `/branches/{code}/reservation-rules` | `RulesController` | public |
| POST | `/reservations` | `PublicReservationController` | public + Turnstile + rate limit |
| GET / POST | `/reservations/{ref}`, `/reservations/{ref}/cancel` | `PublicReservationController` | status token header |
| GET | `/staff/reservations`, `/staff/reservations/{id}` | `StaffReservationController` | `momo_view_reservations` + branch scope |
| POST | `/staff/reservations/{id}/transitions`, `/notes` | `StaffReservationController` | `momo_process_reservations` + scope |

Module-owned routes in the same namespace: Notification `…/notifications/{id}/resend`, Menu
`/staff/branches/{code}/menu-items[/{dish_id}]`, Access `/csp-report`.

## Public services
`Http\ErrorResponder` (domain exception → envelope), `Http\SchemaValidator`, `Http\Guards`,
`RateLimit\RateLimiter` (per IP / per email hash), `Captcha\TurnstileVerifier` (`MOMOS_TURNSTILE_MODE`).

## Hooks · Data · Jobs · CLI
No own hooks, tables, jobs or CLI; business rules live in Reservation services.

## Tests
`ReservationSubmissionTest`, `ReservationStaffTest`, `ReservationFrontendTest` (REST through `rest_do_request`).
