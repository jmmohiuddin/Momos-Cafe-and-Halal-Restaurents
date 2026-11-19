/**
 * QA (a11y-i18n team) — multilingual rendering, RTL and BiDi (i18n-seo-analytics §4, Doc 08 §6, DEC-061/062,
 * DEC-027; TC-I18N-001/004/005/011/013, TC-RTL-001/002, TC-A11Y-007/008, AC-I18N-01-01/02/07).
 *  - lang/dir on every template in all six languages; lang of parts (switcher, Japanese address originals).
 *  - Fonts: only the page language's families are requested, all from this origin; Epilogue never on non-Latin pages.
 *  - LTR isolation (bdi / dir=ltr) for phones, prices, times, references in ar/ur.
 *  - Western digits on output in every language; ja/en date + time + price formats; "JST" only in non-ja.
 *  - No raw string keys and no English fallback text on ja/ar/ur/hi/bn pages; no Japanese on non-ja pages.
 *  - RTL mirroring of the header, breadcrumbs and directional icons; non-directional icons not mirrored.
 */
import { test, expect, Page } from '@playwright/test';
import { execFileSync } from 'child_process';
import * as path from 'path';

const BASE = process.env.MOMOS_E2E_BASE_URL || process.env.MOMOS_URL || 'http://127.0.0.1:8080';
const ROOT = path.resolve(__dirname, '../../..');
const HAS_WP = !!process.env.MOMOS_DB_NAME;
const ALL = ['ja', 'en', 'ar', 'ur', 'hi', 'bn'] as const;
const RTL = new Set(['ar', 'ur']);
const prefix = (l: string) => (l === 'ja' ? '' : `/${l}`);

function wp(...args: string[]): string {
	return execFileSync(path.join(ROOT, 'bin/wp'), args, { cwd: ROOT, env: process.env, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
}

const PAGES = ['/', '/about/', '/halal/', '/branches/', '/branches/demo-kitahama/', '/branches/demo-namba-minami/', '/menu/', '/menu/curry/',
	'/menu/curry/butter-chicken-curry/', '/menu/biryani-rice/', '/reservation/', '/reservation/submitted/', '/contact/', '/faq/', '/offers/',
	'/gallery/', '/privacy-policy/', '/terms/', '/reservation-policy/', '/external-transmission/', '/accessibility/', '/no-such-page-rtl/'];

/** Visible text nodes + accessible-name attributes of the rendered page, with the nearest lang of each. */
async function texts(page: Page): Promise<Array<{ t: string; lang: string; where: string }>> {
	return page.evaluate(() => {
		const out: Array<{ t: string; lang: string; where: string }> = [];
		const skip = (el: Element | null) => !!el?.closest('script, style, noscript, template, [hidden], [aria-hidden="true"]:not(svg *), #momo-lang-data, #momo-res-config, .momo-langbar, option');
		const w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
		for (let n = w.nextNode(); n; n = w.nextNode()) {
			const el = n.parentElement;
			const t = (n.textContent || '').trim();
			if (!t || !el || skip(el)) continue;
			out.push({ t, lang: el.closest('[lang]')?.getAttribute('lang') || '', where: el.tagName.toLowerCase() + (el.className && typeof el.className === 'string' ? '.' + el.className.split(' ')[0] : '') });
		}
		for (const el of Array.from(document.querySelectorAll('[aria-label], [title], [placeholder], img[alt], meta[name="description"], meta[property="og:title"], meta[property="og:description"], title'))) {
			if (skip(el) && el.tagName !== 'META' && el.tagName !== 'TITLE') continue;
			for (const a of ['aria-label', 'title', 'placeholder', 'alt', 'content']) {
				const v = el.getAttribute(a);
				if (v && v.trim()) out.push({ t: v.trim(), lang: el.closest('[lang]')?.getAttribute('lang') || '', where: `${el.tagName.toLowerCase()}[${a}]` });
			}
			if (el.tagName === 'TITLE') out.push({ t: (el.textContent || '').trim(), lang: document.documentElement.lang, where: 'title' });
		}
		return out;
	});
}

test.describe('lang and dir per language, lang of parts (TC-I18N-001, TC-A11Y-007)', () => {
	for (const lang of ALL) {
		test(`every template — ${lang}`, async ({ page }) => {
			test.setTimeout(240_000);
			const bad: string[] = [];
			for (const p of PAGES) {
				const res = await page.goto(`${BASE}${prefix(lang)}${p}`);
				if (res?.status() === 404 && !p.startsWith('/no-such')) continue;
				const html = page.locator('html');
				if ((await html.getAttribute('lang')) !== lang) bad.push(`${p}: lang=${await html.getAttribute('lang')}`);
				if ((await html.getAttribute('dir')) !== (RTL.has(lang) ? 'rtl' : 'ltr')) bad.push(`${p}: dir=${await html.getAttribute('dir')}`);
				// Every switcher option carries its own lang + dir (screen reader pronunciation).
				const opts = await page.locator('header .momo-lang__list a').evaluateAll((as) => as.map((a) => [a.getAttribute('lang'), a.getAttribute('dir'), a.getAttribute('data-momo-to')]));
				for (const [l, d, to] of opts) if (l !== to || d !== (['ar', 'ur'].includes(String(to)) ? 'rtl' : 'ltr')) bad.push(`${p}: switcher option ${to} lang=${l} dir=${d}`);
				if (lang !== 'ja') {
					// Japanese text on a non-ja page must sit in a lang="ja" island (address originals etc.).
					const ja = (await texts(page)).filter((x) => /[぀-ヿ一-鿿]/.test(x.t) && x.lang !== 'ja');
					for (const x of ja.slice(0, 3)) bad.push(`${p}: Japanese text without lang="ja" (${x.where}): ${x.t.slice(0, 40)}`);
				}
			}
			expect(bad).toEqual([]);
		});
	}
});

test.describe('fonts per language (DEC-061, TC-I18N-004, AC-I18N-01-01)', () => {
	const FAMILY: Record<string, RegExp> = {
		en: /epilogue|plus-jakarta-sans/, ja: /noto-sans-jp/, ar: /noto-naskh-arabic|plus-jakarta-sans/, ur: /noto-nastaliq-urdu|plus-jakarta-sans/,
		hi: /noto-sans-devanagari|plus-jakarta-sans/, bn: /noto-sans-bengali|plus-jakarta-sans/,
	};
	for (const lang of ALL) {
		test(`only own families, same origin, Epilogue never on non-Latin pages — ${lang}`, async ({ page }) => {
			const fonts: string[] = [];
			const css: string[] = [];
			page.on('request', (r) => {
				if (r.resourceType() === 'font' || /\.woff2?(\?|$)/.test(r.url())) fonts.push(r.url());
				if (r.resourceType() === 'stylesheet') css.push(r.url());
			});
			for (const p of ['/', '/menu/curry/', '/reservation/']) await page.goto(`${BASE}${prefix(lang)}${p}`, { waitUntil: 'networkidle' });
			const host = new URL(BASE).host;
			const bad: string[] = [];
			for (const u of fonts) {
				if (new URL(u).host !== host) bad.push(`third-party font ${u}`);
				if (!FAMILY[lang].test(u)) bad.push(`foreign family on ${lang}: ${u.split('/').pop()}`);
				if (lang !== 'en' && /epilogue/i.test(u)) bad.push(`Epilogue on ${lang}`);
			}
			for (const u of css) {
				if (/fonts\.googleapis|fonts\.gstatic/.test(u)) bad.push(`Google Fonts CSS ${u}`);
				const m = u.match(/fonts-([a-z]{2})\.css/);
				if (m && m[1] !== lang) bad.push(`foreign font CSS ${m[0]}`);
			}
			if (lang !== 'ja') expect(new Set(fonts.map((u) => u.split('?')[0])).size, 'font files per page view ≤ 6').toBeLessThanOrEqual(6);
			expect(fonts.length, 'the language face is loaded').toBeGreaterThan(0);
			expect([...new Set(bad)]).toEqual([]);
			// Computed body font stack starts with the language family.
			const fam = await page.evaluate(() => getComputedStyle(document.body).fontFamily);
			const first: Record<string, RegExp> = { en: /Plus Jakarta Sans|Epilogue/, ja: /Noto Sans JP/, ar: /Noto Naskh Arabic/, ur: /Noto Nastaliq Urdu/, hi: /Noto Sans Devanagari/, bn: /Noto Sans Bengali/ };
			expect(fam.split(',')[0]).toMatch(first[lang]);
		});
	}
});

test.describe('LTR isolation of phones, prices, times, references in RTL (TC-RTL-002, FR-I18N-005, AC-I18N-01-02)', () => {
	const RX: Array<[string, RegExp]> = [
		['phone', /(\+81|0\d{1,3})[\s-]?\d{1,4}[\s-]\d{3,4}[\s-]?\d{0,4}/],
		['price', /¥\s?\d[\d,]*/],
		['time', /\b\d{1,2}:\d{2}\b/],
		['reference', /\b[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}\b/],
	];
	for (const lang of ['ar', 'ur']) {
		test(`every match sits in <bdi>/dir=ltr — ${lang}`, async ({ page }) => {
			test.setTimeout(240_000);
			const bad: string[] = [];
			for (const p of ['/', '/branches/', '/branches/demo-kitahama/', '/menu/curry/', '/menu/curry/butter-chicken-curry/', '/offers/', '/reservation/?branch=DKH', '/contact/', '/halal/']) {
				const res = await page.goto(`${BASE}/${lang}${p}`, { waitUntil: 'networkidle' });
				if (res?.status() !== 200) continue;
				if (p.startsWith('/reservation/')) {
					// Render the live summary (date, time, party) too.
					const v = await page.$eval('#res-date', (s) => Array.from((s as HTMLSelectElement).options).find((o) => o.value && !o.disabled)?.value || '');
					if (v) { await page.selectOption('#res-date', v); await page.locator('[data-res-times] label').first().click({ timeout: 10_000 }).catch(() => undefined); }
				}
				const found = await page.evaluate((rx) => {
					const out: string[] = [];
					const w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
					for (let n = w.nextNode(); n; n = w.nextNode()) {
						const el = n.parentElement;
						if (!el || el.closest('script, style, noscript, template, [hidden], option, #momo-lang-data, #momo-res-config')) continue;
						const t = n.textContent || '';
						// Editorial prose typed by translators (≥ 5 RTL words) is not code-wrapped (Doc 07 §6.1).
						if ((t.match(/[\u0600-\u06FF]+/g) || []).length >= 5 || el.closest('[data-momo-clamp], .momo-prose, .entry-content')) continue;
						for (const [name, src] of rx) {
							const m = t.match(new RegExp(src));
							if (!m) continue;
							const iso = el.closest('bdi, [dir="ltr"], input, textarea');
							const isoDir = iso ? (iso.getAttribute('dir') || (iso.tagName === 'BDI' ? 'auto' : '')) : '';
							if (!iso || isoDir === 'rtl') out.push(`${name} "${m[0]}" in <${el.tagName.toLowerCase()} class="${el.className}">`);
						}
					}
					return out;
				}, RX.map(([n, r]) => [n, r.source] as [string, string]));
				for (const f of found.slice(0, 5)) bad.push(`${lang}${p}: ${f}`);
			}
			expect([...new Set(bad)]).toEqual([]);
		});
	}
});

test.describe('number, date, time and price formats (DEC-062, TC-I18N-005, AC-I18N-01-07)', () => {
	test('Western digits on output in every language (incl. bn override)', async ({ page }) => {
		test.setTimeout(300_000);
		const bad: string[] = [];
		for (const lang of ALL) {
			for (const p of ['/', '/branches/demo-kitahama/', '/menu/curry/', '/menu/curry/butter-chicken-curry/', '/offers/', '/reservation/?branch=DKH']) {
				const res = await page.goto(`${BASE}${prefix(lang)}${p}`, { waitUntil: 'networkidle' });
				if (res?.status() !== 200) continue;
				for (const x of await texts(page)) {
					const m = x.t.match(/[٠-٩۰-۹०-९০-৯０-９]+/);
					if (m) bad.push(`${lang}${p} (${x.where}): non-Western digits "${m[0]}" in "${x.t.slice(0, 50)}"`);
				}
				// JS-rendered date options as well.
				const opts = await page.$$eval('#res-date option', (os) => os.map((o) => o.textContent || '')).catch(() => [] as string[]);
				for (const o of opts) if (/[٠-٩۰-۹०-९০-৯０-９]/.test(o)) bad.push(`${lang}${p}: date option "${o}"`);
			}
		}
		expect(bad.slice(0, 20)).toEqual([]);
	});

	test('ja/en dates, 24 h times, JST label only in non-ja, price + tax label', async ({ page }) => {
		const out: Record<string, { opts: string[]; sum: string; price: string; body: string }> = {};
		for (const lang of ['ja', 'en'] as const) {
			await page.goto(`${BASE}${prefix(lang)}/reservation/?branch=DKH`, { waitUntil: 'networkidle' });
			const opts = await page.$$eval('#res-date option', (os) => os.filter((o) => (o as HTMLOptionElement).value).slice(0, 3).map((o) => (o.textContent || '').trim()));
			const v = await page.$eval('#res-date', (s) => Array.from((s as HTMLSelectElement).options).find((o) => o.value && !o.disabled)?.value || '');
			await page.selectOption('#res-date', v);
			await page.locator('[data-res-times] label').first().click();
			const sum = (await page.locator('.momo-res-summary').innerText()).replace(/\s+/g, ' ');
			const body = await page.locator('body').innerText();
			await page.goto(`${BASE}${prefix(lang)}/menu/curry/butter-chicken-curry/`);
			const price = ((await page.locator('main .momo-price').first().textContent()) || '').replace(/\s+/g, ' ').trim();
			out[lang] = { opts, sum, price, body };
		}
		for (const o of out.ja.opts) expect(o).toMatch(/^\d{4}年\d{1,2}月\d{1,2}日（[月火水木金土日]）/);
		for (const o of out.en.opts) expect(o).toMatch(/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun) \d{1,2} (Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) \d{4}/);
		expect(out.en.sum).toMatch(/\b\d{2}:\d{2} JST\b/);
		expect(out.ja.sum).toMatch(/\b\d{2}:\d{2}\b/);
		expect(out.ja.body, '"JST" is not shown on Japanese pages').not.toContain('JST');
		expect(out.ja.sum).not.toMatch(/(午前|午後|AM|PM)/i);
		expect(out.ja.price).toMatch(/^¥\d{1,3}(,\d{3})*（税込）$/);
		expect(out.en.price).toMatch(/^¥\d{1,3}(,\d{3})* \(tax incl\.\)$/);
	});

	test('offer validity ranges follow DEC-062 (en "1 Dec – 31 Dec 2026", ja "2026年12月1日〜12月31日")', async ({ page }) => {
		await page.goto(`${BASE}/en/offers/`);
		const en = (await page.locator('main dd').filter({ hasText: /\d{4}/ }).first().innerText()).trim();
		await page.goto(`${BASE}/offers/`);
		const ja = (await page.locator('main dd').filter({ hasText: /\d{4}/ }).first().innerText()).trim();
		// Interval format without weekdays (i18n-seo-analytics §4.4 "Offer date ranges").
		expect(en).not.toMatch(/\b(Mon|Tue|Wed|Thu|Fri|Sat|Sun)\b/);
		expect(en).toMatch(/\d{1,2} [A-Z][a-z]{2}( \d{4})? – \d{1,2} [A-Z][a-z]{2} \d{4}/);
		expect(ja).not.toMatch(/（[月火水木金土日]）/);
		expect(ja).toMatch(/\d{4}年\d{1,2}月\d{1,2}日〜/);
	});
});

test.describe('no raw keys, no fallback text, no mixed language (TC-I18N-011/013, AC-I18N-01-04, DEC-027)', () => {
	let EN: Record<string, string> = {};
	let CAT: Record<string, Record<string, string>> = {};
	test.beforeAll(() => {
		if (!HAS_WP) return;
		const out = wp('eval', 'echo wp_json_encode(array_map(fn($l)=>\\Momos\\Kernel\\I18n\\Strings::catalog($l), ["ja"=>"ja","en"=>"en","ar"=>"ar","ur"=>"ur","hi"=>"hi","bn"=>"bn"]));');
		CAT = JSON.parse(out);
		EN = CAT.en;
	});

	const RAW = /\b(res|menu|nav|common|branch|diet|legal|seo|a11y|status|cta|email|period|form|home|halal|offer|offers|contact|faq|lang|ftr|bc|anl|allergen|dish|news|gallery|about|err|error|footer|header|cookie|consent|privacy|corp|notice|spice|tag|cat|card|page|title|btn|label)\.[a-z][a-z0-9_]*(\.[a-z0-9_]+)*\b/;

	for (const lang of ALL) {
		test(`rendered pages + JS states — ${lang}`, async ({ page }) => {
			test.skip(!HAS_WP, 'needs MOMOS_DB_NAME for the catalogs');
			test.setTimeout(300_000);
			// English sentences (≥ 3 words, ≥ 18 chars) of the en catalog whose own-language value differs.
			const enSentences = Object.entries(EN).filter(([k, v]) => !k.startsWith('admin.') && !k.startsWith('staff.') && /\S+ \S+ \S+/.test(v) && v.length >= 18 && !/[{}]/.test(v))
				.map(([k, v]) => [k, v] as [string, string]);
			const bad: string[] = [];
			const scan = async (label: string) => {
				for (const x of await texts(page)) {
					const m = x.t.match(RAW);
					if (m && !/@|https?:|\.(test|com|jp|php|js|css|png|jpg|xml)\b/.test(x.t)) bad.push(`${label}: raw key "${m[0]}" (${x.where})`);
					if (lang !== 'en' && x.lang === lang) {
						for (const [k, v] of enSentences) if (x.t.includes(v) && CAT[lang]?.[k] !== v) bad.push(`${label}: English fallback of ${k} "${v.slice(0, 50)}" (${x.where})`);
						for (const [k, v] of enSentences) if (x.t.includes(v) && CAT[lang]?.[k] === v) bad.push(`${label}: ${k} untranslated in ${lang} catalog "${v.slice(0, 50)}"`);
					}
				}
			};
			for (const p of PAGES) {
				const res = await page.goto(`${BASE}${prefix(lang)}${p}`, { waitUntil: 'networkidle' });
				if (res?.status() === 404 && !p.startsWith('/no-such')) continue;
				await scan(`${lang}${p}`);
			}
			// JS-rendered states: reservation errors + summary, status shell.
			await page.goto(`${BASE}${prefix(lang)}/reservation/`, { waitUntil: 'networkidle' });
			await page.locator('[data-res-submit]').click();
			await scan(`${lang} C09 errors`);
			await page.selectOption('#res-branch', { index: 1 });
			await page.waitForTimeout(500);
			await scan(`${lang} C09 branch chosen`);
			await page.goto(`${BASE}${prefix(lang)}/reservation/status/ABCD2345/`, { waitUntil: 'networkidle' });
			await scan(`${lang} C11 invalid link`);
			expect([...new Set(bad)].slice(0, 40)).toEqual([]);
		});
	}
});

test.describe('RTL mirroring (TC-RTL-001, Doc 08 §6.1)', () => {
	for (const lang of ['ar', 'ur']) {
		test(`header, breadcrumbs, directional icons mirrored; phone/logo not — ${lang} @1280`, async ({ page }) => {
			await page.setViewportSize({ width: 1280, height: 900 });
			await page.goto(`${BASE}/${lang}/branches/demo-kitahama/`, { waitUntil: 'networkidle' });
			const r = await page.evaluate(() => {
				const box = (s: string) => document.querySelector(s)?.getBoundingClientRect();
				const logo = box('header .momo-logo'), cta = box('header .momo-header__cta');
				const crumbs = Array.from(document.querySelectorAll('nav.momo-breadcrumbs li')).map((li) => li.getBoundingClientRect().left);
				const dirIcons = Array.from(document.querySelectorAll('.momo-icon--dir')).filter((i) => (i as HTMLElement).getBoundingClientRect().width > 0)
					.map((i) => getComputedStyle(i).transform);
				const phoneIcons = Array.from(document.querySelectorAll('use[href*="#i-phone"]')).map((u) => getComputedStyle(u.closest('svg') as Element).transform);
				const logoT = getComputedStyle(document.querySelector('header .momo-logo svg') as Element).transform;
				return { logoRight: (logo?.left ?? 0) > (cta?.left ?? 0), crumbsRtl: crumbs.every((x, i) => i === 0 || x < crumbs[i - 1]), dirIcons, phoneIcons, logoT, n: crumbs.length };
			});
			expect(r.logoRight, 'logo at inline-start (right) of the CTA').toBe(true);
			expect(r.n).toBeGreaterThan(1);
			expect(r.crumbsRtl, 'breadcrumbs run right-to-left').toBe(true);
			const mirrored = (t: string) => /^matrix\(-1,/.test(t);
			expect(r.dirIcons.length).toBeGreaterThan(0);
			expect(r.dirIcons.every(mirrored), `directional icons mirrored: ${r.dirIcons.join(' ')}`).toBe(true);
			expect(r.phoneIcons.some(mirrored), 'phone icons are not mirrored').toBe(false);
			expect(mirrored(r.logoT), 'logo not mirrored').toBe(false);
		});
	}

	test('direction flips on every full load ar → en → ar (no cached opposite layout)', async ({ page }) => {
		for (const [l, d] of [['ar', 'rtl'], ['en', 'ltr'], ['ar', 'rtl']]) {
			await page.goto(`${BASE}/${l}/branches/`);
			await expect(page.locator('html')).toHaveAttribute('dir', d);
			expect(await page.evaluate(() => getComputedStyle(document.body).direction)).toBe(d);
		}
	});

	test('theme CSS uses logical properties only (stylelint rule TRD §3.6, ENGINEERING §5)', async ({ request }) => {
		const bad: string[] = [];
		for (const f of ['momos-ui.css', 'screens.css', 'reservation.css', 'tokens.css']) {
			const css = await (await request.get(`${BASE}/wp-content/themes/momos/assets/css/${f}`)).text();
			const re = /(?:^|[;{\s])(margin-left|margin-right|padding-left|padding-right|border-left|border-right|left|right|float)\s*:\s*([^;}]+)|text-align\s*:\s*(left|right)/g;
			for (let m = re.exec(css); m; m = re.exec(css)) {
				const prop = m[1] || 'text-align', val = (m[2] || m[3] || '').trim();
				if (prop === 'float' && val === 'none') continue;
				if (/^(0|auto)$/.test(val) && (prop === 'left' || prop === 'right')) { /* still physical */ }
				bad.push(`${f}: ${prop}: ${val}`);
			}
		}
		expect(bad).toEqual([]);
	});
});

test.describe('text alternatives (TC-A11Y-008)', () => {
	for (const lang of ['ja', 'en', 'ar']) {
		test(`dish photo alt, icon-only controls named, map has address text — ${lang}`, async ({ page }) => {
			const bad: string[] = [];
			await page.goto(`${BASE}${prefix(lang)}/menu/curry/butter-chicken-curry/`, { waitUntil: 'networkidle' });
			const hero = page.locator('main img').first();
			const alt = await hero.getAttribute('alt');
			if (!alt || !alt.trim()) bad.push('dish page main photo has empty alt (meaningful alt per language required)');
			for (const p of ['/', '/branches/demo-kitahama/', '/menu/curry/']) {
				await page.goto(`${BASE}${prefix(lang)}${p}`, { waitUntil: 'networkidle' });
				const unnamed = await page.evaluate(() => Array.from(document.querySelectorAll('a, button, summary')).filter((e) => {
					const h = e as HTMLElement;
					if (!h.offsetParent || h.closest('[hidden], [aria-hidden="true"]')) return false;
					const name = (h.getAttribute('aria-label') || h.getAttribute('title') || h.textContent || '').trim()
						|| Array.from(h.querySelectorAll('img')).map((i) => i.alt).join('').trim()
						|| (h.getAttribute('aria-labelledby') ? (document.getElementById(h.getAttribute('aria-labelledby') as string)?.textContent || '').trim() : '');
					return !name;
				}).map((e) => e.outerHTML.slice(0, 90)));
				for (const u of unnamed) bad.push(`${p}: unnamed control ${u}`);
			}
			expect(bad).toEqual([]);
		});
	}
});
