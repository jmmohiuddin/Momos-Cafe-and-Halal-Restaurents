/**
 * QA (staff team) — role × admin screen oracle over HTTP (Doc 12 TC-ACL-002/003/004/007/017/018/024,
 * FR-ADM-003 "hiding menus is cosmetic only", access spec §4.3 E2/E3/E5, TRD §2.7.2/§2.7.4) and the
 * admin language per user locale (DEC-037).
 *
 * For every seeded staff account (SA + the 8 demo staff accounts) the test requests each admin screen
 * directly (no menu click) and asserts: allowed → 200 and the screen renders; denied → 403 with no data,
 * plus an `access.denied` audit row for the momos screens. It also asserts the menu shows exactly the
 * allowed momos screens, and that users with locale ja get Japanese screen headings while en_US users get
 * English ones.
 *
 * Data: seeded demo site (tests/e2e/fixtures/seed.sh): branches DKH (Kitahama), DNB (Namba), DKY, TST;
 * demo_bm_kitahama / demo_rs_kitahama are assigned to DKH only.
 * Run: cd tests/e2e && MOMOS_E2E_BASE_URL=$MOMOS_URL npx playwright test qa-staff-access-matrix
 */
import { test, expect, request as pwRequest, APIRequestContext } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import * as path from 'node:path';

const BASE = (process.env.MOMOS_E2E_BASE_URL || process.env.MOMOS_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const ROOT = path.resolve(__dirname, '../../..');
const PASSWORD = 'Momos-Dev-2026!';

function wp(args: string[]): string {
  return execFileSync(path.join(ROOT, 'bin/wp'), args, { cwd: ROOT, env: { ...process.env, MOMOS_URL: process.env.MOMOS_URL || BASE }, encoding: 'utf8' }).trim();
}
function wpEval(php: string): string {
  return wp(['eval', php]);
}

/** Clear login throttling state so earlier failed-login tests cannot lock 127.0.0.1. */
function clearThrottle() {
  wpEval(`global $wpdb; $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%momos_lt_%'"); wp_cache_flush(); echo 'ok';`);
}

type Short = 'SA' | 'HO' | 'MM' | 'CE' | 'TR' | 'BM' | 'RS' | 'BMN' | 'RSN';
const ACCOUNTS: Record<Short, { login: string; lang: 'ja' | 'en' }> = {
  SA: { login: 'superadmin', lang: 'ja' },
  HO: { login: 'demo_ho', lang: 'ja' },
  MM: { login: 'demo_mm', lang: 'ja' },
  CE: { login: 'demo_ce', lang: 'ja' },
  TR: { login: 'demo_tr', lang: 'en' },
  BM: { login: 'demo_bm_kitahama', lang: 'ja' },
  RS: { login: 'demo_rs_kitahama', lang: 'ja' },
  BMN: { login: 'demo_bm_namba', lang: 'en' },
  RSN: { login: 'demo_rs_namba', lang: 'en' },
};

/**
 * Screen oracle. `momos: true` = momos-core screen (must audit access.denied on denial and appear in
 * the Momo's / Reservations menu only for allowed roles). Allowed role lists from TRD §2.7.2/§2.7.4
 * (CANONICAL_3 rows) and the screen specs.
 */
const SCREENS: { url: string; allow: Short[]; momos?: boolean; name: string }[] = [
  { name: 'Reservation inbox (SCR-A08)', url: 'admin.php?page=momos-reservations', allow: ['SA', 'HO', 'BM', 'RS', 'BMN', 'RSN'], momos: true },
  { name: 'Reservation report (SCR-A17)', url: 'admin.php?page=momos-reservation-report', allow: ['SA', 'HO', 'BM', 'BMN'], momos: true },
  { name: 'Audit log (SCR-A16)', url: 'admin.php?page=momos-audit', allow: ['SA', 'HO'], momos: true },
  { name: 'Data requests (SCR-A19)', url: 'admin.php?page=momos-dsr', allow: ['SA', 'HO'], momos: true },
  { name: 'Settings (SCR-A15)', url: 'admin.php?page=momo-settings', allow: ['SA', 'HO'], momos: true },
  { name: 'Brand (G1)', url: 'admin.php?page=momo-brand', allow: ['SA', 'HO'], momos: true },
  { name: 'Redirections / 404 log', url: 'admin.php?page=momos-seo', allow: ['SA', 'HO'], momos: true },
  { name: 'Notification log', url: 'admin.php?page=momo-notifications', allow: ['SA', 'HO'], momos: true },
  { name: 'Translation gaps (SCR-A11)', url: 'admin.php?page=momos-translation-gaps', allow: ['SA', 'HO', 'MM', 'CE', 'TR'], momos: true },
  { name: 'Interface strings', url: 'admin.php?page=momos-strings', allow: ['SA', 'HO', 'MM', 'CE', 'TR'], momos: true },
  { name: 'Halal register (SCR-A12)', url: 'admin.php?page=momo-halal-register', allow: ['SA', 'HO', 'MM', 'BM', 'BMN'], momos: true },
  { name: 'Branch menus (SCR-A07)', url: 'admin.php?page=momo-branch-menu', allow: ['SA', 'HO', 'MM', 'BM', 'RS', 'BMN', 'RSN'], momos: true },
  { name: 'Category order', url: 'edit.php?post_type=momo_dish&page=momo-category-order', allow: ['SA', 'HO', 'MM'], momos: true },
  { name: 'Hours & closures (SCR-A04)', url: 'edit.php?post_type=momo_branch&page=momos-hours', allow: ['SA', 'HO', 'MM', 'CE', 'BM', 'RS', 'BMN', 'RSN'], momos: true },
  { name: 'Users list (SCR-A10)', url: 'users.php', allow: ['SA', 'HO'] },
  { name: 'Add user (SCR-A10)', url: 'user-new.php', allow: ['SA', 'HO'] },
  { name: 'Branches list', url: 'edit.php?post_type=momo_branch', allow: ['SA', 'HO', 'CE', 'BM', 'BMN'] },
  { name: 'New branch', url: 'post-new.php?post_type=momo_branch', allow: ['SA', 'HO'] },
  { name: 'Dishes list (SCR-A06)', url: 'edit.php?post_type=momo_dish', allow: ['SA', 'HO', 'MM'] },
  { name: 'New dish', url: 'post-new.php?post_type=momo_dish', allow: ['SA', 'HO', 'MM'] },
  { name: 'Notices list', url: 'edit.php?post_type=momo_notice', allow: ['SA', 'HO', 'CE', 'BM', 'BMN'] },
  { name: 'Offers list (SCR-A20)', url: 'edit.php?post_type=momo_offer', allow: ['SA', 'HO', 'CE', 'BM', 'BMN'] },
  { name: 'News posts', url: 'edit.php', allow: ['SA', 'HO', 'CE'] },
  { name: 'Pages', url: 'edit.php?post_type=page', allow: ['SA', 'HO', 'CE'] },
  { name: 'Media library', url: 'upload.php', allow: ['SA', 'HO', 'MM', 'CE', 'BM', 'BMN'] },
  { name: 'General settings (core)', url: 'options-general.php', allow: ['SA'] },
  { name: 'Plugins (core)', url: 'plugins.php', allow: ['SA'] },
  { name: 'Customizer (M7)', url: 'customize.php', allow: ['SA'] },
  { name: 'Export (core)', url: 'export.php', allow: ['SA'] },
];

const contexts = new Map<Short, APIRequestContext>();
const ids: Record<string, number> = {};

async function loginContext(login: string): Promise<APIRequestContext> {
  const ctx = await pwRequest.newContext({ baseURL: BASE });
  await ctx.get('/wp-login.php');
  const res = await ctx.post('/wp-login.php', {
    form: { log: login, pwd: PASSWORD, 'wp-submit': 'Log In', testcookie: '1' },
    maxRedirects: 0,
  });
  expect(res.status(), `password login for ${login}`).toBe(302);
  return ctx;
}

function deniedCount(userLogin: string): number {
  return parseInt(wpEval(`global $wpdb; $u = get_user_by('login', '${userLogin}'); echo (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}momo_audit_log WHERE action = 'access.denied' AND actor_id = %d", $u->ID));`), 10);
}

/** Visible text of the admin body (scripts/styles stripped). */
function bodyText(html: string): string {
  const i = html.indexOf('id="wpbody-content"');
  const body = i >= 0 ? html.slice(i) : html;
  return body.replace(/<script[\s\S]*?<\/script>/g, ' ').replace(/<style[\s\S]*?<\/style>/g, ' ').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ');
}

// Not serial: one failing oracle must not hide the others (beforeAll re-runs after a failure).

test.beforeAll(async () => {
  clearThrottle();
  const raw = wpEval(`
    $out = [];
    foreach ( get_posts( [ 'post_type' => 'momo_branch', 'post_status' => 'any', 'numberposts' => -1 ] ) as $p ) { $out[ 'branch_' . get_post_meta( $p->ID, 'momo_branch_code', true ) ] = $p->ID; }
    global $wpdb;
    foreach ( $wpdb->get_results( "SELECT id, branch_id FROM {$wpdb->prefix}momo_reservations ORDER BY id" ) as $r ) {
      $code = get_post_meta( (int) $r->branch_id, 'momo_branch_code', true );
      if ( ! isset( $out[ 'res_' . $code ] ) ) { $out[ 'res_' . $code ] = (int) $r->id; }
    }
    echo wp_json_encode( $out );`);
  Object.assign(ids, JSON.parse(raw));
  for (const [short, acc] of Object.entries(ACCOUNTS) as [Short, { login: string }][]) {
    contexts.set(short, await loginContext(acc.login));
  }
});

test.afterAll(async () => {
  for (const c of contexts.values()) await c.dispose();
});

for (const screen of SCREENS) {
  test(`TC-ACL-002 direct URL oracle: ${screen.name}`, async () => {
    const problems: string[] = [];
    for (const short of Object.keys(ACCOUNTS) as Short[]) {
      const ctx = contexts.get(short)!;
      const before = screen.momos ? deniedCount(ACCOUNTS[short].login) : 0;
      const res = await ctx.get(`/wp-admin/${screen.url}`, { maxRedirects: 0 });
      const allowed = screen.allow.includes(short);
      if (allowed && res.status() !== 200) problems.push(`${short}: expected 200, got ${res.status()}`);
      if (!allowed) {
        if (res.status() !== 403) problems.push(`${short}: expected 403, got ${res.status()}`);
        if (screen.momos && deniedCount(ACCOUNTS[short].login) <= before) problems.push(`${short}: no access.denied audit row`);
      }
    }
    expect(problems, problems.join('\n')).toEqual([]);
  });
}

test('Menus show exactly the allowed momos screens per role (hiding is cosmetic, but must match)', async () => {
  const problems: string[] = [];
  for (const short of Object.keys(ACCOUNTS) as Short[]) {
    const html = await (await contexts.get(short)!.get('/wp-admin/index.php')).text();
    const menu = html.slice(html.indexOf('id="adminmenu"'), html.indexOf('id="wpcontent"'));
    for (const screen of SCREENS.filter((s) => s.momos)) {
      const page = new URL(`${BASE}/wp-admin/${screen.url}`).searchParams.get('page')!;
      const linked = menu.includes(`page=${page}`);
      const allowed = screen.allow.includes(short);
      if (linked !== allowed) problems.push(`${short}: menu link to ${page} ${linked ? 'shown' : 'missing'} (allowed=${allowed})`);
    }
  }
  expect(problems, problems.join('\n')).toEqual([]);
});

test('TC-ACL-004 / TC-ACL-009: Kitahama BM and RS cannot open Namba objects by URL (403, no data)', async () => {
  const nambaRes = ids.res_DNB;
  const namba = ids.branch_DNB;
  expect(nambaRes, 'seeded Namba reservation').toBeTruthy();
  const ref = wpEval(`global $wpdb; echo $wpdb->get_var($wpdb->prepare("SELECT public_ref FROM {$wpdb->prefix}momo_reservations WHERE id = %d", ${nambaRes}));`);
  const urls = [
    `admin.php?page=momos-reservations&id=${nambaRes}`,
    `edit.php?post_type=momo_branch&page=momos-hours&branch=${namba}`,
    `admin.php?page=momo-branch-menu&branch=${namba}`,
    `post.php?post=${namba}&action=edit`,
  ];
  const problems: string[] = [];
  for (const short of ['BM', 'RS'] as Short[]) {
    for (const u of urls) {
      const res = await contexts.get(short)!.get(`/wp-admin/${u}`, { maxRedirects: 0 });
      const body = await res.text();
      if (res.status() !== 403) problems.push(`${short} ${u}: ${res.status()}`);
      if (body.includes(ref)) problems.push(`${short} ${u}: Namba reference ${ref} leaked`);
    }
  }
  expect(problems, problems.join('\n')).toEqual([]);
});

test('TC-ACL-003: a foreign branch filter on the inbox and the report is refused with 403', async () => {
  const namba = ids.branch_DNB;
  const problems: string[] = [];
  for (const u of [`admin.php?page=momos-reservations&branch=${namba}`, `admin.php?page=momos-reservation-report&branch=${namba}`]) {
    const res = await contexts.get('BM')!.get(`/wp-admin/${u}`, { maxRedirects: 0 });
    if (res.status() !== 403) problems.push(`BM ${u}: expected 403 (TC-ACL-003 "branch=Y filter 403"), got ${res.status()}`);
  }
  expect(problems, problems.join('\n')).toEqual([]);
});

test('TC-ACL-003: inbox search for a foreign reference finds nothing and counts are own-branch only', async () => {
  const ref = wpEval(`global $wpdb; echo $wpdb->get_var($wpdb->prepare("SELECT public_ref FROM {$wpdb->prefix}momo_reservations WHERE id = %d", ${ids.res_DNB}));`);
  const own = parseInt(wpEval(`global $wpdb; echo (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}momo_reservations WHERE branch_id = %d", ${ids.branch_DKH}));`), 10);
  const res = await contexts.get('BM')!.get(`/wp-admin/admin.php?page=momos-reservations&view=all&q=${encodeURIComponent(ref)}`);
  expect(res.status()).toBe(200);
  // The search box echoes the query in its value attribute; the visible result list must not contain it.
  expect(bodyText(await res.text()).includes(ref), 'foreign reference found in BM search results').toBe(false);
  const all = bodyText(await (await contexts.get('BM')!.get('/wp-admin/admin.php?page=momos-reservations&view=all')).text());
  const m = all.match(/(?:すべて|All)\s*\(?\s*(\d+)/);
  expect(m, 'count of the "all" view is shown').toBeTruthy();
  expect(parseInt(m![1], 10), 'all-view count includes only the assigned branch').toBe(own);
});

test('TC-ACL-007: BM and RS cannot export reservations (admin-post handler 403)', async () => {
  const problems: string[] = [];
  for (const short of ['BM', 'RS'] as Short[]) {
    const html = await (await contexts.get(short)!.get('/wp-admin/admin.php?page=momos-reservations')).text();
    if (/momos_export|export_reservations|CSV/.test(bodyText(html)) && /action=momos_export|name="action" value="momos_export"/.test(html)) problems.push(`${short}: export control rendered`);
    const res = await contexts.get(short)!.get('/wp-admin/admin-post.php?action=momos_export', { maxRedirects: 0 });
    if (res.status() !== 403) problems.push(`${short}: GET export handler returned ${res.status()}`);
    const res2 = await contexts.get(short)!.post('/wp-admin/admin-post.php', { form: { action: 'momos_export' }, maxRedirects: 0 });
    if (res2.status() !== 403) problems.push(`${short}: POST export handler returned ${res2.status()}`);
  }
  expect(problems, problems.join('\n')).toEqual([]);
});

test('TC-ACL-013: Head Office cannot open, edit or delete the Super Administrator (403, not 500)', async () => {
  const saId = wpEval(`echo get_user_by('login','superadmin')->ID;`);
  const ho = contexts.get('HO')!;
  const edit = await ho.get(`/wp-admin/user-edit.php?user_id=${saId}`, { maxRedirects: 0 });
  expect(edit.status(), 'FR-ADM-003: denial → 403').toBe(403);
  const del = await ho.get(`/wp-admin/users.php?action=delete&user=${saId}`, { maxRedirects: 0 });
  expect(del.status()).toBe(403);
  const list = await (await ho.get('/wp-admin/users.php')).text();
  expect(list, 'no Delete row action / bulk action (BR-ADM-01)').not.toMatch(/<option value="delete"|class=['"]submitdelete|action=delete&amp;user=/);
  const newUser = await (await ho.get('/wp-admin/user-new.php')).text();
  expect(newUser).not.toMatch(/<option[^>]*value=['"]administrator['"]/);
});

const JA = /[぀-ヿ一-龯]/;

test('DEC-037: admin language follows the user locale on every allowed momos screen', async () => {
  const problems: string[] = [];
  for (const short of Object.keys(ACCOUNTS) as Short[]) {
    if (short === 'SA') continue; // superadmin has no explicit locale (site default).
    const ctx = contexts.get(short)!;
    for (const screen of SCREENS.filter((s) => s.momos && s.allow.includes(short))) {
      const html = await (await ctx.get(`/wp-admin/${screen.url}`)).text();
      const h1 = (html.match(/<h1[^>]*>([\s\S]*?)<\/h1>/) || [])[1]?.replace(/<[^>]+>/g, '').trim() || '';
      const lang = (html.match(/<html[^>]*\blang="([^"]+)"/) || [])[1] || '';
      if (ACCOUNTS[short].lang === 'ja' && (!JA.test(h1) || !lang.startsWith('ja'))) problems.push(`${short} ${screen.url}: h1 "${h1}" lang=${lang} (expected Japanese)`);
      if (ACCOUNTS[short].lang === 'en' && (JA.test(h1) || !lang.startsWith('en'))) problems.push(`${short} ${screen.url}: h1 "${h1}" lang=${lang} (expected English)`);
    }
  }
  expect(problems, problems.join('\n')).toEqual([]);
});

/**
 * SCR-A02 (access spec §13.1 widget table; Doc 12 TC-ADM-001, TC-ACL-024 step 1): widgets per role and
 * branch-scoped counts. Expected widget sets (Visible to …):
 *   RS: My pending requests, Needs action, Attendance to record                          → ≥ 3
 *   BM: + Offers awaiting approval (own), Offers ending soon                               → ≥ 5
 *   MM: Dishes awaiting review (own), Translation gaps, Allergen data not verified, Demo content remaining → ≥ 4
 *   CE: Translation gaps, Offers awaiting approval (own), Offers ending soon, Demo content remaining        → ≥ 4
 *   TR: My translation jobs                                                                → ≥ 1
 *   HO: every widget except Attendance and My translation jobs                             → ≥ 11
 */
test('TC-ADM-001 / TC-ACL-024: role-aware dashboard widgets, counts scoped to assigned branches', async () => {
  const minimum: Partial<Record<Short, number>> = { RS: 3, BM: 5, MM: 4, CE: 4, TR: 1, HO: 11 };
  const problems: string[] = [];
  for (const [short, min] of Object.entries(minimum) as [Short, number][]) {
    const html = await (await contexts.get(short)!.get('/wp-admin/index.php')).text();
    const widgets = [...html.matchAll(/<div id="(momos_[a-z_]+)" class="postbox/g)].map((m) => m[1]);
    if (widgets.length < min) problems.push(`${short}: ${widgets.length} momos widgets [${widgets.join(', ')}], spec table requires ≥ ${min}`);
  }
  // Pending count of the Kitahama BM = pending requests of Kitahama only.
  const expected = parseInt(wpEval(`global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}momo_reservations WHERE status = 'pending' AND branch_id = %d", ${ids.branch_DKH} ) );`), 10);
  const bmHtml = await (await contexts.get('BM')!.get('/wp-admin/index.php')).text();
  const start = bmHtml.indexOf('id="momos_pending"');
  const inside = bmHtml.indexOf('class="inside"', start);
  const pendingBox = start >= 0 ? bmHtml.slice(inside, bmHtml.indexOf('postbox-container', inside)).replace(/&#038;/g, '&') : '';
  const shown = parseInt((bodyText(pendingBox).match(/(\d+)\s*件/) || bodyText(pendingBox).match(/(\d+)/) || ['', '-1'])[1], 10);
  if (shown !== expected) problems.push(`BM pending widget shows ${shown}, Kitahama has ${expected} pending`);
  if (!/page=momos-reservations[^"]*status=pending|page=momos-reservations[^"]*view=pending/.test(pendingBox)) problems.push('BM pending widget does not link to the pre-filtered inbox (A08 filtered)');
  // "pending count + 3 oldest/soonest" (SCR-A02 table): each listed request links to its detail (A09).
  const items = (pendingBox.match(/page=momos-reservations&id=\d+/g) || []).length;
  if (expected > 0 && items < Math.min(3, expected)) problems.push(`BM pending widget lists ${items} requests; spec requires the ${Math.min(3, expected)} oldest/soonest`);
  expect(problems, problems.join('\n')).toEqual([]);
});
