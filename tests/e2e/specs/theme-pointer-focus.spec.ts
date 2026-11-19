/**
 * Regression (launch blocker, wave 3): mouse clicks on reservation time/party chips were lost because the
 * "focus not obscured" handler in frame.js (WCAG 2.4.11) scrolled the page on pointer-initiated focus
 * (<main tabindex=-1> or the chip's radio got focus on mousedown), moving the target before mouseup.
 * The handler now scrolls only for keyboard/scripted focus. Covers C09 in en (LTR) and ar (RTL) at 390 and
 * 1280 px, and that keyboard focus is still kept clear of the sticky header (TC-A11Y-004).
 * Base URL: MOMOS_E2E_BASE_URL (or MOMOS_URL), a seeded site with demo branch DKH.
 */
import { test, expect } from '@playwright/test';

const BASE = (process.env.MOMOS_E2E_BASE_URL || process.env.MOMOS_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');

for (const width of [390, 1280]) {
	for (const lang of ['en', 'ar'] as const) {
		test(`C09 ${lang} @${width}px: mouse click selects a time chip and a party chip`, async ({ page }) => {
			await page.setViewportSize({ width, height: width < 600 ? 844 : 900 });
			await page.goto(`${BASE}/${lang}/reservation/?branch=DKH`, { waitUntil: 'networkidle' });
			await expect(page.locator('#momo-res-form')).toBeVisible();
			const date = await page.$eval('#res-date', (s) => Array.from((s as HTMLSelectElement).options).find((o) => o.value && !o.disabled)?.value || '');
			expect(date, 'an open date exists').not.toBe('');
			await page.selectOption('#res-date', date);
			const chips = page.locator('[data-res-times] label');
			await expect(chips.first()).toBeVisible();
			// Pick a chip further down the list so that the page is scrolled when it is clicked.
			const n = await chips.count();
			const chip = chips.nth(Math.min(n - 1, 3));
			const value = (await chip.locator('input').getAttribute('value')) as string;
			await chip.scrollIntoViewIfNeeded();
			const box = (await chip.boundingBox())!;
			await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2);
			await expect(chip.locator('input')).toBeChecked();
			await expect(page.locator('input[name="time"]:checked')).toHaveValue(value);
			await expect(page.locator('.momo-res-summary')).toContainText(value);

			const party = page.locator('label[for="res-party-2"]');
			await party.scrollIntoViewIfNeeded();
			const pb = (await party.boundingBox())!;
			await page.mouse.click(pb.x + pb.width / 2, pb.y + pb.height / 2);
			await expect(page.locator('#res-party-2')).toBeChecked();
			// The time choice survives the second click (no re-render or lost selection).
			await expect(page.locator('input[name="time"]:checked')).toHaveValue(value);
		});
	}
}

test('a mouse press inside <main> does not scroll the page (pointer focus on the skip target)', async ({ page }) => {
	await page.setViewportSize({ width: 390, height: 844 });
	await page.goto(`${BASE}/en/reservation/?branch=DKH`, { waitUntil: 'networkidle' });
	await page.evaluate(() => window.scrollTo(0, 300));
	const y0 = await page.evaluate(() => window.scrollY);
	// A point on non-interactive content inside <main>: the press focuses <main tabindex=-1> itself.
	const hit = await page.evaluate(() => {
		const el = document.elementFromPoint(6, 600) as HTMLElement | null;
		return !!el && !!el.closest('main') && !el.closest('a,button,input,select,textarea,label,summary,[tabindex="0"]');
	});
	expect(hit, 'the probe point is plain content inside <main>').toBe(true);
	await page.mouse.click(6, 600);
	expect(await page.evaluate(() => document.activeElement && document.activeElement.id)).toBe('main');
	expect(await page.evaluate(() => window.scrollY)).toBe(y0);
});

test('keyboard focus is still kept clear of the sticky header (WCAG 2.4.11)', async ({ page }) => {
	await page.setViewportSize({ width: 1280, height: 900 });
	await page.goto(`${BASE}/en/reservation/?branch=DKH`, { waitUntil: 'networkidle' });
	// Put #res-name under the sticky header, then focus it from the keyboard (Shift+Tab from the next field).
	await page.locator('#res-email').focus();
	await page.evaluate(() => {
		const r = (document.getElementById('res-name') as HTMLElement).getBoundingClientRect();
		window.scrollBy(0, r.top - 10);
	});
	await page.keyboard.press('Shift+Tab');
	await expect(page.locator('#res-name')).toBeFocused();
	const headerBottom = await page.evaluate(() => (document.querySelector('[data-momo-header]') as HTMLElement).getBoundingClientRect().bottom);
	const top = await page.evaluate(() => (document.getElementById('res-name') as HTMLElement).getBoundingClientRect().top);
	expect(top).toBeGreaterThanOrEqual(headerBottom);
});
