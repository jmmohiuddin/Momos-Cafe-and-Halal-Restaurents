# Dietary

**Purpose.** Halal, alcohol and allergen information that must equal approved records (F-DIET-01/02, GATE-02;
spec `docs/specs/menu-dietary.md`): allergen reference (M0003), approved statement templates (G2), branch
dietary group (G7), dish dietary & allergens (G10), the halal certificate register (G14) with a private
evidence store, claim guards and the certificate expiry job.

## Public services
- `Service\ClaimResolver` — the claim a page may show for a branch/dish (unverified data → banner).
- `Service\AllergenPresenter` — allergen table for the theme (contains / may contain / not verified).
- `Service\StatementRepository` — approved statement templates per language.
- `Service\CertificateService` — certificate status, scope, expiry.
- Guards: `ClaimFieldGuard` (only CAP-DCA holders), two-person rule (source person ≠ approver), `ClaimTagGuard`,
  `IngredientChangeWatcher` (re-review on ingredient change), `WordingGuard` (prohibited terms), `CertificateGuard`.

## Hooks
- Fires: `momos/dietary/claims_changed`, `momos/dietary/review_required`, `momos/certificate/status_changed`,
  `momos/certificate/expiry_alert`, filters `momos/evidence/dir`, `momos/access/user_active`.
- Consumes: `momos/fields/denied`, `momos/fields/validate_group/*`.

## Data
CPT `momo_certificate`; taxonomies `momo_allergen` (29 seeded items from `config/allergens.php` + custom), `momo_dietary_tag`; dish allergen meta `momo_alg_{code}`; dish/branch claim meta (with
`verified_by`, `verified_at`, source); evidence files outside the web root (`MOMOS_EVIDENCE_DIR`, PDF/JPEG/PNG ≤ 10 MB, served only by the authenticated download handler).

## Capabilities
`momo_edit_dietary_claims` (per-user grant, CAP-DCA), `momo_manage_certificates`, `read_private_momo_certificates`,
meta cap `momo_download_evidence`.

## Admin screens
**Momo's → Halal register** (`momo-halal-register`), **Dietary statements** (`momo-dietary`),
**Claim-term scan** (`momo-claim-scan`), dish editor box "Dietary & allergens".

## Jobs
`momos_job_certificate_expiry` (alerts before/at expiry, heartbeat).

## Tests
`DietaryTest`, `DietaryCertificateTest`.
