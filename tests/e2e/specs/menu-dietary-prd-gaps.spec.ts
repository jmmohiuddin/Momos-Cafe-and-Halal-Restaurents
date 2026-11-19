/**
 * Menu & Dietary PRD gaps against the seeded demo site:
 *  - FR-MENU-005(a) / Doc 07 §5.20: SCR-A05 category order — dragging a row by its handle saves the new
 *    order through the nonce-checked AJAX handler (audited `menu_category.reordered`); the keyboard
 *    up/down buttons still work and restore the order.
 *  - FR-DIET-002(f): the prohibited-wording list is on Settings (SCR-A15, tab "Other") for Head Office,
 *    seeded from the master list; the Menu Manager has no access to it.
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
function audits(action: string): number {
  return parseInt(wpEval(`global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}momo_audit_log WHERE action = %s", ${JSON.stringify(action)} ) );`), 10);
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
async function order(page: Page): Promise<string[]> {
  return page.locator('#momos-cat-order > tr').evaluateAll((rows) => rows.map((r) => (r as HTMLElement).dataset.termId || ''));
}

// Admin language of the demo staff is ja (DEC-037); en for other installs.
const SAVED = /Order saved\.|並び順を保存しました。/;
const ORDER_URL = `${BASE}/wp-admin/edit.php?post_type=momo_dish&page=momo-category-order`;

test.describe('Menu & Dietary PRD gaps', () => {
  test('FR-MENU-005(a): drag handle saves the category order; keyboard buttons restore it', async ({ browser }) => {
    const page = await login(browser, 'demo_mm');
    await page.goto(ORDER_URL);
    const before = await order(page);
    expect(before.length).toBeGreaterThan(1);
    const audited = audits('menu_category.reordered');

    // Drag the second row above the first by its handle (pointer only; jquery-ui-sortable).
    const handle = page.locator(`#cat-${before[1]} .momos-cat-handle`);
    const target = page.locator(`#cat-${before[0]}`);
    const hb = (await handle.boundingBox())!;
    const tb = (await target.boundingBox())!;
    await page.mouse.move(hb.x + hb.width / 2, hb.y + hb.height / 2);
    await page.mouse.down();
    for (let i = 1; i <= 10; i++) {
      await page.mouse.move(hb.x + hb.width / 2, hb.y + hb.height / 2 - ((hb.y - tb.y + tb.height / 2) * i) / 10);
    }
    await page.mouse.move(hb.x + hb.width / 2, tb.y + 2);
    await page.mouse.up();
    await expect(page.locator('#momos-cat-order-status')).toHaveText(SAVED);
    await expect(page.locator(`#cat-${before[1]} .momos-cat-pos`)).toHaveText('1');
    await expect(page.locator(`#cat-${before[1]} button[data-dir="up"]`)).toBeDisabled();

    await page.reload();
    const dragged = await order(page);
    expect(dragged.slice(0, 2)).toEqual([before[1], before[0]]);
    expect(dragged.slice(2)).toEqual(before.slice(2));
    expect(audits('menu_category.reordered')).toBe(audited + 1);

    // Keyboard: focus "Move up" of the former first category and press Enter (no pointer, admin-post).
    const up = page.locator(`#cat-${before[0]} button[data-dir="up"]`);
    await up.focus();
    await Promise.all([page.waitForURL(/moved=/), page.keyboard.press('Enter')]);
    await expect(page.locator('.notice-success')).toContainText(SAVED);
    expect(await order(page)).toEqual(before);
    expect(audits('menu_category.reordered')).toBe(audited + 2);
    await page.context().close();
  });

  test('FR-DIET-002(f): Head Office maintains the prohibited-wording list in Settings', async ({ browser }) => {
    const ho = await login(browser, 'demo_ho');
    await ho.goto(`${BASE}/wp-admin/admin.php?page=momo-settings&tab=other`);
    await expect(ho.getByRole('heading', { name: 'Prohibited wording (claims)' })).toBeVisible();
    for (const lang of ['all', 'ja', 'en', 'ar', 'ur', 'hi', 'bn']) {
      await expect(ho.locator(`[name^="momos_fields[momo_prohibited_terms_${lang}]"]`).first()).toBeAttached();
    }
    const enTerms = await ho.locator('input[name^="momos_fields[momo_prohibited_terms_en]"][name$="[term]"]').evaluateAll((els) => els.map((e) => (e as HTMLInputElement).value));
    expect(enTerms).toContain('gluten-free');

    // Edit one English term and save the tab; WordingGuard reads the saved list, the change is audited.
    const audited = audits('dietary.prohibited_terms_updated');
    const idx = enTerms.indexOf('gluten-free');
    await ho.locator('input[name^="momos_fields[momo_prohibited_terms_en]"][name$="[term]"]').nth(idx).fill('gluten-free menu QA');
    await Promise.all([ho.waitForURL(/updated=1/), ho.locator('form:has([name^="momos_fields[momo_prohibited_terms_en]"]) [type=submit]').first().click()]);
    try {
      expect(wpEval(`echo wp_json_encode( array_column( \\Momos\\Dietary\\Guard\\WordingGuard::check( 'Our GLUTEN-FREE MENU QA', 'en' ), 'term' ) );`)).toContain('gluten-free menu QA');
      expect(audits('dietary.prohibited_terms_updated')).toBe(audited + 1);
    } finally {
      // Back to the master list (the field default) for later runs.
      wpEval(`$v = get_option( 'momos_opt_momo-settings', [] ); foreach ( array_keys( $v ) as $k ) { if ( str_starts_with( $k, 'momo_prohibited_terms_' ) ) { unset( $v[ $k ] ); } } update_option( 'momos_opt_momo-settings', $v ); echo 'ok';`);
    }
    await ho.context().close();

    const mm = await login(browser, 'demo_mm');
    const res = await mm.goto(`${BASE}/wp-admin/admin.php?page=momo-settings&tab=other`);
    expect(res?.status()).toBe(403);
    await mm.context().close();
  });
});
