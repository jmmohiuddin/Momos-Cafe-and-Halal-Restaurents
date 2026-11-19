/**
 * Reservation lifecycle E2E (reservation.md §6/§10; screens.md §3.9–3.11; TC-RES-001/020/021/028/029/040,
 * TC-RSA-001/003, TC-PRV-016, TC-RTL-003):
 *  1. A guest sends a request in English (C09) → C10 shows the reference in an LTR isolate, no booking
 *     wording, no third-party requests → E01 (guest) and E07 (branch) are written by the log transport.
 *  2. Reservation Staff of that branch accepts it in wp-admin (SCR-A09) → the C11 status page (token from
 *     the `#t=` fragment, sent only in X-Momo-Token) shows "Confirmed by the branch".
 *  3. Reservation Staff of ANOTHER branch gets 403 on the same reservation via the admin URL and API-11.
 *  4. A guest sends a request in Arabic (RTL) and cancels it on the status page.
 * Data: tests/e2e/fixtures/reservation-fixture.php (fictional branches XEA/XEB, staff e2e_rs_a/e2e_rs_b),
 * run through bin/wp with the caller's MOMOS_DB_NAME / MOMOS_URL. Turnstile test mode (no network).
 * Base URL: MOMOS_E2E_BASE_URL (or MOMOS_URL), default http://127.0.0.1:8080.
 */
import { test, expect, Page, Request } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';
import * as fs from 'node:fs';
import * as path from 'node:path';

const BASE = (process.env.MOMOS_E2E_BASE_URL || process.env.MOMOS_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const ROOT = path.resolve(__dirname, '../../..');
const MAIL_DIR = process.env.MOMOS_MAIL_CATCHER_DIR || path.join(ROOT, '.wp/wp-content/mail-catcher');
const RUN = Date.now().toString(36);
const BANNED_EN = [/\bbooked\b/i, /reservation confirmed/i, /booking confirmed/i, /your table is reserved/i, /reservation complete/i, /your reservation/i];

type Fixture = { branch_a: number; branch_b: number; staff_a: number; staff_b: number; password: string };
let fx: Fixture;

function wp(args: string[]): string {
  return execFileSync(path.join(ROOT, 'bin/wp'), args, { cwd: ROOT, env: { ...process.env, MOMOS_URL: process.env.MOMOS_URL || BASE }, encoding: 'utf8' });
}
function wpEval(php: string): string {
  return wp(['eval', php]).trim();
}

test.describe.configure({ mode: 'serial' });

test.beforeAll(() => {
  const out = wp(['eval-file', 'tests/e2e/fixtures/reservation-fixture.php']).trim().split('\n').pop() as string;
  fx = JSON.parse(out);
});

// E2E isolation: remove XEA/XEB, their staff and reservations, and restore the rate limits for later specs.
test.afterAll(() => {
  wp(['eval-file', 'tests/e2e/fixtures/reservation-fixture.php', 'teardown']);
});

/** No serious/critical WCAG 2.x A/AA violations (NFR-ACC-01). */
async function axe(page: Page, label: string) {
  const r = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
  const bad = r.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
  expect(bad.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).slice(0, 3).join(' | ')}`), label).toEqual([]);
}

/** Records every request that leaves the site's origin (DEC-066: none on C10/C11). */
function thirdParty(page: Page): string[] {
  const host = new URL(BASE).host;
  const seen: string[] = [];
  page.on('request', (r: Request) => {
    const u = new URL(r.url());
    if (!['data:', 'blob:'].includes(u.protocol) && u.host !== host) seen.push(u.host);
  });
  return seen;
}

async function submitRequest(page: Page, lang: 'en' | 'ar', email: string, dayIndex: number) {
  const prefix = lang === 'en' ? '/en' : '/ar';
  await page.goto(`${BASE}${prefix}/reservation/?branch=XEA`, { waitUntil: 'networkidle' });
  await expect(page.locator('html')).toHaveAttribute('dir', lang === 'ar' ? 'rtl' : 'ltr');
  await expect(page.locator('#momo-res-form')).toBeVisible();
  await expect(page.locator('#res-branch')).toHaveValue('XEA');

  // Empty submit → localised error summary with links, focused (FR-RES-002).
  await page.locator('[data-res-submit]').click();
  await expect(page.locator('#res-errsum')).toBeVisible();
  await expect(page.locator('#res-errsum')).toBeFocused();
  await expect(page.locator('#res-name-error')).toBeVisible();
  await axe(page, `C09 ${lang} with errors`);

  // Date within the booking window from API-01; first time chip; party chip 3.
  const date = await page.$eval('#res-date', (s, i) => Array.from((s as HTMLSelectElement).options).filter((o) => o.value && !o.disabled)[i as number].value, dayIndex);
  await page.selectOption('#res-date', date);
  await page.locator('label[for="res-time-0"]').click();
  await page.locator('label[for="res-party-3"]').click();
  await page.fill('#res-name', lang === 'ar' ? 'عائشة رحمن' : 'Aisha Rahman');
  await page.fill('#res-email', email);
  await page.fill('#res-phone', '+60 12-345 6789');
  await page.check('#res-consent');
  await expect(page.locator('[data-res-sum="email"]')).toContainText(email);

  const posts: string[] = [];
  page.on('request', (r) => { if (r.method() === 'POST' && r.url().includes('/momos/v1/reservations')) posts.push(r.postData() || ''); });
  await Promise.all([page.waitForURL(/\/reservation\/submitted\/$/), page.locator('[data-res-submit]').click()]);
  const sent = JSON.parse(posts[0]);
  expect(sent.idempotency_key).toMatch(/^[0-9a-f-]{36}$/);
  expect(sent.captcha_token).toBeTruthy();
  expect(sent.lang).toBe(lang);
  return { date };
}

test('guest request in English → staff of the branch accepts → status page shows confirmed; other branch is denied', async ({ browser }) => {
  const guest = await browser.newContext();
  const page = await guest.newPage();
  const email = `guest-en-${RUN}@example.test`;
  // dataLayer spy that survives the navigation to C10 (TC-ANL-002).
  await page.addInitScript(() => {
    const dl: unknown[] = ((window as unknown as { dataLayer?: unknown[] }).dataLayer = (window as unknown as { dataLayer?: unknown[] }).dataLayer || []);
    dl.push = function (...items: unknown[]) {
      try {
        const a = JSON.parse(sessionStorage.getItem('e2e_dl') || '[]');
        items.forEach((o) => a.push(JSON.parse(JSON.stringify(o))));
        sessionStorage.setItem('e2e_dl', JSON.stringify(a));
      } catch (e) { /* ignore */ }
      return Array.prototype.push.apply(this, items);
    };
  });
  await submitRequest(page, 'en', email, 2);
  const pushed = JSON.parse((await page.evaluate(() => sessionStorage.getItem('e2e_dl'))) || '[]') as Record<string, unknown>[];
  const leads = pushed.filter((o) => o.event === 'generate_lead');
  expect(leads).toHaveLength(1);
  expect(leads[0]).toMatchObject({ branch_code: 'XEA', language: 'en', party_size_band: '3-6', lead_source: 'reservation_form' });
  expect(pushed.filter((o) => o.event === 'reservation_form_start')).toHaveLength(1);
  expect(JSON.stringify(pushed)).not.toContain(email);
  expect(JSON.stringify(pushed)).not.toMatch(/Aisha|reference|#t=/);

  // C10: no third-party requests, reference in an LTR isolate, request (not booking) wording.
  const external = thirdParty(page);
  await page.reload({ waitUntil: 'networkidle' });
  await expect(page.locator('[data-res-heading]')).toHaveText('Request received — not yet confirmed');
  const ref = (await page.locator('bdi.momo-res-ref__code').textContent()) as string;
  expect(ref).toMatch(/^[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$/);
  await expect(page.locator('bdi.momo-res-ref__code')).toHaveAttribute('dir', 'ltr');
  const body = await page.locator('main').innerText();
  for (const re of BANNED_EN) expect(body).not.toMatch(re);
  expect(page.url()).not.toContain(email);
  expect(external).toEqual([]);
  await axe(page, 'C10 en');
  expect(await page.locator('meta[name="robots"]').getAttribute('content')).toContain('noindex');

  // E01 (guest) + E07 (branch recipient) through the log transport.
  wpEval('\\Momos\\Notification\\Queue\\Dispatcher::run_due();');
  const id = Number(wpEval(`echo (int) (\\Momos\\Reservation\\Repository\\ReservationRepository::find_by_ref('${ref}')['id'] ?? 0);`));
  expect(id).toBeGreaterThan(0);
  const sentRows = JSON.parse(wpEval(`global $wpdb; echo wp_json_encode($wpdb->get_results($wpdb->prepare("SELECT template_key, status, recipient_type FROM {$wpdb->prefix}momo_notifications WHERE reservation_id = %d ORDER BY id", ${id}), ARRAY_A));`));
  expect(sentRows).toEqual(expect.arrayContaining([
    expect.objectContaining({ template_key: 'E01', status: 'sent', recipient_type: 'customer' }),
    expect.objectContaining({ template_key: 'E07', status: 'sent' }),
  ]));
  const mails = fs.readdirSync(MAIL_DIR).filter((f) => f.endsWith('.json')).map((f) => JSON.parse(fs.readFileSync(path.join(MAIL_DIR, f), 'utf8')));
  const toGuest = mails.filter((m) => m.to === email);
  expect(toGuest.length).toBeGreaterThanOrEqual(1);
  expect(toGuest[0].text).toContain(ref);
  expect(mails.some((m) => m.to === 'xea-inbox@momos.example.test' && String(m.text).includes(ref))).toBeTruthy();

  // Status link from C10: token only in the fragment, removed from the address bar, sent as a header.
  const statusUrl = (await page.locator('a[href*="/reservation/status/"]').getAttribute('href')) as string;
  const token = statusUrl.split('#t=')[1];
  expect(token).toMatch(/^[A-Za-z0-9_-]{43}$/);
  const apiCalls: { url: string; header: string | undefined }[] = [];
  page.on('request', (r) => { if (r.url().includes('/momos/v1/reservations/')) apiCalls.push({ url: r.url(), header: r.headers()['x-momo-token'] }); });
  await page.goto(statusUrl, { waitUntil: 'networkidle' });
  await expect(page.locator('[data-res-heading]')).toContainText('Request received — not yet confirmed');
  expect(page.url()).not.toContain(token);
  expect(apiCalls.length).toBeGreaterThan(0);
  for (const c of apiCalls) { expect(c.url).not.toContain(token); expect(c.header).toBe(token); }
  await expect(page.getByRole('button', { name: 'Cancel this request' })).toBeVisible();
  await axe(page, 'C11 en pending');

  // Staff of branch XEA accepts in wp-admin.
  const staffA = await browser.newContext();
  const sa = await staffA.newPage();
  await login(sa, 'e2e_rs_a', fx.password);
  await sa.goto(`${BASE}/wp-admin/admin.php?page=momos-reservations&id=${id}`);
  await expect(sa.locator('#momos-detail')).toContainText(ref);
  await sa.locator('summary', { hasText: /^Accept$/ }).click();
  await Promise.all([sa.waitForURL(/momos_ok=accept/), sa.getByRole('button', { name: 'Accept and email guest' }).click()]);
  await expect(sa.locator('.notice-success')).toContainText('Accepted');

  // Guest status page now shows the branch's confirmation.
  await page.reload({ waitUntil: 'networkidle' });
  await expect(page.locator('[data-res-heading]')).toContainText('Confirmed by the branch');
  await expect(page.getByRole('button', { name: 'Cancel this reservation' })).toBeVisible();

  // TC-RES-040 step 6: the switcher re-attaches the token as a fragment (never a query) → Arabic status.
  await Promise.all([page.waitForURL(/\/ar\/reservation\/status\//), page.locator('footer a[data-momo-to="ar"]').click()]);
  await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
  await expect(page.locator('[data-res-heading]')).toHaveClass(/momo-status--confirmed/);
  expect(page.url()).not.toContain(token);
  expect(page.url()).not.toContain('?');

  // Staff of ANOTHER branch: 403 via the admin URL and via API-11 (forbidden_branch, no data).
  const staffB = await browser.newContext();
  const sb = await staffB.newPage();
  await login(sb, 'e2e_rs_b', fx.password);
  const denied = await sb.goto(`${BASE}/wp-admin/admin.php?page=momos-reservations&id=${id}`);
  expect(denied?.status()).toBe(403);
  await expect(sb.locator('body')).not.toContainText(ref);
  await sb.goto(`${BASE}/wp-admin/admin.php?page=momos-reservations`);
  const nonce = await sb.evaluate(() => (window as unknown as { momosInbox: { nonce: string } }).momosInbox.nonce);
  const api = await sb.evaluate(async ([url, n]) => {
    const r = await fetch(url, { headers: { 'X-WP-Nonce': n } });
    return { status: r.status, body: await r.json() };
  }, [`${BASE}/wp-json/momos/v1/staff/reservations/${id}`, nonce]);
  expect(api.status).toBe(403);
  expect(api.body.code).toBe('forbidden_branch');
  expect(JSON.stringify(api.body)).not.toContain(email);
  await Promise.all([guest.close(), staffA.close(), staffB.close()]);
});

test('guest request in Arabic (RTL) → status page → customer cancels', async ({ browser }) => {
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const page = await ctx.newPage();
  const email = `guest-ar-${RUN}@example.test`;
  await submitRequest(page, 'ar', email, 3);
  await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
  await expect(page.locator('[data-res-heading]')).toHaveText('تم استلام الطلب — لم يتم التأكيد بعد');
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  expect(overflow).toBeLessThanOrEqual(0);

  const statusUrl = (await page.locator('a[href*="/reservation/status/"]').getAttribute('href')) as string;
  expect(statusUrl).toContain('/ar/reservation/status/');
  await page.goto(statusUrl, { waitUntil: 'networkidle' });
  await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
  const cta = page.locator('button[aria-controls="res-cancel-panel"]');
  await expect(cta).toBeVisible();
  await cta.click();
  await expect(page.locator('#res-cancel-q')).toBeFocused();
  await page.locator('#res-cancel-panel .momo-btn--danger').click();
  await expect(page.locator('[data-res-heading]')).toHaveClass(/momo-status--cancelled/);
  await expect(cta).toHaveCount(0);
  await axe(page, 'C11 ar cancelled');

  // E04 (guest) + E09 (branch) are queued for the customer cancellation.
  const ref = (await page.locator('bdi.momo-res-ref__code').textContent()) as string;
  const rows = JSON.parse(wpEval(`global $wpdb; $r = \\Momos\\Reservation\\Repository\\ReservationRepository::find_by_ref('${ref}'); echo wp_json_encode(['status' => $r['status'], 'by' => $r['cancelled_by'], 'tpl' => $wpdb->get_col($wpdb->prepare("SELECT template_key FROM {$wpdb->prefix}momo_notifications WHERE reservation_id = %d", $r['id']))]);`));
  expect(rows.status).toBe('cancelled');
  expect(rows.by).toBe('customer');
  expect(rows.tpl).toEqual(expect.arrayContaining(['E01', 'E04', 'E09']));
  await ctx.close();

  // Without JavaScript the shell shows only the contact message + branch phones (DEC-058).
  const nojs = await browser.newContext({ javaScriptEnabled: false });
  const np = await nojs.newPage();
  await np.goto(statusUrl);
  await expect(np.locator('noscript')).toHaveCount(1);
  expect(await np.content()).toContain('يُرجى التواصل مع الفرع');
  expect(await np.content()).not.toContain(ref);
  await nojs.close();
});

async function login(page: Page, user: string, pass: string) {
  await page.goto(`${BASE}/wp-login.php`);
  await page.fill('#user_login', user);
  await page.fill('#user_pass', pass);
  await Promise.all([page.waitForURL(/wp-admin/), page.click('#wp-submit')]);
}
