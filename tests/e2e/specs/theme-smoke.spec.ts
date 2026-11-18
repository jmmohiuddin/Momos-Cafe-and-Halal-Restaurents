/**
 * Customer screen smoke test (screens.md §9 rows 1/3/5/10; TASK 2): SCR-C01…C08, C12–C18, C21, C22 and the
 * C09–C11 shells in ja, en and ar — HTTP 200, exactly one <h1>, <html lang/dir>, no console errors, no
 * serious/critical axe violations (WCAG 2.0/2.1/2.2 A+AA tags), no horizontal page scroll at 390 px.
 * Branch, category and dish URLs are discovered from the directory / menu pages, so the spec runs on any
 * install; optional (S/C) pages and untranslated pages may legitimately be 404 and are then skipped.
 * Base URL: MOMOS_URL (default http://127.0.0.1:8080).
 */
import { test, expect, Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const BASE = process.env.MOMOS_URL || 'http://127.0.0.1:8080';
const LANGS = [['ja', 'ltr'], ['en', 'ltr'], ['ar', 'rtl']] as const;
const prefix = (lang: string) => (lang === 'ja' ? '' : `/${lang}`);

/** Pages that must exist in every enabled language (string-driven system pages, M0007). */
const REQUIRED = ['/', '/halal/', '/branches/', '/menu/', '/offers/', '/reservation/', '/reservation/submitted/'];
/** Pages that exist only when published/translated (S/C pages, editorial pages, legal drafts). */
const OPTIONAL = ['/about/', '/contact/', '/faq/', '/news/', '/gallery/', '/privacy-policy/', '/terms/', '/reservation-policy/', '/external-transmission/', '/accessibility/'];

/** Browser noise that is not a page error (report-only CSP of the security module). */
const IGNORE = [/report-only policy/i, /Content Security Policy/i];

async function discover(page: Page, lang: string): Promise<string[]> {
  const found: string[] = [];
  const pick = async (url: string, sel: string) => {
    const res = await page.goto(url);
    if (!res || res.status() !== 200) return null;
    const href = await page.locator(sel).first().getAttribute('href').catch(() => null);
    return href ? new URL(href, BASE).pathname + new URL(href, BASE).search : null;
  };
  const branch = await pick(`${BASE}${prefix(lang)}/branches/`, 'main .momo-branch-card .momo-btn--text');
  if (branch) found.push(branch.replace(prefix(lang), '') || '/');
  const cat = await pick(`${BASE}${prefix(lang)}/menu/`, 'main a.momo-tile');
  if (cat) {
    found.push(cat.replace(prefix(lang), ''));
    const dish = await pick(`${BASE}${cat}`, 'main .momo-dish-card .momo-card__link');
    if (dish) found.push(dish.replace(prefix(lang), ''));
  }
  return found;
}

async function check(page: Page, url: string, lang: string, dir: string, required: boolean) {
  const errors: string[] = [];
  page.on('console', (m) => { if (m.type() === 'error' && !IGNORE.some((r) => r.test(m.text()))) errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push(String(e)));
  const res = await page.goto(url, { waitUntil: 'networkidle' });
  const status = res?.status() ?? 0;
  if (!required && status === 404) return false;
  expect(status, url).toBe(200);
  await expect(page.locator('h1'), url).toHaveCount(1);
  await expect(page.locator('html')).toHaveAttribute('lang', lang);
  await expect(page.locator('html')).toHaveAttribute('dir', dir);
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  expect(overflow, `${url} horizontal scroll`).toBeLessThanOrEqual(0);
  const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
  const bad = axe.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
  expect(bad.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).slice(0, 3).join(' | ')}`), url).toEqual([]);
  expect(errors, `${url} console errors`).toEqual([]);
  return true;
}

test.describe('customer screens smoke', () => {
  test.use({ viewport: { width: 390, height: 844 } });
  for (const [lang, dir] of LANGS) {
    test(`required and optional screens — ${lang}`, async ({ page, browser }) => {
      test.setTimeout(240_000);
      const extra = await discover(page, lang);
      let checked = 0;
      for (const path of [...REQUIRED, ...extra]) {
        const ctx = await browser.newContext({ viewport: { width: 390, height: 844 } });
        if (await check(await ctx.newPage(), `${BASE}${prefix(lang)}${path}`, lang, dir, true)) checked++;
        await ctx.close();
      }
      for (const path of OPTIONAL) {
        const ctx = await browser.newContext({ viewport: { width: 390, height: 844 } });
        if (await check(await ctx.newPage(), `${BASE}${prefix(lang)}${path}`, lang, dir, false)) checked++;
        await ctx.close();
      }
      expect(checked).toBeGreaterThanOrEqual(REQUIRED.length);
    });
  }
});

test.describe('screen rules', () => {
  test('C10/C11: noindex + X-Robots-Tag + no-referrer, C11 no-store, no analytics data', async ({ page }) => {
    for (const path of ['/reservation/submitted/', '/en/reservation/status/ABCD1234/']) {
      const res = await page.goto(`${BASE}${path}`);
      expect(res?.status()).toBe(200);
      const h = res!.headers();
      expect(h['x-robots-tag']).toContain('noindex');
      expect(h['referrer-policy']).toBe('no-referrer');
      if (path.includes('/status/')) expect(h['cache-control']).toContain('no-store');
      await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/);
      await expect(page.locator('#momo-page-data')).toHaveCount(0);
      await expect(page.locator('.momo-bar')).toHaveCount(0);
    }
  });

  test('query variants are noindex; home carries the momo_page data hook', async ({ page }) => {
    await page.goto(`${BASE}/menu/?branch=XYZ`);
    await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/);
    await page.goto(`${BASE}/en/`);
    const data = JSON.parse((await page.locator('#momo-page-data').textContent()) || '{}');
    expect(data.page.page_type).toBe('home');
    expect(data.page.language).toBe('en');
  });

  test('no ordering UI anywhere on home / menu (DEC-042)', async ({ page }) => {
    for (const path of ['/', '/en/', '/menu/']) {
      await page.goto(`${BASE}${path}`);
      const text = (await page.locator('main').innerText()).toLowerCase();
      for (const term of ['add to cart', 'order now', 'checkout', 'カートに追加', '今すぐ注文']) expect(text).not.toContain(term);
    }
  });

  test('contact handler: missing nonce → error redirect, nothing sent', async ({ request }) => {
    const res = await request.post(`${BASE}/wp-admin/admin-post.php`, {
      form: { action: 'momos_contact', lang: 'en', name: 'Test', email: 'test@example.com', topic: 'general', message: 'Hello', consent: '1' },
      maxRedirects: 0,
    });
    expect(res.status()).toBe(303);
    expect(res.headers()['location']).toContain('contact_error=expired');
  });
});
