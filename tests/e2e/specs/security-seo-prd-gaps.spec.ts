/**
 * PRD gaps (security/SEO fixer):
 *  - FR-SEC-002 / AC-SEC-01-02: progressive delay from the 5th failed login — an attempt inside the
 *    backoff window is refused with the same generic error even with the correct password; after the
 *    window the correct password works. No PHP sleep: the response comes back at once.
 *  - FR-SEO-006(b) / F-SEO-02 / Q-415: Head Office enters servesCuisine (per language) and priceRange in
 *    SEO → Structured data; the branch Restaurant JSON-LD carries them only when set.
 * All requests come from 127.0.0.1, so login throttling state is cleared before and after.
 */
import { test, expect, request as pwRequest, APIRequestContext } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import * as path from 'node:path';

const BASE = (process.env.MOMOS_E2E_BASE_URL || process.env.MOMOS_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const ROOT = path.resolve(__dirname, '../../..');
const DEMO_PW = 'Momos-Dev-2026!';
const QA_PW = 'QA-Backoff-2026!x';
const GENERIC = ['ユーザー名またはパスワードが正しくありません。', 'Incorrect username or password.'];

function wpEval(php: string): string {
  return execFileSync(path.join(ROOT, 'bin/wp'), ['eval', php], { cwd: ROOT, env: { ...process.env, MOMOS_URL: process.env.MOMOS_URL || BASE }, encoding: 'utf8' }).trim();
}
function clearThrottle() {
  wpEval(`global $wpdb; $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%momos_lt_%'"); wp_cache_flush(); echo 'ok';`);
}
function loginError(html: string): string {
  const m = html.match(/<div id="login_error"[^>]*>([\s\S]*?)<\/div>/);
  return m ? m[1].replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim() : '';
}
async function postLogin(ctx: APIRequestContext, log: string, pwd: string) {
  // The Turnstile step (3rd failure per IP, CHG-009) runs in test mode on dev/CI: any token but "test-fail" passes.
  return ctx.post('/wp-login.php', { form: { log, pwd, 'wp-submit': 'Log In', testcookie: '1', 'cf-turnstile-response': 'test-pass' }, maxRedirects: 0 });
}

test.describe.configure({ mode: 'serial' });
test.beforeAll(() => clearThrottle());
test.afterAll(() => {
  clearThrottle();
  wpEval(`delete_option( 'momos_seo_schema' ); do_action( 'momos/cache/purge', [ 'momo-global' ] ); echo 'ok';`);
});

test('FR-SEC-002: after 5 failures the next attempt waits out the backoff window with the generic error', async () => {
  const uid = wpEval(`
    $u = get_user_by( 'login', 'qa_sec_backoff' );
    $id = $u ? (int) $u->ID : (int) wp_insert_user( [ 'user_login' => 'qa_sec_backoff', 'user_pass' => ${JSON.stringify(QA_PW)}, 'user_email' => 'qa_sec_backoff@momos.example.test', 'role' => 'momo_head_office' ] );
    wp_set_password( ${JSON.stringify(QA_PW)}, $id );
    delete_user_meta( $id, 'momo_2fa_challenge' );
    echo $id;`);
  const ctx = await pwRequest.newContext({ baseURL: BASE });
  await ctx.get('/wp-login.php');
  for (let i = 1; i <= 5; i++) {
    const res = await postLogin(ctx, 'qa_sec_backoff', `wrong-password-${i}`);
    expect(res.status(), `failure ${i}`).toBe(200);
    expect(GENERIC).toContain(loginError(await res.text()));
  }
  // Inside the window: refused at once, same message, even with the right password.
  const started = Date.now();
  const refused = await postLogin(ctx, 'qa_sec_backoff', QA_PW);
  expect(refused.status()).toBe(200);
  expect(GENERIC, 'same generic error').toContain(loginError(await refused.text()));
  expect(Date.now() - started, 'no server-side sleep').toBeLessThan(5000);
  const reason = wpEval(`global $wpdb; echo (string) $wpdb->get_var( $wpdb->prepare( "SELECT changes FROM {$wpdb->prefix}momo_audit_log WHERE action = 'auth.login_failed' AND object_id = %d ORDER BY id DESC LIMIT 1", ${uid} ) );`);
  expect(reason).toContain('throttled');
  // 6 failures → 2 s window; afterwards the correct password signs in.
  await new Promise((r) => setTimeout(r, 2500));
  const ok = await postLogin(ctx, 'qa_sec_backoff', QA_PW);
  expect(ok.status(), `login after the window: ${loginError(await ok.text())}`).toBe(302);
  await ctx.dispose();
});

test('FR-SEO-006 / Q-415: Head Office sets servesCuisine and priceRange; branch JSON-LD carries them only when set', async () => {
  clearThrottle();
  const enUrl = wpEval(`$b = get_posts( [ 'post_type' => 'momo_branch', 'meta_key' => 'momo_branch_code', 'meta_value' => 'DKH', 'fields' => 'ids' ] ); echo (string) \\Momos\\Seo\\Context::entity_url( \\Momos\\Seo\\Context::BRANCH, (int) $b[0], 'en' );`);
  expect(enUrl).toMatch(/^https?:\/\//);
  const restaurant = async (url: string) => {
    const html = await (await (await pwRequest.newContext()).get(url)).text();
    const blocks = [...html.matchAll(/<script type="application\/ld\+json">([\s\S]*?)<\/script>/g)].map((m) => JSON.parse(m[1]));
    return blocks.flatMap((b) => b['@graph'] || []).find((n: Record<string, unknown>) => n['@type'] === 'Restaurant') as Record<string, unknown>;
  };
  const before = await restaurant(enUrl);
  expect(before, 'Restaurant node').toBeTruthy();
  expect(before.priceRange, 'omitted until supplied').toBeUndefined();
  expect(before.servesCuisine).toBeUndefined();

  const ho = await pwRequest.newContext({ baseURL: BASE });
  await ho.get('/wp-login.php');
  expect((await postLogin(ho, 'demo_ho', DEMO_PW)).status(), 'HO login').toBe(302);
  const tab = await ho.get('/wp-admin/admin.php?page=momos-seo&tab=schema');
  expect(tab.status()).toBe(200);
  const form = await tab.text();
  expect(form).toContain('name="serves_cuisine[ar]"');
  const nonce = form.match(/name="_wpnonce" value="([0-9a-f]+)"/)![1];
  const saved = await ho.post('/wp-admin/admin-post.php', {
    form: { action: 'momos_seo_schema', _wpnonce: nonce, _wp_http_referer: '/wp-admin/admin.php?page=momos-seo&tab=schema', 'serves_cuisine[en]': 'QA cuisine', price_range: 'QA price range' },
    maxRedirects: 0,
  });
  expect(saved.status()).toBe(302);
  expect(saved.headers()['location']).toContain('momos_notice=saved');
  // Too long (100 characters) → refused, nothing changes.
  const tooLong = await ho.post('/wp-admin/admin-post.php', {
    form: { action: 'momos_seo_schema', _wpnonce: nonce, 'serves_cuisine[en]': 'x'.repeat(100), price_range: 'QA price range' },
    maxRedirects: 0,
  });
  expect(tooLong.headers()['location']).toContain('momos_notice=error');
  await ho.dispose();

  const after = await restaurant(enUrl);
  expect(after.servesCuisine).toBe('QA cuisine');
  expect(after.priceRange).toBe('QA price range');
  // Japanese page: no ja cuisine was entered → no servesCuisine (no fallback), priceRange shown.
  const jaUrl = enUrl.replace(/\/en\//, '/');
  const ja = await restaurant(jaUrl);
  expect(ja.servesCuisine).toBeUndefined();
  expect(ja.priceRange).toBe('QA price range');
});
