/**
 * Data-subject request tool SCR-A19 E2E (Access spec §10.5/§13.1; TC-PRV-006, AC-PRV-03-01…03, FR-PRV-005):
 *  1. Head Office searches by email: the email never appears in the URL; the result table lists the
 *     guest's reservations across branches without contact data; export downloads JSON with a
 *     non-personal file name and the guest's data.
 *  2. Anonymise: a wrong typed reference changes nothing; the active future request is blocked with a
 *     link to cancel it first; the past one is anonymised and disappears from a new search.
 *  3. A Branch Manager gets 403 on the screen.
 * Data: synthetic (example.test), created through bin/wp with the caller's MOMOS_DB_NAME / MOMOS_URL.
 * Base URL: MOMOS_E2E_BASE_URL (or MOMOS_URL), default http://127.0.0.1:8080.
 */
import { test, expect, Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';
import * as fs from 'node:fs';
import * as path from 'node:path';

const BASE = (process.env.MOMOS_E2E_BASE_URL || process.env.MOMOS_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const ROOT = path.resolve(__dirname, '../../..');
const RUN = Date.now().toString(36);
const EMAIL = `dsr-${RUN}@example.test`;
const PASSWORD = 'E2E-Privacy-2026!';

type Fixture = { past: string; future: string; other: string };
let fx: Fixture;

function wpEval(php: string): string {
  return execFileSync(path.join(ROOT, 'bin/wp'), ['eval', php], { cwd: ROOT, env: { ...process.env, MOMOS_URL: process.env.MOMOS_URL || BASE }, encoding: 'utf8' }).trim();
}

test.describe.configure({ mode: 'serial' });

test.beforeAll(() => {
  const php = `
    $pw = ${JSON.stringify(PASSWORD)};
    $user = static function ( string $login, string $role ) use ( $pw ): int {
      $u  = get_user_by( 'login', $login );
      $id = $u ? (int) $u->ID : (int) wp_insert_user( [ 'user_login' => $login, 'user_pass' => $pw, 'user_email' => $login . '@momos.example.test', 'role' => $role ] );
      wp_set_password( $pw, $id );
      ( new WP_User( $id ) )->set_role( $role );
      update_user_meta( $id, 'locale', 'en_US' );
      return $id;
    };
    $branch = static function ( string $code ): int {
      $p = get_page_by_path( strtolower( $code ), OBJECT, 'momo_branch' );
      if ( $p ) { return (int) $p->ID; }
      $id = wp_insert_post( [ 'post_type' => 'momo_branch', 'post_status' => 'draft', 'post_title' => 'E2E ' . $code, 'post_name' => strtolower( $code ) ] );
      update_post_meta( $id, 'momo_branch_code', $code );
      return (int) $id;
    };
    $a = $branch( 'XPA' ); $b = $branch( 'XPB' );
    $user( 'e2e_ho_privacy', 'momo_head_office' );
    $bm = $user( 'e2e_bm_privacy', 'momo_branch_manager' );
    \\Momos\\Access\\Users\\AssignmentService::set( $bm, [ $a ], 1 );
    global $wpdb;
    $mk = static function ( int $branch_id, string $date, string $status, string $email, string $name ) use ( $wpdb ): string {
      $ref   = \\Momos\\Kernel\\Support\\Crockford::random( 8 );
      $start = \\Momos\\Kernel\\Clock\\Clock::jst_at( $date, '19:00' );
      $now   = \\Momos\\Kernel\\Clock\\Clock::mysql_utc();
      $wpdb->insert( \\Momos\\Kernel\\Db\\Tables::reservations(), [
        'public_ref' => $ref, 'idempotency_key' => wp_generate_uuid4(), 'branch_id' => $branch_id, 'status' => $status,
        'requested_date' => $date, 'requested_time' => '19:00:00', 'requested_start_utc' => \\Momos\\Kernel\\Clock\\Clock::mysql_utc( $start ),
        'party_size' => 2, 'guest_name' => $name, 'email' => $email, 'phone_e164' => '+819000000000', 'special_request' => 'E2E-SR-MARKER',
        'preferred_lang' => 'en', 'source_lang' => 'en', 'consent_version' => 'PP1.0-RP1.0', 'consent_at' => $now,
        'token_hash' => str_repeat( 'e', 64 ), 'token_expires_utc' => \\Momos\\Kernel\\Clock\\Clock::mysql_utc( $start->modify( '+24 hours' ) ),
        'created_at' => $now, 'updated_at' => $now,
      ] );
      return \\Momos\\Kernel\\Support\\Crockford::display( $ref );
    };
    $future = gmdate( 'Y-m-d', time() + 20 * DAY_IN_SECONDS );
    $past   = gmdate( 'Y-m-d', time() - 40 * DAY_IN_SECONDS );
    echo wp_json_encode( [
      'past'   => $mk( $a, $past, 'completed', ${JSON.stringify(EMAIL)}, 'Dsr Guest ${RUN}' ),
      'future' => $mk( $b, $future, 'confirmed', ${JSON.stringify(EMAIL)}, 'Dsr Guest ${RUN}' ),
      'other'  => $mk( $a, $past, 'completed', 'other-${RUN}@example.test', 'Other Guest ${RUN}' ),
    ] );
  `;
  fx = JSON.parse(wpEval(php).split('\n').pop() as string);
});

async function login(page: Page, user: string) {
  await page.goto(`${BASE}/wp-login.php`);
  await page.fill('#user_login', user);
  await page.fill('#user_pass', PASSWORD);
  await Promise.all([page.waitForURL(/wp-admin/), page.click('#wp-submit')]);
}

async function search(page: Page, q: string) {
  await page.goto(`${BASE}/wp-admin/admin.php?page=momos-dsr`);
  await page.getByLabel('Email address or reservation reference').fill(q);
  await Promise.all([page.waitForURL(/page=momos-dsr/), page.getByRole('button', { name: 'Search' }).click()]);
}

test('Head Office searches by email and exports without PII in URL', async ({ page }) => {
  await login(page, 'e2e_ho_privacy');
  await search(page, `  ${EMAIL.toUpperCase()} `);
  expect(page.url()).not.toContain('@');
  expect(page.url().toLowerCase()).not.toContain(`dsr-${RUN}`);
  const table = page.getByRole('table', { name: 'Reservations found' });
  await expect(table.getByText(fx.past)).toBeVisible();
  await expect(table.getByText(fx.future)).toBeVisible();
  await expect(table.getByText(fx.other)).toHaveCount(0);
  const html = await page.content();
  expect(html).not.toContain(EMAIL);
  expect(html).not.toContain(`Dsr Guest ${RUN}`);

  const r = await new AxeBuilder({ page }).include('.momos-dsr').withTags(['wcag2a', 'wcag2aa']).analyze();
  expect(r.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical').map((v) => v.id)).toEqual([]);

  const [download] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'Export selected (JSON)' }).click()]);
  expect(download.suggestedFilename()).toMatch(/^momo-dsr-export-\d{8}-\d{6}\.json$/);
  const file = await download.path();
  const data = JSON.parse(fs.readFileSync(file as string, 'utf8'));
  expect(data.reservations).toHaveLength(2);
  expect(data.reservations.map((x: { email: string }) => x.email)).toEqual([EMAIL, EMAIL]);
  expect(data.reservations[0].special_request).toBe('E2E-SR-MARKER');
});

test('Anonymise needs the typed reference and is blocked for active future requests', async ({ page }) => {
  await login(page, 'e2e_ho_privacy');
  await search(page, EMAIL);

  const future = page.locator('details', { hasText: fx.future });
  await future.locator('summary').click();
  await expect(future.getByText('Cancel the active request first.')).toBeVisible();
  await expect(future.getByRole('link', { name: 'Open the reservation to cancel it' })).toBeVisible();

  const past = page.locator('details', { hasText: fx.past });
  await past.locator('summary').click();
  await past.getByLabel('Reference', { exact: true }).fill('ZZZZ-ZZZZ');
  await past.getByLabel('Reason', { exact: true }).selectOption('erasure_request');
  await Promise.all([page.waitForURL(/m=anon_confirm_mismatch/), past.getByRole('button', { name: 'Anonymise this reservation' }).click()]);
  await expect(page.getByRole('alert')).toContainText('does not match');

  const again = page.locator('details', { hasText: fx.past });
  await again.locator('summary').click();
  await again.getByLabel('Reference', { exact: true }).fill(fx.past.toLowerCase());
  await again.getByLabel('Reason', { exact: true }).selectOption('erasure_request');
  await again.getByLabel('Register reference (optional)').fill(`DSR-${RUN}`);
  await Promise.all([page.waitForURL(/m=anon_anonymized/), again.getByRole('button', { name: 'Anonymise this reservation' }).click()]);
  await expect(page.getByRole('status')).toContainText('anonymised');

  await search(page, EMAIL);
  const table = page.getByRole('table', { name: 'Reservations found' });
  await expect(table.getByText(fx.future)).toBeVisible();
  await expect(table.getByText(fx.past)).toHaveCount(0);
});

test('Branch Manager is refused (403)', async ({ page }) => {
  await login(page, 'e2e_bm_privacy');
  const res = await page.goto(`${BASE}/wp-admin/admin.php?page=momos-dsr`);
  expect(res?.status()).toBe(403);
});
