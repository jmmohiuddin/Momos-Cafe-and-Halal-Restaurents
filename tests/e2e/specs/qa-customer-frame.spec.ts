/**
 * QA (customer team) — shared frame on every customer screen (screens.md §1.1/§2, ui-design-system.md §8,
 * Doc 07/08): header + Branches dropdown (TC-NAV-001), mobile menu dialog and action bar per page type
 * (TC-NAV-002), footer (TC-NAV-003), breadcrumbs presence/absence (TC-NAV-004), conditional Offers item (TC-NAV-006,
 * DEC-060), <html lang dir> (TC-I18N-001), one H1 and no horizontal scroll at 390 and 1280 px (TC-CMP-005),
 * axe WCAG 2.2 A/AA on the languages the smoke spec does not cover (TC-A11Y-001: ur, hi, bn).
 */
import { test, expect, Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'child_process';
import * as path from 'path';

const BASE = process.env.MOMOS_E2E_BASE_URL || process.env.MOMOS_URL || 'http://127.0.0.1:8080';
const ROOT = path.resolve(__dirname, '../../..');
const HAS_WP = !!process.env.MOMOS_DB_NAME;
const LANGS = ['ja', 'en', 'ar', 'ur', 'hi', 'bn'] as const;
const RTL = new Set(['ar', 'ur']);
const prefix = (l: string) => (l === 'ja' ? '' : `/${l}`);
function wp(...args: string[]): string {
	return execFileSync(path.join(ROOT, 'bin/wp'), args, { env: process.env, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
}
function freeze(iso: string | null): void {
	try { if (iso) wp('option', 'update', 'momos_frozen_now', iso); else wp('option', 'delete', 'momos_frozen_now'); } catch { /* absent */ }
}

/** page path → expected mobile bar kind and breadcrumb presence (screens.md §2). */
const PAGES: Array<{ p: string; bar: 'std' | 'branch' | 'none'; crumbs: boolean }> = [
	{ p: '/', bar: 'std', crumbs: false },
	{ p: '/about/', bar: 'std', crumbs: true },
	{ p: '/halal/', bar: 'std', crumbs: true },
	{ p: '/branches/', bar: 'std', crumbs: true },
	{ p: '/branches/demo-namba-minami/', bar: 'branch', crumbs: true },
	{ p: '/menu/', bar: 'std', crumbs: true },
	{ p: '/menu/curry/', bar: 'std', crumbs: true },
	{ p: '/menu/curry/butter-chicken-curry/', bar: 'std', crumbs: true },
	{ p: '/reservation/', bar: 'none', crumbs: false },
	{ p: '/reservation/submitted/', bar: 'none', crumbs: false },
	{ p: '/reservation/status/ABCD2345/', bar: 'none', crumbs: false },
	{ p: '/contact/', bar: 'std', crumbs: true },
	{ p: '/faq/', bar: 'std', crumbs: true },
	{ p: '/offers/', bar: 'std', crumbs: true },
	{ p: '/privacy-policy/', bar: 'std', crumbs: true },
	{ p: '/terms/', bar: 'std', crumbs: true },
	{ p: '/reservation-policy/', bar: 'std', crumbs: true },
	{ p: '/external-transmission/', bar: 'std', crumbs: true },
	{ p: '/accessibility/', bar: 'std', crumbs: true },
	{ p: '/no-such-page-qa/', bar: 'std', crumbs: false },
];

async function frameChecks(page: Page, url: string, lang: string, w: number, spec: (typeof PAGES)[number]) {
	const problems: string[] = [];
	const res = await page.goto(url);
	const st = res?.status() ?? 0;
	if (spec.p.startsWith('/no-such')) { if (st !== 404) problems.push(`${url}: status ${st} (want 404)`); }
	else if (st !== 200) { problems.push(`${url}: status ${st}`); return problems; }
	if ((await page.locator('html').getAttribute('lang')) !== lang) problems.push(`${url}: html lang`);
	if ((await page.locator('html').getAttribute('dir')) !== (RTL.has(lang) ? 'rtl' : 'ltr')) problems.push(`${url}: html dir`);
	const h1 = await page.locator('h1').count();
	if (h1 !== 1) problems.push(`${url}: ${h1} h1`);
	const ov = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
	if (ov > 0) problems.push(`${url} @${w}: horizontal overflow ${ov}px`);
	const crumbs = await page.locator('main nav.momo-breadcrumbs').count();
	if (spec.crumbs && crumbs !== 1) problems.push(`${url}: breadcrumbs missing`);
	if (!spec.crumbs && crumbs !== 0) problems.push(`${url}: breadcrumbs must be absent`);
	if (w < 768) {
		const bar = page.locator('nav.momo-bar a');
		const n = await bar.count();
		const visible = n ? await page.locator('nav.momo-bar').isVisible() : false;
		if (spec.bar === 'none' && n) problems.push(`${url}: action bar must be absent (C09/C10/C11)`);
		if (spec.bar === 'std') {
			const hrefs = n ? await bar.evaluateAll((as) => as.map((a) => (a as HTMLAnchorElement).pathname + (a as HTMLAnchorElement).search)) : [];
			if (n !== 2 || !visible || !hrefs[0].endsWith('/reservation/') || !hrefs[1].endsWith('/branches/')) problems.push(`${url}: bar ${JSON.stringify(hrefs)} (want Request a table · Branches)`);
			else if (lang !== 'ja' && !hrefs.every((h) => h.startsWith(`/${lang}/`))) problems.push(`${url}: bar links not in ${lang}`);
		}
		if (spec.bar === 'branch' && n !== 3) problems.push(`${url}: branch bar has ${n} items (want 3)`);
	} else if (await page.locator('nav.momo-bar').isVisible().catch(() => false)) {
		problems.push(`${url} @${w}: action bar visible on desktop`);
	}
	return problems;
}

test.describe('every customer screen: lang/dir, one H1, no horizontal scroll, bar and breadcrumb rules', () => {
	for (const [w, h] of [[390, 844], [1280, 900]] as const) {
		for (const lang of LANGS) {
			test(`${lang} @${w}`, async ({ browser }) => {
				test.setTimeout(300_000);
				const ctx = await browser.newContext({ viewport: { width: w, height: h } });
				const page = await ctx.newPage();
				const problems: string[] = [];
				for (const spec of PAGES) problems.push(...(await frameChecks(page, `${BASE}${prefix(lang)}${spec.p}`, lang, w, spec)));
				await ctx.close();
				expect(problems).toEqual([]);
			});
		}
	}
});

test.describe('header (TC-NAV-001) @1280', () => {
	for (const lang of LANGS) {
		test(`items, same-language links, Branches dropdown, CTA above the fold — ${lang}`, async ({ page }) => {
			await page.goto(`${BASE}${prefix(lang)}/`);
			const items = page.locator('header .momo-navpill > li');
			const n = await items.count();
			expect(n === 5 || n === 6).toBeTruthy();
			const hrefs = await page.locator('header .momo-navpill a').evaluateAll((as) => as.map((a) => (a as HTMLAnchorElement).pathname));
			for (const h of hrefs) expect(h.startsWith(lang === 'ja' ? '/' : `/${lang}/`), h).toBeTruthy();
			for (const p of ['/menu/', '/halal/', '/about/', '/reservation/']) expect(hrefs).toContain(`${prefix(lang)}${p}`);
			const summary = page.locator('header .momo-navpill details > summary');
			await summary.focus();
			await page.keyboard.press('Enter');
			const dd = page.locator('header .momo-navpill details .momo-dropdown a');
			await expect(dd.first()).toBeVisible();
			await expect(dd).toHaveCount(4); // 3 public branches + "All branches"
			await page.keyboard.press('Escape');
			await expect(page.locator('header .momo-navpill details')).not.toHaveAttribute('open', '');
			const cta = page.locator('.momo-header__cta');
			await expect(cta).toBeInViewport();
			expect(new URL((await cta.getAttribute('href'))!).pathname).toBe(`${prefix(lang)}/reservation/`);
		});
	}
});

test.describe('mobile menu dialog (TC-NAV-002) @390', () => {
	test.use({ viewport: { width: 390, height: 844 } });
	for (const lang of LANGS) {
		test(`opens, lists same-language links + languages, closes on navigation — ${lang}`, async ({ page }) => {
			await page.goto(`${BASE}${prefix(lang)}/menu/`);
			await page.locator('[data-momo-menu-open]').click();
			const dlg = page.locator('dialog.momo-mmenu');
			await expect(dlg).toBeVisible();
			const hrefs = await dlg.locator('.momo-mmenu__nav a').evaluateAll((as) => as.map((a) => (a as HTMLAnchorElement).pathname));
			for (const h of hrefs) expect(h.startsWith(lang === 'ja' ? '/' : `/${lang}/`), h).toBeTruthy();
			await expect(dlg.locator('.momo-langgrid a')).toHaveCount(6);
			await expect(dlg.locator(`.momo-langgrid a[lang="${lang}"]`)).toHaveAttribute('aria-current', 'true');
			// Language buttons ≥ 44 px (i18n-seo-analytics §5.1 "target ≥ 44 px"; mobile grid of 48 px buttons).
			for (const a of await dlg.locator('.momo-langgrid a').all()) expect((await a.boundingBox())!.height).toBeGreaterThanOrEqual(44);
			await dlg.locator('.momo-mmenu__nav a').filter({ hasText: /./ }).last().click();
			await page.waitForLoadState('domcontentloaded');
			await expect(page.locator('dialog.momo-mmenu')).toBeHidden();
		});
	}

	test('drawer is closed by Close button and focus returns to the opener', async ({ page }) => {
		await page.goto(`${BASE}/ar/`);
		const btn = page.locator('[data-momo-menu-open]');
		await btn.click();
		await page.locator('[data-momo-mmenu-close]').click();
		await expect(page.locator('dialog.momo-mmenu')).toBeHidden();
		await expect(btn).toBeFocused();
	});
});

test.describe('footer (TC-NAV-003, FR-CORP-009)', () => {
	for (const lang of LANGS) {
		test(`branches with tel:, Offers always, five legal links + analytics settings, all resolving — ${lang}`, async ({ page, request }) => {
			await page.goto(`${BASE}${prefix(lang)}/faq/`);
			const f = page.locator('footer');
			await expect(f.locator('.momo-footer__branch')).toHaveCount(3);
			await expect(f.locator('a[href^="tel:+81"]')).toHaveCount(3);
			await expect(f).not.toContainText(/TST|test-branch/i);
			await expect(f.locator(`a[href$="${prefix(lang)}/offers/"]`)).toHaveCount(1);
			for (const p of ['/privacy-policy/', '/terms/', '/reservation-policy/', '/external-transmission/', '/accessibility/', '/external-transmission/#analytics-settings'])
				await expect(f.locator(`a[href$="${prefix(lang)}${p}"]`), p).toHaveCount(1);
			const links = await f.locator('a[href^="http"]').evaluateAll((as) => as.map((a) => (a as HTMLAnchorElement).href.split('#')[0]));
			for (const u of [...new Set(links)]) {
				const r = await request.get(u, { maxRedirects: 0 });
				expect(r.status(), u).toBe(200);
			}
		});
	}
});

test.describe('conditional Offers header item (TC-NAV-006, DEC-060)', () => {
	test.skip(!HAS_WP, 'needs bin/wp');
	const OFFERS = ['105', '108'];
	test.afterAll(() => {
		freeze(null);
		for (const id of OFFERS) wp('post', 'meta', 'update', id, '_momos_i18n_status_ur', 'approved');
	});

	test('active offers translated → 6th item after Branches; untranslated in ur → absent, footer link stays', async ({ page }) => {
		await page.goto(`${BASE}/en/`);
		const labels = (await page.locator('header .momo-navpill > li').allInnerTexts()).map((s) => s.trim());
		expect(labels[2]).toBe('Offers');
		for (const id of OFFERS) wp('post', 'meta', 'update', id, '_momos_i18n_status_ur', 'draft');
		await page.goto(`${BASE}/ur/`);
		await expect(page.locator(`header .momo-navpill a[href$="/ur/offers/"]`)).toHaveCount(0);
		await expect(page.locator('footer a[href$="/ur/offers/"]')).toHaveCount(1);
		// ur home: no offers section, no Japanese offer text (no fallback, TC-CORP-014)
		await expect(page.locator('main #momo-home-offers')).toHaveCount(0);
		await expect(page.locator('main')).not.toContainText(/平日ランチセット|グループ来店特典/);
		await page.goto(`${BASE}/ur/offers/`);
		await expect(page.locator('main .momo-offer-card')).toHaveCount(0);
		await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/);
		for (const id of OFFERS) wp('post', 'meta', 'update', id, '_momos_i18n_status_ur', 'approved');
	});

	test('after every offer ended (clock past end_at): no header item, no home section/link, /offers/ empty + noindex', async ({ page }) => {
		freeze('2027-02-01T12:00:00+09:00');
		await page.goto(`${BASE}/en/`);
		await expect(page.locator('header .momo-navpill a[href$="/en/offers/"]')).toHaveCount(0);
		await expect(page.locator('dialog.momo-mmenu a[href$="/en/offers/"]')).toHaveCount(0);
		await expect(page.locator('footer a[href$="/en/offers/"]')).toHaveCount(1);
		await expect(page.locator('main #momo-home-offers')).toHaveCount(0);
		await expect(page.locator('main .momo-hero a[href$="/en/offers/"]')).toHaveCount(0);
		await page.goto(`${BASE}/en/offers/`);
		await expect(page.locator('main .momo-offer-card')).toHaveCount(0);
		await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/);
		await page.goto(`${BASE}/en/branches/demo-namba-minami/`);
		await expect(page.locator('main #branch-offers')).toHaveCount(0);
		freeze(null);
	});

	test('offer not yet started is not shown anywhere', async ({ page }) => {
		freeze('2026-11-10T12:00:00+09:00');
		await page.goto(`${BASE}/en/offers/`);
		await expect(page.locator('main .momo-offer-card')).toHaveCount(0);
		freeze(null);
	});
});

test.describe('axe WCAG 2.2 A/AA on key screens — ur, hi, bn (TC-A11Y-001)', () => {
	test.use({ viewport: { width: 390, height: 844 } });
	for (const lang of ['ur', 'hi', 'bn']) {
		test(`0 serious/critical — ${lang}`, async ({ page }) => {
			test.setTimeout(240_000);
			const bad: string[] = [];
			for (const p of ['/', '/branches/demo-namba-minami/', '/menu/curry/', '/menu/curry/butter-chicken-curry/', '/halal/', '/offers/', '/faq/', '/contact/', '/reservation/']) {
				await page.goto(`${BASE}/${lang}${p}`, { waitUntil: 'networkidle' });
				const r = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
				for (const v of r.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical'))
					bad.push(`${p} ${v.id}: ${v.nodes.slice(0, 2).map((n) => n.target.join(' ')).join(' | ')}`);
			}
			expect(bad).toEqual([]);
		});
	}
});
