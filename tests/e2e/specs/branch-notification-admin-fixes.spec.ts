/**
 * Branch / notification / admin fixes against the seeded demo site (Kitahama = DKH, Namba = DNB):
 *  - FR-BR-005 + AC-BR-03-07: the Branch Manager sees the reservation settings (momo_res_*) read-only in
 *    the branch editor; a crafted POST of a momo_res_* change is rejected and audited (access.denied).
 *  - FR-ADM-002(e): the "Assigned staff" panel lists active users only (a disabled account is left out).
 *  - FR-CORP-010(c): the offers list filters by state, scope and branch; branch-scoped users only get
 *    their branches.
 *  - AC-ADM-01-02 / FR-ADM-001(d): "Offers awaiting approval: N" (HO) links to the filtered list; CE gets
 *    "Pages awaiting review" and page translation gaps with the LCCS %.
 *  - AC-SEO-02-04 + AC-BR-04-06: a BM hour change is re-fetched in the branch page JSON-LD and listed in
 *    the next HO digest with weekday, before → after and user.
 * Synthetic data (QA-FIX markers) is created through bin/wp and removed afterwards.
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
function branchId(code: string): number {
  return parseInt(wpEval(`$b = get_posts( [ 'post_type' => 'momo_branch', 'post_status' => 'any', 'meta_key' => 'momo_branch_code', 'meta_value' => '${code}', 'fields' => 'ids' ] ); echo (int) $b[0];`), 10);
}
function audits(action: string, where = '1=1'): number {
  return parseInt(wpEval(`global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}momo_audit_log WHERE action = %s AND ${where}", ${JSON.stringify(action)} ) );`), 10);
}
async function login(browser: Browser, user: string): Promise<Page> {
  wpEval(`global $wpdb; $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%momos_lt_%'"); wp_cache_flush(); echo 'ok';`);
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  await page.goto(`${BASE}/wp-login.php`);
  await page.fill('#user_login', user);
  await page.fill('#user_pass', PASSWORD);
  await Promise.all([page.waitForURL(/wp-admin/), page.click('#wp-submit')]);
  return page;
}
function jsonLdHours(html: string): string[] {
  const out: string[] = [];
  for (const m of html.matchAll(/<script type="application\/ld\+json"[^>]*>([\s\S]*?)<\/script>/g)) {
    const walk = (n: unknown): void => {
      if (Array.isArray(n)) { n.forEach(walk); return; }
      if (n && typeof n === 'object') {
        const o = n as Record<string, unknown>;
        if (o['@type'] === 'Restaurant' && Array.isArray(o.openingHoursSpecification)) {
          for (const h of o.openingHoursSpecification as Record<string, string>[]) {
            if (!h.validFrom) out.push(`${String(h.dayOfWeek).replace('https://schema.org/', '')} ${h.opens}-${h.closes}`);
          }
        }
        Object.values(o).forEach(walk);
      }
    };
    walk(JSON.parse(m[1]));
  }
  return out;
}

test('AC-BR-03-07 / FR-BR-005: BM sees reservation settings read-only; a crafted POST is rejected and audited', async ({ browser }) => {
  const dkh = branchId('DKH');
  const before = wpEval(`echo get_post_meta( ${dkh}, 'momo_res_party_max', true );`);
  const page = await login(browser, 'demo_bm_kitahama');
  await page.goto(`${BASE}/wp-admin/post.php?post=${dkh}&action=edit`);
  const box = page.locator('#momos-momo_branch_reservation');
  await expect(box).toBeVisible();
  await expect(box.locator('.momos-readonly').first()).toBeVisible();
  expect(await page.locator('[name^="momos_fields[momo_res_"]').count(), 'no editable momo_res_* input for the BM').toBe(0);

  // Crafted POST: the editor's own form (valid nonces) plus a momo_res_* value.
  const form = await page.locator('form#post').evaluate((f: HTMLFormElement) => {
    const out: Record<string, string> = {};
    new FormData(f).forEach((v, k) => { if (!k.endsWith('[]')) out[k] = String(v); });
    return out;
  });
  const denied = audits('access.denied', `branch_id = ${dkh}`);
  const crafted = { ...form, 'momos_fields[momo_res_party_max]': String(parseInt(before || '10', 10) + 7), '_momos_rendered[]': 'momo_res_party_max', action: 'editpost' };
  const res = await page.request.post(`${BASE}/wp-admin/post.php`, { form: crafted, maxRedirects: 0 });
  expect([200, 302]).toContain(res.status());
  expect(wpEval(`wp_cache_flush(); echo get_post_meta( ${dkh}, 'momo_res_party_max', true );`), 'momo_res_party_max unchanged').toBe(before);
  expect(audits('access.denied', `branch_id = ${dkh}`), 'denial audited').toBeGreaterThan(denied);
  await page.context().close();
});

test('FR-ADM-002(e): the Assigned staff panel lists active users only', async ({ browser }) => {
  const dkh = branchId('DKH');
  const uid = parseInt(wpEval(`
    $id = wp_insert_user( [ 'user_login' => 'qa_fix_disabled_rs', 'user_pass' => wp_generate_password( 24 ), 'user_email' => 'qa-fix-disabled@example.test', 'display_name' => 'QA-FIX Disabled RS', 'role' => 'momo_reservation_staff' ] );
    global $wpdb; $wpdb->insert( \\Momos\\Kernel\\Db\\Tables::user_branches(), [ 'user_id' => $id, 'branch_id' => ${dkh}, 'assigned_at' => gmdate( 'Y-m-d H:i:s' ) ] );
    update_user_meta( $id, 'momo_account_disabled', '1' ); echo (int) $id;`), 10);
  try {
    const page = await login(browser, 'demo_bm_kitahama');
    await page.goto(`${BASE}/wp-admin/post.php?post=${dkh}&action=edit`);
    const panel = page.locator('#momos-branch-staff');
    await expect(panel).toBeVisible();
    const rsName = wpEval(`echo get_user_by( 'login', 'demo_rs_kitahama' )->display_name;`);
    await expect(panel).toContainText(rsName);
    await expect(panel).not.toContainText('QA-FIX Disabled RS');
    expect(await panel.locator('input, a').count(), 'read-only').toBe(0);
    await page.context().close();
  } finally {
    wpEval(`require_once ABSPATH . 'wp-admin/includes/user.php'; global $wpdb; $wpdb->delete( \\Momos\\Kernel\\Db\\Tables::user_branches(), [ 'user_id' => ${uid} ] ); wp_delete_user( ${uid} ); echo 'ok';`);
  }
});

test('FR-CORP-010(c) + AC-ADM-01-02: offer list filters and "Offers awaiting approval: N" link', async ({ browser }) => {
  const dkh = branchId('DKH');
  const dnb = branchId('DNB');
  const ids = wpEval(`
    $mk = function ( $title, $status, $scope, $branch ) {
      $id = wp_insert_post( [ 'post_type' => 'momo_offer', 'post_status' => 'draft', 'post_title' => $title ] );
      global $wpdb; $wpdb->update( $wpdb->posts, [ 'post_status' => $status ], [ 'ID' => $id ] ); clean_post_cache( $id );
      update_post_meta( $id, 'momo_offer_scope', $scope );
      update_post_meta( $id, '_momo_offer_start_utc', gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
      update_post_meta( $id, '_momo_offer_end_utc', gmdate( 'Y-m-d H:i:s', time() + 10 * 86400 ) );
      if ( $branch ) { wp_set_object_terms( $id, [ \\Momos\\Branch\\PostType\\BranchScopeTaxonomy::term_for_branch( $branch )->term_id ], 'momo_branch_scope' ); }
      return $id;
    };
    echo implode( ',', [ $mk( 'QA-FIX pending DKH', 'pending', 'branches', ${dkh} ), $mk( 'QA-FIX pending DNB', 'pending', 'branches', ${dnb} ), $mk( 'QA-FIX scheduled all', 'publish', 'all_branches', 0 ) ] );`);
  try {
    const pending = parseInt(wpEval(`wp_cache_flush(); echo (int) wp_count_posts( 'momo_offer' )->pending;`), 10);
    expect(pending).toBeGreaterThanOrEqual(2);
    const ho = await login(browser, 'demo_ho');
    await ho.goto(`${BASE}/wp-admin/index.php`);
    const widget = ho.locator('#momos_offers_pending');
    await expect(widget).toBeVisible();
    const link = widget.locator('a').first();
    await expect(link).toHaveText(new RegExp(`(承認待ちの特典：${pending}件|Offers awaiting approval: ${pending})`));
    expect(await link.getAttribute('href')).toContain('momo_offer_state=pending');

    await Promise.all([ho.waitForLoadState('domcontentloaded'), link.click()]);
    await expect(ho.locator('select#momo_offer_state')).toHaveValue('pending');
    await expect(ho.locator('#the-list tr.type-momo_offer')).toHaveCount(pending);
    await expect(ho.locator('#the-list')).toContainText('QA-FIX pending DKH');
    await expect(ho.locator('#the-list')).not.toContainText('QA-FIX scheduled all');

    await ho.goto(`${BASE}/wp-admin/edit.php?post_type=momo_offer&momo_offer_scope=all_branches&momo_offer_state=scheduled`);
    await expect(ho.locator('#the-list')).toContainText('QA-FIX scheduled all');
    await expect(ho.locator('#the-list')).not.toContainText('QA-FIX pending');
    await ho.goto(`${BASE}/wp-admin/edit.php?post_type=momo_offer&momo_offer_branch=${dnb}`);
    await expect(ho.locator('#the-list')).toContainText('QA-FIX pending DNB');
    await expect(ho.locator('#the-list')).toContainText('QA-FIX scheduled all');
    await expect(ho.locator('#the-list')).not.toContainText('QA-FIX pending DKH');
    for (const name of ['momo_offer_state', 'momo_offer_scope', 'momo_offer_branch']) {
      await expect(ho.locator(`label.screen-reader-text[for="${name}"]`)).toHaveCount(1);
    }
    await ho.context().close();

    // Branch-scoped: the BM of Kitahama gets only Kitahama in the branch filter; Namba's offer never listed.
    const bm = await login(browser, 'demo_bm_kitahama');
    await bm.goto(`${BASE}/wp-admin/edit.php?post_type=momo_offer&momo_offer_branch=${dnb}`);
    const options = await bm.locator('select#momo_offer_branch option').evaluateAll((os) => os.map((o) => (o as HTMLOptionElement).value).filter(Boolean));
    expect(options).toEqual([String(dkh)]);
    await expect(bm.locator('#the-list')).not.toContainText('QA-FIX pending DNB');
    await bm.context().close();

    // ROLE-04 (FR-ADM-001(d)): pages awaiting review + page translation gaps with LCCS %.
    const ce = await login(browser, 'demo_ce');
    await ce.goto(`${BASE}/wp-admin/index.php`);
    await expect(ce.locator('#momos_pages_pending')).toBeVisible();
    await expect(ce.locator('#momos_pages_pending a, #momos_pages_pending p').first()).toBeVisible();
    await expect(ce.locator('#momos_translation_gaps table.momos-dash-gaps')).toBeVisible();
    await expect(ce.locator('#momos_translation_gaps')).toContainText('%');
    await ce.context().close();
  } finally {
    wpEval(`foreach ( explode( ',', '${ids}' ) as $id ) { wp_delete_post( (int) $id, true ); } echo 'ok';`);
  }
});

test('AC-SEO-02-04 + AC-BR-04-06: BM hour change → branch page JSON-LD re-fetched with new hours; next HO digest lists it', async ({ request }) => {
  const dkh = branchId('DKH');
  const url = wpEval(`echo get_permalink( ${dkh} );`);
  const saved = wpEval(`global $wpdb; echo wp_json_encode( $wpdb->get_results( $wpdb->prepare( 'SELECT weekday, period_no, period_label AS label, LEFT(opens_at,5) AS opens_at, LEFT(closes_at,5) AS closes_at, LEFT(last_order_at,5) AS last_order_at FROM ' . \\Momos\\Kernel\\Db\\Tables::hours() . ' WHERE branch_id = %d AND valid_from IS NULL ORDER BY weekday, period_no', ${dkh} ), ARRAY_A ) );`);
  const replace = (periodsJson: string, asBm: boolean) => wpEval(`
    ${asBm ? "wp_set_current_user( get_user_by( 'login', 'demo_bm_kitahama' )->ID );" : ''}
    $p = array_map( static fn( $r ) => [ 'weekday' => (int) $r['weekday'], 'period_no' => (int) $r['period_no'], 'label' => (string) $r['label'], 'opens_at' => $r['opens_at'], 'closes_at' => $r['closes_at'], 'last_order_at' => $r['last_order_at'] ], json_decode( '${periodsJson.replace(/'/g, "\\'")}', true ) );
    \\Momos\\Branch\\Repository\\HoursRepository::replace_week( ${dkh}, null, null, $p, ${asBm ? '\\Momos\\Branch\\Domain\\AccessContext::current()' : '\\Momos\\Branch\\Domain\\AccessContext::system()'} );
    \\Momos\\Kernel\\Events\\AfterCommit::flush(); echo 'ok';`);
  const before = jsonLdHours(await (await request.get(url)).text());
  expect(before).toContain('Monday 11:00-15:00');
  const changed = (JSON.parse(saved) as Record<string, string | null>[]).map((r) => (String(r.weekday) === '1' && String(r.period_no) === '1' ? { ...r, opens_at: '11:30', closes_at: '15:45' } : r));
  try {
    expect(replace(JSON.stringify(changed), true)).toBe('ok');
    const after = jsonLdHours(await (await request.get(url)).text());
    expect(after).toContain('Monday 11:30-15:45');
    expect(after).not.toContain('Monday 11:00-15:00');
    const digest = wpEval(`
      foreach ( \\Momos\\Notification\\Job\\DigestJob::sections( null, \\Momos\\Kernel\\Clock\\Clock::utc()->modify( '+1 minute' ) ) as $s ) {
        if ( 'branch_changes' === $s['key'] ) { echo implode( "\\n", $s['items'] ); }
      }`);
    const name = wpEval(`echo get_user_by( 'login', 'demo_bm_kitahama' )->display_name;`);
    expect(digest).toContain('DKH · branch.hours_updated · Mon: 11:00–15:00');
    expect(digest).not.toMatch(/before|after|\{|\[/);
    expect(digest).toMatch(new RegExp(`→ 11:30–15:45[^·]* · ${name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}`));
  } finally {
    replace(saved, false);
  }
});
