# Branch

**Purpose.** Branches (`momo_branch` CPT, field groups G4–G6), weekly hours and dated closures, branch
operating status, notices (`momo_notice`) and offers (`momo_offer`) with scope/validity, branch visibility
(public / hidden TEST branch) and the branch publish validator (GATE-01 facts). Spec `docs/specs/branch.md`.

## Public services
- `Repository\BranchRepository` — `get()`, `find_by_code()`, `published_ids()`, `public_ids( $lang )`,
  `is_public()`, `is_test()`, `name()`, `operating_status()`, `has_reservations()`.
- `Repository\BranchSettingsRepository` — reservation settings per branch, `active_recipients()` (E07–E11).
- `Service\ScheduleService` — `periods_on()`, `is_closed_on()`, `closure_on()`, `open_status()`, `week()`,
  `is_open_at()`, `intervals()`, `end_of_business_day()`, `schedule_json()` (all in Asia/Tokyo).
  Request cache (NFR-PERF-02): the first lookup in the open-now window (JST yesterday … +8) loads every
  branch's closures for the window in one query and every branch's weekly rows in one query; other dates
  load 31 days per branch. `prime( array $branch_ids, string $from_ymd, string $to_ymd )` preloads an
  explicit range (e.g. a calendar). Writes through `HoursRepository`/`ClosureRepository` call `flush()`.
- `PostType\BranchScopeTaxonomy::prime_posts( $ids )` — posts, meta, scope terms, term meta and scoped
  branches of a notice/offer list in a fixed number of queries (used by `OfferQuery`/`NoticeQuery`);
  `BranchRepository::published_ids()` primes the branch posts and meta.
- `Service\ClosureImpactService` — pending/confirmed reservations affected by a new closure.
- `Query\OfferQuery`, `Query\NoticeQuery` — scoped, time-valid queries for the theme.

## Hooks
- Fires after commit: `momos/branch/schedule_changed`, `momos/branch/settings_changed`,
  `momos/branch/identity_changed`, `momos/branch/accepting_changed`, `momos/offer/visibility_changed`;
  also `momos/cache/purge`, `momos/access/denied`, `momos/branch/integrity_check`, filter `momos/branch/slot_valid`.
- Consumes the kernel field hooks (`momos/fields/saved|denied|validate_group/*|publish_errors/*|taxonomy_choices`).

## Data
CPTs `momo_branch`, `momo_notice`, `momo_offer`; taxonomy `momo_branch_scope`; branch meta `momo_branch_code`,
`momo_res_*` settings, `momo_res_notification_recipients`; tables `momo_branch_hours`, `momo_branch_closures`.

## Capabilities
Branch fields are split by aspect: `momo_edit_branch_identity` (HO), `momo_edit_branch_hours`,
`momo_edit_branch_settings`, plus the meta cap `momo_edit_branch` (scope check, Access M2). Branch Managers edit
only their assigned branches; identity fields (name, slug, status, order) are HO-only and denied edits are audited.

## Admin screens
Branches list/editor (`edit.php?post_type=momo_branch`), **Branches → Hours & closures** (`momos-hours`),
Notices, Offers.

## Jobs
`momos_job_branch_integrity`, `momos_job_offer_expiry`, `momos_purge_scheduled` (scheduled cache purges at
validity boundaries).

## Tests
`Branch*Test.php` (hours validator, schedule, closures, notices, offers, publish, settings).
