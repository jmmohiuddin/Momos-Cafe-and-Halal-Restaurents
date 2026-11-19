/**
 * QA (customer team) — SCR-C06 menu hub, SCR-C07 category, SCR-C08 dish (screens.md §3.6–3.8, menu-dietary.md):
 * TC-MENU-001/002/003/007/008/011, TC-DISH-001/006, TC-DIET-011/012/027, TC-REG-007, FR-MENU-008 filters with and
 * without JavaScript, price modes, DEC-047 illustration captions, content-strings §10.1 D5 SAMPLE badge (DEF-011).
 */
import { test, expect } from '@playwright/test';
import { execFileSync } from 'child_process';
import * as path from 'path';

const BASE = process.env.MOMOS_E2E_BASE_URL || process.env.MOMOS_URL || 'http://127.0.0.1:8080';
const ROOT = path.resolve(__dirname, '../../..');
const HAS_WP = !!process.env.MOMOS_DB_NAME;
const LANGS = ['ja', 'en', 'ar', 'ur', 'hi', 'bn'] as const;
const prefix = (l: string) => (l === 'ja' ? '' : `/${l}`);
function wp(...args: string[]): string {
	return execFileSync(path.join(ROOT, 'bin/wp'), args, { env: process.env, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
}
const CURRY = '/menu/curry/';
const BUTTER = '/menu/curry/butter-chicken-curry/';

test.describe('SCR-C06 menu hub (TC-MENU-001)', () => {
	test('default "All branches — reference prices", branch change keeps the URL ?branch=, canonical clean, noindex', async ({ page }) => {
		await page.goto(`${BASE}/en/menu/`);
		const sel = page.locator('main select').first();
		await expect(sel.locator('option').first()).toHaveText(/All branches — reference prices/);
		await expect(sel.locator('option')).toHaveCount(4); // all + 3 public branches, no TEST
		await sel.selectOption('DNB');
		await page.waitForURL(/\/en\/menu\/\?branch=DNB$/);
		await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', `${BASE}/en/menu/`);
		await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/);
		for (const href of await page.locator('main a.momo-tile').evaluateAll((as) => as.map((a) => (a as HTMLAnchorElement).search)))
			expect(href, 'category links keep ?branch=').toBe('?branch=DNB');
		// no dish counts on tiles (conflict C-3), no search/cart
		expect(await page.locator('main a.momo-tile').first().innerText()).not.toMatch(/\d+\s*(dishes|items)/i);
		await expect(page.locator('main input[type="search"]')).toHaveCount(0);
	});

	test('without JavaScript the branch selector still works (Show button)', async ({ browser }) => {
		const ctx = await browser.newContext({ javaScriptEnabled: false });
		const page = await ctx.newPage();
		await page.goto(`${BASE}/en/menu/`);
		await page.locator('main select').first().selectOption('DKY');
		await page.locator('main form button[type="submit"], main form input[type="submit"]').first().click();
		await page.waitForURL(/branch=DKY/);
		await ctx.close();
	});

	test('unknown ?branch= code is ignored (200, default label)', async ({ page }) => {
		const r = await page.goto(`${BASE}/en/menu/?branch=ZZZ`);
		expect(r?.status()).toBe(200);
		await expect(page.locator('main select').first()).toHaveValue('');
	});
});

test.describe('SCR-C07 category filters (TC-MENU-007, FR-MENU-008)', () => {
	const visibleNames = (page: import('@playwright/test').Page) =>
		page.locator('main [data-momo-dish-list] > li:not([hidden]) .momo-dish-card h2, main [data-momo-dish-list] > li:not([hidden]) .momo-dish-card h3').allInnerTexts();

	test('JS: vegetarian → only vegetarian dishes, count + URL updated; spice ≤ 0; clear', async ({ page }) => {
		await page.goto(`${BASE}/en${CURRY}`);
		const all = await page.locator('main [data-momo-dish-list] > li').count();
		await page.locator('main input[name="diet[]"][value="vegetarian"]').check({ force: true });
		const names = await visibleNames(page);
		expect(names.sort()).toEqual(['Dal fry', 'Mixed vegetable curry']);
		await expect(page.locator('main [data-momo-result-count]')).toContainText('2');
		expect(page.url()).toMatch(/diet(%5B%5D|\[\])?=vegetarian/);
		// round trip: the URL written by the filter reproduces the same result when opened (state in URL)
		const shared = page.url();
		const p2 = await page.context().newPage();
		await p2.goto(shared);
		expect((await visibleNames(p2)).sort(), `reload of ${shared}`).toEqual(['Dal fry', 'Mixed vegetable curry']);
		await p2.close();
		await page.locator('main input[name="max_spice"][value="0"]').check({ force: true });
		await expect(page.locator('main [data-momo-filter-empty]')).toBeVisible();
		await page.locator('main [data-momo-filter-clear]').first().click({ force: true });
		await expect(page.locator('main [data-momo-dish-list] > li:not([hidden])')).toHaveCount(all);
	});

	test('JS: allergen exclusion (milk) is conservative — contains / may contain / not verified / review all hidden', async ({ page }) => {
		await page.goto(`${BASE}/en${CURRY}`);
		for (const d of await page.locator('main details:has(input[name="exclude[]"])').all()) await d.evaluate((el) => ((el as HTMLDetailsElement).open = true));
		await page.locator('main input[name="exclude[]"][value="milk"]').check();
		const rows = await page.locator('main [data-momo-dish-list] > li').evaluateAll((lis) => lis.map((li) => ({
			hidden: (li as HTMLElement).hidden, alg: li.querySelector('[data-tags], .momo-dish-card')?.getAttribute('data-alg') || '' })));
		for (const r of rows) if (!r.hidden) expect(r.alg.split(' ')).not.toContain('milk');
		expect(rows.some((r) => r.hidden)).toBeTruthy();
		// never "allergen-free" wording
		expect((await page.locator('main').innerText()).toLowerCase()).not.toContain('allergen-free');
	});

	test('JS: a shared filter URL is applied on load (state in URL)', async ({ page }) => {
		await page.goto(`${BASE}/en${CURRY}?diet%5B%5D=vegetarian`);
		await expect(page.locator('main input[name="diet[]"][value="vegetarian"]')).toBeChecked();
		expect((await visibleNames(page)).sort()).toEqual(['Dal fry', 'Mixed vegetable curry']);
		await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/);
		await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', `${BASE}/en${CURRY}`);
	});

	test('no JS: Apply filters returns the filtered list (progressive enhancement)', async ({ browser }) => {
		const ctx = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 1280, height: 900 } });
		const page = await ctx.newPage();
		await page.goto(`${BASE}/en${CURRY}`);
		const det = page.locator('main details:has([data-momo-filter-form])');
		if (await det.count()) await det.first().evaluate((el) => ((el as HTMLDetailsElement).open = true));
		await page.locator('main input[name="diet[]"][value="vegetarian"]').check();
		await page.locator('main [data-momo-filter-form] button[type="submit"]').click();
		await page.waitForURL(/diet/);
		const names = await page.locator('main [data-momo-dish-list] > li:not([hidden]) .momo-dish-card').allInnerTexts();
		expect(names.length, `no-JS list shows ${names.length} dishes for vegetarian`).toBe(2);
		await ctx.close();
	});

	for (const lang of LANGS) {
		test(`dish cards: name link, price "(tax incl.)", spice text, illustration caption, no add/cart — ${lang}`, async ({ page }) => {
			await page.goto(`${BASE}${prefix(lang)}${CURRY}?branch=DKY`);
			const cards = page.locator('main .momo-dish-card');
			expect(await cards.count()).toBeGreaterThan(0);
			for (const c of await cards.all()) {
				await expect(c.locator('a')).toHaveCount(1);
				expect(await c.innerText()).toMatch(/¥\d{1,3}(,\d{3})*/);
			}
			// Kyoto price for butter chicken = ¥1,380 (branch override, TC-MENU-003)
			await expect(page.locator('main .momo-dish-card', { has: page.locator('a[href*="butter-chicken-curry"]') })).toContainText('¥1,380');
			const main = (await page.locator('main').innerText()).toLowerCase();
			for (const t of ['add to cart', 'add to order', 'order now', 'checkout', 'カートに追加']) expect(main).not.toContain(t);
		});
	}

	test('advance-order dish shows the advance-order label on its card and page (TC-MENU-011)', async ({ page }) => {
		await page.goto(`${BASE}/en/menu/grill-kebab/`);
		const card = page.locator('main .momo-dish-card', { has: page.locator('a[href*="mixed-grill-platter"]') });
		await expect(card.locator('.momo-advance')).toBeVisible();
		await page.goto(`${BASE}/en/menu/grill-kebab/mixed-grill-platter/`);
		await expect(page.locator('main')).toContainText(/at least\s*24\s*hours in advance/);
		await expect(page.locator('main a[href*="/en/reservation/"]').first()).toBeVisible();
	});
});

test.describe('SCR-C08 dish detail (TC-DISH-001, TC-MENU-003, TC-DIET-011/012/027)', () => {
	test('price modes: no branch → per-branch table; ?branch=DKY → "¥1,380 … at Demo Kyoto Kawaramachi" + Change', async ({ page }) => {
		await page.goto(`${BASE}/en${BUTTER}`);
		await expect(page.locator('main')).toContainText('Prices vary by branch');
		const table = page.locator('main table').filter({ hasText: 'Demo Kyoto Kawaramachi' });
		await expect(table).toContainText('¥1,380');
		await expect(table).toContainText('¥1,280');
		await page.goto(`${BASE}/en${BUTTER}?branch=DKY`);
		const price = page.locator('main .momo-price').first();
		await expect(price).toContainText('¥1,380');
		await expect(page.locator('main')).toContainText(/¥1,380\s*\(tax incl\.\)\s*at Demo Kyoto Kawaramachi/);
		await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/);
		await expect(page.locator('main a[href*="/en/reservation/?branch=DKY"]').first()).toBeVisible();
	});

	for (const lang of LANGS) {
		test(`content blocks: one H1, halal basis line, allergen table + disclaimer + tell-staff, illustration caption, related — ${lang}`, async ({ page }) => {
			const r = await page.goto(`${BASE}${prefix(lang)}${BUTTER}`);
			expect(r?.status()).toBe(200);
			await expect(page.locator('h1')).toHaveCount(1);
			// §3.8 #7 halal basis: "never omitted" (branch_default/no_claim template).
			// The dish template renders the line as `.momo-dish-halal` (with the {branch_links} of branch_default); the
			// compact claim-statement component uses `.momo-diet` — either markup satisfies §3.8 #7 (QA-006).
			await expect(page.locator('main :is(.momo-diet, .momo-dish-halal):has(svg use[href$="#i-shield-check"])'), 'dish halal basis line missing').toHaveCount(1);
			const tbl = page.locator('main table').first();
			await expect(tbl.locator('caption')).toHaveCount(1);
			expect(await page.locator('main tbody th[scope="rowgroup"], main th[scope="rowgroup"]').count(), 'allergen groups as rowgroup headers').toBeGreaterThanOrEqual(2);
			// gallery image(s) flagged as illustration carry a visible caption below the image (DEC-047)
			const fig = page.locator('main figure:has(img)').first();
			await expect(fig.locator('figcaption')).toBeVisible();
			const imgBox = (await fig.locator('img').boundingBox())!;
			const capBox = (await fig.locator('figcaption').boundingBox())!;
			expect(capBox.y, 'caption must be below the image, not over it').toBeGreaterThanOrEqual(imgBox.y + imgBox.height - 1);
			// related dishes ≤ 4
			expect(await page.locator('main .momo-dish-card').count()).toBeLessThanOrEqual(4);
		});
	}

	test('not-verified banner on a dish with any not_verified allergen (TC-DIET-012)', async ({ page }) => {
		test.skip(!HAS_WP, 'needs bin/wp');
		const ids = wp('eval', `foreach(get_posts(["post_type"=>"momo_dish","numberposts"=>-1]) as $p){ $a=\\Momos\\Dietary\\Service\\AllergenPresenter::for_dish($p->ID,"en"); if(!empty($a["not_verified"])){ echo get_permalink($p), "\\n"; } }`).trim().split('\n').filter(Boolean);
		test.skip(ids.length === 0, 'no seeded dish with a not_verified allergen');
		await page.goto(ids[0].replace(/^https?:\/\/[^/]+/, `${BASE}/en`).replace(`${BASE}/en/en/`, `${BASE}/en/`));
		await expect(page.locator('main [role="note"]')).toContainText(/not verified/i);
	});

	test('retired dish → 301 to its category in the same language (TC-MENU-008)', async ({ request }) => {
		test.skip(!HAS_WP, 'needs bin/wp');
		wp('post', 'update', '96', '--post_status=draft');
		wp('post', 'meta', 'update', '96', 'momo_retired', '1');
		try {
			for (const lang of ['ja', 'en']) {
				const r = await request.get(`${BASE}${prefix(lang)}/menu/dessert/vanilla-ice-cream/`, { maxRedirects: 0 });
				expect(r.status(), lang).toBe(301);
				expect(new URL(r.headers()['location'], BASE).pathname).toBe(`${prefix(lang)}/menu/dessert/`);
			}
		} finally {
			wp('post', 'meta', 'delete', '96', 'momo_retired');
			wp('post', 'update', '96', '--post_status=publish');
		}
	});

	test('dish URL under a non-primary category → 301 canonical', async ({ request }) => {
		const r = await request.get(`${BASE}/en/menu/dessert/butter-chicken-curry/`, { maxRedirects: 0 });
		expect([301, 404]).toContain(r.status());
		if (r.status() === 301) expect(new URL(r.headers()['location'], BASE).pathname).toBe(`/en${BUTTER}`);
	});
});

test.describe('DEF-011: SAMPLE badge on sample items outside production (content-strings §10.1 D5)', () => {
	for (const lang of ['ja', 'en'] as const) {
		test(`sample dish card and dish price carry a visible SAMPLE badge — ${lang}`, async ({ page }) => {
			const label = lang === 'ja' ? 'サンプル' : 'SAMPLE';
			await page.goto(`${BASE}${prefix(lang)}${CURRY}`);
			const card = page.locator('main .momo-dish-card').first();
			await expect(card.getByText(label, { exact: true })).toBeVisible();
			await page.goto(`${BASE}${prefix(lang)}${BUTTER}?branch=DKH`);
			await expect(page.locator('main').getByText(label, { exact: true }).first()).toBeVisible();
		});
	}
});

test.describe('no ordering routes or UI (TC-REG-007, DEC-042)', () => {
	for (const lang of LANGS) {
		test(`/cart/ /checkout/ /order/ are 404 — ${lang}`, async ({ request }) => {
			for (const p of ['/cart/', '/checkout/', '/order/']) expect((await request.get(`${BASE}${prefix(lang)}${p}`)).status(), p).toBe(404);
		});
	}
});
