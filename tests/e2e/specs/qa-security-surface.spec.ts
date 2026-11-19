/**
 * QA (security team) — black-box checks of the public and staff attack surface against Doc 11 / SDD §6 /
 * docs/specs/access-security-privacy.md and Doc 12 TC-SEC-003/004/006/010/019/025/027, TC-PRV-016.
 *
 * Every test asserts the DOCUMENTED behaviour: it passes where the product is right and fails where it
 * deviates (reproducer + regression test). Synthetic data only; uploads made by the tests are deleted.
 *
 * Run: cd tests/e2e && MOMOS_E2E_BASE_URL=$MOMOS_URL npx playwright test qa-security-surface
 */
import { test, expect, request as pwRequest, APIRequestContext } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import * as path from 'node:path';
import { randomUUID } from 'node:crypto';

const BASE = (process.env.MOMOS_E2E_BASE_URL || process.env.MOMOS_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const ROOT = path.resolve(__dirname, '../../..');
const DEV_PW = 'Momos-Dev-2026!';
const ATTACKER = 'https://attacker.example';

function wpEval(php: string): string {
  return execFileSync(path.join(ROOT, 'bin/wp'), ['eval', php], { cwd: ROOT, env: { ...process.env, MOMOS_URL: process.env.MOMOS_URL || BASE }, encoding: 'utf8' }).trim();
}
/** All requests come from 127.0.0.1: clear login throttle / API rate-limit counters between tests. */
function clearThrottles() {
  wpEval(`global $wpdb; $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%momos_lt_%' OR option_name LIKE '%momos_rl_%' OR option_name LIKE '%momos_contact_rl_%'"); wp_cache_flush(); echo 'ok';`);
}

/** Cookie session for a seeded staff account (2FA is not enforced in development) + REST nonce. */
async function staff(login: string): Promise<{ ctx: APIRequestContext; nonce: string }> {
  const ctx = await pwRequest.newContext({ baseURL: BASE, storageState: { cookies: [{ name: 'wordpress_test_cookie', value: 'WP%20Cookie%20check', domain: new URL(BASE).hostname, path: '/', expires: -1, httpOnly: false, secure: false, sameSite: 'Lax' }], origins: [] } });
  const r = await ctx.post('/wp-login.php', { form: { log: login, pwd: DEV_PW, 'wp-submit': 'Log In', testcookie: '1' }, maxRedirects: 0 });
  expect(r.status(), `login of ${login}`).toBe(302);
  const nonce = (await (await ctx.get('/wp-admin/admin-ajax.php?action=rest-nonce')).text()).trim();
  expect(nonce).toMatch(/^[0-9a-f]{10}$/);
  return { ctx, nonce };
}

function branchId(code: string): number {
  return parseInt(wpEval(`$p = get_posts( [ 'post_type' => 'momo_branch', 'post_status' => 'any', 'meta_key' => 'momo_branch_code', 'meta_value' => ${JSON.stringify(code)}, 'fields' => 'ids' ] ); echo (int) ( $p[0] ?? 0 );`), 10);
}


test.beforeEach(() => clearThrottles());

// ------------------------------------------------------------------ TC-SEC-003 user enumeration

test.describe('TC-SEC-003 user enumeration', () => {
  for (const route of ['/wp-json/wp/v2/users', '/wp-json/wp/v2/USERS', '/wp-json/wp/v2/Users/1', '/?rest_route=/wp/v2/uSers', '/?rest_route=/wp/v2/USERS/1']) {
    test(`anonymous ${route} answers 401 and discloses no username`, async ({ request }) => {
      const r = await request.get(BASE + route);
      const body = await r.text();
      expect(body, 'response discloses a staff login').not.toMatch(/"slug":"(superadmin|demo_[a-z_]+)"/);
      expect(r.status()).toBe(401);
    });
  }

  test('/?author=1 and author archives redirect home without the login in the URL', async ({ request }) => {
    for (const p of ['/?author=1', '/?author=2', '/news/author/superadmin/']) {
      const r = await request.get(BASE + p, { maxRedirects: 0 });
      expect([301, 302, 404]).toContain(r.status());
      expect(r.headers()['location'] || '').not.toMatch(/author|superadmin|demo_/);
    }
  });

  test('oEmbed carries no author name/url', async ({ request }) => {
    const r = await request.get(`${BASE}/wp-json/oembed/1.0/embed?url=${encodeURIComponent(BASE + '/about/')}`);
    const j = r.ok() ? await r.json() : {};
    expect(j).not.toHaveProperty('author_name');
    expect(j).not.toHaveProperty('author_url');
  });

  test('lost-password form gives the same answer for unknown and existing accounts (spec §5.3)', async ({ request }) => {
    const ask = async (login: string) => {
      const r = await request.post(`${BASE}/wp-login.php?action=lostpassword`, { form: { user_login: login, redirect_to: '', 'wp-submit': 'Get New Password' }, maxRedirects: 0 });
      const html = await r.text();
      const err = (html.match(/id="login_error"[\s\S]*?<\/div>/) || [''])[0].replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
      return { status: r.status(), location: r.headers()['location'] || '', err };
    };
    const unknown = await ask(`nobody_${Date.now()}`);
    const known = await ask('demo_mm');
    expect(unknown.err, 'unknown account is announced').not.toMatch(/no account|アカウントがありません|存在しません/i);
    expect({ status: unknown.status, err: unknown.err, location: unknown.location.replace(/checkemail=[a-z]+/, 'checkemail=*') })
      .toEqual({ status: known.status, err: known.err, location: known.location.replace(/checkemail=[a-z]+/, 'checkemail=*') });
  });

  test('login errors are generic for unknown user and wrong password', async ({ request }) => {
    const msg = async (log: string) => {
      const ctx = await pwRequest.newContext({ baseURL: BASE });
      const r = await ctx.post('/wp-login.php', { form: { log, pwd: 'wrong-password-xyz', 'wp-submit': 'Log In' }, headers: { Cookie: 'wordpress_test_cookie=WP%20Cookie%20check' } });
      const html = await r.text();
      await ctx.dispose();
      return (html.match(/id="login_error"[\s\S]*?<\/div>/) || [''])[0].replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
    };
    const wrongPw = await msg('demo_mm');
    clearThrottles();
    const unknown = await msg(`nobody_${Date.now()}`);
    expect(wrongPw).not.toBe('');
    expect(unknown).toBe(wrongPw);
  });
});

// ------------------------------------------------------------------ TC-SEC-010 / TC-SEC-025 exposure

test.describe('TC-SEC-010/025 configuration and version exposure', () => {
  for (const p of ['/readme.html', '/license.txt', '/wp-admin/install.php', '/wp-content/debug.log']) {
    test(`${p} is not served (403/404)`, async ({ request }) => {
      const r = await request.get(BASE + p, { maxRedirects: 0 });
      expect([403, 404]).toContain(r.status());
    });
  }

  test('XML-RPC is refused and no X-Pingback / generator', async ({ request }) => {
    const x = await request.post(`${BASE}/xmlrpc.php`, { data: '<?xml version="1.0"?><methodCall><methodName>system.listMethods</methodName></methodCall>', headers: { 'Content-Type': 'text/xml' } });
    expect(x.status()).toBe(403);
    const home = await request.get(`${BASE}/`);
    expect(home.headers()['x-pingback']).toBeUndefined();
    expect(await home.text()).not.toMatch(/<meta name="generator"/i);
  });

  test('comments endpoints are unavailable', async ({ request }) => {
    expect((await request.get(`${BASE}/wp-json/wp/v2/comments`)).status()).toBe(404);
    expect((await request.post(`${BASE}/wp-comments-post.php`, { form: { comment: 'x', comment_post_ID: '1', author: 'a', email: 'a@example.test' }, maxRedirects: 0 })).status()).toBeGreaterThanOrEqual(400);
  });
});

// ------------------------------------------------------------------ TC-SEC-027 public write integrity / CORS

test.describe('TC-SEC-027 cross-origin', () => {
  const body = () => ({ branch_code: 'DKH', date: '2026-12-20', time: '18:00', party_size: 2, name: 'QA Sec', email: `qa-sec-${Date.now()}@example.test`, lang: 'en', preferred_lang: 'en', consent: true, idempotency_key: randomUUID(), captcha_token: 'XXXX.DUMMY' });

  test('API-02 with a foreign Origin: 403 origin_rejected and no widened Access-Control-Allow-Origin', async ({ request }) => {
    const r = await request.post(`${BASE}/wp-json/momos/v1/reservations`, { data: body(), headers: { Origin: ATTACKER, 'Content-Type': 'application/json' } });
    expect(r.status()).toBe(403);
    expect((await r.json()).code).toBe('origin_rejected');
    expect(r.headers()['access-control-allow-origin'], 'foreign origin echoed in ACAO').toBeUndefined();
    expect(r.headers()['access-control-allow-credentials']).toBeUndefined();
  });

  test('API-02 form-encoded and text/plain: 415', async ({ request }) => {
    const f = await request.post(`${BASE}/wp-json/momos/v1/reservations`, { form: { branch_code: 'DKH' }, headers: { Origin: BASE } });
    expect(f.status()).toBe(415);
    const t = await request.post(`${BASE}/wp-json/momos/v1/reservations`, { data: JSON.stringify(body()), headers: { Origin: BASE, 'Content-Type': 'text/plain' } });
    expect(t.status()).toBe(415);
  });

  test('momos/v1 GET and preflight never reflect a foreign origin', async ({ request }) => {
    const g = await request.get(`${BASE}/wp-json/momos/v1/branches/DKH/reservation-rules`, { headers: { Origin: ATTACKER } });
    expect(g.headers()['access-control-allow-origin']).toBeUndefined();
    const o = await request.fetch(`${BASE}/wp-json/momos/v1/reservations`, { method: 'OPTIONS', headers: { Origin: ATTACKER, 'Access-Control-Request-Method': 'POST', 'Access-Control-Request-Headers': 'content-type' } });
    expect(o.headers()['access-control-allow-origin']).toBeUndefined();
  });

  test('status token never accepted in the URL (DEC-058)', async ({ request }) => {
    const r = await request.get(`${BASE}/wp-json/momos/v1/reservations/ABCD2345?t=${'a'.repeat(43)}`);
    expect(r.status()).toBe(400);
    expect((await r.json()).data.fields.t).toBe('not_allowed');
  });
});

// ------------------------------------------------------------------ TC-SEC-004 / TC-PRV-016 headers

test.describe('TC-SEC-004 / TC-PRV-016 headers', () => {
  test('public page carries the global headers and the canonical CSP', async ({ request }) => {
    const h = (await request.get(`${BASE}/en/menu/`)).headers();
    expect(h['x-content-type-options']).toBe('nosniff');
    expect(h['x-frame-options']).toBe('SAMEORIGIN');
    expect(h['referrer-policy']).toBe('strict-origin-when-cross-origin');
    expect(h['permissions-policy']).toBe('camera=(), microphone=(), geolocation=(), payment=(), usb=(), browsing-topics=()');
    expect(h['content-security-policy-report-only'] || h['content-security-policy']).toContain("frame-ancestors 'self'");
  });

  for (const p of ['/reservation/status/ABCD2345/', '/en/reservation/status/ABCD2345/', '/ar/reservation/submitted/']) {
    test(`private result page ${p}: no-referrer, no-store, noindex nofollow (spec §8)`, async ({ request }) => {
      const h = (await request.get(BASE + p)).headers();
      expect(h['referrer-policy']).toBe('no-referrer');
      expect(h['cache-control']).toContain('no-store');
      expect(h['x-robots-tag']).toBe('noindex, nofollow');
    });
  }

  test('X-Powered-By is removed from REST responses too (spec §8 "all responses")', async ({ request }) => {
    for (const p of ['/', '/wp-json/', '/wp-json/momos/v1/branches/DKH/reservation-rules']) {
      expect((await request.get(BASE + p)).headers()['x-powered-by'], `X-Powered-By on ${p}`).toBeUndefined();
    }
  });

  test('wp-login.php: admin CSP, same-origin referrer, no-store', async ({ request }) => {
    const h = (await request.get(`${BASE}/wp-login.php`)).headers();
    expect(h['content-security-policy']).toBe("frame-ancestors 'self'; object-src 'none'; base-uri 'self'");
    expect(h['referrer-policy']).toBe('same-origin');
    expect(h['cache-control']).toContain('no-store');
  });

  test('auth cookies are HttpOnly + SameSite=Lax and session-only (TC-SEC-019/024)', async () => {
    const ctx = await pwRequest.newContext({ baseURL: BASE });
    const r = await ctx.post('/wp-login.php', { form: { log: 'demo_ce', pwd: DEV_PW, 'wp-submit': 'Log In', testcookie: '1', rememberme: 'forever' }, headers: { Cookie: 'wordpress_test_cookie=WP%20Cookie%20check' }, maxRedirects: 0 });
    const cookies = r.headersArray().filter((x) => x.name.toLowerCase() === 'set-cookie' && /^wordpress_(logged_in_)?[0-9a-f]{32}=/.test(x.value)).map((x) => x.value);
    expect(cookies.length).toBeGreaterThanOrEqual(2);
    for (const c of cookies) {
      expect(c).toMatch(/HttpOnly/i);
      expect(c).toMatch(/SameSite=Lax/i);
      expect(c, 'remember-me ignored: no persistent expiry').not.toMatch(/expires=/i);
    }
    await ctx.dispose();
  });
});

// ------------------------------------------------------------------ staff channels (E7, SEC-028, TC-SEC-006)

test.describe('staff channels', () => {
  test('BM cannot write a branch through core REST in any letter case (spec §7.4 E7 → 405)', async () => {
    const { ctx, nonce } = await staff('demo_bm_kitahama');
    const id = branchId('DKH');
    const before = wpEval(`echo md5( get_post_field( 'post_content', ${id}, 'raw' ) );`);
    for (const r of ['momo_branch', 'MOMO_BRANCH', 'Momo_Branch']) {
      const res = await ctx.post(`/wp-json/wp/v2/${r}/${id}`, { data: { content: `<p>qa-sec ${r}</p>` }, headers: { 'X-WP-Nonce': nonce } });
      expect(res.status(), `POST /wp/v2/${r}/${id}`).toBe(405);
    }
    expect(wpEval(`echo md5( get_post_field( 'post_content', ${id}, 'raw' ) );`), 'branch content changed through core REST').toBe(before);
    await ctx.dispose();
  });

  test('media library accepts only JPEG/PNG/WebP (spec §7.7, SEC-028)', async () => {
    const { ctx, nonce } = await staff('demo_bm_kitahama');
    const files: Array<[string, string, Buffer]> = [
      ['qa-sec.pdf', 'application/pdf', Buffer.from('%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n')],
      ['qa-sec.avif', 'image/avif', Buffer.from('000000206674797061766966000000006176696661766966', 'hex')],
      ['qa-sec.zip', 'application/zip', Buffer.from('504b0506000000000000000000000000000000000000', 'hex')],
    ];
    const accepted: string[] = [];
    for (const [name, type, data] of files) {
      const res = await ctx.post('/wp-json/wp/v2/media', { data, headers: { 'X-WP-Nonce': nonce, 'Content-Type': type, 'Content-Disposition': `attachment; filename=${name}` } });
      if (res.status() === 201) {
        accepted.push(name);
        const id = (await res.json()).id as number;
        wpEval(`wp_delete_attachment( ${id}, true ); echo 'ok';`);
      }
    }
    expect(accepted, 'uploads outside the allow-list were accepted').toEqual([]);
    await ctx.dispose();
  });

  test('staff REST without nonce: 403 rest_forbidden; foreign-branch detail: 403 forbidden_branch (TC-SEC-006, E1)', async () => {
    const { ctx, nonce } = await staff('demo_rs_kitahama');
    const noNonce = await ctx.get('/wp-json/momos/v1/staff/reservations');
    expect(noNonce.status()).toBe(403);
    const foreign = parseInt(wpEval(`global $wpdb; $b = get_posts( [ 'post_type' => 'momo_branch', 'meta_key' => 'momo_branch_code', 'meta_value' => 'DNB', 'fields' => 'ids' ] ); echo (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}momo_reservations WHERE branch_id = %d ORDER BY id LIMIT 1", $b[0] ) );`), 10);
    test.skip(!foreign, 'no seeded reservation in the foreign branch');
    const r = await ctx.get(`/wp-json/momos/v1/staff/reservations/${foreign}`, { headers: { 'X-WP-Nonce': nonce } });
    expect(r.status()).toBe(403);
    const j = await r.json();
    expect(j.code).toBe('forbidden_branch');
    expect(JSON.stringify(j)).not.toMatch(/guest_name|email|phone/);
    await ctx.dispose();
  });
});

// ------------------------------------------------------------------ contact form (spec §7.3 / §10.1, FR-SEC-006)

test.describe('SCR-C12 contact form public-write integrity', () => {
  async function contactNonce(request: APIRequestContext): Promise<string> {
    const html = await (await request.get(`${BASE}/en/contact/`)).text();
    const m = html.match(/name="_momos_nonce" value="([0-9a-f]+)"/);
    expect(m, 'contact form nonce').not.toBeNull();
    return (m as RegExpMatchArray)[1];
  }
  const fields = (nonce: string) => ({ action: 'momos_contact', _momos_nonce: nonce, lang: 'en', name: 'QA Sec', email: 'qa-sec-contact@example.test', topic: 'general', message: 'security QA', consent: '1' });

  test('cross-origin POST is refused (Origin/Referer host must be the site host)', async ({ request }) => {
    const nonce = await contactNonce(request);
    const r = await request.post(`${BASE}/wp-admin/admin-post.php`, { form: fields(nonce), headers: { Origin: ATTACKER, Referer: `${ATTACKER}/x` }, maxRedirects: 0 });
    expect(r.headers()['location'] || '', 'cross-origin contact submission was sent').not.toContain('sent=1');
  });

  test('POST without a Turnstile token is refused (contact uses Turnstile like API-02)', async ({ request }) => {
    const nonce = await contactNonce(request);
    const r = await request.post(`${BASE}/wp-admin/admin-post.php`, { form: fields(nonce), headers: { Origin: BASE, Referer: `${BASE}/en/contact/` }, maxRedirects: 0 });
    expect(r.headers()['location'] || '', 'contact submission without captcha was sent').not.toContain('sent=1');
  });
});
