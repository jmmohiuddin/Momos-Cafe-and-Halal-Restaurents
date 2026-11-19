/**
 * SEO head & analytics loader (i18n-seo-analytics.md §9, §12; AC-SEO-01-01/02/06, AC-ANL-01-02/03, AC-ANL-02-01,
 * TC-SEO-002/003/005, TC-ANL-004/007, TC-PRV-016, DEC-066).
 *  - ja/en/ar: <title>, description, self canonical in the page language, hreflang incl. self + x-default → en,
 *    og:locale, one valid JSON-LD block; query variants noindex with a clean canonical.
 *  - C10/C11: noindex (+ X-Robots-Tag), no GTM / datalayer script and zero requests to Google tag domains.
 *  - Opt-out cookie: no GTM request; dataLayer only carries allow-listed events/params.
 * Base URL: MOMOS_E2E_BASE_URL (or MOMOS_URL, default http://127.0.0.1:8080). Google requests are intercepted
 * and aborted, so the spec never contacts Google.
 */
import { test, expect, Page } from '@playwright/test';

const BASE = (process.env.MOMOS_E2E_BASE_URL || process.env.MOMOS_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const prefix = (lang: string) => (lang === 'ja' ? '' : `/${lang}`);
const OG_LOCALE: Record<string, string> = { ja: 'ja_JP', en: 'en_US', ar: 'ar_AR' };
const GOOGLE = /googletagmanager\.com|google-analytics\.com|analytics\.google\.com/;

async function trackGoogle(page: Page): Promise<string[]> {
  const hits: string[] = [];
  await page.route(GOOGLE, (route) => { hits.push(route.request().url()); return route.abort(); });
  return hits;
}

async function head(page: Page) {
  return page.evaluate(() => {
    const attr = (sel: string, a: string) => document.querySelector(sel)?.getAttribute(a) ?? null;
    return {
      title: document.title,
      description: attr('meta[name="description"]', 'content'),
      robots: attr('meta[name="robots"]', 'content'),
      canonical: attr('link[rel="canonical"]', 'href'),
      hreflang: Array.from(document.querySelectorAll('link[rel="alternate"][hreflang]')).map((l) => [l.getAttribute('hreflang'), l.getAttribute('href')]),
      ogLocale: attr('meta[property="og:locale"]', 'content'),
      ogTitle: attr('meta[property="og:title"]', 'content'),
      ldjson: Array.from(document.querySelectorAll('script[type="application/ld+json"]')).map((s) => s.textContent || ''),
      gtmScripts: Array.from(document.scripts).filter((s) => /googletagmanager/.test(s.src) || s.hasAttribute('data-momo-datalayer')).length,
      noscriptIframes: document.querySelectorAll('noscript iframe').length,
    };
  });
}

for (const lang of ['ja', 'en', 'ar']) {
  test(`head tags in ${lang}: title, canonical, hreflang, OG, JSON-LD`, async ({ page }) => {
    await trackGoogle(page);
    for (const path of ['/', '/halal/', '/menu/']) {
      const url = `${BASE}${prefix(lang)}${path}`;
      const res = await page.goto(url);
      expect(res?.status(), url).toBe(200);
      await expect(page.locator('html')).toHaveAttribute('lang', lang);
      const h = await head(page);
      expect(h.title.trim().length, `${url} title`).toBeGreaterThan(0);
      expect(h.canonical, `${url} canonical`).toBe(url);
      expect(h.robots ?? '', `${url} indexable`).not.toContain('noindex');
      const codes = h.hreflang.map(([c]) => c);
      expect(codes, `${url} self hreflang`).toContain(lang);
      const self = h.hreflang.find(([c]) => c === lang);
      expect(self?.[1]).toBe(url);
      if (codes.includes('en')) {
        const xd = h.hreflang.find(([c]) => c === 'x-default');
        expect(xd?.[1], 'x-default → en').toBe(`${BASE}/en${path}`);
      }
      for (const [, href] of h.hreflang) expect(href).not.toContain('?');
      expect(h.ogLocale).toBe(OG_LOCALE[lang]);
      expect(h.ogTitle).toBe(h.title);
      expect(h.ldjson.length, `${url} one JSON-LD block`).toBe(1);
      const graph = JSON.parse(h.ldjson[0]);
      expect(graph['@context']).toBe('https://schema.org');
      expect(Array.isArray(graph['@graph'])).toBe(true);
      const json = h.ldjson[0];
      expect(json).not.toMatch(/aggregateRating|"review"/);
      if (path === '/') expect(graph['@graph'].map((n: { '@type': string }) => n['@type'])).toEqual(['Organization', 'WebSite']);
      if (path === '/menu/') expect(graph['@graph'][0]['@type']).toBe('Menu');
      if (path === '/halal/') {
        const bc = graph['@graph'].find((n: { '@type': string }) => n['@type'] === 'BreadcrumbList');
        expect(bc.itemListElement[0].item).toBe(`${BASE}${prefix(lang)}/`);
      }
    }
  });
}

test('query variants are noindex,follow with a clean canonical (AC-SEO-01-02)', async ({ page }) => {
  await trackGoogle(page);
  await page.goto(`${BASE}/en/menu/?branch=ZZZ`);
  const h = await head(page);
  expect(h.robots).toContain('noindex');
  expect(h.robots).toContain('follow');
  expect(h.canonical).toBe(`${BASE}/en/menu/`);
  expect(h.hreflang).toEqual([]);
});

for (const path of ['/reservation/submitted/', '/en/reservation/submitted/', '/ar/reservation/submitted/', '/reservation/status/ABCD2345/', '/en/reservation/status/ABCD2345/']) {
  test(`no GTM and noindex on C10/C11: ${path}`, async ({ page }) => {
    const google = await trackGoogle(page);
    const res = await page.goto(`${BASE}${path}`, { waitUntil: 'networkidle' });
    expect(res?.status()).toBe(200);
    expect((res?.headers()['x-robots-tag'] || '')).toContain('noindex');
    expect(res?.headers()['referrer-policy']).toBe('no-referrer');
    const h = await head(page);
    expect(h.robots).toContain('noindex');
    expect(h.canonical).toBeNull();
    expect(h.gtmScripts, 'no GTM / datalayer script').toBe(0);
    expect(h.noscriptIframes).toBe(0);
    expect(await page.locator('#momo-page-data').count()).toBe(0);
    expect(google, 'no request to Google tag domains').toEqual([]);
  });
}

test('dataLayer carries only allow-listed events; GTM follows the opt-out cookie', async ({ page, context }) => {
  const google = await trackGoogle(page);
  await page.goto(`${BASE}/en/`, { waitUntil: 'networkidle' });
  const gtm = await page.locator('script[data-momo-datalayer]').getAttribute('data-gtm');
  const layer = await page.evaluate(() => JSON.parse(JSON.stringify((window as unknown as { dataLayer: unknown[] }).dataLayer.filter((e) => !Array.isArray(e) && typeof e === 'object' && e !== null && !('0' in (e as object))))));
  const page_evt = layer.find((e: Record<string, unknown>) => e.event === 'momo_page');
  expect(page_evt).toMatchObject({ event: 'momo_page', page_type: 'home', language: 'en' });
  expect(layer.some((e: Record<string, unknown>) => Array.isArray(e['gtm.blocklist']))).toBe(true);
  // Allow-list: unknown events and invalid/PII values are dropped.
  const pushed = await page.evaluate(() => {
    const w = window as unknown as { momoPush: (e: string, p: Record<string, unknown>) => boolean; dataLayer: Record<string, unknown>[] };
    const before = w.dataLayer.length;
    const unknown = w.momoPush('purchase', { email: 'a@b.c' });
    w.momoPush('click_phone', { branch_code: 'SHN', cta_location: 'header', email: 'a@b.c', name: 'Aiko' });
    w.momoPush('click_reservation', { branch_code: 'shn-bad', cta_location: 'nowhere' });
    return { unknown, added: w.dataLayer.slice(before) };
  });
  expect(pushed.unknown).toBe(false);
  expect(pushed.added[0]).toEqual({ event: 'click_phone', branch_code: 'SHN', cta_location: 'header', language: 'en' });
  expect(pushed.added[1]).toEqual({ event: 'click_reservation', language: 'en' });
  if (gtm) expect(google.length, 'GTM requested when configured and not opted out').toBeGreaterThan(0);

  // Opt-out cookie → no Google request on the next pages (AC-ANL-02-01).
  await context.addCookies([{ name: 'momo_analytics_optout', value: '1', url: BASE }]);
  google.length = 0;
  for (const p of ['/en/', '/en/halal/', '/en/menu/']) await page.goto(`${BASE}${p}`, { waitUntil: 'networkidle' });
  expect(google).toEqual([]);
});
