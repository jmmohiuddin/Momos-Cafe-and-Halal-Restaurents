/**
 * QA (customer team) — language behaviour on customer screens (i18n-seo-analytics.md §5, DEC-027, IMPL-002):
 * TC-I18N-002 (switcher: same page when translated, else target home + notice), TC-I18N-009 (hidden language),
 * TC-I18N-013 (no mixed-language pages), TC-I18N-014 (context kept), TC-DISH-005 / TC-HOME-002 / TC-CORP-014
 * (untranslated items hidden, no fallback), switcher query rule (keep branch/diet/spice, drop others),
 * plus default WordPress content that must not be public on a launch build.
 */
import { test, expect, Page } from '@playwright/test';
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
const JP = /[぀-ヿ一-鿿]/;

async function switcherHrefs(page: Page): Promise<Record<string, string>> {
	return page.locator('header .momo-lang__list a').evaluateAll((as) =>
		Object.fromEntries(as.map((a) => [a.getAttribute('lang')!, (a as HTMLAnchorElement).href])));
}

/** Japanese text outside an element marked lang="ja" (allow-list: Japanese address, language names). */
async function japaneseLeaks(page: Page): Promise<string[]> {
	return page.evaluate((src) => {
		const re = new RegExp(src);
		const out: string[] = [];
		const inJa = (el: Element | null) => !!el && (el.closest('[lang]')?.getAttribute('lang') || '').startsWith('ja');
		const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
		let n: Node | null;
		while ((n = walker.nextNode())) {
			const el = n.parentElement;
			if (!el || el.closest('script,style,noscript,template,[hidden],[aria-hidden="true"]')) continue;
			if (re.test(n.textContent || '') && !inJa(el)) out.push(`text: ${(n.textContent || '').trim().slice(0, 60)}`);
		}
		document.querySelectorAll('img[alt], [aria-label], [title]').forEach((el) => {
			for (const at of ['alt', 'aria-label', 'title']) {
				const v = el.getAttribute(at);
				if (v && re.test(v) && !inJa(el)) out.push(`${el.tagName.toLowerCase()}[${at}]: ${v.slice(0, 60)}`);
			}
		});
		return out;
	}, JP.source);
}

test.describe('switcher resolves to the same page in every language (TC-I18N-002/014)', () => {
	const PATHS = ['/', '/branches/demo-namba-minami/', '/menu/', '/menu/curry/', '/menu/curry/butter-chicken-curry/', '/halal/', '/offers/', '/faq/', '/contact/', '/about/', '/privacy-policy/', '/reservation/'];
	for (const from of ['ja', 'en', 'ar'] as const) {
		test(`from ${from}`, async ({ page }) => {
			const problems: string[] = [];
			for (const p of PATHS) {
				await page.goto(`${BASE}${prefix(from)}${p}`);
				const map = await switcherHrefs(page);
				for (const to of LANGS) {
					const want = `${BASE}${prefix(to)}${p}`;
					if (map[to] !== want) problems.push(`${prefix(from)}${p} → ${to}: ${map[to]} (want ${want})`);
				}
			}
			expect(problems).toEqual([]);
		});
	}

	test('404 → target home without notice', async ({ page }) => {
		await page.goto(`${BASE}/en/no-such-thing-qa/`);
		const map = await switcherHrefs(page);
		expect(map.ar).toBe(`${BASE}/ar/`);
		expect(map.ja).toBe(`${BASE}/`);
	});
});

test.describe('switcher query rule (i18n-seo-analytics §5.1: keep branch/diet/spice, drop the rest)', () => {
	test('category with branch + filter + tracking params', async ({ page }) => {
		await page.goto(`${BASE}/en/menu/curry/?branch=DNB&diet%5B%5D=vegetarian&max_spice=1&utm_source=qa&foo=bar`);
		const href = new URL((await switcherHrefs(page)).ar);
		expect(href.pathname).toBe('/ar/menu/curry/');
		expect(href.searchParams.get('branch')).toBe('DNB');
		expect(href.searchParams.getAll('diet[]').concat(href.searchParams.getAll('diet'))).toContain('vegetarian');
		expect(href.searchParams.get('max_spice') ?? href.searchParams.get('spice')).toBe('1');
		expect(href.searchParams.has('utm_source')).toBeFalsy();
		expect(href.searchParams.has('foo')).toBeFalsy();
	});

	test('menu hub keeps ?branch=', async ({ page }) => {
		await page.goto(`${BASE}/menu/?branch=DKH&gclid=abc`);
		const href = new URL((await switcherHrefs(page)).en);
		expect(href.pathname + href.search).toBe('/en/menu/?branch=DKH');
	});
});

test.describe('untranslated items are hidden, never shown in Japanese (DEC-027)', () => {
	test.skip(!HAS_WP, 'needs bin/wp');
	const DISH = '99'; // vanilla-matcha-ice-cream (dessert)
	const ABOUT = '5';
	const BRANCH = '31'; // demo-kyoto-kawaramachi
	test.beforeAll(() => {
		wp('post', 'meta', 'update', DISH, '_momos_i18n_status_bn', 'draft');
		wp('post', 'meta', 'update', ABOUT, '_momos_i18n_status_bn', 'draft');
		wp('post', 'meta', 'update', BRANCH, '_momos_i18n_status_ur', 'draft');
	});
	test.afterAll(() => {
		wp('post', 'meta', 'update', DISH, '_momos_i18n_status_bn', 'approved');
		wp('post', 'meta', 'update', ABOUT, '_momos_i18n_status_bn', 'approved');
		wp('post', 'meta', 'update', BRANCH, '_momos_i18n_status_ur', 'approved');
	});

	test('dish untranslated in bn: 404 in bn, absent from bn lists, switcher → bn home + notice (TC-DISH-005)', async ({ page, request }) => {
		const r = await request.get(`${BASE}/bn/menu/dessert/vanilla-matcha-ice-cream/`);
		expect(r.status()).toBe(404);
		await page.goto(`${BASE}/bn/menu/dessert/`);
		await expect(page.locator('main a[href*="vanilla-matcha-ice-cream"]')).toHaveCount(0);
		await page.goto(`${BASE}/bn/menu/dessert/matcha-ice-cream/`);
		await expect(page.locator('main a[href*="vanilla-matcha-ice-cream"]')).toHaveCount(0);
		await page.goto(`${BASE}/en/menu/dessert/vanilla-matcha-ice-cream/`);
		const href = (await switcherHrefs(page)).bn;
		expect(href).toBe(`${BASE}/bn/?notice=untranslated`);
		await page.goto(href);
		const notice = page.locator('main, body').getByText(/বাংলা/).first();
		await expect(notice).toBeVisible();
		await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/);
		// legacy alias accepted
		const alias = await request.get(`${BASE}/bn/?momo_notice=missing_translation`);
		expect(alias.status()).toBe(200);
	});

	test('page untranslated in bn: /bn/about/ 404, not linked from bn header/footer/menu dialog', async ({ page, request }) => {
		expect((await request.get(`${BASE}/bn/about/`)).status()).toBe(404);
		await page.goto(`${BASE}/bn/`);
		await expect(page.locator('a[href$="/bn/about/"]')).toHaveCount(0);
	});

	test('branch untranslated in ur: absent from ur directory, home teaser, dropdown, footer; detail 404', async ({ page, request }) => {
		expect((await request.get(`${BASE}/ur/branches/demo-kyoto-kawaramachi/`)).status()).toBe(404);
		await page.goto(`${BASE}/ur/branches/`);
		await expect(page.locator('main .momo-branch-card')).toHaveCount(2);
		await page.goto(`${BASE}/ur/`);
		await expect(page.locator('a[href*="demo-kyoto-kawaramachi"]')).toHaveCount(0);
		await expect(page.locator('main')).not.toContainText('京都');
		await page.goto(`${BASE}/ur/menu/`);
		await expect(page.locator('main select option[value="DKY"]')).toHaveCount(0);
	});
});

test.describe('hidden language is not public (TC-I18N-009)', () => {
	test.skip(!HAS_WP, 'needs bin/wp');
	test.afterAll(() => { try { wp('option', 'delete', 'momos_languages_enabled'); } catch { /* none */ } });

	test('hi hidden → /hi/ URLs 404, not in switcher, hreflang or footer list', async ({ page, request }) => {
		wp('option', 'update', 'momos_languages_enabled', '["ja","en","ar","ur","bn"]', '--format=json');
		for (const p of ['/hi/', '/hi/menu/', '/hi/branches/demo-kitahama/']) expect((await request.get(`${BASE}${p}`)).status(), p).toBe(404);
		await page.goto(`${BASE}/en/menu/`);
		await expect(page.locator('a[lang="hi"]')).toHaveCount(0);
		await expect(page.locator('link[hreflang="hi"]')).toHaveCount(0);
		wp('option', 'delete', 'momos_languages_enabled');
	});
});

test.describe('no mixed-language pages (TC-I18N-013): no Japanese outside lang="ja" islands', () => {
	const PATHS = ['/', '/branches/', '/branches/demo-kitahama/', '/menu/', '/menu/curry/', '/menu/grill-kebab/mixed-grill-platter/', '/halal/', '/offers/', '/faq/', '/contact/', '/about/', '/reservation/', '/privacy-policy/'];
	for (const lang of ['en', 'ar', 'ur', 'hi', 'bn'] as const) {
		test(lang, async ({ page }) => {
			test.setTimeout(180_000);
			const leaks: string[] = [];
			for (const p of PATHS) {
				const r = await page.goto(`${BASE}/${lang}${p}`);
				if (r?.status() !== 200) { leaks.push(`${p}: HTTP ${r?.status()}`); continue; }
				for (const l of await japaneseLeaks(page)) leaks.push(`${p} ${l}`);
			}
			expect(leaks).toEqual([]);
		});
	}
});

test.describe('default WordPress content is not public', () => {
	test('"Sample Page" and "Hello world!" are not reachable / listed', async ({ page, request }) => {
		expect((await request.get(`${BASE}/sample-page/`)).status()).toBe(404);
		expect((await request.get(`${BASE}/news/hello-world/`)).status()).toBe(404);
		await page.goto(`${BASE}/news/`);
		await expect(page.locator('main')).not.toContainText('Hello world');
	});
});
