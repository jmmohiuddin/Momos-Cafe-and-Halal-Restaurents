/**
 * QA (reservation team) — concurrency and exactly-once effects against the real dev server
 * (reservation.md §3.4/§3.8/§3.7, notifications.md §4.2; Doc 12 TC-RES-017/025, TC-RSA-013/014,
 * FR-RES-007, NFR-REL-01, FR-RES-025):
 *  1. N parallel API-02 submissions with the SAME idempotency key (Node fetch and background curl)
 *     → exactly one row, one reference, one E01 and one E07 per active branch recipient.
 *  2. Parallel submissions of the same request from two "tabs" (different keys) → one row (soft duplicate).
 *  3. Parallel accept + decline (and accept + accept) of one pending reservation by two staff sessions
 *     → exactly one transition, the other request 409, exactly one customer email.
 *  4. The per-IP limit holds under parallel load (sliding window must not be exceeded).
 *  5. End to end through the queue: the guest receives exactly one E01 (mail catcher), in the
 *     preferred language chosen on the Japanese form (TC-RES-034), RTL for Urdu.
 * Uses the seeded demo site (tests/e2e/fixtures/seed.sh): branch DKH, staff demo_rs_kitahama /
 * demo_bm_kitahama (development password). Fictional guest data at example.test only.
 */
import { test, expect, APIRequestContext, BrowserContext } from '@playwright/test';
import { execFileSync, spawn } from 'node:child_process';
import * as fs from 'node:fs';
import * as path from 'node:path';
import { randomUUID } from 'node:crypto';

const BASE = (process.env.MOMOS_E2E_BASE_URL || process.env.MOMOS_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const ROOT = path.resolve(__dirname, '../../..');
const MAIL_DIR = process.env.MOMOS_MAIL_CATCHER_DIR || path.join(ROOT, '.wp/wp-content/mail-catcher');
const RUN = Date.now().toString(36);
const PASSWORD = process.env.MOMOS_DEMO_PASSWORD || 'Momos-Dev-2026!';
const BRANCH = 'DKH';

function wpEval(php: string): string {
  return execFileSync(path.join(ROOT, 'bin/wp'), ['eval', php], { cwd: ROOT, env: { ...process.env, MOMOS_URL: process.env.MOMOS_URL || BASE }, encoding: 'utf8' }).trim();
}
function rows(query: string): Record<string, string>[] {
  // Queries use the literal {p} placeholder for the table prefix.
  const php = `global $wpdb; $q = str_replace('{p}', $wpdb->prefix, base64_decode('${Buffer.from(query).toString('base64')}')); echo wp_json_encode($wpdb->get_results($q, ARRAY_A));`;
  return JSON.parse(wpEval(php));
}
function setOption(name: string, value: string | null) {
  wpEval(value === null ? `delete_option('${name}');` : `update_option('${name}', '${value}');`);
}
function clearRateLimits() {
  wpEval(`global $wpdb; $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\\\_transient\\\\_momos\\\\_rl\\\\_%' OR option_name LIKE '\\\\_transient\\\\_timeout\\\\_momos\\\\_rl\\\\_%'"); wp_cache_flush();`);
}

type Slot = { date: string; time: string };
let slots: Slot[] = [];

async function freeSlots(request: APIRequestContext): Promise<Slot[]> {
  const r = await request.get(`${BASE}/wp-json/momos/v1/branches/${BRANCH}/reservation-rules?lang=en`);
  expect(r.status()).toBe(200);
  const body = await r.json();
  expect(body.accepting).toBe(true);
  const out: Slot[] = [];
  for (const d of body.days as { date: string; slots: string[] }[]) for (const t of d.slots) out.push({ date: d.date, time: t });
  return out.slice(10); // skip the first days (lead time / expiry during the run)
}

let slotIndex = 0;
function nextSlot(): Slot {
  const s = slots[slotIndex];
  slotIndex += 7;
  return s;
}

function body(slot: Slot, over: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    branch_code: BRANCH, date: slot.date, time: slot.time, party_size: 2, name: 'Qa Concurrency', email: `qa-conc-${RUN}-${randomUUID().slice(0, 8)}@example.test`,
    phone: null, lang: 'en', preferred_lang: 'en', consent: true, idempotency_key: randomUUID(), captcha_token: 'test-pass', ...over,
  };
}

async function post(request: APIRequestContext, payload: Record<string, unknown>) {
  const r = await request.post(`${BASE}/wp-json/momos/v1/reservations`, { data: payload, headers: { Origin: BASE, 'Content-Type': 'application/json' } });
  return { status: r.status(), body: await r.json().catch(() => ({})) };
}

function curlPost(payload: Record<string, unknown>): Promise<{ status: number; body: Record<string, unknown> }> {
  return new Promise((resolve, reject) => {
    const p = spawn('curl', ['-s', '-o', '-', '-w', '\n%{http_code}', '-X', 'POST', '-H', 'Content-Type: application/json', '-H', `Origin: ${BASE}`, '--data-binary', JSON.stringify(payload), `${BASE}/wp-json/momos/v1/reservations`]);
    let out = '';
    p.stdout.on('data', (d) => (out += d));
    p.on('error', reject);
    p.on('close', () => {
      const i = out.lastIndexOf('\n');
      let parsed: Record<string, unknown> = {};
      try { parsed = JSON.parse(out.slice(0, i)); } catch { /* non-JSON */ }
      resolve({ status: Number(out.slice(i + 1)), body: parsed });
    });
  });
}

async function staffSession(browser: import('@playwright/test').Browser, user: string): Promise<{ ctx: BrowserContext; nonce: string }> {
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  await page.goto(`${BASE}/wp-login.php`);
  await page.fill('#user_login', user);
  await page.fill('#user_pass', PASSWORD);
  await Promise.all([page.waitForURL(/wp-admin/), page.click('#wp-submit')]);
  const nonce = (await (await ctx.request.get(`${BASE}/wp-admin/admin-ajax.php?action=rest-nonce`)).text()).trim();
  expect(nonce).toMatch(/^[0-9a-f]{10}$/);
  return { ctx, nonce };
}

function reservationByEmail(email: string) {
  return rows(`SELECT id, public_ref, status, version FROM {p}momo_reservations WHERE email = '${email.replace(/'/g, '')}'`);
}
function notificationsOf(id: string | number) {
  return rows(`SELECT template_key, template_variant, recipient_type, recipient_masked, status FROM {p}momo_notifications WHERE reservation_id = ${Number(id)} ORDER BY id`);
}
function activeRecipients(): number {
  return Number(wpEval(`$b = \\Momos\\Branch\\Repository\\BranchRepository::find_by_code('${BRANCH}', true); echo count(\\Momos\\Branch\\Repository\\BranchSettingsRepository::active_recipients((int) $b['id']));`));
}

test.describe.configure({ mode: 'default' });

test.beforeAll(async ({ playwright }) => {
  for (const o of ['momo_rate_ip_per_hour', 'momo_rate_email_per_hour', 'momo_rate_staff_per_minute']) setOption(o, '1000');
  clearRateLimits();
  const req = await playwright.request.newContext();
  slots = await freeSlots(req);
  expect(slots.length).toBeGreaterThan(60);
  await req.dispose();
});

test.afterAll(() => {
  for (const o of ['momo_rate_ip_per_hour', 'momo_rate_email_per_hour', 'momo_rate_staff_per_minute']) setOption(o, null);
  clearRateLimits();
});

test('TC-RES-017: 6 parallel API-02 calls with one idempotency key → one row, one ref, one E01, one E07 per recipient', async ({ request }) => {
  const payload = body(nextSlot());
  const results = await Promise.all(Array.from({ length: 6 }, () => post(request, payload)));
  const statuses = results.map((r) => r.status);
  expect(statuses.every((s) => s === 201 || s === 200), `statuses ${JSON.stringify(statuses)} ${JSON.stringify(results.map((r) => r.body.code))}`).toBe(true);
  expect(statuses.filter((s) => s === 201)).toHaveLength(1);
  const refs = new Set(results.map((r) => r.body.reference));
  expect([...refs]).toHaveLength(1);
  expect(results.every((r) => r.body.duplicate === false)).toBe(true);
  const res = reservationByEmail(String(payload.email));
  expect(res).toHaveLength(1);
  const n = notificationsOf(res[0].id);
  expect(n.filter((x) => x.template_key === 'E01')).toHaveLength(1);
  expect(n.filter((x) => x.template_key === 'E07')).toHaveLength(activeRecipients());
});

test('TC-RES-017 (curl in background): 5 concurrent curl processes, same key → exactly one row', async () => {
  const payload = body(nextSlot());
  const results = await Promise.all(Array.from({ length: 5 }, () => curlPost(payload)));
  const statuses = results.map((r) => r.status);
  expect(statuses.every((s) => s === 201 || s === 200), JSON.stringify(statuses)).toBe(true);
  expect(new Set(results.map((r) => r.body.reference)).size).toBe(1);
  const res = reservationByEmail(String(payload.email));
  expect(res).toHaveLength(1);
  expect(notificationsOf(res[0].id).filter((x) => x.template_key === 'E01')).toHaveLength(1);
});

test('BR-RES-08: the same request sent in parallel from two tabs (different keys) → one row, the other a soft duplicate', async ({ request }) => {
  const base = body(nextSlot());
  const results = await Promise.all(Array.from({ length: 4 }, () => post(request, { ...base, idempotency_key: randomUUID() })));
  const statuses = results.map((r) => r.status);
  expect(statuses.filter((s) => s === 201), JSON.stringify(results.map((r) => [r.status, r.body.code, r.body.duplicate]))).toHaveLength(1);
  expect(results.filter((r) => r.status === 200).every((r) => r.body.duplicate === true)).toBe(true);
  expect(new Set(results.filter((r) => r.status < 300).map((r) => r.body.reference)).size).toBe(1);
  expect(reservationByEmail(String(base.email))).toHaveLength(1);
});

test('TC-RSA-013/014: parallel accept + decline and accept + accept → one transition, one 409, one customer email', async ({ browser, request }) => {
  const rs = await staffSession(browser, 'demo_rs_kitahama');
  const bm = await staffSession(browser, 'demo_bm_kitahama');
  for (const pair of [['accept', 'decline'], ['accept', 'accept']] as const) {
    const payload = body(nextSlot());
    const sub = await post(request, payload);
    expect(sub.status).toBe(201);
    const [res] = reservationByEmail(String(payload.email));
    const url = `${BASE}/wp-json/momos/v1/staff/reservations/${res.id}/transitions`;
    const mk = (action: string) => (action === 'decline' ? { action, version: 1, reason_code: 'fully_booked' } : { action, version: 1 });
    const [a, b] = await Promise.all([
      rs.ctx.request.post(url, { data: mk(pair[0]), headers: { 'X-WP-Nonce': rs.nonce } }),
      bm.ctx.request.post(url, { data: mk(pair[1]), headers: { 'X-WP-Nonce': bm.nonce } }),
    ]);
    const codes = [a.status(), b.status()].sort();
    const bodies = [await a.json(), await b.json()];
    expect(codes, `${pair.join('+')}: ${JSON.stringify(bodies)}`).toEqual([200, 409]);
    const loser = bodies.find((x) => x.code);
    expect(['invalid_transition', 'version_conflict']).toContain(loser.code);
    const winner = bodies.find((x) => !x.code);
    const [after] = reservationByEmail(String(payload.email));
    expect(after.status).toBe(winner.status);
    expect(Number(after.version)).toBe(2);
    const events = rows(`SELECT event_type FROM {p}momo_reservation_events WHERE reservation_id = ${Number(res.id)} AND event_type = 'status_changed'`);
    expect(events).toHaveLength(1);
    const customer = notificationsOf(res.id).filter((x) => x.recipient_type === 'customer' && x.template_key !== 'E01');
    expect(customer.map((x) => x.template_key)).toEqual([after.status === 'confirmed' ? 'E02' : 'E03']);
  }
  await rs.ctx.close();
  await bm.ctx.close();
});

test('FR-RES-007: the per-IP limit is not exceeded by parallel submissions', async ({ request }) => {
  setOption('momo_rate_ip_per_hour', '3');
  clearRateLimits();
  try {
    const payloads = Array.from({ length: 8 }, () => body(nextSlot()));
    const results = await Promise.all(payloads.map((p) => post(request, p)));
    const created = results.filter((r) => r.status === 201).length;
    const limited = results.filter((r) => r.status === 429).length;
    expect(created + limited, JSON.stringify(results.map((r) => [r.status, r.body.code]))).toBe(8);
    expect(created, `accepted ${created} of 8 parallel requests with a limit of 3/h`).toBeLessThanOrEqual(3);
  } finally {
    setOption('momo_rate_ip_per_hour', '1000');
    clearRateLimits();
  }
});

test('TC-RES-034 end to end: Japanese form, Urdu replies → exactly one E01 delivered, in Urdu, RTL, reference isolated', async ({ request }) => {
  const payload = body(nextSlot(), { lang: 'ja', preferred_lang: 'ur', name: 'عائشہ خان' });
  const first = await post(request, payload);
  expect(first.status).toBe(201);
  await post(request, payload); // a retried submission must not produce a second E01
  wpEval('\\Momos\\Notification\\Queue\\Dispatcher::run_due();');
  wpEval('\\Momos\\Notification\\Queue\\Dispatcher::run_due();');
  const mails = fs.readdirSync(MAIL_DIR).filter((f) => f.endsWith('.json')).map((f) => JSON.parse(fs.readFileSync(path.join(MAIL_DIR, f), 'utf8')));
  const toGuest = mails.filter((m) => m.to === payload.email);
  expect(toGuest).toHaveLength(1);
  const m = toGuest[0];
  expect(m.lang).toBe('ur');
  expect(m.html).toContain('<html lang="ur" dir="rtl">');
  const ref = String(first.body.reference_display);
  expect(m.subject).toContain(ref);
  expect(m.html).toMatch(new RegExp(`<bdi dir="ltr"[^>]*>${ref}</bdi>`));
  expect(m.html).toContain('/ur/reservation/status/');
  // Staff alert: given name only, no guest email, in the recipient's language.
  const staff = mails.filter((x) => String(x.subject).includes(ref) && x.to !== payload.email);
  expect(staff.length).toBe(activeRecipients());
  for (const s of staff) {
    expect(String(s.html) + String(s.text)).not.toContain(String(payload.email));
    expect(String(s.html) + String(s.text)).toContain('عائشہ');
    expect(String(s.html) + String(s.text)).not.toContain('خان'); // surname never in staff email (PRV-008)
    expect(['ja', 'en']).toContain(s.lang);
  }
});

test('Staff REST: a cookie-authenticated transition without X-WP-Nonce is refused (CSRF) and changes nothing', async ({ browser, request }) => {
  const rs = await staffSession(browser, 'demo_rs_kitahama');
  const payload = body(nextSlot());
  expect((await post(request, payload)).status).toBe(201);
  const [res] = reservationByEmail(String(payload.email));
  const r = await rs.ctx.request.post(`${BASE}/wp-json/momos/v1/staff/reservations/${res.id}/transitions`, { data: { action: 'accept', version: 1 } });
  expect(r.status()).toBe(403);
  expect((await r.json()).code).toBe('rest_forbidden');
  const [after] = reservationByEmail(String(payload.email));
  expect(after.status).toBe('pending');
  expect(notificationsOf(res.id).filter((x) => x.template_key === 'E02')).toHaveLength(0);
  // With the nonce it works (control).
  const ok = await rs.ctx.request.post(`${BASE}/wp-json/momos/v1/staff/reservations/${res.id}/transitions`, { data: { action: 'accept', version: 1 }, headers: { 'X-WP-Nonce': rs.nonce } });
  expect(ok.status()).toBe(200);
  await rs.ctx.close();
});
