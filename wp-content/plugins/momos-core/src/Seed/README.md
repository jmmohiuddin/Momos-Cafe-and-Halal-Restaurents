# Seed

**Purpose.** Fictional demo/sample content for development, E2E and demos (NFR-MNT-02, DEC-047,
content-strings.md §10): branches, menu, dishes, offers, notices, pages, staff users and sample reservations,
all flagged `momo_is_sample = 1`; demo images flagged `momo_image_illustrative = 1`. The demo also approves the
neutral G2 statement templates (`brand.neutral`, `no_claim`, `branch_default`, `alcohol.served.*`; never a claim
template) in six languages as the CAP-DCA demo user, and unpublishes the WordPress install defaults
("Hello world!", "Sample Page"); both are restored by `--reset`. CLI only — the only web-request hook adds the
`content.samples_purged` audit action to the catalogue. Seeding is refused in production.

## Public services
`DemoSeeder` (idempotent create/update), `Resetter` (removes only sample content and restores what it changed),
`Writer` (upserts keyed by a stable seed key), `Media` (UI-baseline photos or GD placeholders), `SampleCheck`,
`PurgeSamples`.

## Registration
Listed in `Kernel\Plugin::MODULES`; `cli-bootstrap.php` (used by `tests/e2e/fixtures/seed.sh`) is harmless when
the module is already registered.

## CLI
- `wp momos seed --demo | --reset [--photos=<dir> | --no-photos]`.
- `wp momos content sample-check` — go-live gate: exit 1 when a sample item is published or a demo image is
  used outside dish/offer contexts (target 0 before launch, GATE-02).
- `wp momos purge-samples [--dry-run] [--yes]` (content-strings.md §10.1 D8): removes the seeded demo content
  through the Resetter (restoring edited pages/settings), then every other `momo_is_sample = 1` dish, offer,
  notice, menu category/tag/ingredient term and media item. Sample-flagged branches/pages the seed did not
  create are only reported (`skipped_*`). Production: `--yes` and a Super Administrator (`--user=`) required.
  Audited `content.samples_purged` (counts only).

## Data · Capabilities · Jobs
Writes through the normal services (FieldRegistry, Branch/Menu repositories) so validation and audit apply.
Demo staff logins: see `tests/e2e/fixtures/seed.sh`. No capabilities or jobs of its own.

## Tests
`SeedDemoTest`, `SeedPurgeSamplesTest`; E2E `seed-demo.spec.ts`.
