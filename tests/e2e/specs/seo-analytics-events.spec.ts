/**
 * FR-ANL-001 event allow-list (i18n-seo-analytics.md §12, theme assets/js/datalayer.js; AC-ANL-01-02/03,
 * TC-ANL-001/007) and the C10/C11 robots header (§9.3, access-security-privacy §8, TC-PRV-016, IMPL-008).
 *  - Page events: view_menu (hub, category, branch-scoped), view_dish, view_halal_information, view_branch.
 *  - Click events: select_language, click_reservation, click_phone, click_directions (delegated listener).
 *  - Every dataLayer event carries only the parameters allow-listed for that event, each matching its pattern
 *    (codes, slugs, SKU, language) — never names, free text or PII.
 *  - C10/C11 send `X-Robots-Tag: noindex, nofollow` exactly once (no duplicate from SEO + security headers).
 * Google requests are intercepted and aborted. Base URL: MOMOS_E2E_BASE_URL (seeded demo content).
 */
import { test, expect, Page } from '@playwright/test';

const BASE = (process.env.MOMOS_E2E_BASE_URL || process.env.MOMOS_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const GOOGLE = /googletagmanager\.com|google-analytics\.com|analytics\.google\.com/;

/** Mirror of datalayer.js EV/RX (the contract under test, §12.2). */
const EVENTS: Record<string, string[]> = {
  momo_page: ['page_type', 'language', 'branch_code', 'menu_category', 'dish_sku'],
  view_branch: ['branch_code', 'language'],
  view_menu: ['menu_category', 'branch_code', 'language'],
  view_dish: ['dish_sku', 'menu_category', 'language'],
  view_halal_information: ['language', 'source_page'],
  select_language: ['from_language', 'to_language'],
  click_reservation: ['cta_location', 'branch_code', 'language'],
  reservation_form_start: ['branch_code', 'language'],
  generate_lead: ['branch_code', 'language', 'party_size_band', 'lead_source'],
  click_phone: ['branch_code', 'cta_location', 'language'],
  click_directions: ['branch_code', 'cta_location', 'language'],
};
const LANG = /^(ja|en|ar|ur|hi|bn)$/;
const PATTERN: Record<string, RegExp> = {
  language: LANG, from_language: LANG, to_language: LANG,
  page_type: /^(home|about|halal|branch_directory|branch|menu_hub|menu_category|dish|offers|reservation|contact|faq|news|legal|gallery|error_404|page)$/,
  source_page: /^(halal|faq)$/, branch_code: /^[A-Z]{3}$/, menu_category: /^(hub|[a-z0-9-]{1,60})$/, dish_sku: /^D\d{4}$/,
  cta_location: /^(header|hero|branch_card|branch_page|sticky_bar|menu_page|dish_page|offer_card|home_section|halal_page|footer|reservation_page|error_page)$/,
  party_size_band: /^(1-2|3-6|7\+)$/, lead_source: /^reservation_form$/,
};

type Ev = Record<string, unknown>;

async function blockGoogle(page: Page): Promise<void> {
  await page.route(GOOGLE, (route) => route.abort());
}

/** Event objects pushed so far (gtag argument arrays and GTM internals excluded). */
async function events(page: Page): Promise<Ev[]> {
  return page.evaluate(() => JSON.parse(JSON.stringify(((window as unknown as { dataLayer?: unknown[] }).dataLayer || [])
    .filter((x) => x && typeof x === 'object' && !Array.isArray(x) && !('0' in (x as object)) && typeof (x as { event?: unknown }).event === 'string'
      && !String((x as { event: string }).event).startsWith('gtm.')))));
}

/** Every event uses only its allow-listed keys, each valid; momo_page may carry null for absent context. */
function expectAllowListed(list: Ev[], where: string): void {
  for (const e of list) {
    const name = String(e.event);
    expect(Object.keys(EVENTS), `${where}: known event ${name}`).toContain(name);
    for (const [k, v] of Object.entries(e)) {
      if (k === 'event') continue;
      expect(EVENTS[name], `${where}: ${name}.${k} allow-listed`).toContain(k);
      if (v === null && name === 'momo_page') continue;
      expect(typeof v, `${where}: ${name}.${k} is a string`).toBe('string');
      expect(String(v), `${where}: ${name}.${k} pattern`).toMatch(PATTERN[k]);
    }
  }
}

async function visit(page: Page, path: string): Promise<Ev[]> {
  const res = await page.goto(`${BASE}${path}`, { waitUntil: 'domcontentloaded' });
  expect(res?.status(), path).toBe(200);
  await expect(page.locator('script[data-momo-datalayer]'), `${path} loads datalayer.js`).toHaveCount(1);
  await page.waitForFunction(() => ((window as unknown as { dataLayer?: unknown[] }).dataLayer || []).some((x) => (x as { event?: string })?.event === 'momo_page'));
  const list = await events(page);
  expectAllowListed(list, path);
  return list;
}

test.describe('FR-ANL-001 page events', () => {
  test.beforeEach(async ({ page }) => blockGoogle(page));

  test('view_menu on the menu hub, a category and the branch-scoped menu', async ({ page }) => {
    let list = await visit(page, '/en/menu/');
    expect(list.find((e) => e.event === 'momo_page')).toMatchObject({ page_type: 'menu_hub', language: 'en', menu_category: 'hub' });
    expect(list.filter((e) => e.event === 'view_menu')).toEqual([{ event: 'view_menu', menu_category: 'hub', language: 'en' }]);

    const catHref = await page.locator('main a[href*="/en/menu/"]').evaluateAll((as) => (as as HTMLAnchorElement[])
      .map((a) => new URL(a.href).pathname).find((p) => /^\/en\/menu\/[a-z0-9-]+\/$/.test(p)) || '');
    expect(catHref, 'a category link on the hub').not.toBe('');
    const slug = catHref.split('/')[3];
    list = await visit(page, catHref);
    expect(list.find((e) => e.event === 'momo_page')).toMatchObject({ page_type: 'menu_category', menu_category: slug });
    expect(list.filter((e) => e.event === 'view_menu')).toEqual([{ event: 'view_menu', menu_category: slug, language: 'en' }]);

    list = await visit(page, '/menu/?branch=DKH');
    expect(list.filter((e) => e.event === 'view_menu')).toEqual([{ event: 'view_menu', menu_category: 'hub', branch_code: 'DKH', language: 'ja' }]);
  });

  test('view_dish on a dish page', async ({ page }) => {
    await visit(page, '/en/menu/');
    const dishHref = await page.locator('main a[href*="/en/menu/"]').evaluateAll((as) => (as as HTMLAnchorElement[])
      .map((a) => new URL(a.href).pathname).find((p) => /^\/en\/menu\/[a-z0-9-]+\/[a-z0-9-]+\/$/.test(p)) || '');
    let path = dishHref;
    if (!path) { // The hub may list categories only: open the first category and take a dish from there.
      const cat = await page.locator('main a[href*="/en/menu/"]').evaluateAll((as) => (as as HTMLAnchorElement[])
        .map((a) => new URL(a.href).pathname).find((p) => /^\/en\/menu\/[a-z0-9-]+\/$/.test(p)) || '');
      await visit(page, cat);
      path = await page.locator('main a[href*="/en/menu/"]').evaluateAll((as) => (as as HTMLAnchorElement[])
        .map((a) => new URL(a.href).pathname).find((p) => /^\/en\/menu\/[a-z0-9-]+\/[a-z0-9-]+\/$/.test(p)) || '');
    }
    expect(path, 'a dish link').not.toBe('');
    const list = await visit(page, path);
    const page_evt = list.find((e) => e.event === 'momo_page') as Ev;
    expect(page_evt).toMatchObject({ page_type: 'dish', language: 'en' });
    const vd = list.filter((e) => e.event === 'view_dish');
    expect(vd).toHaveLength(1);
    expect(vd[0]).toEqual({ event: 'view_dish', dish_sku: page_evt.dish_sku, menu_category: page_evt.menu_category, language: 'en' });
    expect(String(vd[0].dish_sku)).toMatch(/^D\d{4}$/);
  });

  test('view_halal_information on the halal page and view_branch on a branch page', async ({ page }) => {
    let list = await visit(page, '/ar/halal/');
    expect(list.filter((e) => e.event === 'view_halal_information')).toEqual([{ event: 'view_halal_information', language: 'ar', source_page: 'halal' }]);
    list = await visit(page, '/en/branches/demo-kitahama/');
    expect(list.filter((e) => e.event === 'view_branch')).toEqual([{ event: 'view_branch', branch_code: 'DKH', language: 'en' }]);
    expect(list.find((e) => e.event === 'momo_page')).toMatchObject({ page_type: 'branch', branch_code: 'DKH' });
  });

  test('pages without a view event push only momo_page', async ({ page }) => {
    for (const p of ['/en/', '/en/branches/']) {
      const list = await visit(page, p);
      expect(list.map((e) => e.event), p).toEqual(['momo_page']);
    }
  });
});

test.describe('FR-ANL-001 click events (delegated listener)', () => {
  test.beforeEach(async ({ page }) => blockGoogle(page));

  /** Click the first element carrying data-momo-event=$event (navigation prevented) and return the new events. */
  async function clickEvent(page: Page, selector: string): Promise<Ev[]> {
    return page.evaluate((sel) => {
      const w = window as unknown as { dataLayer: unknown[] };
      const el = document.querySelector(sel) as HTMLElement | null;
      if (!el) return [{ event: 'MISSING ' + sel }];
      document.addEventListener('click', (e) => e.preventDefault(), { once: true }); // Bubble phase: after datalayer.js.
      const before = w.dataLayer.length;
      el.click();
      return JSON.parse(JSON.stringify(w.dataLayer.slice(before)));
    }, selector);
  }

  test('select_language, click_reservation, click_phone, click_directions carry allow-listed parameters only', async ({ page }) => {
    await visit(page, '/en/');
    let added = await clickEvent(page, 'a[data-momo-event="select_language"][data-momo-to="ar"]');
    expectAllowListed(added, 'select_language');
    expect(added).toEqual([{ event: 'select_language', from_language: 'en', to_language: 'ar' }]);

    added = await clickEvent(page, 'header [data-momo-event="click_reservation"][data-momo-cta="header"]');
    expectAllowListed(added, 'click_reservation');
    expect(added).toEqual([{ event: 'click_reservation', cta_location: 'header', language: 'en' }]);

    added = await clickEvent(page, 'footer a[data-momo-event="click_phone"]');
    expectAllowListed(added, 'click_phone');
    expect(added).toHaveLength(1);
    expect(added[0]).toMatchObject({ event: 'click_phone', cta_location: 'footer', language: 'en' });
    expect(String(added[0].branch_code)).toMatch(/^[A-Z]{3}$/);

    await visit(page, '/en/branches/demo-kitahama/');
    added = await clickEvent(page, 'main [data-momo-event="click_directions"][data-momo-cta="branch_page"]');
    expectAllowListed(added, 'click_directions');
    expect(added).toEqual([{ event: 'click_directions', cta_location: 'branch_page', branch_code: 'DKH', language: 'en' }]);

    added = await clickEvent(page, 'main [data-momo-event="click_reservation"][data-momo-cta="branch_page"]');
    expect(added).toEqual([{ event: 'click_reservation', cta_location: 'branch_page', branch_code: 'DKH', language: 'en' }]);

    added = await clickEvent(page, 'main a[data-momo-event="click_phone"][data-momo-cta="branch_page"]');
    expect(added).toEqual([{ event: 'click_phone', cta_location: 'branch_page', branch_code: 'DKH', language: 'en' }]);
    const all = JSON.stringify(await events(page));
    expect(all, 'no phone number or address in the dataLayer').not.toMatch(/\d{2,4}-\d{2,4}-\d{3,4}|\+81|@/);
  });
});

test.describe('C10/C11 robots header (§9.3, IMPL-008)', () => {
  for (const path of ['/reservation/submitted/', '/en/reservation/submitted/', '/reservation/status/ABCD2345/', '/ar/reservation/status/ABCD2345/']) {
    test(`X-Robots-Tag is "noindex, nofollow" exactly once: ${path}`, async ({ request }) => {
      const res = await request.get(`${BASE}${path}`, { maxRedirects: 0 });
      expect(res.status(), path).toBe(200);
      const robots = res.headersArray().filter((h) => h.name.toLowerCase() === 'x-robots-tag').map((h) => h.value);
      expect(robots, `${path} X-Robots-Tag headers`).toEqual(['noindex, nofollow']);
      expect(res.headers()['referrer-policy']).toBe('no-referrer');
    });
  }
});
