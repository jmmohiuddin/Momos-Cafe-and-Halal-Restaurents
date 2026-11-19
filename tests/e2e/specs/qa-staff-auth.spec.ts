/**
 * QA (staff team) — staff authentication end to end in the browser (SCR-A01, WF-ADM-01; access spec
 * §5.1–§5.5; Doc 12 TC-SEC-001/002/019/023/024, TC-ADM-004/008/009, TC-ACL-011, AC-SEC-01-01/02).
 *
 *  - 2FA is switched to "enforced for all staff" for the duration of this file (setting momo_2fa_enforce,
 *    production behaviour) and switched back afterwards.
 *  - TOTP codes are computed in the test from the manual-entry key shown on the enrolment screen
 *    (RFC 6238, HMAC-SHA1, 6 digits, 30 s).
 *  - Each test uses its own synthetic QA account (qa_staff_auth_*), created through bin/wp.
 *  - Login throttling state is cleared before every test (all requests come from 127.0.0.1).
 */
import { test, expect, Page, BrowserContext } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import * as crypto from 'node:crypto';
import * as path from 'node:path';
import AxeBuilder from '@axe-core/playwright';

const BASE = (process.env.MOMOS_E2E_BASE_URL || process.env.MOMOS_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const ROOT = path.resolve(__dirname, '../../..');
const PW = 'QA-Staff-Auth-2026!x';
const GENERIC_JA = 'ユーザー名またはパスワードが正しくありません。';
const GENERIC_EN = 'Incorrect username or password.';
const GENERIC = [GENERIC_JA, GENERIC_EN];

function wpEval(php: string): string {
  return execFileSync(path.join(ROOT, 'bin/wp'), ['eval', php], { cwd: ROOT, env: { ...process.env, MOMOS_URL: process.env.MOMOS_URL || BASE }, encoding: 'utf8' }).trim();
}
function clearThrottle() {
  wpEval(`global $wpdb; $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%momos_lt_%'"); wp_cache_flush(); echo 'ok';`);
}
/** Create (or reset) a synthetic staff user; returns its ID. Branch roles get the Kitahama branch. */
function qaUser(login: string, role: string, locale = 'ja', extraPhp = ''): number {
  return parseInt(wpEval(`
    $login = ${JSON.stringify(login)}; $role = ${JSON.stringify(role)};
    $u = get_user_by( 'login', $login );
    $id = $u ? (int) $u->ID : (int) wp_insert_user( [ 'user_login' => $login, 'user_pass' => ${JSON.stringify(PW)}, 'user_email' => $login . '@momos.example.test', 'display_name' => 'QA ' . $login, 'role' => $role ] );
    wp_set_password( ${JSON.stringify(PW)}, $id );
    ( new WP_User( $id ) )->set_role( $role );
    update_user_meta( $id, 'locale', ${JSON.stringify(locale)} );
    foreach ( [ 'momo_2fa_secret', 'momo_2fa_enrolled_at', 'momo_2fa_last_step', 'momo_2fa_recovery', 'momo_2fa_challenge', 'momo_account_disabled', 'momo_access_expires_at' ] as $k ) { delete_user_meta( $id, $k ); }
    if ( in_array( $role, [ 'momo_branch_manager', 'momo_reservation_staff' ], true ) ) {
      $b = get_posts( [ 'post_type' => 'momo_branch', 'post_status' => 'any', 'meta_key' => 'momo_branch_code', 'meta_value' => 'DKH', 'fields' => 'ids' ] );
      \\Momos\\Access\\Users\\AssignmentService::set( $id, [ (int) $b[0] ], 1 );
    }
    WP_Session_Tokens::get_instance( $id )->destroy_all();
    ${extraPhp}
    echo $id;`), 10);
}
function auditCount(action: string, userId: number): number {
  return parseInt(wpEval(`global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}momo_audit_log WHERE action = %s AND (object_id = %d OR actor_id = %d)", ${JSON.stringify(action)}, ${userId}, ${userId} ) );`), 10);
}

// ---------------------------------------------------------------- RFC 6238 TOTP
function base32Decode(s: string): Buffer {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  const clean = s.replace(/[\s=-]/g, '').toUpperCase();
  let bits = '';
  for (const ch of clean) bits += alphabet.indexOf(ch).toString(2).padStart(5, '0');
  const out: number[] = [];
  for (let i = 0; i + 8 <= bits.length; i += 8) out.push(parseInt(bits.slice(i, i + 8), 2));
  return Buffer.from(out);
}
function totp(secret: string, step: number): string {
  const msg = Buffer.alloc(8);
  msg.writeBigUInt64BE(BigInt(step));
  const h = crypto.createHmac('sha1', base32Decode(secret)).update(msg).digest();
  const o = h[h.length - 1] & 0xf;
  return ((h.readUInt32BE(o) & 0x7fffffff) % 1_000_000).toString().padStart(6, '0');
}
const nowStep = () => Math.floor(Date.now() / 1000 / 30);

// ---------------------------------------------------------------- flows
async function passwordStep(page: Page, login: string, password = PW, extra?: (p: Page) => Promise<void>) {
  await page.goto(`${BASE}/wp-login.php`);
  await page.fill('#user_login', login);
  await page.fill('#user_pass', password);
  if (extra) await extra(page);
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('#wp-submit')]);
}

/** Complete the enrolment wizard; returns { secret, codes, step }. */
async function enrol(page: Page): Promise<{ secret: string; codes: string[]; step: number }> {
  await expect(page.locator('form.momos-2fa input[name="momos_step"][value="enrol"]')).toHaveCount(1);
  const secret = (await page.locator('.momos-2fa-secret').innerText()).trim();
  expect(secret.replace(/\s/g, '')).toMatch(/^[A-Z2-7]{32}$/);
  const step = nowStep();
  await page.fill('#momos_code', totp(secret, step));
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('form.momos-2fa button[type="submit"]')]);
  const codes = (await page.locator('.momos-2fa-codes li').allInnerTexts()).map((c) => c.trim());
  await page.check('input[name="momos_saved"]');
  await Promise.all([page.waitForURL(/wp-admin/), page.click('form.momos-2fa button[type="submit"]')]);
  return { secret, codes, step };
}

async function logout(page: Page) {
  await page.goto(`${BASE}/wp-admin/`);
  const href = await page.locator('#wp-admin-bar-logout a').getAttribute('href');
  await page.goto(href!);
}

async function loginError(page: Page): Promise<string> {
  return (await page.locator('#login_error').innerText()).trim();
}

async function loggedInCookie(ctx: BrowserContext) {
  return (await ctx.cookies()).find((c) => c.name.startsWith('wordpress_logged_in_'));
}

test.describe.configure({ mode: 'default' });

test.beforeAll(() => {
  wpEval(`\\Momos\\Kernel\\Settings::set( 'momo_2fa_enforce', '1' ); echo 'ok';`);
});
test.afterAll(() => {
  wpEval(`\\Momos\\Kernel\\Settings::set( 'momo_2fa_enforce', '0' ); delete_option( 'momos_frozen_now' ); echo 'ok';`);
  clearThrottle();
});
test.beforeEach(() => clearThrottle());

const ROLES: [string, string, RegExp][] = [
  ['sa', 'administrator', /\/wp-admin\/(index\.php)?$/],
  ['ho', 'momo_head_office', /\/wp-admin\/(index\.php)?$/],
  ['mm', 'momo_menu_manager', /\/wp-admin\/(index\.php)?$/],
  ['ce', 'momo_content_editor', /\/wp-admin\/(index\.php)?$/],
  ['tr', 'momo_translator', /\/wp-admin\/(index\.php)?$/],
  ['bm', 'momo_branch_manager', /page=momos-reservations&status=pending/],
  ['rs', 'momo_reservation_staff', /page=momos-reservations&status=pending/],
];

for (const [short, role, landing] of ROLES) {
  test(`TC-SEC-001 / TC-ADM-004: ${short.toUpperCase()} must enrol in 2FA before any admin page or staff API`, async ({ page, context }) => {
    const login = `qa_staff_auth_${short}`;
    qaUser(login, role);
    await passwordStep(page, login);
    // Pre-auth state: no auth cookie, enrolment wizard with a local QR code + manual key.
    expect(await loggedInCookie(context), 'no logged-in cookie before the second factor').toBeUndefined();
    await expect(page.locator('form.momos-2fa svg')).toHaveCount(1);
    await expect(page.locator('.momos-2fa-secret')).toBeVisible();
    // No admin page and no staff REST in the pre-auth state.
    const admin = await page.request.get(`${BASE}/wp-admin/`, { maxRedirects: 0 });
    expect(admin.status()).toBe(302);
    expect(admin.headers()['location']).toContain('wp-login.php');
    const rest = await page.request.get(`${BASE}/wp-json/wp/v2/users/me`, { maxRedirects: 0 });
    expect(rest.status()).toBe(401);
    // Enrol with a TOTP code computed from the key → 10 recovery codes once → role start screen.
    const { codes } = await enrol(page);
    expect(codes).toHaveLength(10);
    for (const c of codes) expect(c).toMatch(/^[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$/);
    expect(new Set(codes).size).toBe(10);
    expect(page.url()).toMatch(landing);
    expect(await loggedInCookie(context)).toBeDefined();
  });
}

test('TC-SEC-001: TOTP login (replay refused), single-use recovery code, five wrong codes restart', async ({ page, context }) => {
  const login = 'qa_staff_auth_rs2';
  const uid = qaUser(login, 'momo_reservation_staff');
  await passwordStep(page, login);
  const { secret, codes, step } = await enrol(page);
  await logout(page);

  // Replay of the enrolment code is refused (step ≤ last used step).
  await passwordStep(page, login);
  await expect(page.locator('input[name="momos_step"][value="verify"]')).toHaveCount(1);
  await expect(page.locator('#momos_code')).toHaveAttribute('autocomplete', 'one-time-code');
  await expect(page.locator('#momos_code')).toHaveAttribute('inputmode', 'numeric');
  await page.fill('#momos_code', totp(secret, step));
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('form.momos-2fa button[type="submit"]')]);
  await expect(page.locator('#login_error')).toContainText('コードが正しくありません');
  // The next time-step (inside the ±1 window) is accepted.
  await page.fill('#momos_code', totp(secret, step + 1));
  await Promise.all([page.waitForURL(/wp-admin/), page.click('form.momos-2fa button[type="submit"]')]);
  expect(await loggedInCookie(context)).toBeDefined();
  await logout(page);

  // Recovery code: works once.
  await passwordStep(page, login);
  await page.locator('#momos-recovery summary').click();
  await page.fill('#momos_recovery', codes[0]);
  await Promise.all([page.waitForURL(/wp-admin/), page.click('form.momos-2fa button[type="submit"]')]);
  await logout(page);
  await passwordStep(page, login);
  await page.locator('#momos-recovery summary').click();
  await page.fill('#momos_recovery', codes[0]);
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('form.momos-2fa button[type="submit"]')]);
  await expect(page.locator('#login_error')).toContainText('コードが正しくありません');
  expect(auditCount('user.2fa_recovery_used', uid)).toBeGreaterThanOrEqual(1);

  // Five wrong codes in one challenge → back to the login form (restart), with a message.
  clearThrottle();
  await passwordStep(page, login);
  for (let i = 0; i < 5; i++) {
    await page.fill('#momos_code', '000000' === totp(secret, nowStep()) ? '111111' : '000000');
    await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('form.momos-2fa button[type="submit"]')]);
  }
  expect(page.url()).toContain('momos_2fa=too_many');
  await expect(page.locator('#user_login')).toBeVisible();
  await expect(page.locator('#login_error')).toBeVisible();
});

test('TC-SEC-024 step 4 / AC: Head Office "Reset two-factor" ends the user\'s sessions, is audited, and forces re-enrolment', async ({ browser }) => {
  const login = 'qa_staff_auth_bm2';
  const uid = qaUser(login, 'momo_branch_manager');
  const hoLogin = 'qa_staff_auth_ho2';
  qaUser(hoLogin, 'momo_head_office');

  const userCtx = await browser.newContext();
  const userPage = await userCtx.newPage();
  await passwordStep(userPage, login);
  await enrol(userPage);

  const hoCtx = await browser.newContext();
  const hoPage = await hoCtx.newPage();
  await passwordStep(hoPage, hoLogin);
  await enrol(hoPage);
  await hoPage.goto(`${BASE}/wp-admin/user-edit.php?user_id=${uid}`);
  await hoPage.check('input[name="momos_2fa_reset"]');
  await Promise.all([hoPage.waitForLoadState('domcontentloaded'), hoPage.click('#submit')]);
  await expect(hoPage.locator('#message, .notice-success').first()).toBeVisible();
  expect(auditCount('user.2fa_reset', uid)).toBeGreaterThanOrEqual(1);

  // The user's existing session is refused on its next request.
  const r = await userPage.request.get(`${BASE}/wp-admin/admin.php?page=momos-reservations`, { maxRedirects: 0 });
  expect(r.status()).toBe(302);
  expect(r.headers()['location']).toContain('wp-login.php');
  // Next login → enrolment wizard again.
  await passwordStep(userPage, login);
  await expect(userPage.locator('input[name="momos_step"][value="enrol"]')).toHaveCount(1);
  await userCtx.close();
  await hoCtx.close();
});

test('TC-SEC-002: wrong password, unknown user, disabled and expired accounts all get the same generic message', async ({ page }) => {
  qaUser('qa_staff_auth_gen', 'momo_reservation_staff');
  const dis = qaUser('qa_staff_auth_dis', 'momo_reservation_staff', 'ja', `update_user_meta( $id, 'momo_account_disabled', '1' );`);
  const exp = qaUser('qa_staff_auth_exp', 'momo_reservation_staff', 'ja', `update_user_meta( $id, 'momo_access_expires_at', gmdate( 'Y-m-d H:i:s', time() - 120 ) );`);
  const messages: Record<string, string> = {};
  await passwordStep(page, 'qa_staff_auth_gen', 'wrong-password-123');
  messages.wrong = await loginError(page);
  await passwordStep(page, 'qa_staff_auth_dis');
  messages.disabled = await loginError(page);
  await passwordStep(page, 'qa_staff_auth_exp');
  messages.expired = await loginError(page);
  await passwordStep(page, 'qa_staff_auth_gen@momos.example.test', 'wrong-password-123');
  messages.wrongByEmail = await loginError(page);
  await passwordStep(page, 'qa-no-such-user-xyz', 'wrong-password-123'); // locks the IP (SEC-004): run last
  messages.unknown = await loginError(page);
  // admin.login.err.generic (access spec §15.1) — identical for every case.
  expect(GENERIC, `generic message catalog value, got ${JSON.stringify(messages)}`).toContain(messages.wrong);
  for (const [k, v] of Object.entries(messages)) expect(v, k).toBe(messages.wrong);
  expect(auditCount('auth.disabled_login', dis)).toBeGreaterThanOrEqual(1);
  expect(auditCount('auth.disabled_login', exp)).toBeGreaterThanOrEqual(1);
  // Unknown-username lock: the next attempt from this IP, even with a correct password, is refused.
  await passwordStep(page, 'qa_staff_auth_gen');
  expect(await loginError(page)).toMatch(/\d{2}:\d{2} JST/);
});

test('TC-SEC-002 / AC-SEC-01-02: ten failures lock the account for 60 minutes with the retry time; correct password refused', async ({ page }) => {
  const uid = qaUser('qa_staff_auth_lock', 'momo_reservation_staff');
  for (let i = 1; i <= 9; i++) {
    await passwordStep(page, 'qa_staff_auth_lock', `wrong-password-${i}`);
    expect(GENERIC, `attempt ${i}`).toContain(await loginError(page));
  }
  await passwordStep(page, 'qa_staff_auth_lock', 'wrong-password-10');
  const msg = await loginError(page);
  const m = msg.match(/(\d{1,2}):(\d{2}) JST/);
  expect(m, `lock message shows the retry time: "${msg}"`).toBeTruthy();
  // Retry time ≈ now + 60 min (JST).
  const jstNow = new Date(Date.now() + 9 * 3600_000);
  const expected = new Date(jstNow.getTime() + 60 * 60_000);
  const shown = parseInt(m![1], 10) * 60 + parseInt(m![2], 10);
  const exp = expected.getUTCHours() * 60 + expected.getUTCMinutes();
  expect(Math.min(Math.abs(shown - exp), 1440 - Math.abs(shown - exp))).toBeLessThanOrEqual(1);
  await passwordStep(page, 'qa_staff_auth_lock');
  expect(await loginError(page)).toMatch(/JST/);
  expect(auditCount('auth.lockout', uid)).toBeGreaterThanOrEqual(1);
});

test('DEC-037 / SCR-A01: the login screen language matches its lang attribute and the 2FA screens (ja default)', async ({ page }) => {
  qaUser('qa_staff_auth_lang', 'momo_reservation_staff');
  await passwordStep(page, 'qa_staff_auth_lang', 'wrong-password-1');
  const lang = await page.locator('html').getAttribute('lang');
  const err = await loginError(page);
  const label = (await page.locator('label[for="user_login"]').innerText()).trim();
  const ja = /[\u3040-\u30ff\u4e00-\u9faf]/;
  // The site is Japanese-first (DEC-003/DEC-037): an html lang of "ja" must come with Japanese UI text.
  expect(lang).toMatch(/^ja/);
  expect(ja.test(err), `login error "${err}" is not Japanese although <html lang="${lang}">`).toBe(true);
  expect(ja.test(label), `login label "${label}" is not Japanese although <html lang="${lang}">`).toBe(true);
});

test('FR-SEC-002: a captcha (Turnstile) is required on the login form from the 3rd failure per IP', async ({ page }) => {
  qaUser('qa_staff_auth_cap', 'momo_reservation_staff');
  for (let i = 1; i <= 3; i++) await passwordStep(page, 'qa_staff_auth_cap', `wrong-password-${i}`);
  await page.goto(`${BASE}/wp-login.php`);
  const html = await page.content();
  expect(/cf-turnstile|challenges\.cloudflare\.com\/turnstile/.test(html), 'Turnstile widget on the login form after 3 failures (access spec §5.3 captcha step)').toBe(true);
});

test('TC-SEC-024 step 1 / TC-SEC-019: no Remember-me, cookie ≤ 14 h despite rememberme=forever, HttpOnly + SameSite=Lax', async ({ page, context }) => {
  const uid = qaUser('qa_staff_auth_rm', 'momo_content_editor');
  await page.goto(`${BASE}/wp-login.php`);
  await expect(page.locator('#rememberme')).not.toBeVisible();
  // Force the hidden checkbox on: the server must ignore it.
  await passwordStep(page, 'qa_staff_auth_rm', PW, async (p) => {
    await p.evaluate(() => {
      const cb = document.querySelector<HTMLInputElement>('#rememberme');
      if (cb) cb.checked = true;
      else {
        const i = document.createElement('input');
        i.type = 'hidden'; i.name = 'rememberme'; i.value = 'forever';
        document.querySelector('#loginform')!.appendChild(i);
      }
    });
  });
  await enrol(page);
  const now = Date.now() / 1000;
  for (const c of (await context.cookies()).filter((c) => /^wordpress_(logged_in|sec|[0-9a-f]{32})/.test(c.name))) {
    if (c.expires !== -1) expect(c.expires - now, `${c.name} expiry`).toBeLessThanOrEqual(14 * 3600 + 60);
    expect(c.httpOnly, `${c.name} HttpOnly`).toBe(true);
    expect(c.sameSite, `${c.name} SameSite`).toBe('Lax');
  }
  const lifetime = parseInt(wpEval(`$s = WP_Session_Tokens::get_instance( ${uid} )->get_all(); $s = end( $s ); echo (int) $s['expiration'] - (int) $s['login'];`), 10);
  expect(lifetime, 'absolute session lifetime').toBeLessThanOrEqual(14 * 3600);
});

test('TC-SEC-019: a logged-out cookie replayed in a new browser is rejected', async ({ page, context, browser }) => {
  qaUser('qa_staff_auth_lo', 'momo_reservation_staff');
  await passwordStep(page, 'qa_staff_auth_lo');
  await enrol(page);
  const cookies = await context.cookies();
  await logout(page);
  const ctx2 = await browser.newContext();
  await ctx2.addCookies(cookies);
  const r = await ctx2.request.get(`${BASE}/wp-admin/admin.php?page=momos-reservations`, { maxRedirects: 0 });
  expect(r.status()).toBe(302);
  expect(r.headers()['location']).toContain('wp-login.php');
  await ctx2.close();
});

test('TC-SEC-024 / TC-ADM-004: idle timeout of 8 h (frozen clock) ends the session; 7 h 59 min keeps it', async ({ page }) => {
  qaUser('qa_staff_auth_idle', 'momo_reservation_staff');
  await passwordStep(page, 'qa_staff_auth_idle');
  await enrol(page);
  const at = (minutes: number) => new Date(Date.now() + minutes * 60_000).toISOString();
  try {
    wpEval(`update_option( 'momos_frozen_now', ${JSON.stringify(at(7 * 60 + 59))} ); echo 'ok';`);
    let r = await page.request.get(`${BASE}/wp-admin/admin.php?page=momos-reservations`, { maxRedirects: 0 });
    expect(r.status(), 'still active at 7 h 59 min').toBe(200);
    // 8 h 01 min after that last activity → idle.
    wpEval(`update_option( 'momos_frozen_now', ${JSON.stringify(at(7 * 60 + 59 + 8 * 60 + 1))} ); echo 'ok';`);
    r = await page.request.get(`${BASE}/wp-admin/admin.php?page=momos-reservations`, { maxRedirects: 0 });
    expect(r.status()).toBe(302);
    expect(r.headers()['location']).toContain('reauth=1');
  } finally {
    wpEval(`delete_option( 'momos_frozen_now' ); echo 'ok';`);
  }
  const r2 = await page.request.get(`${BASE}/wp-admin/admin.php?page=momos-reservations`, { maxRedirects: 0 });
  expect(r2.status(), 'the idle session stays destroyed').toBe(302);
});

test('TC-SEC-023: password policy on the own profile — 11 and 129 chars and a breached password rejected, 12 accepted', async ({ page }) => {
  qaUser('qa_staff_auth_pw', 'momo_content_editor');
  await passwordStep(page, 'qa_staff_auth_pw');
  await enrol(page);
  const attempt = async (pw: string) => {
    await page.goto(`${BASE}/wp-admin/profile.php`);
    await page.click('.wp-generate-pw');
    await page.fill('#pass1', pw);
    const weak = page.locator('.pw-weak input[type="checkbox"], #pw-weak');
    if (await weak.isVisible().catch(() => false)) await weak.check();
    await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('#submit')]);
    return (await page.locator('#wpbody-content').innerText());
  };
  expect(await attempt('Abcdefghij1')).toContain('12〜128文字で入力してください。');
  expect(await attempt('A'.repeat(64) + 'b'.repeat(65))).toContain('12〜128文字で入力してください。');
  expect(await attempt('Password1234')).toContain('一般的すぎるか、流出が確認されています');
  const ok = await attempt('Kx7-moss-Tide');
  expect(ok).not.toContain('12〜128文字');
  expect(ok).not.toContain('流出が確認');
});

test('TC-SEC-024 step 3: changing the password ends the user\'s other sessions', async ({ browser }) => {
  const login = 'qa_staff_auth_two';
  const uid = qaUser(login, 'momo_content_editor');
  const a = await browser.newContext();
  const b = await browser.newContext();
  const pa = await a.newPage();
  const pb = await b.newPage();
  await passwordStep(pa, login);
  const { secret, step } = await enrol(pa);
  await passwordStep(pb, login);
  await pb.fill('#momos_code', totp(secret, step + 1));
  await Promise.all([pb.waitForURL(/wp-admin/), pb.click('form.momos-2fa button[type="submit"]')]);
  expect(parseInt(wpEval(`echo count( WP_Session_Tokens::get_instance( ${uid} )->get_all() );`), 10)).toBe(2);
  await pa.goto(`${BASE}/wp-admin/profile.php`);
  await pa.click('.wp-generate-pw');
  await pa.fill('#pass1', 'Kx7-moss-Tide-2');
  await Promise.all([pa.waitForLoadState('domcontentloaded'), pa.click('#submit')]);
  const rb = await pb.request.get(`${BASE}/wp-admin/`, { maxRedirects: 0 });
  expect(rb.status(), 'other session refused').toBe(302);
  const ra = await pa.request.get(`${BASE}/wp-admin/`, { maxRedirects: 0 });
  expect(ra.status(), 'own session kept').toBe(200);
  await a.close();
  await b.close();
});

test('TC-ACL-011 / TC-ADM-009: disable, branch change and role change by Head Office end the user\'s sessions immediately', async ({ browser }) => {
  const hoLogin = 'qa_staff_auth_ho3';
  qaUser(hoLogin, 'momo_head_office');
  const hoCtx = await browser.newContext();
  const ho = await hoCtx.newPage();
  await passwordStep(ho, hoLogin);
  await enrol(ho);
  const namba = wpEval(`$b = get_posts( [ 'post_type' => 'momo_branch', 'post_status' => 'any', 'meta_key' => 'momo_branch_code', 'meta_value' => 'DNB', 'fields' => 'ids' ] ); echo $b[0];`);

  const scenarios: [string, (uid: number) => Promise<void>][] = [
    ['disable', async (uid) => {
      await ho.goto(`${BASE}/wp-admin/user-edit.php?user_id=${uid}`);
      await ho.check('input[name="momos_disabled"]');
      await Promise.all([ho.waitForLoadState('domcontentloaded'), ho.click('#submit')]);
    }],
    ['branch change', async (uid) => {
      await ho.goto(`${BASE}/wp-admin/user-edit.php?user_id=${uid}`);
      await ho.check(`input[name="momos_branches[]"][value="${namba}"]`);
      await Promise.all([ho.waitForLoadState('domcontentloaded'), ho.click('#submit')]);
    }],
    ['role change', async (uid) => {
      await ho.goto(`${BASE}/wp-admin/user-edit.php?user_id=${uid}`);
      await ho.selectOption('select[name="role"]', 'momo_branch_manager');
      await Promise.all([ho.waitForLoadState('domcontentloaded'), ho.click('#submit')]);
    }],
  ];
  for (const [name, act] of scenarios) {
    const login = `qa_staff_auth_s_${name.replace(/\W/g, '')}`;
    const uid = qaUser(login, 'momo_reservation_staff');
    const s1 = await browser.newContext();
    const s2 = await browser.newContext();
    const p1 = await s1.newPage();
    const p2 = await s2.newPage();
    await passwordStep(p1, login);
    const { secret, step } = await enrol(p1);
    await passwordStep(p2, login);
    await p2.fill('#momos_code', totp(secret, step + 1));
    await Promise.all([p2.waitForURL(/wp-admin/), p2.click('form.momos-2fa button[type="submit"]')]);
    await act(uid);
    for (const [i, p] of [p1, p2].entries()) {
      const r = await p.request.get(`${BASE}/wp-admin/admin.php?page=momos-reservations`, { maxRedirects: 0 });
      expect(r.status(), `${name}: session ${i + 1} refused on next request`).toBe(302);
    }
    if (name === 'disable') {
      expect(wpEval(`global $wpdb; echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}momo_user_branches WHERE user_id = ${uid}" );`), 'branch rows kept on disable').toBe('1');
      expect(auditCount('user.disabled', uid)).toBeGreaterThanOrEqual(1);
    }
    await s1.close();
    await s2.close();
  }
  await hoCtx.close();
});

test('TC-ADM-008: when the access window (momo_access_expires_at) passes, the live session is refused on its next request', async ({ page }) => {
  const uid = qaUser('qa_staff_auth_vendor', 'momo_reservation_staff', 'ja', `update_user_meta( $id, 'momo_access_expires_at', gmdate( 'Y-m-d H:i:s', time() + 3600 ) );`);
  await passwordStep(page, 'qa_staff_auth_vendor');
  await enrol(page);
  let r = await page.request.get(`${BASE}/wp-admin/admin.php?page=momos-reservations`, { maxRedirects: 0 });
  expect(r.status(), 'inside the window').toBe(200);
  // The window ends (no cron run in between — spec §5.1 step 2 / TC-ADM-008 "session ends at expiry").
  wpEval(`update_user_meta( ${uid}, 'momo_access_expires_at', gmdate( 'Y-m-d H:i:s', time() - 30 ) ); echo 'ok';`);
  r = await page.request.get(`${BASE}/wp-admin/admin.php?page=momos-reservations`, { maxRedirects: 0 });
  expect(r.status(), 'expired account session must be refused on the next request').toBe(302);
  const p = await page.request.get(`${BASE}/wp-admin/profile.php`, { maxRedirects: 0 });
  expect(p.status(), 'no admin page at all after expiry').toBe(302);
});

test('SCR-A01 accessibility: login, 2FA enrolment, recovery codes and code screens have no WCAG 2.1 A/AA axe violations (incl. 360 px)', async ({ page }) => {
  qaUser('qa_staff_auth_a11y', 'momo_reservation_staff');
  const scan = async (label: string) => {
    const r = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    return r.violations.map((v) => `${label}: ${v.id} (${v.impact}) ×${v.nodes.length} — ${v.nodes[0]?.target.join(' ')}`);
  };
  const found: string[] = [];
  await page.setViewportSize({ width: 360, height: 780 });
  await page.goto(`${BASE}/wp-login.php`);
  found.push(...(await scan('login')));
  await passwordStep(page, 'qa_staff_auth_a11y');
  found.push(...(await scan('2fa-enrol')));
  const secret = (await page.locator('.momos-2fa-secret').innerText()).trim();
  const step = nowStep();
  await page.fill('#momos_code', totp(secret, step));
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('form.momos-2fa button[type="submit"]')]);
  found.push(...(await scan('2fa-recovery-codes')));
  await page.check('input[name="momos_saved"]');
  await Promise.all([page.waitForURL(/wp-admin/), page.click('form.momos-2fa button[type="submit"]')]);
  await logout(page);
  await passwordStep(page, 'qa_staff_auth_a11y');
  found.push(...(await scan('2fa-code')));
  expect(found, found.join('\n')).toEqual([]);
});
