/**
 * Reservation gaps of the PRD audit (tests added by the reservation fixer):
 *  1. AC-RES-01-15 + AC-PRV-01-01 (TC-PRV-001): C09 in every enabled language has no nationality / religion /
 *     DOB / Katakana / payment field, the consent box is unticked, "No payment or deposit is required" is
 *     shown in that language, and the purpose notice + a same-language Privacy Policy link come before submit.
 *  2. FR-BR-009(b): notices of the selected branch on C09 — server-rendered for `?branch=`, swapped by
 *     reservation-form.js when another branch is selected (page config), page language only.
 *  3. AC-PRV-01-03 (TC-PRV-008 automated): full journey with a unique email/phone (submit → staff list,
 *     detail, accept → guest status page), then the PHP error log, MOMOS_LOG_FILE, the Action Scheduler logs,
 *     the audit table and every other table except reservations/notifications contain neither value.
 *     Set MOMOS_E2E_SERVER_LOG to the dev server's stderr file to search it as well.
 *  4. AC-I18N-04-01 / FR-I18N-010(c): the inbox for an en_US user shows English labels and JST times; for a
 *     ja user Japanese labels, a Japanese weekday and JST.
 *  5. AC-RES-04-05: at 390 px the inbox and the detail have no horizontal scroll; Accept/Decline ≥ 44 px.
 * Data: tests/e2e/fixtures/reservation-fixture.php (XEA/XEB, e2e_rs_a; all six languages enabled), plus one
 * synthetic notice for XEB removed in afterAll. Base URL: MOMOS_E2E_BASE_URL (or MOMOS_URL).
 */
import { test, expect, Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';
import * as fs from 'node:fs';
import * as path from 'node:path';

const BASE = (process.env.MOMOS_E2E_BASE_URL || process.env.MOMOS_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const ROOT = path.resolve(__dirname, '../../..');
const RUN = Date.now().toString(36);
const LANGS = ['ja', 'en', 'ar', 'ur', 'hi', 'bn'] as const;

type Fixture = { branch_a: number; branch_b: number; staff_a: number; staff_b: number; password: string };
let fx: Fixture;
let noticeId = 0;

function wp(args: string[]): string {
  return execFileSync(path.join(ROOT, 'bin/wp'), args, { cwd: ROOT, env: { ...process.env, MOMOS_URL: process.env.MOMOS_URL || BASE }, encoding: 'utf8' });
}
function wpEval(php: string): string {
  return wp(['eval', php]).trim();
}
function prefix(lang: string): string {
  return lang === 'ja' ? '' : `/${lang}`;
}
async function login(page: Page, user: string, pass: string) {
  await page.goto(`${BASE}/wp-login.php`);
  await page.fill('#user_login', user);
  await page.fill('#user_pass', pass);
  await Promise.all([page.waitForURL(/wp-admin/), page.click('#wp-submit')]);
}

test.describe.configure({ mode: 'serial' });

test.beforeAll(() => {
  const out = wp(['eval-file', 'tests/e2e/fixtures/reservation-fixture.php']).trim().split('\n').pop() as string;
  fx = JSON.parse(out);
  // A branch notice for XEB only, approved in English (FR-BR-009(b)); active for the whole run.
  noticeId = Number(wpEval(`
    $b  = ${fx.branch_b};
    $id = (int) wp_insert_post( [ 'post_type' => 'momo_notice', 'post_status' => 'draft', 'post_title' => 'E2E 臨時休業' ] );
    update_post_meta( $id, 'momo_notice_body', 'E2E: 設備点検のため臨時休業します。' );
    update_post_meta( $id, 'momo_notice_severity', 'warning' );
    update_post_meta( $id, 'momo_notice_scope', 'branches' );
    update_post_meta( $id, 'momo_notice_start_at', \\Momos\\Kernel\\Clock\\Clock::jst()->modify( '-1 hour' )->format( 'Y-m-d H:i' ) );
    update_post_meta( $id, 'momo_notice_end_at', \\Momos\\Kernel\\Clock\\Clock::jst()->modify( '+2 days' )->format( 'Y-m-d H:i' ) );
    $t = \\Momos\\Branch\\PostType\\BranchScopeTaxonomy::term_for_branch( $b )->term_id;
    wp_set_object_terms( $id, [ $t ], \\Momos\\Branch\\PostType\\BranchScopeTaxonomy::TAX );
    update_post_meta( $id, 'momo_notice_branches', [ $t ] );
    wp_update_post( [ 'ID' => $id, 'post_status' => 'publish' ] );
    \\Momos\\Kernel\\I18n\\Translations::save( 'post', $id, 'en', [ 'title' => 'E2E temporary closure', 'momo_notice_body' => 'E2E: closed for equipment maintenance.' ], 'approved' );
    echo $id;
  `));
  expect(noticeId).toBeGreaterThan(0);
});

// E2E isolation: the fixture teardown also removes e2e_rs_a (and its locale); then the synthetic notice.
test.afterAll(() => {
  wp(['eval-file', 'tests/e2e/fixtures/reservation-fixture.php', 'teardown']);
  if (noticeId) wpEval(`wp_delete_post( ${noticeId}, true );`);
});

test('AC-RES-01-15 + AC-PRV-01-01: C09 in every enabled language — no sensitive/payment field, unticked consent, no-payment text, purpose notice and same-language Privacy Policy link before submit', async ({ page }) => {
  const enabled = JSON.parse(wpEval('echo wp_json_encode( \\Momos\\Kernel\\I18n\\Lang::enabled() );')) as string[];
  expect(enabled.sort()).toEqual([...LANGS].sort());
  for (const lang of LANGS) {
    const s = JSON.parse(wpEval(`echo wp_json_encode( [
      'no_pay'  => momos_t( 'res.no_payment', [], '${lang}' ),
      'purpose' => momos_t( 'res.notice.collection', [], '${lang}' ),
      'privacy' => momos_t( 'ftr.privacy', [], '${lang}' ),
      'url'     => \\Momos\\Presentation\\SystemPages::legal_url( 'privacy', '${lang}' ),
    ] );`)) as { no_pay: string; purpose: string; privacy: string; url: string | null };
    expect(s.url, `${lang}: Privacy Policy published in ${lang}`).toBeTruthy();
    if (lang !== 'ja') expect(s.url).toContain(`/${lang}/`);

    await page.goto(`${BASE}${prefix(lang)}/reservation/`, { waitUntil: 'networkidle' });
    await expect(page.locator('html')).toHaveAttribute('lang', new RegExp(`^${lang}`));
    const form = page.locator('#momo-res-form');
    await expect(form).toBeVisible();

    // AC-RES-01-15: no forbidden field, consent unticked, "No payment or deposit is required".
    const fields = await form.locator('input, select, textarea').evaluateAll((els) => els.map((e) => [e.getAttribute('name'), e.id, e.getAttribute('autocomplete')].join('|')));
    expect(fields.length).toBeGreaterThan(5);
    for (const f of fields) expect(f, `${lang}: ${f}`).not.toMatch(/nation|religio|birth|bday|dob|kana|furigana|payment|deposit|card|cc-|iban/i);
    await expect(page.locator('#res-consent')).not.toBeChecked();
    await expect(form.getByText(s.no_pay, { exact: true })).toBeVisible();
    if (lang === 'en') expect(s.no_pay).toBe('No payment or deposit is required.');

    // AC-PRV-01-01: purpose notice + Privacy Policy link (same language) before the submit button.
    const notice = form.getByText(s.purpose, { exact: true });
    await expect(notice).toBeVisible();
    const link = page.locator('label[for="res-consent"] a', { hasText: s.privacy });
    await expect(link).toHaveAttribute('href', s.url as string);
    const order = await page.evaluate(() => {
      const submit = document.querySelector('[data-res-submit]') as Element;
      const before = (n: Element | null) => !!n && !!(n.compareDocumentPosition(submit) & Node.DOCUMENT_POSITION_FOLLOWING);
      const p = Array.from(document.querySelectorAll('#momo-res-form p')).find((x) => x.nextElementSibling && x.nextElementSibling.matches('[data-res-field="consent"]')) || null;
      return { notice: before(p), link: before(document.querySelector('label[for="res-consent"] a')) };
    });
    expect(order, lang).toEqual({ notice: true, link: true });
    // The link opens the Privacy Policy in the same language.
    const res = await page.request.get(s.url as string);
    expect(res.status()).toBe(200);
    expect(await res.text()).toMatch(new RegExp(`<html[^>]*lang="${lang}`));
  }
});

test('FR-BR-009(b): branch notices on C09 follow the selected branch (server + client), page language only', async ({ page, request }) => {
  // Server-rendered for the preselected branch (before any script runs).
  const html = await (await request.get(`${BASE}/en/reservation/?branch=XEB`)).text();
  expect(html).toMatch(/data-res-notices[^>]*>\s*<div[^>]*class="momo-notice momo-notice--warning momo-notice--card[^"]*"[\s\S]*E2E: closed for equipment maintenance\./);
  const ja = await (await request.get(`${BASE}/reservation/?branch=XEB`)).text();
  expect(ja).toContain('E2E: 設備点検のため臨時休業します。');
  expect(ja).not.toContain('E2E: closed for equipment maintenance.');

  await page.goto(`${BASE}/en/reservation/?branch=XEA`, { waitUntil: 'networkidle' });
  const box = page.locator('[data-res-notices]');
  await expect(box).toBeHidden();
  await expect(page.getByText('E2E: closed for equipment maintenance.')).toHaveCount(0);
  await page.selectOption('#res-branch', 'XEB');
  await expect(box).toBeVisible();
  const n = box.locator('.momo-notice--warning[role="region"]');
  await expect(n).toHaveCount(1);
  await expect(n).toContainText('E2E temporary closure');
  await expect(n).toContainText('E2E: closed for equipment maintenance.');
  await expect(n).toHaveAttribute('aria-label', 'Notice');
  const ax = await new AxeBuilder({ page }).include('#momo-res-form').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
  expect(ax.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical').map((v) => v.id), 'axe with a client-rendered notice').toEqual([]);
  await page.selectOption('#res-branch', 'XEA');
  await expect(box).toBeHidden();
  await expect(box.locator('.momo-notice')).toHaveCount(0);

  // Arabic page: the notice has no approved Arabic text → never shown (BR-I18N-02).
  await page.goto(`${BASE}/ar/reservation/?branch=XEA`, { waitUntil: 'networkidle' });
  await page.selectOption('#res-branch', 'XEB');
  await expect(page.locator('[data-res-notices] .momo-notice')).toHaveCount(0);
  await expect(page.getByText(/E2E/)).toHaveCount(0);
});

test('AC-PRV-01-03 + AC-I18N-04-01 + AC-RES-04-05: journey with a unique email/phone → staff inbox (en/ja, JST) and 390 px detail → accept → no PII in logs', async ({ browser }) => {
  const email = `prv-${RUN}@example.test`;
  const digits = String(Date.now()).slice(-7);
  const phoneInput = `+60 12-${digits.slice(0, 3)} ${digits.slice(3)}`;
  const e164 = `+6012${digits}`;
  const logs = JSON.parse(wpEval(`echo wp_json_encode( [ 'error_log' => (string) ini_get( 'error_log' ), 'debug' => true === WP_DEBUG_LOG ? WP_CONTENT_DIR . '/debug.log' : ( is_string( WP_DEBUG_LOG ) ? WP_DEBUG_LOG : '' ), 'momos' => defined( 'MOMOS_LOG_FILE' ) ? (string) MOMOS_LOG_FILE : '' ] );`)) as { error_log: string; debug: string; momos: string };
  const files = [...new Set([logs.error_log, logs.debug, logs.momos, process.env.MOMOS_E2E_SERVER_LOG || ''])].filter((f) => f && fs.existsSync(f));
  const sizes = new Map(files.map((f) => [f, fs.statSync(f).size]));

  // Guest submits in English.
  const guest = await browser.newContext();
  const page = await guest.newPage();
  await page.goto(`${BASE}/en/reservation/?branch=XEA`, { waitUntil: 'networkidle' });
  const date = await page.$eval('#res-date', (s) => Array.from((s as HTMLSelectElement).options).filter((o) => o.value && !o.disabled)[1].value);
  await page.selectOption('#res-date', date);
  await page.locator('label[for="res-time-0"]').click();
  await page.locator('label[for="res-party-3"]').click();
  await page.fill('#res-name', 'Privacy Probe');
  await page.fill('#res-email', email);
  await page.fill('#res-phone', phoneInput);
  await page.check('#res-consent');
  await Promise.all([page.waitForURL(/\/reservation\/submitted\/$/), page.locator('[data-res-submit]').click()]);
  const ref = (await page.locator('bdi.momo-res-ref__code').textContent()) as string;
  const row = JSON.parse(wpEval(`$r = \\Momos\\Reservation\\Repository\\ReservationRepository::find_by_ref( '${ref}' ); echo wp_json_encode( [ 'id' => (int) $r['id'], 'phone' => $r['phone_e164'], 'date' => $r['requested_date'], 'time' => substr( $r['requested_time'], 0, 5 ) ] );`)) as { id: number; phone: string; date: string; time: string };
  expect(row.phone).toBe(e164);
  wpEval('\\Momos\\Notification\\Queue\\Dispatcher::run_due();');
  const statusUrl = (await page.locator('a[href*="/reservation/status/"]').getAttribute('href')) as string;
  await page.goto(statusUrl, { waitUntil: 'networkidle' });
  await expect(page.locator('[data-res-heading]')).toContainText('Request received');

  // AC-I18N-04-01: English admin → English labels, JST times.
  wpEval(`update_user_meta( ${fx.staff_a}, 'locale', 'en_US' );`);
  const staff = await browser.newContext();
  const sa = await staff.newPage();
  await login(sa, 'e2e_rs_a', fx.password);
  await sa.goto(`${BASE}/wp-admin/admin.php?page=momos-reservations`);
  await expect(sa.locator('#momos-inbox h1')).toHaveText('Reservation requests');
  const enRow = sa.locator('#momos-inbox tbody tr', { hasText: ref });
  await expect(enRow).toHaveCount(1);
  await expect(enRow.locator('.momos-when time')).toHaveText(new RegExp(`^${row.date} \\((Mon|Tue|Wed|Thu|Fri|Sat|Sun)\\) ${row.time} JST$`));
  await expect(enRow.locator('time.momos-received')).toHaveText(/^\d{4}-\d{2}-\d{2} \((Mon|Tue|Wed|Thu|Fri|Sat|Sun)\) \d{2}:\d{2} JST$/);
  await expect(enRow.locator('.momos-badge')).toHaveText('Pending');
  await expect(sa.locator('#momos-inbox thead')).toContainText('Received');
  expect(await enRow.innerText()).not.toMatch(/[぀-ヿ一-鿿]/);

  // ja admin → Japanese labels, Japanese weekday, JST.
  wpEval(`update_user_meta( ${fx.staff_a}, 'locale', 'ja' );`);
  await sa.reload();
  await expect(sa.locator('#momos-inbox h1')).toHaveText('予約リクエスト');
  await expect(sa.locator('#momos-inbox thead')).toContainText('受付日時');
  const jaRow = sa.locator('#momos-inbox tbody tr', { hasText: ref });
  await expect(jaRow.locator('.momos-when time')).toHaveText(new RegExp(`^${row.date}（[月火水木金土日]）${row.time} JST$`));
  await expect(jaRow.locator('time.momos-received')).toHaveText(/^\d{4}-\d{2}-\d{2}（[月火水木金土日]）\d{2}:\d{2} JST$/);
  wpEval(`update_user_meta( ${fx.staff_a}, 'locale', 'en_US' );`);

  // AC-RES-04-05: 390 px — no horizontal scroll on inbox and detail; Accept/Decline targets ≥ 44 px.
  await sa.setViewportSize({ width: 390, height: 844 });
  await sa.goto(`${BASE}/wp-admin/admin.php?page=momos-reservations`);
  expect(await sa.evaluate(() => document.documentElement.scrollWidth - window.innerWidth), 'inbox overflow').toBeLessThanOrEqual(0);
  await sa.locator('#momos-inbox tbody tr', { hasText: ref }).locator('a.momos-when').click();
  await expect(sa.locator('#momos-detail')).toContainText(ref);
  expect(await sa.evaluate(() => document.documentElement.scrollWidth - window.innerWidth), 'detail overflow').toBeLessThanOrEqual(0);
  for (const name of ['Accept', 'Decline']) {
    const summary = sa.locator('details.momos-sheet > summary', { hasText: new RegExp(`^${name}$`) });
    await summary.scrollIntoViewIfNeeded();
    await expect(summary).toBeVisible();
    const box = (await summary.boundingBox()) as { x: number; width: number; height: number };
    expect(box.height, `${name} height`).toBeGreaterThanOrEqual(44);
    expect(box.x).toBeGreaterThanOrEqual(0);
    expect(box.x + box.width, `${name} reachable without horizontal scroll`).toBeLessThanOrEqual(390);
  }

  // FR-RES-009(a): Request card shows consent version + time (JST) and the record version.
  const card = sa.locator('section[aria-labelledby="momos-h-request"]');
  await expect(card.locator('dd.momos-consent time')).toHaveText(/^\d{4}-\d{2}-\d{2} \(\w{3}\) \d{2}:\d{2} JST$/);
  await expect(card.locator('dd.momos-record-version')).toHaveText(/^\d+$/);

  // Accept (wider viewport, as in reservation-lifecycle) → guest status page.
  await sa.locator('summary', { hasText: /^Accept$/ }).click();
  const accept = sa.getByRole('button', { name: 'Accept and email guest' });
  await accept.scrollIntoViewIfNeeded();
  const btn = (await accept.boundingBox()) as { height: number };
  expect(btn.height, 'Accept submit ≥ 44 px').toBeGreaterThanOrEqual(44);
  await Promise.all([sa.waitForURL(/momos_ok=accept/), accept.click()]);
  wpEval('\\Momos\\Notification\\Queue\\Dispatcher::run_due();');
  if (wpEval("echo function_exists( 'as_get_scheduled_actions' ) ? 1 : 0;") === '1') {
    wpEval("do_action( 'action_scheduler_run_queue', 'E2E' );");
  }
  await page.reload({ waitUntil: 'networkidle' });
  await expect(page.locator('[data-res-heading]')).toContainText('Confirmed by the branch');
  await Promise.all([guest.close(), staff.close()]);

  // AC-PRV-01-03: logs (new bytes since the journey started) and every table except reservations/notifications.
  const needles = [email, e164, e164.slice(1), phoneInput];
  for (const f of files) {
    const fd = fs.openSync(f, 'r');
    const start = sizes.get(f) || 0;
    const len = Math.max(0, fs.statSync(f).size - start);
    const buf = Buffer.alloc(len);
    fs.readSync(fd, buf, 0, len, start);
    fs.closeSync(fd);
    const text = buf.toString('utf8');
    for (const n of needles) expect(text.includes(n), `${path.basename(f)} contains ${n === email ? 'the email' : 'the phone'}`).toBe(false);
  }
  expect(files.length, 'at least the PHP error log is inspected').toBeGreaterThan(0);
  const hits = JSON.parse(wpEval(`
    global $wpdb;
    $needles = json_decode( '${JSON.stringify(needles)}', true );
    $skip    = [ \\Momos\\Kernel\\Db\\Tables::reservations(), \\Momos\\Kernel\\Db\\Tables::notifications() ];
    $checked = [];
    $hits    = [];
    foreach ( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) ) as $t ) {
      if ( in_array( $t, $skip, true ) ) { continue; }
      $checked[] = $t;
      $cols = [];
      foreach ( $wpdb->get_results( "SHOW COLUMNS FROM \`{$t}\`", ARRAY_A ) as $c ) {
        if ( preg_match( '/char|text|blob|json/i', $c['Type'] ) ) { $cols[] = $c['Field']; }
      }
      foreach ( $cols as $c ) {
        foreach ( $needles as $n ) {
          $q = $wpdb->prepare( "SELECT COUNT(*) FROM \`{$t}\` WHERE \`{$c}\` LIKE %s", '%' . $wpdb->esc_like( $n ) . '%' );
          if ( (int) $wpdb->get_var( $q ) > 0 ) { $hits[] = "{$t}.{$c}"; }
        }
      }
    }
    echo wp_json_encode( [ 'checked' => $checked, 'hits' => array_values( array_unique( $hits ) ), 'audit' => \\Momos\\Kernel\\Db\\Tables::audit(), 'as_logs' => $wpdb->prefix . 'actionscheduler_logs' ] );
  `)) as { checked: string[]; hits: string[]; audit: string; as_logs: string };
  expect(hits.checked).toContain(hits.audit);
  expect(hits.checked).toContain(hits.as_logs);
  expect(hits.hits, 'tables other than reservations/notifications holding the email or phone').toEqual([]);
  // Sanity: the needles are findable where they belong.
  expect(Number(wpEval(`global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . \\Momos\\Kernel\\Db\\Tables::reservations() . ' WHERE email = %s AND phone_e164 = %s', '${email}', '${e164}' ) );`))).toBe(1);
});
