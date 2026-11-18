/**
 * Theme frame and design system (ui-design-system.md §8, §15): TC-I18N-001/002/003/004, TC-NAV-001/002,
 * TC-CORP-006 (404), TC-A11Y-001/004, TC-CMP-005. Base URL: MOMOS_URL (default http://127.0.0.1:8080).
 * Works on an install without seed data (uses the 404 page and system pages created by M0007).
 */
import { test, expect, Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const BASE = process.env.MOMOS_URL || 'http://127.0.0.1:8080';
const prefix = (lang: string) => (lang === 'ja' ? '' : `/${lang}`);

async function noHorizontalOverflow(page: Page) {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  expect(overflow).toBeLessThanOrEqual(0);
}

test.describe('frame: language and direction', () => {
  for (const [lang, dir] of [['ja', 'ltr'], ['en', 'ltr'], ['ar', 'rtl'], ['ur', 'rtl']] as const) {
    test(`<html lang dir> and body classes — ${lang}`, async ({ page }) => {
      await page.goto(`${BASE}${prefix(lang)}/this-page-does-not-exist/`);
      await expect(page.locator('html')).toHaveAttribute('lang', lang);
      await expect(page.locator('html')).toHaveAttribute('dir', dir);
      await expect(page.locator('body')).toHaveClass(new RegExp(`momo-lang-${lang}.*momo-dir-${dir}|momo-dir-${dir}.*momo-lang-${lang}`));
    });
  }
});

test.describe('SCR-C19 404', () => {
  for (const lang of ['ja', 'en', 'ar']) {
    test(`404 localised, noindex, four link cards — ${lang}`, async ({ page }) => {
      const res = await page.goto(`${BASE}${prefix(lang)}/no-such-url-xyz/`);
      expect(res?.status()).toBe(404);
      await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/);
      await expect(page.locator('h1')).toHaveCount(1);
      const cards = page.locator('main .momo-card__link');
      await expect(cards).toHaveCount(4);
      for (const href of await cards.evaluateAll((as) => as.map((a) => (a as HTMLAnchorElement).pathname))) {
        if (lang !== 'ja') expect(href.startsWith(`/${lang}/`)).toBeTruthy();
      }
      const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
      expect(axe.violations.filter((v) => ['critical', 'serious'].includes(v.impact || ''))).toEqual([]);
    });
  }
});

test.describe('skip link, header, switcher (desktop)', () => {
  test.use({ viewport: { width: 1280, height: 900 } });

  test('skip link is the first focusable element and targets main', async ({ page }) => {
    await page.goto(`${BASE}/no-such-url-xyz/`);
    await page.keyboard.press('Tab');
    const focused = page.locator(':focus');
    await expect(focused).toHaveClass(/momo-skip/);
    await expect(focused).toHaveAttribute('href', '#main');
    await expect(page.locator('main#main')).toHaveAttribute('tabindex', '-1');
  });

  test('header nav order and current item', async ({ page }) => {
    await page.goto(`${BASE}/en/no-such-url-xyz/`);
    const labels = await page.locator('.momo-navpill > li > a, .momo-navpill > li > details > summary').allInnerTexts();
    const clean = labels.map((l) => l.trim());
    expect(clean[0]).toBe('Menu');
    expect(clean[1]).toBe('Branches');
    expect(clean).toContain('Halal');
    expect(clean[clean.length - 1]).toBe('Reservation');
    // Offers, when present, sits directly after Branches (DEC-060).
    const i = clean.indexOf('Offers');
    if (i !== -1) expect(i).toBe(2);
    await expect(page.locator('.momo-header__cta')).toBeVisible();
    await expect(page.locator('.momo-bar')).toBeHidden();
  });

  test('switcher: native names, lang/hreflang/dir, aria-current, Esc closes and returns focus', async ({ page }) => {
    await page.goto(`${BASE}/no-such-url-xyz/`);
    const summary = page.locator('header .momo-lang summary');
    await summary.click();
    const opts = page.locator('header .momo-lang__list a');
    expect(await opts.count()).toBeGreaterThanOrEqual(2);
    await expect(opts.first()).toHaveAttribute('aria-current', 'true');
    await expect(opts.first()).toHaveAttribute('lang', 'ja');
    for (const a of await opts.all()) {
      const code = await a.getAttribute('lang');
      await expect(a).toHaveAttribute('hreflang', code!);
      await expect(a).toHaveAttribute('dir', /^(ltr|rtl)$/);
    }
    await page.keyboard.press('Escape');
    await expect(page.locator('header .momo-lang')).not.toHaveAttribute('open', '');
    await expect(summary).toBeFocused();
  });

  test('choosing a language sets momo_lang_choice and keeps the 404 → target home rule', async ({ page, context }) => {
    await page.goto(`${BASE}/no-such-url-xyz/`);
    await page.locator('header .momo-lang summary').click();
    const en = page.locator('header .momo-lang__list a[lang="en"]');
    test.skip((await en.count()) === 0, 'English not enabled');
    expect(new URL((await en.getAttribute('href'))!).pathname).toBe('/en/');
    await en.click();
    await page.waitForURL(/\/en\/$/);
    const cookie = (await context.cookies()).find((c) => c.name === 'momo_lang_choice');
    expect(cookie?.value).toBe('en');
  });

  test('fonts: each language loads only its own families from this origin (DEC-061)', async ({ page }) => {
    const fonts: string[] = [];
    page.on('request', (r) => { if (r.resourceType() === 'font') fonts.push(r.url()); });
    await page.goto(`${BASE}/ar/no-such-url-xyz/`, { waitUntil: 'networkidle' });
    expect(fonts.length).toBeGreaterThan(0);
    for (const f of fonts) {
      expect(f.startsWith(BASE)).toBeTruthy();
      expect(f).toMatch(/noto-naskh-arabic|plus-jakarta-sans/);
    }
    fonts.length = 0;
    await page.goto(`${BASE}/no-such-url-xyz/`, { waitUntil: 'networkidle' });
    for (const f of fonts) expect(f).toMatch(/noto-sans-jp/); // Epilogue never on non-Latin pages
  });
});

test.describe('mobile frame (390 px)', () => {
  test.use({ viewport: { width: 390, height: 844 } });

  for (const lang of ['ja', 'ar']) {
    test(`menu dialog: opens, Esc closes, focus returns; action bar Request · Branches — ${lang}`, async ({ page }) => {
      await page.goto(`${BASE}${prefix(lang)}/no-such-url-xyz/`);
      await noHorizontalOverflow(page);
      const bar = page.locator('nav.momo-bar a');
      await expect(bar).toHaveCount(2);
      await expect(bar.first()).toHaveAttribute('data-momo-event', 'click_reservation');
      const btn = page.locator('[data-momo-menu-open]');
      await btn.click();
      const dlg = page.locator('dialog.momo-mmenu');
      await expect(dlg).toBeVisible();
      await expect(btn).toHaveAttribute('aria-expanded', 'true');
      await expect(dlg.locator('.momo-langgrid a')).not.toHaveCount(0);
      await page.keyboard.press('Escape');
      await expect(dlg).toBeHidden();
      await expect(btn).toBeFocused();
      await expect(btn).toHaveAttribute('aria-expanded', 'false');
    });
  }

  test('action bar hides while a text field has focus', async ({ page }) => {
    await page.goto(`${BASE}/no-such-url-xyz/`);
    await page.evaluate(() => {
      const i = document.createElement('input');
      i.type = 'text'; i.id = 'probe'; document.querySelector('main')!.appendChild(i);
    });
    await page.focus('#probe');
    await expect(page.locator('nav.momo-bar')).toBeHidden();
  });
});

test.describe('language suggestion bar (FR-I18N-003)', () => {
  test.use({ locale: 'en-US' });

  test('suggests English on a Japanese page without redirecting; dismissal is remembered', async ({ page, context }) => {
    const res = await page.goto(`${BASE}/`);
    expect(res?.status()).toBe(200);
    expect(new URL(page.url()).pathname).toBe('/');
    const bar = page.locator('[data-momo-langbar]');
    test.skip((await bar.count()) === 0, 'no translated alternate for this page');
    await expect(bar).toBeVisible();
    await expect(bar).toHaveAttribute('lang', 'en');
    await bar.locator('[data-momo-langbar-dismiss]').click();
    await expect(bar).toBeHidden();
    const cookie = (await context.cookies()).find((c) => c.name === 'momo_lang_choice');
    expect(cookie?.value).toBe('ja');
    await page.reload();
    await expect(bar).toBeHidden();
  });
});
