/**
 * QA (a11y-i18n team) — automated WCAG 2.2 A/AA scan (TC-A11Y-001, NFR-ACC-01, qa.md §6.1, DEC-036).
 * Every customer template in ja, en and ar at 390 and 1280 px, plus the states qa.md §6.1 names:
 * reservation form empty / with errors / submitted / status, language switcher open, mobile menu open, 404,
 * /offers/ and /gallery/, and the main admin screens (reservation inbox + detail, branches, menu, settings).
 * Pass = 0 critical + 0 serious violations with the tags wcag2a, wcag2aa, wcag21a, wcag21aa, wcag22aa.
 * Base URL: MOMOS_E2E_BASE_URL (seeded site, tests/e2e/fixtures/seed.sh).
 */
import { test, expect, Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const BASE = process.env.MOMOS_E2E_BASE_URL || process.env.MOMOS_URL || 'http://127.0.0.1:8080';
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const LANGS = ['ja', 'en', 'ar'] as const;
const prefix = (l: string) => (l === 'ja' ? '' : `/${l}`);
const PW = 'Momos-Dev-2026!';

/** Customer templates (screens.md §2). Optional pages that 404 in a language are skipped (untranslated). */
const PAGES = [
	'/', '/about/', '/halal/', '/branches/', '/branches/demo-namba-minami/', '/branches/demo-kitahama/',
	'/menu/', '/menu/curry/', '/menu/curry/butter-chicken-curry/', '/reservation/', '/reservation/submitted/',
	'/reservation/status/ABCD2345/', '/contact/', '/faq/', '/offers/', '/gallery/', '/news/',
	'/privacy-policy/', '/terms/', '/reservation-policy/', '/external-transmission/', '/accessibility/',
	'/no-such-page-qa-a11y/',
];

async function axe(page: Page, label: string): Promise<string[]> {
	const r = await new AxeBuilder({ page }).withTags(TAGS).analyze();
	return r.violations
		.filter((v) => v.impact === 'serious' || v.impact === 'critical')
		.map((v) => `${label} [${v.impact}] ${v.id}: ${v.nodes.slice(0, 3).map((n) => n.target.join(' ')).join(' | ')}`);
}

test.describe('axe on every customer template — ja/en/ar × 390/1280 (TC-A11Y-001)', () => {
	for (const [w, h] of [[390, 844], [1280, 900]] as const) {
		for (const lang of LANGS) {
			test(`0 serious/critical — ${lang} @${w}`, async ({ browser }) => {
				test.setTimeout(600_000);
				const ctx = await browser.newContext({ viewport: { width: w, height: h } });
				const page = await ctx.newPage();
				const bad: string[] = [];
				let scanned = 0;
				for (const p of PAGES) {
					const res = await page.goto(`${BASE}${prefix(lang)}${p}`, { waitUntil: 'networkidle' });
					const st = res?.status() ?? 0;
					if (st === 404 && !p.startsWith('/no-such')) continue; // untranslated optional page
					scanned++;
					bad.push(...(await axe(page, `${lang}${p}@${w}`)));
				}
				await ctx.close();
				expect(scanned).toBeGreaterThanOrEqual(15);
				expect(bad).toEqual([]);
			});
		}
	}
});

test.describe('axe on interactive states (qa.md §6.1)', () => {
	for (const lang of LANGS) {
		test(`reservation form with errors (empty submit) — ${lang}`, async ({ page }) => {
			await page.setViewportSize({ width: 390, height: 844 });
			await page.goto(`${BASE}${prefix(lang)}/reservation/`, { waitUntil: 'networkidle' });
			await page.locator('[data-res-submit]').click();
			await expect(page.locator('#res-errsum')).toBeVisible();
			expect(await axe(page, `${lang} form-errors`)).toEqual([]);
		});

		test(`language switcher open (1280) and mobile menu open (390) — ${lang}`, async ({ page }) => {
			await page.setViewportSize({ width: 1280, height: 900 });
			await page.goto(`${BASE}${prefix(lang)}/menu/`, { waitUntil: 'networkidle' });
			const sw = page.locator('header summary.momo-lang__btn');
			await sw.click();
			const bad = await axe(page, `${lang} switcher-open`);
			await page.keyboard.press('Escape');
			await page.setViewportSize({ width: 390, height: 844 });
			await page.goto(`${BASE}${prefix(lang)}/menu/`, { waitUntil: 'networkidle' });
			await page.locator('header [data-momo-menu-open]').click();
			await page.waitForTimeout(400);
			bad.push(...(await axe(page, `${lang} mobile-menu-open`)));
			expect(bad).toEqual([]);
		});
	}
});

async function login(page: Page, user: string) {
	await page.goto(`${BASE}/wp-login.php`);
	await page.fill('#user_login', user);
	await page.fill('#user_pass', PW);
	await page.click('#wp-submit');
	await page.waitForLoadState('networkidle');
}

test.describe('axe on main admin screens (qa.md §6.1: reservation inbox and detail)', () => {
	test('head office: inbox, detail, branches, menu, strings, settings', async ({ page }) => {
		test.setTimeout(300_000);
		await page.setViewportSize({ width: 1280, height: 900 });
		await login(page, 'demo_ho');
		const bad: string[] = [];
		await page.goto(`${BASE}/wp-admin/admin.php?page=momos-reservations`, { waitUntil: 'networkidle' });
		bad.push(...(await axe(page, 'inbox')));
		const detail = page.locator('#wpbody a[href*="page=momos-reservations"][href*="id="], #wpbody a[href*="reservation="]').first();
		if (await detail.count()) {
			await detail.click();
			await page.waitForLoadState('networkidle');
			bad.push(...(await axe(page, `detail ${new URL(page.url()).search}`)));
		} else bad.push('inbox: no link to a reservation detail found');
		const links = await page.locator('#adminmenu a[href*="page=momos"], #adminmenu a[href*="post_type=momo_"]').evaluateAll((as) =>
			Array.from(new Set(as.map((a) => (a as HTMLAnchorElement).href))));
		// Momo's own screens and list tables only: the core post editor (TinyMCE, category metabox) is WordPress markup.
		for (const href of links.filter((h) => !h.includes('post-new.php')).slice(0, 20)) {
			await page.goto(href, { waitUntil: 'networkidle' });
			bad.push(...(await axe(page, new URL(href).search)));
		}
		expect(bad).toEqual([]);
	});

	test('branch reservation staff (390 px, phone inbox TC-RSA-002)', async ({ page }) => {
		await page.setViewportSize({ width: 390, height: 844 });
		await login(page, 'demo_rs_kitahama');
		await page.goto(`${BASE}/wp-admin/admin.php?page=momos-reservations`, { waitUntil: 'networkidle' });
		expect(await axe(page, 'rs inbox@390')).toEqual([]);
	});
});
