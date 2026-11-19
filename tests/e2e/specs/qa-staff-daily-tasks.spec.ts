/**
 * QA (staff team) — the daily tasks of each staff role in wp-admin, as described in
 * docs/ADMIN_GUIDE_ja_en.md, plus the negative cases of the role boundaries (Doc 12 TC-ACL-006/009/013/
 * 014/016/017, TC-ADM-002, TC-I18N string placeholders, AC-ADM-02-01/06, AC-ADM-03-01).
 *
 * Accounts: the seeded demo staff (password Momos-Dev-2026!); Kitahama = DKH, Namba = DNB.
 * Data created here is synthetic (QA-STAFF markers, example.test addresses) and inserted via bin/wp.
 */
import { test, expect, Page, Browser } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import * as path from 'node:path';

const BASE = (process.env.MOMOS_E2E_BASE_URL || process.env.MOMOS_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const ROOT = path.resolve(__dirname, '../../..');
const PASSWORD = 'Momos-Dev-2026!';

function wpEval(php: string): string {
  return execFileSync(path.join(ROOT, 'bin/wp'), ['eval', php], { cwd: ROOT, env: { ...process.env, MOMOS_URL: process.env.MOMOS_URL || BASE }, encoding: 'utf8' }).trim();
}
function clearThrottle() {
  wpEval(`global $wpdb; $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%momos_lt_%'"); wp_cache_flush(); echo 'ok';`);
}
function branchId(code: string): number {
  return parseInt(wpEval(`$b = get_posts( [ 'post_type' => 'momo_branch', 'post_status' => 'any', 'meta_key' => 'momo_branch_code', 'meta_value' => '${code}', 'fields' => 'ids' ] ); echo (int) $b[0];`), 10);
}
function userId(login: string): number {
  return parseInt(wpEval(`echo (int) get_user_by( 'login', '${login}' )->ID;`), 10);
}
/** Insert a synthetic pending reservation (JST date/time) and return its id. */
function pendingReservation(code: string, date: string, time = '18:00'): number {
  return parseInt(wpEval(`
    global $wpdb;
    $b = get_posts( [ 'post_type' => 'momo_branch', 'post_status' => 'any', 'meta_key' => 'momo_branch_code', 'meta_value' => '${code}', 'fields' => 'ids' ] );
    $start = ( new DateTimeImmutable( '${date} ${time}:00', new DateTimeZone( 'Asia/Tokyo' ) ) )->setTimezone( new DateTimeZone( 'UTC' ) );
    $now = gmdate( 'Y-m-d H:i:s' );
    $wpdb->insert( \\Momos\\Kernel\\Db\\Tables::reservations(), [
      'public_ref' => 'Q' . strtoupper( substr( md5( uniqid( '', true ) ), 0, 7 ) ), 'idempotency_key' => wp_generate_uuid4(), 'branch_id' => (int) $b[0], 'status' => 'pending',
      'requested_date' => '${date}', 'requested_time' => '${time}:00', 'requested_start_utc' => $start->format( 'Y-m-d H:i:s' ),
      'party_size' => 2, 'guest_name' => 'QA-STAFF Guest', 'email' => 'qa-staff-guest@example.test', 'preferred_lang' => 'en', 'source_lang' => 'en',
      'consent_version' => 'unversioned', 'consent_at' => $now, 'token_hash' => str_repeat( 'b', 64 ),
      'token_expires_utc' => $start->modify( '+1 day' )->format( 'Y-m-d H:i:s' ), 'created_at' => $now, 'updated_at' => $now,
    ] );
    echo (int) $wpdb->insert_id;`), 10);
}
function resStatus(id: number): string {
  return wpEval(`global $wpdb; echo $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}momo_reservations WHERE id = %d", ${id} ) );`);
}
function audit(action: string, where = '1=1'): number {
  return parseInt(wpEval(`global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}momo_audit_log WHERE action = %s AND ${where}", ${JSON.stringify(action)} ) );`), 10);
}

async function login(browser: Browser, user: string): Promise<Page> {
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  await page.goto(`${BASE}/wp-login.php`);
  await page.fill('#user_login', user);
  await page.fill('#user_pass', PASSWORD);
  await Promise.all([page.waitForURL(/wp-admin/), page.click('#wp-submit')]);
  return page;
}

/** Serialise one admin form (by selector) into a key/value object, as the browser would post it. */
async function formData(page: Page, selector: string): Promise<Record<string, string>> {
  return page.locator(selector).first().evaluate((f: HTMLFormElement) => {
    const out: Record<string, string> = {};
    new FormData(f).forEach((v, k) => { out[k] = String(v); });
    return out;
  });
}

test.beforeEach(() => clearThrottle());

// ------------------------------------------------------------------ Reservation Staff / Branch Manager

test('RS daily task: claim and accept a pending request of the own branch (audited, guest email queued)', async ({ browser }) => {
  const id = pendingReservation('DKH', '2026-12-03');
  const page = await login(browser, 'demo_rs_kitahama');
  await page.goto(`${BASE}/wp-admin/admin.php?page=momos-reservations&id=${id}`);
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('form:has(input[name="op"][value="claim"]) button[type="submit"]').click()]);
  await expect(page.locator('.notice-success, .updated').first()).toBeVisible();
  // The accept form sits in a collapsed panel; post it as the browser would after expanding it.
  const accept = await formData(page, 'form:has(input[name="op"][value="accept"])');
  const r = await page.request.post(`${BASE}/wp-admin/admin-post.php`, { form: accept, maxRedirects: 0 });
  expect(r.status()).toBe(302);
  expect(resStatus(id)).toBe('confirmed');
  expect(audit('reservation.claimed', `object_id = ${id}`)).toBeGreaterThanOrEqual(1);
  expect(audit('reservation.status_changed', `object_id = ${id}`)).toBeGreaterThanOrEqual(1);
  const queued = parseInt(wpEval(`global $wpdb; echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}momo_notifications WHERE reservation_id = ${id}" );`), 10);
  expect(queued, 'confirmation email to the guest queued (E-template)').toBeGreaterThanOrEqual(1);
  await page.context().close();
});

test('TC-ACL-006: replaying an own-branch action form against a Namba reservation (valid nonce) is refused', async ({ browser }) => {
  const own = pendingReservation('DKH', '2026-12-04');
  const foreign = pendingReservation('DNB', '2026-12-04');
  const page = await login(browser, 'demo_rs_kitahama');
  await page.goto(`${BASE}/wp-admin/admin.php?page=momos-reservations&id=${own}`);
  const data = await formData(page, 'form:has(input[name="op"][value="claim"])');
  const res = await page.request.post(`${BASE}/wp-admin/admin-post.php`, { form: { ...data, id: String(foreign) }, maxRedirects: 0 });
  expect([403], `replayed claim on foreign reservation → ${res.status()}`).toContain(res.status());
  expect(resStatus(foreign)).toBe('pending');
  expect(parseInt(wpEval(`global $wpdb; echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}momo_reservations WHERE id = ${foreign} AND handled_by IS NOT NULL" );`), 10), 'not claimed').toBe(0);
  await page.context().close();
});

test('TC-ACL-017: RS sets a dish sold out on the own branch grid; a crafted price change is not applied', async ({ browser }) => {
  const kita = branchId('DKH');
  const page = await login(browser, 'demo_rs_kitahama');
  await page.goto(`${BASE}/wp-admin/admin.php?page=momo-branch-menu&branch=${kita}`);
  const form = page.locator('form[id^="momos-bmg-row-"]').first();
  const formId = (await form.getAttribute('id'))!;
  const dish = parseInt(formId.replace('momos-bmg-row-', ''), 10);
  const priceBefore = wpEval(`global $wpdb; echo (string) $wpdb->get_var( "SELECT price_override_jpy FROM {$wpdb->prefix}momo_branch_menu_items WHERE branch_id = ${kita} AND dish_id = ${dish}" );`);
  const data = await formData(page, `#${formId}`);
  // Sold out via the documented quick action.
  let res = await page.request.post(`${BASE}/wp-admin/admin-post.php`, { form: { ...data, quick: 'sold_out_today' }, maxRedirects: 0 });
  expect(res.status()).toBe(302);
  expect(wpEval(`global $wpdb; echo $wpdb->get_var( "SELECT availability FROM {$wpdb->prefix}momo_branch_menu_items WHERE branch_id = ${kita} AND dish_id = ${dish}" );`)).toBe('sold_out');
  expect(audit('menu_item.availability_changed', `branch_id = ${kita}`)).toBeGreaterThanOrEqual(1);
  // Crafted price override (RS has no momo_edit_branch_prices).
  await page.goto(`${BASE}/wp-admin/admin.php?page=momo-branch-menu&branch=${kita}`);
  const data2 = await formData(page, `#${formId}`);
  res = await page.request.post(`${BASE}/wp-admin/admin-post.php`, { form: { ...data2, availability: 'available', price_override_jpy: '1' }, maxRedirects: 0 });
  const priceAfter = wpEval(`global $wpdb; echo (string) $wpdb->get_var( "SELECT price_override_jpy FROM {$wpdb->prefix}momo_branch_menu_items WHERE branch_id = ${kita} AND dish_id = ${dish}" );`);
  expect(priceAfter, 'price unchanged by RS').toBe(priceBefore);
  // Restore availability.
  await page.goto(`${BASE}/wp-admin/admin.php?page=momo-branch-menu&branch=${kita}`);
  await page.request.post(`${BASE}/wp-admin/admin-post.php`, { form: { ...(await formData(page, `#${formId}`)), quick: 'available' }, maxRedirects: 0 });
  await page.context().close();
});

test('BM daily task: add a closure day for the own branch (audited); the same form replayed for Namba is refused', async ({ browser }) => {
  const kita = branchId('DKH');
  const namba = branchId('DNB');
  const date = '2027-01-14';
  wpEval(`global $wpdb; $wpdb->query( "DELETE FROM {$wpdb->prefix}momo_branch_closures WHERE closure_date = '${date}'" ); echo 'ok';`);
  const page = await login(browser, 'demo_bm_kitahama');
  await page.goto(`${BASE}/wp-admin/edit.php?post_type=momo_branch&page=momos-hours&branch=${kita}&tab=closures`);
  const sel = 'form:has(input[name="date_from"])';
  const before = audit('branch.closure_created', `branch_id = ${kita}`);
  const data = await formData(page, sel);
  const post = { ...data, date_from: date, date_to: date, type: 'closed_all_day', public_reason: 'maintenance', internal_note: '' };
  // Remove empty special-hours periods (closed all day).
  for (const k of Object.keys(post)) if (k.startsWith('periods[')) delete (post as Record<string, string>)[k];
  let res = await page.request.post(`${BASE}/wp-admin/admin-post.php`, { form: post, maxRedirects: 0 });
  expect(res.status()).toBe(302);
  expect(parseInt(wpEval(`global $wpdb; echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}momo_branch_closures WHERE branch_id = ${kita} AND closure_date = '${date}'" );`), 10)).toBe(1);
  expect(audit('branch.closure_created', `branch_id = ${kita}`)).toBeGreaterThan(before);
  // Replay for Namba with the Kitahama nonce → 403, nothing stored (TC-ACL-009).
  res = await page.request.post(`${BASE}/wp-admin/admin-post.php`, { form: { ...post, branch_id: String(namba) }, maxRedirects: 0 });
  expect(res.status()).toBe(403);
  expect(parseInt(wpEval(`global $wpdb; echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}momo_branch_closures WHERE branch_id = ${namba} AND closure_date = '${date}'" );`), 10)).toBe(0);
  wpEval(`global $wpdb; $wpdb->query( "DELETE FROM {$wpdb->prefix}momo_branch_closures WHERE closure_date = '${date}'" ); echo 'ok';`);
  await page.context().close();
});

// ------------------------------------------------------------------ Head Office: users

test('TC-ACL-013 / AC-ADM-02-01: HO creates staff — RS without branch refused, with branch created and audited', async ({ browser }) => {
  const login1 = `qa_staff_new_${Date.now() % 100000}`;
  const kita = branchId('DKH');
  const page = await login(browser, 'demo_ho');
  await page.goto(`${BASE}/wp-admin/user-new.php`);
  await page.fill('#user_login', login1);
  await page.fill('#email', `${login1}@momos.example.test`);
  await page.fill('#first_name', 'QA');
  await page.fill('#last_name', 'Staff New');
  await page.selectOption('#role', 'momo_reservation_staff');
  if (await page.locator('#pass1').isVisible().catch(() => false)) await page.fill('#pass1', 'Kx7-moss-Tide-QA');
  const weak = page.locator('input[name="pw_weak"]');
  if (await weak.isVisible().catch(() => false)) await weak.check();
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('#createusersub')]);
  await expect(page.locator('#wpbody-content')).toContainText('少なくとも1つの店舗を割り当ててください。');
  expect(wpEval(`echo get_user_by( 'login', '${login1}' ) ? 'yes' : 'no';`)).toBe('no');
  // Now with a branch.
  await page.fill('#user_login', login1);
  await page.fill('#email', `${login1}@momos.example.test`);
  await page.fill('#first_name', 'QA');
  await page.fill('#last_name', 'Staff New');
  await page.selectOption('#role', 'momo_reservation_staff');
  if (await page.locator('#pass1').isVisible().catch(() => false)) await page.fill('#pass1', 'Kx7-moss-Tide-QA');
  if (await weak.isVisible().catch(() => false)) await weak.check();
  await page.check(`input[name="momos_branches[]"][value="${kita}"]`);
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('#createusersub')]);
  const uid = parseInt(wpEval(`$u = get_user_by( 'login', '${login1}' ); echo $u ? (int) $u->ID : 0;`), 10);
  expect(uid, 'user created').toBeGreaterThan(0);
  expect(wpEval(`echo implode( ',', get_userdata( ${uid} )->roles );`)).toBe('momo_reservation_staff');
  expect(audit('user.created', `object_id = ${uid}`)).toBe(1);
  expect(audit('user.branch_assigned', `object_id = ${uid} AND branch_id = ${kita}`)).toBe(1);
  await page.context().close();
});

test('TC-ACL-013 step 2: a crafted role=administrator from Head Office is refused (no elevation) and audited', async ({ browser }) => {
  const target = userId('demo_ce');
  const page = await login(browser, 'demo_ho');
  await page.goto(`${BASE}/wp-admin/user-edit.php?user_id=${target}`);
  const data = await formData(page, '#your-profile');
  const before = audit('access.denied');
  await page.request.post(`${BASE}/wp-admin/user-edit.php`, { form: { ...data, role: 'administrator' }, maxRedirects: 0 });
  expect(wpEval(`echo implode( ',', get_userdata( ${target} )->roles );`)).toBe('momo_content_editor');
  expect(audit('access.denied'), 'crafted elevation audited as access.denied').toBeGreaterThan(before);
  await page.context().close();
});

test('TC-ACL-013 step 3 / E12: a Branch Manager cannot change own role, branches, disabled flag or CAP-DCA by crafted profile POST', async ({ browser }) => {
  const bm = userId('demo_bm_kitahama');
  const namba = branchId('DNB');
  const page = await login(browser, 'demo_bm_kitahama');
  await page.goto(`${BASE}/wp-admin/profile.php`);
  const data = await formData(page, '#your-profile');
  const before = audit('access.denied', `actor_id = ${bm}`);
  await page.request.post(`${BASE}/wp-admin/profile.php`, {
    form: { ...data, role: 'momo_head_office', 'momos_branches[]': String(namba), momos_dca_present: '1', momos_dca: '1', momos_disabled_present: '1' },
    maxRedirects: 0,
  });
  expect(wpEval(`echo implode( ',', get_userdata( ${bm} )->roles );`)).toBe('momo_branch_manager');
  expect(wpEval(`global $wpdb; echo implode( ',', $wpdb->get_col( "SELECT branch_id FROM {$wpdb->prefix}momo_user_branches WHERE user_id = ${bm}" ) );`)).toBe(String(branchId('DKH')));
  expect(wpEval(`echo user_can( ${bm}, 'momo_edit_dietary_claims' ) ? 'yes' : 'no';`)).toBe('no');
  expect(audit('access.denied', `actor_id = ${bm}`)).toBeGreaterThan(before);
  await page.context().close();
});

// ------------------------------------------------------------------ Head Office: DSR, SEO, audit, settings

test('HO daily task: DSR search by reference finds the record and is audited without the search term (PRV-016)', async ({ browser }) => {
  const id = pendingReservation('DNB', '2026-12-10');
  const ref = wpEval(`global $wpdb; echo $wpdb->get_var( "SELECT public_ref FROM {$wpdb->prefix}momo_reservations WHERE id = ${id}" );`);
  const page = await login(browser, 'demo_ho');
  await page.goto(`${BASE}/wp-admin/admin.php?page=momos-dsr`);
  await page.fill('input[name="q"]', ref);
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('form:has(input[name="q"]) button[type="submit"]').click()]);
  // References are displayed in Crockford groups (XXXX-XXXX).
  await expect(page.locator('#wpbody-content')).toContainText(ref.slice(0, 4) + '-' + ref.slice(4));
  const row = wpEval(`global $wpdb; echo (string) $wpdb->get_var( "SELECT changes FROM {$wpdb->prefix}momo_audit_log WHERE action = 'dsr.searched' ORDER BY id DESC LIMIT 1" );`);
  expect(row, 'dsr.searched row written').not.toBe('');
  expect(row).not.toContain('qa-staff-guest@example.test');
  await page.context().close();
});

test('HO daily task: add a redirect on Momo\'s → Redirections; it is listed and works', async ({ browser }) => {
  const from = `/qa-staff-old-${Date.now() % 100000}/`;
  const page = await login(browser, 'demo_ho');
  await page.goto(`${BASE}/wp-admin/admin.php?page=momos-seo`);
  const form = page.locator('form:has(input[name="from"])');
  await form.locator('input[name="from"]').fill(from);
  await form.locator('input[name="to"]').fill('/menu/');
  await Promise.all([page.waitForLoadState('domcontentloaded'), form.locator('[type="submit"]').first().click()]);
  await expect(page.locator('#wpbody-content')).toContainText(from);
  const r = await page.request.get(`${BASE}${from}`, { maxRedirects: 0 });
  expect([301, 302, 307, 308]).toContain(r.status());
  expect(r.headers()['location']).toContain('/menu/');
  await page.context().close();
});

test('TC-ADM-002 / SCR-A16: audit viewer filters by action and shows masked changes; no edit/delete path', async ({ browser }) => {
  const page = await login(browser, 'demo_ho');
  await page.goto(`${BASE}/wp-admin/admin.php?page=momos-audit&audit_action=access.denied&from=2026-01-01`);
  const text = await page.locator('#wpbody-content table').last().innerText();
  expect(text).toContain('access.denied');
  expect(text).not.toMatch(/user\.created|reservation\.claimed/);
  // Append-only: no edit/delete controls or handlers.
  const html = await page.content();
  expect(html).not.toMatch(/action=momos_audit_(delete|edit|update)/);
  const del = await page.request.post(`${BASE}/wp-admin/admin-post.php`, { form: { action: 'momos_audit_delete', id: '1' }, maxRedirects: 0 });
  expect([400, 403, 404, 500]).toContain(del.status());
  expect(parseInt(wpEval(`global $wpdb; echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}momo_audit_log WHERE id = 1" );`), 10)).toBe(1);
  await page.context().close();
});

test('SCR-A15: retention setting outside 1–60 months is rejected; a valid change is audited settings.updated', async ({ browser }) => {
  const before = wpEval(`echo (string) \\Momos\\Kernel\\Settings::get( 'momo_retention_pii_months', '12' );`);
  const page = await login(browser, 'demo_ho');
  await page.goto(`${BASE}/wp-admin/admin.php?page=momo-settings&tab=privacy`);
  const input = page.locator('[name="momos_fields[momo_retention_pii_months]"]');
  await expect(input).toBeVisible();
  await input.fill('61');
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('#wpbody-content form [type="submit"]').last().click()]);
  expect(wpEval(`\\Momos\\Kernel\\Settings::flush(); echo (string) \\Momos\\Kernel\\Settings::get( 'momo_retention_pii_months', '12' );`)).toBe(before);
  const n = audit('settings.updated');
  await page.goto(`${BASE}/wp-admin/admin.php?page=momo-settings&tab=privacy`);
  await page.locator('[name="momos_fields[momo_retention_pii_months]"]').fill(before === '13' ? '12' : '13');
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('#wpbody-content form [type="submit"]').last().click()]);
  expect(audit('settings.updated')).toBeGreaterThan(n);
  // restore
  wpEval(`\\Momos\\Kernel\\Settings::set( 'momo_retention_pii_months', '${before}' ); echo 'ok';`);
  await page.context().close();
});

// ------------------------------------------------------------------ Translator / Menu Manager / Content Editor

test('Translator daily task: an interface string whose placeholders differ from the source is not saved', async ({ browser }) => {
  const key = 'res.submitted.reference';
  const page = await login(browser, 'demo_tr');
  await page.goto(`${BASE}/wp-admin/admin.php?page=momos-strings&lang=en&q=${encodeURIComponent(key)}`);
  const ta = page.locator(`textarea[name="values[${key}]"]`);
  await expect(ta).toBeVisible();
  await ta.fill('Your reference: {reference}');
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('form:has(textarea) [type="submit"]').first().click()]);
  await expect(page.locator('#wpbody-content')).toContainText(/placeholders must match|プレースホルダー/);
  expect(wpEval(`echo momos_t( '${key}', [ 'ref' => 'X' ], 'en' );`)).not.toContain('{reference}');
  await page.context().close();
});

test('TC-ACL-016: Menu Manager edits dishes but cannot publish them (submit for review) nor edit claim fields', async ({ browser }) => {
  const page = await login(browser, 'demo_mm');
  await page.goto(`${BASE}/wp-admin/post-new.php?post_type=momo_dish`);
  await expect(page.locator('#publish')).toBeVisible();
  const label = (await page.locator('#publish').getAttribute('value')) || '';
  expect(label, 'MM sees "Submit for Review", not "Publish" (DEC-026)').toMatch(/Submit for Review|レビュー待ちとして送信|レビュー/);
  // Halal / allergen claim inputs are not editable for MM (CAP-DCA only).
  const editableClaims = await page.locator('[name^="momos_fields[momo_alg_"]:not([disabled]):not([readonly]), [name^="momos_fields[momo_halal"]:not([disabled]):not([readonly]), [name^="momos_fields[momo_alcohol"]:not([disabled]):not([readonly])').count();
  expect(editableClaims).toBe(0);
  await page.context().close();
});

test('TC-ACL-014: Content Editor cannot publish an offer (submit for review only) nor edit branch identity', async ({ browser }) => {
  const page = await login(browser, 'demo_ce');
  await page.goto(`${BASE}/wp-admin/post-new.php?post_type=momo_offer`);
  const label = (await page.locator('#publish').getAttribute('value')) || '';
  expect(label).toMatch(/Submit for Review|レビュー待ちとして送信|レビュー/);
  const kita = branchId('DKH');
  await page.goto(`${BASE}/wp-admin/post.php?post=${kita}&action=edit`);
  const identity = await page.locator('[name="momos_fields[momo_phone_e164]"]:not([disabled]):not([readonly]), [name="momos_fields[momo_address_line1]"]:not([disabled]):not([readonly]), [name="momos_fields[momo_branch_code]"]:not([disabled]):not([readonly])').count();
  expect(identity, 'identity fields not editable by CE').toBe(0);
  await page.context().close();
});

test('DEC-037: a ja Head Office sees the dish editor field labels in Japanese (no English momos labels)', async ({ browser }) => {
  const page = await login(browser, 'demo_ho');
  await page.goto(`${BASE}/wp-admin/post-new.php?post_type=momo_dish`);
  const text = await page.locator('#wpbody-content').innerText();
  const english = ['Dish name', 'Meta description', 'Social share title', 'Social share description'].filter((s) => text.includes(s));
  expect(english, `untranslated momos labels for a ja user: ${english.join(', ')}`).toEqual([]);
  await page.context().close();
});

test('GATE-04 / FR-I18N-009: HO can hide a language, but re-enabling with LCCS gaps and no native sign-off is refused', async ({ browser }) => {
  const enabledBefore = wpEval(`echo wp_json_encode( get_option( 'momos_languages_enabled', null ) );`);
  const stateBefore = wpEval(`echo wp_json_encode( get_option( 'momos_language_gate', null ) );`);
  const page = await login(browser, 'demo_ho');
  try {
    await page.goto(`${BASE}/wp-admin/admin.php?page=momo-settings&tab=languages`);
    const disable = await formData(page, 'form:has(input[name="lang"][value="bn"])');
    expect(disable.op).toBe('disable');
    let r = await page.request.post(`${BASE}/wp-admin/admin-post.php`, { form: disable, maxRedirects: 0 });
    expect(r.status()).toBe(302);
    expect(wpEval(`echo in_array( 'bn', \\Momos\\Kernel\\I18n\\Lang::enabled(), true ) ? 'on' : 'off';`)).toBe('off');
    expect(audit('language.disabled')).toBeGreaterThanOrEqual(1);
    await page.goto(`${BASE}/wp-admin/admin.php?page=momo-settings&tab=languages`);
    const enable = await formData(page, 'form:has(input[name="lang"][value="bn"])');
    expect(enable.op).toBe('enable');
    r = await page.request.post(`${BASE}/wp-admin/admin-post.php`, { form: enable, maxRedirects: 0 });
    expect(wpEval(`echo in_array( 'bn', \\Momos\\Kernel\\I18n\\Lang::enabled(), true ) ? 'on' : 'off';`), 'enable refused while LCCS < 100 % (GATE-04)').toBe('off');
  } finally {
    // Restore exactly (an option that did not exist is deleted again, so the dev default "all enabled" applies).
    wpEval(`foreach ( [ 'momos_languages_enabled' => '${enabledBefore}', 'momos_language_gate' => '${stateBefore}' ] as $k => $json ) { $v = json_decode( $json, true ); if ( null === $v ) { delete_option( $k ); } else { update_option( $k, $v, true ); } } echo 'ok';`);
  }
  await page.context().close();
});

test('i18n §8 two-person rule: a translator cannot approve their own change of a review-class string (res.*)', async ({ browser }) => {
  const key = 'res.title';
  const page = await login(browser, 'demo_tr');
  await page.goto(`${BASE}/wp-admin/admin.php?page=momos-strings&lang=ar&q=${encodeURIComponent(key)}`);
  const ta = page.locator(`textarea[name="values[${key}]"]`);
  await expect(ta).toBeVisible();
  await ta.fill(((await ta.inputValue()) || 'طلب طاولة') + ' ');
  await ta.fill(`${(await ta.inputValue()).trim()} QA`);
  await page.selectOption(`select[name="status[${key}]"]`, 'approved');
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('form:has(textarea) [type="submit"]').first().click()]);
  await expect(page.locator('#wpbody-content')).toContainText(/cannot approve your own translation|自分の翻訳を承認/);
  const status = wpEval(`$r = \\Momos\\Kernel\\I18n\\Strings::record( '${key}', 'ar' ); echo (string) ( $r['status'] ?? '' );`);
  expect(status).not.toBe('approved');
  await page.context().close();
});

/** Synthetic offer authored by `author`, scoped to the given branch codes (or all branches). */
function offer(author: string, status: string, codes: string[] | 'all'): number {
  return parseInt(wpEval(`
    $u = get_user_by( 'login', '${author}' );
    $id = wp_insert_post( [ 'post_type' => 'momo_offer', 'post_status' => '${status}', 'post_title' => 'QA-STAFF offer ' . wp_generate_password( 6, false ), 'post_author' => $u->ID ] );
    update_post_meta( $id, 'momo_offer_summary', 'QA summary' );
    update_post_meta( $id, 'momo_offer_conditions', 'QA conditions' );
    update_post_meta( $id, 'momo_offer_start_at', '2026-11-01 00:00:00' );
    update_post_meta( $id, 'momo_offer_end_at', '2027-03-31 23:59:00' );
    update_post_meta( $id, 'momo_offer_cta_type', 'none' );
    ${codes === 'all' ? `update_post_meta( $id, 'momo_offer_scope', 'all_branches' );` : `update_post_meta( $id, 'momo_offer_scope', 'branches' );
    $terms = [];
    foreach ( ${JSON.stringify(codes)} as $c ) { $b = get_posts( [ 'post_type' => 'momo_branch', 'post_status' => 'any', 'meta_key' => 'momo_branch_code', 'meta_value' => $c, 'fields' => 'ids' ] ); $t = \\Momos\\Branch\\PostType\\BranchScopeTaxonomy::term_for_branch( (int) $b[0] ); $terms[] = (int) $t->term_id; }
    wp_set_object_terms( $id, $terms, \\Momos\\Branch\\PostType\\BranchScopeTaxonomy::TAX );`}
    echo (int) $id;`), 10);
}

test('Offer approval (BR-CORP-02): HO publishes a CE submission; CE can no longer edit it; audited offer.published', async ({ browser }) => {
  const id = offer('demo_ce', 'pending', 'all');
  const ho = await login(browser, 'demo_ho');
  await ho.goto(`${BASE}/wp-admin/post.php?post=${id}&action=edit`);
  await expect(ho.locator('#publish')).toBeVisible();
  await Promise.all([ho.waitForLoadState('domcontentloaded'), ho.click('#publish')]);
  const status = wpEval(`echo get_post_status( ${id} );`);
  expect(['publish', 'future']).toContain(status);
  expect(audit('offer.published', `object_id = ${id}`)).toBeGreaterThanOrEqual(1);
  expect(wpEval(`echo (string) get_post_meta( ${id}, 'momo_offer_approved_by', true );`)).toBe(String(userId('demo_ho')));
  await ho.context().close();
  const ce = await login(browser, 'demo_ce');
  const r = await ce.request.get(`${BASE}/wp-admin/post.php?post=${id}&action=edit`, { maxRedirects: 0 });
  expect(r.status(), 'CE editing a published offer → 403 (M3)').toBe(403);
  await ce.context().close();
});

test('TC-ACL-021: BM edits only offers/notices scoped to the own branch; Namba-scoped and all-branch items are 403', async ({ browser }) => {
  const own = offer('demo_bm_kitahama', 'draft', ['DKH']);
  const foreign = offer('demo_bm_namba', 'draft', ['DNB']);
  const global = offer('demo_ho', 'draft', 'all');
  const bm = await login(browser, 'demo_bm_kitahama');
  const ok = await bm.request.get(`${BASE}/wp-admin/post.php?post=${own}&action=edit`, { maxRedirects: 0 });
  expect(ok.status()).toBe(200);
  for (const [name, pid] of [['Namba offer', foreign], ['all-branch offer', global]] as [string, number][]) {
    const r = await bm.request.get(`${BASE}/wp-admin/post.php?post=${pid}&action=edit`, { maxRedirects: 0 });
    expect(r.status(), `${name} → 403`).toBe(403);
  }
  // The offers list shows only the own-branch draft, not the Namba one.
  const list = await (await bm.request.get(`${BASE}/wp-admin/edit.php?post_type=momo_offer&post_status=draft`)).text();
  expect(list).toContain(`post=${own}&amp;action=edit`);
  expect(list).not.toContain(`post=${foreign}&amp;action=edit`);
  // RS: offers are read-only (no edit screen at all).
  const rs = await login(browser, 'demo_rs_kitahama');
  const r2 = await rs.request.get(`${BASE}/wp-admin/post.php?post=${own}&action=edit`, { maxRedirects: 0 });
  expect(r2.status()).toBe(403);
  await bm.context().close();
  await rs.context().close();
});

test('FR-DIET-008 two-person rule: a CAP-DCA approver cannot record themself as the kitchen source; without CAP-DCA the claim fields are ignored', async ({ browser }) => {
  const dish = parseInt(wpEval(`$d = get_posts( [ 'post_type' => 'momo_dish', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => 'momo_alg_sourced_by', 'meta_compare' => 'EXISTS' ] ); echo (int) $d[0];`), 10);
  const before = wpEval(`echo (string) get_post_meta( ${dish}, 'momo_alg_sourced_by', true ) . '|' . (string) get_post_meta( ${dish}, 'momo_alg_wheat', true );`);
  const ho = await login(browser, 'demo_ho'); // holds CAP-DCA in the seed
  await ho.goto(`${BASE}/wp-admin/post.php?post=${dish}&action=edit`);
  await ho.fill('[name="momos_fields[momo_alg_sourced_by]"]', 'Demo Head Office');
  const attest = ho.locator('[name="momos_fields[momo_alg_attest_not_source]"][type="checkbox"]');
  if (await attest.count()) await attest.check();
  await Promise.all([ho.waitForLoadState('domcontentloaded'), ho.click('#publish')]);
  await expect(ho.locator('#wpbody-content')).toContainText(/two-person rule|2 人確認|二人|別の人/);
  expect(wpEval(`echo (string) get_post_meta( ${dish}, 'momo_alg_sourced_by', true ) . '|' . (string) get_post_meta( ${dish}, 'momo_alg_wheat', true );`), 'record unchanged').toBe(before);
  await ho.context().close();

  // Menu Manager (no CAP-DCA): a crafted allergen change in the dish form is not applied (M4 / ClaimFieldGuard).
  const mm = await login(browser, 'demo_mm');
  await mm.goto(`${BASE}/wp-admin/post.php?post=${dish}&action=edit`);
  const data = await formData(mm, '#post');
  const flipped = before.split('|')[1] === 'contains' ? 'not_contained' : 'contains';
  await mm.request.post(`${BASE}/wp-admin/post.php`, { form: { ...data, 'momos_fields[momo_alg_wheat]': flipped, '_momos_rendered[]': 'momo_alg_wheat', 'momos_fields[momo_alg_sourced_by]': 'Someone Else', 'momos_fields[momo_alg_attest_not_source]': '1' }, maxRedirects: 0 });
  expect(wpEval(`echo (string) get_post_meta( ${dish}, 'momo_alg_sourced_by', true ) . '|' . (string) get_post_meta( ${dish}, 'momo_alg_wheat', true );`), 'MM cannot change claim data').toBe(before);
  await mm.context().close();
});

test('M11 / TC-ACL-024: the halal register is read-only for MM/BM and evidence download is refused without CAP-DCA', async ({ browser }) => {
  const cert = parseInt(wpEval(`
    $id = wp_insert_post( [ 'post_type' => 'momo_certificate', 'post_status' => 'publish', 'post_title' => 'QA-STAFF certificate' ] );
    $b = get_posts( [ 'post_type' => 'momo_branch', 'post_status' => 'any', 'meta_key' => 'momo_branch_code', 'meta_value' => 'DNB', 'fields' => 'ids' ] );
    update_post_meta( $id, 'momo_cert_branches', [ (int) $b[0] ] );
    echo (int) $id;`), 10);
  for (const user of ['demo_mm', 'demo_bm_kitahama']) {
    const p = await login(browser, user);
    const edit = await p.request.get(`${BASE}/wp-admin/post.php?post=${cert}&action=edit`, { maxRedirects: 0 });
    expect(edit.status(), `${user} cannot open the certificate editor`).toBe(403);
    for (const action of ['momos_evidence_download', 'momos_download_evidence']) {
      const dl = await p.request.get(`${BASE}/wp-admin/admin-post.php?action=${action}&id=${cert}&certificate=${cert}`, { maxRedirects: 0 });
      expect([400, 403, 404], `${user} ${action} → ${dl.status()}`).toContain(dl.status());
    }
    const reg = await (await p.request.get(`${BASE}/wp-admin/admin.php?page=momo-halal-register`)).text();
    if (user === 'demo_bm_kitahama') expect(reg, 'BM register does not list the Namba certificate (M12)').not.toContain('QA-STAFF certificate');
    await p.context().close();
  }
});

test('HO daily task: the 404 log on Momo\'s → Redirections lists a missing URL (path only, no query string)', async ({ browser }) => {
  const missing = `/qa-staff-missing-${Date.now() % 100000}/`;
  const page = await login(browser, 'demo_ho');
  const r = await page.request.get(`${BASE}${missing}?email=qa-staff-guest@example.test`, { maxRedirects: 0 });
  expect(r.status()).toBe(404);
  await page.goto(`${BASE}/wp-admin/admin.php?page=momos-seo&tab=notfound`);
  const text = await page.locator('#wpbody-content').innerText();
  expect(text).toContain(missing);
  expect(text, 'no query string / PII in the 404 log (FR-PRV-005)').not.toContain('qa-staff-guest@example.test');
  await page.context().close();
});
