# Access

**Purpose.** Roles and capabilities, central meta-capability mapping, branch scope, staff user lifecycle,
TOTP two-factor login, login throttling, password/session policy, WordPress hardening, security headers and
WordPress-level activity logging. Requirements: F-ADM-02, F-SEC-01, SEC-001…015, TRD §2.7
(`docs/specs/access-security-privacy.md`).

## Public services
- `BranchScope` / `momos_user_branch_ids( ?int $user_id )` (`null` = all branches) and
  `momos_user_can_branch( int $branch_id )` (`src/functions.php`) — the only way other modules resolve scope.
- `Users\AssignmentService::set( $user_id, list<int> $branch_ids, $actor_id )` — branch assignments
  (`momo_user_branches`); `Users\AccountService` — disable/enable, access expiry.
- `MetaCapMapper` — rules M1–M12 on `map_meta_cap` (fails closed with `do_not_allow`).
- `TwoFactor\TwoFactor`, `TwoFactor\LoginFlow` — enrolment, challenge, recovery codes.
- `Roles\RoleInstaller` — installs roles/caps from `config/capabilities.php` (migration `M0004_Roles`).

## Hooks
- Fires: `momos/user/disabled`, `momos/monitoring/alert` (lockouts, CSP bursts), `momos/audit/log` via `momos_audit()`.
- Consumes: `momos/user/assignment_changed`, filter `momos/access/user_branch_ids`; adds a step to
  `momos/privacy/retention_steps`; core `map_meta_cap`, `authenticate`, `wp_login`, `set_user_role`, `send_headers`.

## Data
- Table `momo_user_branches` (user ↔ branch). User meta: 2FA secret (encrypted, `MOMOS_2FA_KEY`), recovery
  code hashes, `momo_access_expires_at`, account status. No customer data.

## Capabilities
Roles: Head Office Administrator, Menu Manager, Content Editor, Translator, Branch Manager, Reservation Staff
(+ WordPress administrator = Super Admin). Scoped roles: Branch Manager, Reservation Staff. Per-user cap:
`momo_edit_dietary_claims`. Vendor accounts are denied PII/reservation caps (`config/capabilities.php`).
`momo_manage_staff` gates the user screens.

## Jobs · REST · CLI
- Job `momos_job_access_expiry` (disables accounts past `momo_access_expires_at`).
- REST `POST /momos/v1/csp-report` (CSP violation reports, rate limited, no body logged).
- CLI: none (user management is in wp-admin → Users).

## Tests
`tests/phpunit/integration/Access*Test.php` (roles, meta caps, login/2FA, users).

## Known TBDs
2FA enforcement is controlled by `MOMOS_2FA_ENFORCE` (off in development, on in production — GATE-07).
