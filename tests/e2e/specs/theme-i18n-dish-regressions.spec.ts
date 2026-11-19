/**
 * Presentation regressions (wave 3b):
 *  - FR-I18N-013 / Doc 07 §4.13 row 4: values entered on the reservation form survive a language switch through
 *    the header switcher (en → ar); consent checkboxes are never restored.
 *  - FR-DIET-007: the dish detail page renders "Key ingredients (not a complete list)" from the dish's
 *    `momo_ingredient` terms (single-momo_dish.php → MenuView::dish), in the page language.
 * Base URL: MOMOS_E2E_BASE_URL (or MOMOS_URL), a seeded site (demo branch DKH, dish butter-chicken-curry).
 * The ingredient check reads the expected terms through bin/wp (needs MOMOS_DB_NAME), otherwise it only checks
 * that the section renders with chips.
 */
import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import * as path from 'node:path';

const BASE = (process.env.MOMOS_E2E_BASE_URL || process.env.MOMOS_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const ROOT = path.resolve(__dirname, '../../..');
const HAS_WP = !!process.env.MOMOS_DB_NAME;

function wpEval(php: string): string {
	return execFileSync(path.join(ROOT, 'bin/wp'), ['eval', php], { cwd: ROOT, env: { ...process.env, MOMOS_URL: process.env.MOMOS_URL || BASE }, encoding: 'utf8' }).trim();
}

test('FR-I18N-013: reservation entries are kept across a language switch (en → ar), consent is not', async ({ page }) => {
	await page.goto(`${BASE}/en/reservation/?branch=DKH`, { waitUntil: 'networkidle' });
	await expect(page.locator('#momo-res-form')).toBeVisible();
	const date = await page.$eval('#res-date', (s) => Array.from((s as HTMLSelectElement).options).filter((o) => o.value && !o.disabled)[1]?.value || '');
	expect(date, 'an open date exists').not.toBe('');
	await page.selectOption('#res-date', date);
	const chip = page.locator('[data-res-times] label').first();
	await expect(chip).toBeVisible();
	const time = (await chip.locator('input').getAttribute('value')) as string;
	await chip.click();
	await page.locator('label[for="res-party-3"]').click();
	await page.fill('#res-name', 'Aisha Rahman');
	await page.fill('#res-email', 'aisha.switch@example.test');
	await page.fill('#res-phone', '+60 12-345 6789');
	await page.fill('#res-note', 'Window seat if possible');
	await page.check('#res-consent');

	await page.locator('header .momo-lang summary').click();
	const ar = page.locator('header .momo-lang__list a[lang="ar"]');
	test.skip((await ar.count()) === 0, 'Arabic not enabled');
	await Promise.all([page.waitForURL(/\/ar\/reservation\//), ar.click()]);
	await page.waitForLoadState('networkidle');
	await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');

	await expect(page.locator('#res-branch')).toHaveValue('DKH');
	await expect(page.locator('#res-name')).toHaveValue('Aisha Rahman');
	await expect(page.locator('#res-email')).toHaveValue('aisha.switch@example.test');
	await expect(page.locator('#res-phone')).toHaveValue('+60 12-345 6789');
	await expect(page.locator('#res-note')).toHaveValue('Window seat if possible');
	await expect(page.locator('#res-date')).toHaveValue(date);
	await expect(page.locator('input[name="time"]:checked')).toHaveValue(time);
	await expect(page.locator('#res-party-3')).toBeChecked();
	// Consent is never carried over (it must be given again on the page in the new language).
	await expect(page.locator('#res-consent')).not.toBeChecked();
	const special = page.locator('#res-special-consent');
	if (await special.count()) await expect(special).not.toBeChecked();
});

for (const lang of ['ja', 'en'] as const) {
	test(`FR-DIET-007: dish detail shows the key-ingredients block from the ingredient taxonomy — ${lang}`, async ({ page }) => {
		const prefix = lang === 'ja' ? '' : `/${lang}`;
		await page.goto(`${BASE}${prefix}/menu/curry/butter-chicken-curry/`);
		const sec = page.locator('section[aria-labelledby="dish-ingredients"]');
		await expect(sec).toBeVisible();
		await expect(page.locator('#dish-ingredients')).toHaveText(lang === 'en' ? 'Key ingredients (not a complete list)' : /\S/);
		const shown = (await sec.locator('li.momo-chip').allInnerTexts()).map((t) => t.trim());
		expect(shown.length).toBeGreaterThan(0);
		if (HAS_WP) {
			const expected: string[] = JSON.parse(wpEval(`$p = get_page_by_path('butter-chicken-curry', OBJECT, 'momo_dish'); $o = []; foreach ( wp_get_object_terms( $p->ID, 'momo_ingredient' ) as $t ) { $n = \\Momos\\Kernel\\I18n\\Translations::term( $t, 'name', '${lang}' ); if ( '' !== $n ) { $o[] = $n; } } echo wp_json_encode( $o );`));
			expect(expected.length, 'the demo dish has ingredient terms').toBeGreaterThan(0);
			expect(shown).toEqual(expected);
		}
	});
}
