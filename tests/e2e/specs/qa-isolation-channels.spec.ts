/**
 * QA (isolation team) — branch isolation and object-level authorisation over real HTTP channels
 * (Doc 12 TC-ACL-003…012, 021, 022, 024; access spec §4.2 M1–M12, §4.3 E1–E14; branch spec §4.9;
 * reservation spec §3.6/§3.7/§3.14; FR-RES-026, PRV-028, SEC-008/012).
 *
 * Seeded demo site (tests/e2e/fixtures/seed.sh): DKH = Kitahama (A), DNB = Namba (B), DKY, hidden TEST
 * branch TST. demo_bm_kitahama / demo_rs_kitahama are assigned to DKH only. The spec creates its own
 * fictional fixtures (a vendor account, a Kitahama notice, a Namba notice) through WP-CLI and removes
 * them again; it never changes seeded reservations.
 *
 * Complements tests/phpunit/integration/QaIsolationMatrixTest.php (in-process matrix) with the channels
 * that only exist over HTTP: list-screen view counts, core REST search/oEmbed, post.php form saves,
 * admin-ajax, real nonces bound to the browser session, rate limiting and the anonymous front end.
 *
 * Run: cd tests/e2e && MOMOS_E2E_BASE_URL=$MOMOS_URL npx playwright test qa-isolation-channels
 */
import { test, expect, request as pwRequest, APIRequestContext } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import * as path from 'node:path';

const BASE = (process.env.MOMOS_E2E_BASE_URL || process.env.MOMOS_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const ROOT = path.resolve(__dirname, '../../..');
const PASSWORD = 'Momos-Dev-2026!';

function wpEval(php: string): string {
  return execFileSync(path.join(ROOT, 'bin/wp'), ['eval', php], {
    cwd: ROOT,
    env: { ...process.env, MOMOS_URL: process.env.MOMOS_URL || BASE },
    encoding: 'utf8',
  }).trim();
}

function clearThrottle() {
  wpEval(`global $wpdb; $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%momos_lt_%' OR option_name LIKE '%momos_rl_%'"); wp_cache_flush(); echo 'ok';`);
}

type Ctx = { req: APIRequestContext; restNonce: string; login: string; id: number };
const ctx: Record<string, Ctx> = {};
const ids: Record<string, any> = {};

async function login(login: string): Promise<Ctx> {
  const req = await pwRequest.newContext({ baseURL: BASE });
  await req.get('/wp-login.php');
  const res = await req.post('/wp-login.php', { form: { log: login, pwd: PASSWORD, 'wp-submit': 'Log In', testcookie: '1' }, maxRedirects: 0 });
  expect(res.status(), `password login for ${login}`).toBe(302);
  const restNonce = (await (await req.get('/wp-admin/admin-ajax.php?action=rest-nonce')).text()).trim();
  const id = parseInt(wpEval(`echo get_user_by('login', '${login}')->ID;`), 10);
  return { req, restNonce, login, id };
}

/** A nonce minted for *this* browser session and user (what a page of the user would embed). */
async function sessionNonce(c: Ctx, action: string): Promise<string> {
  const state = await c.req.storageState();
  const cookie = state.cookies.find((k) => k.name.startsWith('wordpress_logged_in_'));
  expect(cookie, 'logged-in cookie').toBeTruthy();
  return wpEval(`$_COOKIE[LOGGED_IN_COOKIE] = ${JSON.stringify(decodeURIComponent(cookie!.value))}; wp_set_current_user(${c.id}); echo wp_create_nonce('${action}');`);
}

function deniedCount(userId: number): number {
  return parseInt(wpEval(`global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}momo_audit_log WHERE action = 'access.denied' AND actor_id = ${userId}");`), 10);
}

/** Value of a form input by name, whatever the attribute order or quote style. */
function inputValue(html: string, name: string): string | undefined {
  for (const m of html.matchAll(/<input\b[^>]*>/g)) {
    const tag = m[0];
    if (new RegExp(`\\bname=["']${name.replace(/[[\]]/g, '\\$&')}["']`).test(tag)) return (tag.match(/\bvalue=["']([^"']*)["']/) || [])[1];
  }
  return undefined;
}

/** Published notice scoped to one branch (fixture; published directly so the publish checklist does not apply). */
function makeNotice(branchId: number, title: string, author = 0): number {
  return parseInt(
    wpEval(`
      $term = Momos\\Branch\\PostType\\BranchScopeTaxonomy::term_for_branch( ${branchId} );
      $id = wp_insert_post( [ 'post_type' => 'momo_notice', 'post_status' => 'draft', 'post_title' => ${JSON.stringify(title)}, 'post_author' => ${author} ] );
      foreach ( [ 'momo_notice_body' => 'QA isolation fixture notice.', 'momo_notice_severity' => 'info', 'momo_notice_scope' => 'branches', 'momo_notice_branches' => [ (int) $term->term_id ],
        'momo_notice_start_at' => '2026-01-01 00:00', '_momo_notice_start_utc' => '2025-12-31 15:00:00', 'momo_notice_end_at' => '2030-12-31 23:59', '_momo_notice_end_utc' => '2030-12-31 14:59:00' ] as $k => $v ) { update_post_meta( $id, $k, $v ); }
      wp_set_object_terms( $id, [ (int) $term->term_id ], 'momo_branch_scope' );
      global $wpdb; $wpdb->update( $wpdb->posts, [ 'post_status' => 'publish' ], [ 'ID' => $id ] ); clean_post_cache( $id );
      echo $id;`),
    10,
  );
}

function visible(html: string): string {
  const i = html.indexOf('id="wpbody-content"');
  return (i >= 0 ? html.slice(i) : html).replace(/<script[\s\S]*?<\/script>/g, ' ').replace(/<style[\s\S]*?<\/style>/g, ' ').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ');
}

test.beforeAll(async () => {
  clearThrottle();
  Object.assign(
    ids,
    JSON.parse(
      wpEval(`
      $o = [];
      foreach ( get_posts( [ 'post_type' => 'momo_branch', 'post_status' => 'any', 'numberposts' => -1 ] ) as $p ) { $o[ 'branch_' . get_post_meta( $p->ID, 'momo_branch_code', true ) ] = $p->ID; $o[ 'slug_' . get_post_meta( $p->ID, 'momo_branch_code', true ) ] = $p->post_name; $o[ 'title_' . get_post_meta( $p->ID, 'momo_branch_code', true ) ] = $p->post_title; }
      global $wpdb;
      foreach ( $wpdb->get_results( "SELECT id, branch_id, public_ref, status FROM {$wpdb->prefix}momo_reservations ORDER BY id" ) as $r ) {
        $c = get_post_meta( (int) $r->branch_id, 'momo_branch_code', true );
        $o['refs_' . $c][] = $r->public_ref; $o['res_' . $c][] = (int) $r->id;
      }
      foreach ( [ 'DKH', 'DNB', 'TST' ] as $c ) { $t = Momos\\Branch\\PostType\\BranchScopeTaxonomy::term_for_branch( $o[ 'branch_' . $c ] ); $o[ 'term_' . $c ] = $t ? $t->name : ''; }
      echo wp_json_encode( $o );`),
    ),
  );
  // Named vendor ROLE-01 account with an open access window (TC-ACL-022 precondition, DEC-035).
  ids.vendor = parseInt(
    wpEval(`
      $u = get_user_by( 'login', 'qa_iso_vendor' );
      $id = $u ? $u->ID : wp_insert_user( [ 'user_login' => 'qa_iso_vendor', 'user_pass' => '${PASSWORD}', 'user_email' => 'qa.iso.vendor@example.com', 'role' => 'administrator', 'display_name' => 'QA Iso Vendor' ] );
      update_user_meta( $id, 'momo_vendor', '1' );
      delete_user_meta( $id, 'momo_account_disabled' );
      update_user_meta( $id, 'momo_access_expires_at', gmdate( 'Y-m-d H:i:s', time() + 4 * 3600 ) );
      Momos\\Access\\Roles\\RoleInstaller::apply_user_denies( $id );
      echo $id;`),
    10,
  );
  for (const l of ['demo_bm_kitahama', 'demo_rs_kitahama', 'demo_ho', 'qa_iso_vendor']) ctx[l] = await login(l);
});

test.afterAll(async () => {
  for (const c of Object.values(ctx)) await c.req.dispose();
  wpEval(`$u = get_user_by( 'login', 'qa_iso_vendor' ); if ( $u ) { update_user_meta( $u->ID, 'momo_account_disabled', '1' ); } echo 'ok';`);
  clearThrottle();
});

// ---------------------------------------------------------------- TEST branch (FR-RES-026, branch spec §4.9)

test('FR-RES-026: hidden TEST branch is absent from every anonymous public channel', async ({ request }) => {
  const problems: string[] = [];
  const testId = ids.branch_TST;
  const slug = ids.slug_TST;
  const title = ids.title_TST as string;
  const search = await request.get(`/wp-json/wp/v2/search?search=${encodeURIComponent(title.slice(0, 3))}&subtype=any`);
  if ((await search.text()).includes(`"id":${testId}`)) problems.push(`GET /wp-json/wp/v2/search?search=${title.slice(0, 3)} lists the TEST branch (id ${testId})`);
  const oembed = await request.get(`/wp-json/oembed/1.0/embed?url=${encodeURIComponent(`${BASE}/branches/${slug}/`)}`);
  if (oembed.status() === 200) problems.push(`GET /wp-json/oembed/1.0/embed?url=…/branches/${slug}/ → 200 ${(await oembed.text()).slice(0, 160)}`);
  const page = await request.get(`/branches/${slug}/`, { maxRedirects: 0 });
  if (page.status() !== 404) problems.push(`/branches/${slug}/ → ${page.status()}`);
  const embedPage = await request.get(`/branches/${slug}/embed/`, { maxRedirects: 0 });
  if (embedPage.status() === 200 && (await embedPage.text()).includes(title)) problems.push(`/branches/${slug}/embed/ renders the TEST branch`);
  const rules = await request.get('/wp-json/momos/v1/branches/TST/reservation-rules');
  if (rules.status() !== 404) problems.push(`API-01 TST → ${rules.status()}`);
  const core = await request.get(`/wp-json/wp/v2/momo_branch/${testId}`);
  if (core.status() === 200) problems.push(`wp/v2/momo_branch/${testId} → 200`);
  for (const lang of ['', 'en/']) {
    const form = await request.get(`/${lang}reservation/?branch=TST`);
    const html = await form.text();
    // Reservation spec §3.14: reachable at /reservation/?branch=TST only by logged-in users assigned to it.
    if (/TEST BRANCH|テスト店舗（実在の店舗ではありません）/.test(html)) problems.push(`anonymous /${lang}reservation/?branch=TST renders the TEST branch form/banner`);
  }
  for (const u of ['/wp-sitemap.xml', '/feed/', '/en/branches/', '/branches/']) {
    const t = await (await request.get(u)).text();
    if (t.includes(`/branches/${slug}/`) || t.includes(title)) problems.push(`${u} mentions the TEST branch`);
  }
  expect(problems, problems.join('\n')).toEqual([]);
});

test('FR-RES-026: staff not assigned to TEST cannot see it (page, REST, search)', async () => {
  const problems: string[] = [];
  for (const l of ['demo_bm_kitahama', 'demo_rs_kitahama']) {
    const c = ctx[l];
    const page = await c.req.get(`/branches/${ids.slug_TST}/`, { maxRedirects: 0 });
    if (page.status() !== 404) problems.push(`${l} /branches/${ids.slug_TST}/ → ${page.status()}`);
    const core = await c.req.get(`/wp-json/wp/v2/momo_branch/${ids.branch_TST}`, { headers: { 'X-WP-Nonce': c.restNonce } });
    if (core.status() === 200) problems.push(`${l} wp/v2/momo_branch/TEST → 200`);
    const s = await c.req.get(`/wp-json/wp/v2/search?search=${encodeURIComponent((ids.title_TST as string).slice(0, 3))}`, { headers: { 'X-WP-Nonce': c.restNonce } });
    if ((await s.text()).includes(`"id":${ids.branch_TST}`)) problems.push(`${l} wp/v2/search lists TEST`);
    const hours = await c.req.get(`/wp-admin/edit.php?post_type=momo_branch&page=momos-hours&branch=${ids.branch_TST}`, { maxRedirects: 0 });
    if (hours.status() !== 403) problems.push(`${l} hours editor TEST → ${hours.status()}`);
    const menu = await c.req.get(`/wp-admin/admin.php?page=momo-branch-menu&branch=${ids.branch_TST}`, { maxRedirects: 0 });
    if (menu.status() !== 403) problems.push(`${l} branch menu TEST → ${menu.status()}`);
  }
  expect(problems, problems.join('\n')).toEqual([]);
});

// ---------------------------------------------------------------- TC-ACL-003 list screens and counts

test('TC-ACL-003 / E3: list-screen view counts of branches, notices and offers include only assigned-branch rows', async () => {
  const problems: string[] = [];
  const c = ctx.demo_bm_kitahama;
  for (const pt of ['momo_branch', 'momo_notice', 'momo_offer']) {
    const html = await (await c.req.get(`/wp-admin/edit.php?post_type=${pt}`)).text();
    const rows = (html.match(/<tr id="post-\d+"/g) || []).length;
    const views = [...html.matchAll(/<li class='([a-z_-]+)'><a [^>]*>[^<]*<span class="count">\((\d+)\)<\/span>/g)].map((m) => ({ view: m[1], n: parseInt(m[2], 10) }));
    const all = views.find((v) => v.view === 'all');
    if (all && all.n !== rows) problems.push(`BM Kitahama edit.php?post_type=${pt}: "All (${all.n})" but ${rows} row(s) visible — views: ${JSON.stringify(views)}`);
  }
  expect(problems, problems.join('\n')).toEqual([]);
});

test('TC-ACL-003 / E6: heartbeat badge and inbox totals count only assigned branches', async () => {
  const problems: string[] = [];
  const own = parseInt(wpEval(`global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}momo_reservations WHERE status = 'pending' AND branch_id = ${ids.branch_DKH}");`), 10);
  for (const l of ['demo_bm_kitahama', 'demo_rs_kitahama']) {
    const c = ctx[l];
    const page = await (await c.req.get('/wp-admin/admin.php?page=momos-reservations')).text();
    const hb = (page.match(/heartbeatSettings = \{"nonce":"([^"]+)"/) || [])[1];
    expect(hb, 'heartbeat nonce on A08').toBeTruthy();
    const res = await c.req.post('/wp-admin/admin-ajax.php', { form: { action: 'heartbeat', _nonce: hb!, screen_id: 'toplevel_page_momos-reservations', has_focus: 'false', interval: '60', 'data[momos_res_badge]': '1' } });
    const body = await res.json();
    if (body?.momos_res_badge?.pending !== own) problems.push(`${l} heartbeat pending=${JSON.stringify(body?.momos_res_badge)} expected ${own}`);
    const api = await c.req.get('/wp-json/momos/v1/staff/reservations?status=pending,confirmed,rejected,cancelled,expired,completed,no_show&per_page=50', { headers: { 'X-WP-Nonce': c.restNonce } });
    const rows: any[] = await api.json();
    const foreign = rows.filter((r) => r.branch_code !== 'DKH');
    if (foreign.length) problems.push(`${l} API-10 returned foreign rows ${foreign.map((r) => r.reference_display).join(',')}`);
    if (parseInt(api.headers()['x-wp-total'] || '-1', 10) !== (ids.res_DKH || []).length) problems.push(`${l} X-WP-Total=${api.headers()['x-wp-total']} expected ${(ids.res_DKH || []).length}`);
  }
  expect(problems, problems.join('\n')).toEqual([]);
});

// ---------------------------------------------------------------- TC-ACL-004 / 009 direct admin URLs

test('TC-ACL-004 / TC-ACL-009: direct admin URLs with Namba / TEST ids are 403 with no data and audited', async () => {
  const problems: string[] = [];
  const nambaNotice = makeNotice(ids.branch_DNB, 'QA-ISO Namba notice');
  const nambaOffer = parseInt(wpEval(`$p = get_posts( [ 'post_type' => 'momo_offer', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ] ); foreach ( $p as $id ) { $b = Momos\\Branch\\PostType\\BranchScopeTaxonomy::branch_ids_of_post( $id ); if ( $b && ! in_array( ${ids.branch_DKH}, $b, true ) ) { echo $id; break; } }`) || '0', 10);
  const refB = ids.refs_DNB[0];
  const resB = ids.res_DNB[0];
  const noticeStatus = wpEval(`echo get_post_status( ${nambaNotice} );`);
  try {
    const urls = [
      `post.php?post=${nambaNotice}&action=edit`,
      `post.php?post=${nambaOffer}&action=edit`,
      `post.php?post=${ids.branch_DNB}&action=edit`,
      `post.php?post=${ids.branch_TST}&action=edit`,
      `edit.php?post_type=momo_branch&page=momos-hours&branch=${ids.branch_DNB}`,
      `edit.php?post_type=momo_branch&page=momos-hours&branch=${ids.branch_DNB}&tab=closures`,
      `admin.php?page=momo-branch-menu&branch=${ids.branch_DNB}`,
      `admin.php?page=momos-reservations&id=${resB}`,
      // (Foreign `branch=` filters on A08/A17 are asserted in qa-staff-access-matrix.spec.ts, TC-ACL-003.)
      `admin.php?page=momos-audit&branch=${ids.branch_DNB}`,
      `admin.php?page=momo-notifications`,
      `admin.php?page=momos-dsr&q=${encodeURIComponent(refB)}`,
      `post.php?post=${nambaNotice}&action=trash`,
    ];
    for (const l of ['demo_bm_kitahama', 'demo_rs_kitahama']) {
      const c = ctx[l];
      const before = deniedCount(c.id);
      let refused = 0;
      for (const u of urls) {
        const res = await c.req.get(`/wp-admin/${u}`, { maxRedirects: 0 });
        const body = await res.text();
        if (res.status() !== 403) problems.push(`${l} /wp-admin/${u} → ${res.status()}`);
        else refused++;
        if (body.includes(refB) || body.includes('QA-ISO Namba notice')) problems.push(`${l} /wp-admin/${u} leaks Namba data`);
      }
      // E-mail deep link (notifications spec §5: staff link) for a Namba reservation, following redirects.
      const deep = await c.req.get(`/wp-admin/admin.php?page=momo-reservations&reservation=${resB}`);
      if (deep.status() !== 403 || (await deep.text()).includes(refB)) problems.push(`${l} E07 deep link for Namba reservation → ${deep.status()}`);
      const after = deniedCount(c.id);
      if (after - before < 6) problems.push(`${l}: only ${after - before} access.denied rows for ${refused} refused momos screens (FR-ADM-003)`);
    }
    const stillThere = wpEval(`echo get_post_status( ${nambaNotice} );`);
    if (stillThere !== noticeStatus) problems.push(`Namba notice status changed from ${noticeStatus} to ${stillThere}`);
  } finally {
    wpEval(`wp_delete_post( ${nambaNotice}, true ); echo 'ok';`);
  }
  expect(problems, problems.join('\n')).toEqual([]);
});

// ---------------------------------------------------------------- TC-ACL-006 forms replayed with valid session nonces

test('TC-ACL-006: hours/closure admin-post with a valid session nonce for Namba is 403 and changes nothing', async () => {
  const problems: string[] = [];
  const state = () => wpEval(`global $wpdb; echo md5( wp_json_encode( [ $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}momo_branch_hours WHERE branch_id IN (${ids.branch_DNB}, ${ids.branch_TST}) ORDER BY id" ), $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}momo_branch_closures WHERE branch_id IN (${ids.branch_DNB}, ${ids.branch_TST}) ORDER BY id" ) ] ) );`);
  const before = state();
  const day = new Date(Date.now() + 20 * 86400_000).toISOString().slice(0, 10);
  for (const l of ['demo_bm_kitahama', 'demo_rs_kitahama']) {
    const c = ctx[l];
    for (const target of [ids.branch_DNB, ids.branch_TST]) {
      const nonce = await sessionNonce(c, `momos_hours_${target}`);
      const forms: Record<string, Record<string, string>> = {
        momos_save_closure: { date_from: day, date_to: day, type: 'closed_all_day', public_reason: 'other', confirm: '1', replace_existing: '1' },
        momos_delete_closure: { date: day },
        momos_save_hours: { set: 'default', 'periods[1][0][opens_at]': '09:00', 'periods[1][0][closes_at]': '10:00', 'periods[1][0][last_order_at]': '09:30', 'periods[1][0][label]': 'all_day', confirm: '1' },
        momos_delete_hours_set: { set: `${day}_open` },
      };
      for (const [action, form] of Object.entries(forms)) {
        const res = await c.req.post('/wp-admin/admin-post.php', { form: { action, branch_id: String(target), _momos_nonce: nonce, ...form }, maxRedirects: 0 });
        if (res.status() !== 403) problems.push(`${l} ${action} branch ${target} with valid nonce → ${res.status()} ${res.headers().location || ''}`);
      }
    }
  }
  if (state() !== before) problems.push('Namba/TEST hours or closures changed');
  expect(problems, problems.join('\n')).toEqual([]);
});

test('TC-ACL-006: quick edit (admin-ajax inline-save) of the Namba / TEST branch is refused', async () => {
  const c = ctx.demo_bm_kitahama;
  const html = await (await c.req.get('/wp-admin/edit.php?post_type=momo_branch')).text();
  const nonce = inputValue(html, '_inline_edit');
  expect(nonce, 'inline-edit nonce on the branch list').toBeTruthy();
  const problems: string[] = [];
  for (const id of [ids.branch_DNB, ids.branch_TST]) {
    const before = wpEval(`$p = get_post( ${id} ); echo md5( $p->post_title . $p->post_status . $p->post_name );`);
    const res = await c.req.post('/wp-admin/admin-ajax.php', { form: { action: 'inline-save', _inline_edit: nonce!, post_ID: String(id), post_type: 'momo_branch', post_title: 'QA-ISO HACK', post_name: 'qa-iso-hack', _status: 'draft', screen: 'edit-momo_branch' } });
    const after = wpEval(`$p = get_post( ${id} ); echo md5( $p->post_title . $p->post_status . $p->post_name );`);
    if (after !== before) problems.push(`inline-save changed branch ${id}`);
    if ((await res.text()).includes('QA-ISO HACK')) problems.push(`inline-save echoed a row for branch ${id}`);
  }
  expect(problems, problems.join('\n')).toEqual([]);
});

/**
 * M3 / TC-ACL-021: the classic editor save (post.php action=editpost) with `tax_input[momo_branch_scope]`
 * and no `momos_fields` — the guard only inspects `momos_fields`, so the core taxonomy path must still
 * refuse a foreign scope.
 */
test('TC-ACL-021 / M3: Kitahama BM cannot re-scope its own notice to Namba through core tax_input', async () => {
  const c = ctx.demo_bm_kitahama;
  const notice = makeNotice(ids.branch_DKH, 'QA-ISO Kitahama notice', c.id);
  try {
    const edit = await (await c.req.get(`/wp-admin/post.php?post=${notice}&action=edit`)).text();
    const nonce = inputValue(edit, '_wpnonce');
    expect(nonce, 'post edit nonce').toBeTruthy();
    const res = await c.req.post('/wp-admin/post.php', {
      form: { action: 'editpost', post_ID: String(notice), _wpnonce: nonce!, post_type: 'momo_notice', original_post_status: 'publish', post_status: 'publish', post_title: 'QA-ISO Kitahama notice', 'tax_input[momo_branch_scope]': ids.term_DNB },
      maxRedirects: 0,
    });
    const scope = JSON.parse(wpEval(`echo wp_json_encode( Momos\\Branch\\PostType\\BranchScopeTaxonomy::branch_ids_of_post( ${notice} ) );`));
    const namba = await (await c.req.get(`/branches/${ids.slug_DNB}/`)).text();
    expect(
      { status: res.status(), scope, onNambaPage: namba.includes('QA-ISO Kitahama notice') },
      `POST post.php editpost tax_input[momo_branch_scope]=${ids.term_DNB} → ${res.status()}; notice now scoped to ${JSON.stringify(scope)}`,
    ).toEqual({ status: 403, scope: [ids.branch_DKH], onNambaPage: false });
  } finally {
    wpEval(`wp_delete_post( ${notice}, true ); echo 'ok';`);
  }
});

test('TC-ACL-021: Kitahama BM cannot create a draft offer scoped to Namba through core tax_input', async () => {
  const c = ctx.demo_bm_kitahama;
  const page = await (await c.req.get('/wp-admin/post-new.php?post_type=momo_offer')).text();
  const nonce = inputValue(page, '_wpnonce');
  const postId = inputValue(page, 'post_ID');
  expect(nonce && postId, 'new offer form').toBeTruthy();
  try {
    const res = await c.req.post('/wp-admin/post.php', {
      form: { action: 'editpost', post_ID: postId!, _wpnonce: nonce!, post_type: 'momo_offer', original_post_status: 'auto-draft', post_status: 'draft', post_title: 'QA-ISO offer', save: 'Save Draft', 'tax_input[momo_branch_scope]': ids.term_DNB },
      maxRedirects: 0,
    });
    const scope = JSON.parse(wpEval(`echo wp_json_encode( Momos\\Branch\\PostType\\BranchScopeTaxonomy::branch_ids_of_post( ${postId} ) );`));
    expect(scope.includes(ids.branch_DNB), `draft offer ${postId} scoped to Namba (HTTP ${res.status()}): ${JSON.stringify(scope)}`).toBe(false);
  } finally {
    wpEval(`wp_delete_post( ${postId}, true ); echo 'ok';`);
  }
});

// ---------------------------------------------------------------- TC-ACL-022 vendor

test('TC-ACL-022 / PRV-028: vendor account cannot read or act on reservations through any REST route', async () => {
  const c = ctx.qa_iso_vendor;
  const id = ids.res_DKH[0];
  const snapshot = () => wpEval(`global $wpdb; echo md5( wp_json_encode( [ $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}momo_reservations WHERE id = ${id}" ), $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}momo_reservation_events WHERE reservation_id = ${id}" ) ] ) );`);
  const before = snapshot();
  const version = parseInt(wpEval(`global $wpdb; echo (int) $wpdb->get_var( "SELECT version FROM {$wpdb->prefix}momo_reservations WHERE id = ${id}" );`), 10);
  const guest = wpEval(`global $wpdb; echo $wpdb->get_var( "SELECT guest_name FROM {$wpdb->prefix}momo_reservations WHERE id = ${id}" );`);
  const h = { 'X-WP-Nonce': c.restNonce, 'Content-Type': 'application/json' };
  const calls: [string, () => Promise<any>][] = [
    ['API-10', () => c.req.get('/wp-json/momos/v1/staff/reservations', { headers: h })],
    ['API-11', () => c.req.get(`/wp-json/momos/v1/staff/reservations/${id}`, { headers: h })],
    // A stale version: even the refusal path must not run the action or return the record.
    ['API-16 PATCH', () => c.req.patch(`/wp-json/momos/v1/staff/reservations/${id}`, { headers: h, data: { party_size: 3, version: version + 100, reason: 'agreed_with_guest' } })],
    ['API-12', () => c.req.post(`/wp-json/momos/v1/staff/reservations/${id}/transitions`, { headers: h, data: { action: 'accept', version: version + 100 } })],
  ];
  const problems: string[] = [];
  for (const [name, call] of calls) {
    const res = await call();
    const body = await res.text();
    if (res.status() !== 403) problems.push(`vendor ${name} → ${res.status()} ${body.slice(0, 200)}`);
    if (guest && body.includes(guest)) problems.push(`vendor ${name} response contains the guest name`);
  }
  // A08/A09 and export/DSR screens (TC-ACL-022 step 1).
  for (const u of [`admin.php?page=momos-reservations`, `admin.php?page=momos-reservations&id=${id}`, 'admin.php?page=momos-dsr', 'admin-post.php?action=momos_export']) {
    const res = await c.req.get(`/wp-admin/${u}`, { maxRedirects: 0 });
    if (res.status() !== 403) problems.push(`vendor /wp-admin/${u} → ${res.status()}`);
  }
  if (snapshot() !== before) problems.push('vendor calls changed the reservation');
  expect(problems, problems.join('\n')).toEqual([]);
});

// ---------------------------------------------------------------- TC-ACL-008 notification fan-out

test('TC-ACL-008 / E9: every staff notification row went only to recipients of the reservation branch', async () => {
  const out = JSON.parse(
    wpEval(`
      global $wpdb; $bad = [];
      foreach ( $wpdb->get_results( "SELECT n.id, n.recipient_email, n.recipient_type, n.branch_id, r.branch_id AS res_branch FROM {$wpdb->prefix}momo_notifications n JOIN {$wpdb->prefix}momo_reservations r ON r.id = n.reservation_id WHERE n.recipient_type IN ('branch','user')" ) as $n ) {
        $allowed = array_map( static fn( $r ) => strtolower( $r['email'] ), Momos\\Branch\\Repository\\BranchSettingsRepository::active_recipients( (int) $n->res_branch ) );
        if ( (int) $n->branch_id !== (int) $n->res_branch || ! in_array( strtolower( (string) $n->recipient_email ), $allowed, true ) ) { $bad[] = [ 'id' => (int) $n->id, 'to' => $n->recipient_email, 'res_branch' => (int) $n->res_branch ]; }
      }
      echo wp_json_encode( $bad );`),
  );
  expect(out, `staff notifications to recipients of another branch: ${JSON.stringify(out)}`).toEqual([]);
});

// ---------------------------------------------------------------- customer token / enumeration (API-03/04)

test('Reservation spec §3.6/§3.7: API-03 answers do not reveal whether a reference exists, even when rate limited', async ({ request }) => {
  // E2E isolation: this test depends on the API-03 per-IP limit, so it sets the documented default (30/h) itself
  // and restores whatever value the install had afterwards.
  const prevLimit = wpEval(`echo wp_json_encode( get_option( 'momo_rate_status_ip_per_hour', null ) );`);
  wpEval(`update_option( 'momo_rate_status_ip_per_hour', 30, false ); echo 'ok';`);
  try {
    clearThrottle();
    const token = 'A'.repeat(43);
    const real = ids.refs_DNB[0];
    const problems: string[] = [];
    const norm = (s: string) => s.replace(/"request_id":"[^"]+"/, '').replace(/"retry_after":\d+/, '');
    const a = await request.get(`/wp-json/momos/v1/reservations/${real}?lang=en`, { headers: { 'X-Momo-Token': token } });
    const b = await request.get('/wp-json/momos/v1/reservations/ZZZZZZZZ?lang=en', { headers: { 'X-Momo-Token': token } });
    if (a.status() !== b.status() || norm(await a.text()) !== norm(await b.text())) problems.push(`wrong token: real ${a.status()} vs unknown ${b.status()}`);
    const q = await request.get(`/wp-json/momos/v1/reservations/${real}?t=${token}`);
    if (q.status() !== 400) problems.push(`token in query string → ${q.status()} (expected 400 before lookup)`);
    let last = 0;
    for (let i = 0; i < 40 && last !== 429; i++) {
      last = (await request.get(`/wp-json/momos/v1/reservations/ZZZZZZZ${i % 10}?lang=en`, { headers: { 'X-Momo-Token': token } })).status();
    }
    expect(last, 'status_ip limit reached').toBe(429);
    const ra = await request.get(`/wp-json/momos/v1/reservations/${real}?lang=en`, { headers: { 'X-Momo-Token': token } });
    const rb = await request.get('/wp-json/momos/v1/reservations/YYYYYYYY?lang=en', { headers: { 'X-Momo-Token': token } });
    const ta = norm(await ra.text());
    const tb = norm(await rb.text());
    if (ta !== tb) problems.push(`429 body differs for an existing reference:\n  real:    ${ta}\n  unknown: ${tb}`);
    expect(problems, problems.join('\n')).toEqual([]);
  } finally {
    clearThrottle();
    wpEval(`$v = json_decode( ${JSON.stringify(prevLimit)}, true ); null === $v ? delete_option( 'momo_rate_status_ip_per_hour' ) : update_option( 'momo_rate_status_ip_per_hour', $v, false ); echo 'ok';`);
  }
});

// ---------------------------------------------------------------- core wp/v2 surface

test('TC-ACL-012 / E7: core wp/v2 routes expose nothing branch-private to branch staff or anonymous users', async ({ request }) => {
  const problems: string[] = [];
  for (const l of ['demo_bm_kitahama', 'demo_rs_kitahama']) {
    const c = ctx[l];
    const h = { 'X-WP-Nonce': c.restNonce };
    const checks: [string, number[]][] = [
      [`/wp-json/wp/v2/momo_branch/${ids.branch_DNB}?context=edit`, [401, 403]],
      [`/wp-json/wp/v2/momo_branch/${ids.branch_DNB}/revisions`, [401, 403]],
      [`/wp-json/wp/v2/users?context=edit`, [401, 403]],
      ['/wp-json/wp/v2/settings', [401, 403]],
    ];
    for (const [u, ok] of checks) {
      const res = await c.req.get(u, { headers: h });
      if (!ok.includes(res.status())) problems.push(`${l} GET ${u} → ${res.status()}`);
    }
    const w = await c.req.post(`/wp-json/wp/v2/momo_branch/${ids.branch_DNB}`, { headers: { ...h, 'Content-Type': 'application/json' }, data: { title: 'QA-ISO' } });
    if (![403, 405].includes(w.status())) problems.push(`${l} POST wp/v2/momo_branch/DNB → ${w.status()}`);
  }
  const anon = await request.get('/wp-json/wp/v2/users');
  if (anon.status() !== 401) problems.push(`anonymous /wp/v2/users → ${anon.status()}`);
  for (const u of ['/wp-json/', '/wp-json/wp/v2/search?search=example.org']) {
    const t = await (await request.get(u)).text();
    if (/demo\.guest\d+@example\.com/.test(t)) problems.push(`${u} contains a guest e-mail`);
  }
  expect(problems, problems.join('\n')).toEqual([]);
});
