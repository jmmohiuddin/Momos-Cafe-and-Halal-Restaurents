/**
 * Demo seed (wp momos seed --demo; DEC-047, GATE-02): the fictional demo content makes the six-language
 * site browsable. Prepare the database with tests/e2e/fixtures/seed.sh, then run with
 * MOMOS_E2E_BASE_URL (or MOMOS_URL) pointing at that install.
 */
import { test, expect } from '@playwright/test';

const BASE = process.env.MOMOS_E2E_BASE_URL || process.env.MOMOS_URL || 'http://127.0.0.1:8080';
const LANGS = ['ja', 'en', 'ar', 'ur', 'hi', 'bn'] as const;
const RTL = new Set(['ar', 'ur']);
const prefix = (lang: string) => (lang === 'ja' ? '' : `/${lang}`);

test.describe('demo seed content', () => {
  for (const lang of LANGS) {
    test(`home, branches, menu and dish render seeded content — ${lang}`, async ({ page }) => {
      const res = await page.goto(`${BASE}${prefix(lang)}/`);
      expect(res?.status()).toBe(200);
      await expect(page.locator('html')).toHaveAttribute('dir', RTL.has(lang) ? 'rtl' : 'ltr');
      // Featured offer, three public branches (TEST branch hidden), featured dishes.
      await expect(page.locator('main .momo-branch-card')).toHaveCount(3);
      expect(await page.locator('main .momo-dish-card').count()).toBeGreaterThanOrEqual(4);
      await expect(page.locator('main')).not.toContainText(/TST|test-branch/);
      // QA-006: the seed approves the neutral G2 templates, so the brand halal summary is never empty.
      await expect(page.locator('main .momo-halal')).toHaveCount(1);
      await expect(page.locator('main .momo-halal')).not.toHaveText(/^\s*$/);

      const branches = await page.goto(`${BASE}${prefix(lang)}/branches/`);
      expect(branches?.status()).toBe(200);
      await expect(page.locator('main .momo-branch-card')).toHaveCount(3);

      const dish = await page.goto(`${BASE}${prefix(lang)}/menu/curry/butter-chicken-curry/`);
      expect(dish?.status()).toBe(200);
      await expect(page.locator('h1')).toHaveCount(1);
      await expect(page.locator('h1')).not.toHaveText('');
      // Demo photo is labelled as an illustration (DEC-047) and the allergen list is named in the page language.
      expect(await page.locator('main figcaption, main .momo-illustration').count()).toBeGreaterThan(0);
      await expect(page.locator('main')).not.toContainText(/:\s*[،,]\s*[،,]/);
    });

    test(`FAQ and legal placeholder are published — ${lang}`, async ({ page }) => {
      const faq = await page.goto(`${BASE}${prefix(lang)}/faq/`);
      expect(faq?.status()).toBe(200);
      expect(await page.locator('main details, main [aria-expanded]').count()).toBeGreaterThanOrEqual(6);
      const legal = await page.goto(`${BASE}${prefix(lang)}/privacy-policy/`);
      expect(legal?.status()).toBe(200);
      await expect(page.locator('main')).toContainText('[PLACEHOLDER – requires legal review]');
    });
  }

  test('halal page shows no claim for any demo branch (GATE-02)', async ({ page }) => {
    await page.goto(`${BASE}/en/halal/`);
    const main = page.locator('main');
    await expect(main).toContainText('Demo Kitahama');
    // One "No statement" per demo branch (3) plus the legend entry; no certified/ingredients statement row.
    const text = await main.innerText();
    expect((text.match(/No statement/g) || []).length).toBeGreaterThanOrEqual(4);
    // QA-006: each public demo branch shows the approved neutral no_claim template; no claim template is approved.
    expect((text.match(/Please ask our staff at this branch about ingredients and preparation\./g) || []).length).toBeGreaterThanOrEqual(3);
    // Claim template sentences (Doc 11 §8.1) never appear; the explainer legend may still describe the terms.
    await expect(main).not.toContainText(/This branch is halal-certified by|Dishes marked on our menu are halal-certified|are covered by halal certification from|as recorded in our halal register/);
  });

  test('WordPress default content is unpublished by the seed (QA-030)', async ({ request }) => {
    expect((await request.get(`${BASE}/sample-page/`)).status()).toBe(404);
    expect((await request.get(`${BASE}/news/hello-world/`)).status()).toBe(404);
  });
});
