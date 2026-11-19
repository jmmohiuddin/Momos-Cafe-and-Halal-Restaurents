/**
 * Staff inbox live updates (FR-RES-028, reservation.md §7.1, SCR-A08; assets/js/staff-inbox.js):
 *  - the inbox polls API-10 every 60 s with `since` and, when new pending requests arrived, shows the
 *    "{n} new — Show" bar (role=status) without moving the visible rows; the link reloads the list;
 *  - while the browser is offline polling pauses and an offline banner (role=status) is shown; it goes away
 *    when the connection returns.
 * The 60 s interval is fast-forwarded with Playwright's fake clock (page.clock); the network requests stay real.
 * Data: the seeded demo site (tests/e2e/fixtures/seed.sh, branch DKH, demo_bm_kitahama). A new request is
 * sent through the public API-02 in the meantime, exactly as a guest would.
 */
import { test, expect, APIRequestContext, Request, Response } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import * as path from 'node:path';

const BASE = (process.env.MOMOS_E2E_BASE_URL || process.env.MOMOS_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const ROOT = path.resolve(__dirname, '../../..');
const PASSWORD = 'Momos-Dev-2026!';
const RUN = Date.now().toString(36);

function wpEval(php: string): string {
  return execFileSync(path.join(ROOT, 'bin/wp'), ['eval', php], { cwd: ROOT, env: { ...process.env, MOMOS_URL: process.env.MOMOS_URL || BASE }, encoding: 'utf8' }).trim();
}
function clearThrottle() {
  wpEval(`global $wpdb; $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%momos_lt_%' OR option_name LIKE '%momos_rl_%'"); wp_cache_flush(); echo 'ok';`);
}

/** A guest request for DKH through API-01 + API-02 (no browser). Returns the reference as staff see it. */
async function guestRequest(request: APIRequestContext, tag: string): Promise<string> {
  const rules = await (await request.get(`${BASE}/wp-json/momos/v1/branches/DKH/reservation-rules?lang=en`)).json();
  expect(rules.accepting, 'DKH accepts requests on the seeded site').toBe(true);
  const day = (rules.days as { date: string; slots: string[] }[]).filter((d) => d.slots.length > 0)[1];
  const res = await request.post(`${BASE}/wp-json/momos/v1/reservations`, {
    headers: { 'Content-Type': 'application/json', Origin: BASE },
    data: {
      branch_code: 'DKH', date: day.date, time: day.slots[0], party_size: 2, name: 'Polling Guest', email: `poll-${RUN}-${tag}@example.com`,
      phone: '+81 90-0000-0000', lang: 'en', preferred_lang: 'en', consent: true, idempotency_key: crypto.randomUUID(), captcha_token: 'test-pass',
    },
  });
  expect(res.status(), await res.text()).toBe(201);
  return (await res.json()).reference_display as string;
}

/** The inbox poll of API-10 (`since=`), as sent by staff-inbox.js. */
const isPoll = (r: Response | Request) => r.url().includes('/momos/v1/staff/reservations') && r.url().includes('since=');

test.describe.configure({ mode: 'serial' });
test.beforeEach(() => clearThrottle());

test('FR-RES-028: after 60 s the inbox announces new requests ("n new — Show") without moving the rows; the link shows them', async ({ browser, request }) => {
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  await page.clock.install();
  await page.goto(`${BASE}/wp-login.php`);
  await page.fill('#user_login', 'demo_bm_kitahama');
  await page.fill('#user_pass', PASSWORD);
  await Promise.all([page.waitForURL(/wp-admin/), page.click('#wp-submit')]);
  await page.goto(`${BASE}/wp-admin/admin.php?page=momos-reservations&status=pending`);

  const bar = page.locator('#momos-newbar');
  await expect(page.locator('#momos-inbox')).toBeVisible();
  await expect(bar).toBeHidden();
  await expect(bar).toHaveAttribute('role', 'status');
  const rowsBefore = await page.locator('#momos-inbox tbody tr').allTextContents();

  // Nothing new yet: one interval passes, the poll answers 0 and the bar stays hidden.
  const [first] = await Promise.all([page.waitForResponse(isPoll), page.clock.runFor(60_000)]);
  expect(first.url()).toMatch(/status=pending/);
  expect(first.headers()['x-momo-new-count']).toBe('0');
  await expect(bar).toBeHidden();

  // Two guests send requests; the next tick shows the count, rows are not re-rendered.
  const refs = [await guestRequest(request, 'a'), await guestRequest(request, 'b')];
  const [second] = await Promise.all([page.waitForResponse(isPoll), page.clock.runFor(60_000)]);
  expect(second.headers()['x-momo-new-count']).toBe('2');
  await expect(bar).toBeVisible();
  await expect(page.locator('#momos-newbar-link')).toHaveText(/(^|\D)2(\D|$)/);
  expect(await page.locator('#momos-inbox tbody tr').allTextContents(), 'visible rows do not move').toEqual(rowsBefore);
  for (const ref of refs) await expect(page.locator('#momos-inbox tbody')).not.toContainText(ref);

  // "Show" reloads the list with the new requests.
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('#momos-newbar-link').click()]);
  await expect(page.locator('#momos-newbar')).toBeHidden();
  const body = page.locator('#momos-inbox tbody');
  for (const ref of refs) await expect(body).toContainText(ref);
  await ctx.close();
});

test('FR-RES-028: offline pauses polling and shows a banner; it goes away when the connection returns', async ({ browser, request }) => {
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  await page.clock.install();
  await page.goto(`${BASE}/wp-login.php`);
  await page.fill('#user_login', 'demo_bm_kitahama');
  await page.fill('#user_pass', PASSWORD);
  await Promise.all([page.waitForURL(/wp-admin/), page.click('#wp-submit')]);
  await page.goto(`${BASE}/wp-admin/admin.php?page=momos-reservations&status=pending`);

  const banner = page.locator('#momos-offline');
  await expect(banner).toBeHidden();
  await expect(banner).toHaveAttribute('role', 'status');

  const polls: string[] = [];
  page.on('request', (r) => { if (isPoll(r)) polls.push(r.url()); });
  await ctx.setOffline(true);
  await expect(banner).toBeVisible();
  await expect(banner.locator('p')).not.toHaveText('');
  await guestRequest(request, 'offline');
  await page.clock.runFor(120_000);
  expect(polls, 'no polling while offline').toEqual([]);
  await expect(page.locator('#momos-newbar')).toBeHidden();

  // Back online: the banner disappears and the inbox polls at once (no wait for the next interval).
  await ctx.setOffline(false);
  await expect(banner).toBeHidden();
  await expect.poll(() => polls.length, { message: 'immediate poll when the connection returns' }).toBeGreaterThanOrEqual(1);
  await expect(page.locator('#momos-newbar')).toBeVisible();
  await expect(page.locator('#momos-newbar-link')).toHaveText(/(^|\D)1(\D|$)/);
  await ctx.close();
});
