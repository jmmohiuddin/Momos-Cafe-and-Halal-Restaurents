# Seed

**Purpose.** Fictional demo/sample content for development, E2E and demos (NFR-MNT-02, DEC-047,
content-strings.md §10): branches, menu, dishes, offers, notices, pages, staff users and sample reservations,
all flagged `momo_is_sample = 1`; demo images flagged `momo_image_illustrative = 1`. CLI only — no hooks on web
requests. Refused in production.

## Public services
`DemoSeeder` (idempotent create/update), `Resetter` (removes only sample content and restores what it changed),
`Writer` (upserts keyed by a stable seed key), `Media` (UI-baseline photos or GD placeholders), `SampleCheck`.

## Registration
Not in `Kernel\Plugin::MODULES` yet: load it with
`bin/wp --require=wp-content/plugins/momos-core/src/Seed/cli-bootstrap.php momos seed …` (adds `SeedModule`
through the `momos/modules` filter). `tests/e2e/fixtures/seed.sh` does this.

## CLI
- `wp momos seed --demo | --reset [--photos=<dir> | --no-photos]`.
- `wp momos content sample-check` — go-live gate: exit 1 when a sample item is published or a demo image is
  used outside dish/offer contexts (target 0 before launch, GATE-02).

## Data · Capabilities · Jobs
Writes through the normal services (FieldRegistry, Branch/Menu repositories) so validation and audit apply.
Demo staff logins: see `tests/e2e/fixtures/seed.sh`. No capabilities or jobs of its own.

## Tests
`SeedDemoTest`; E2E `seed-demo.spec.ts`.
