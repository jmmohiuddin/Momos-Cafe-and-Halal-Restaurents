/**
 * QA (performance team) — front-end budgets, lab Web Vitals, cache headers, staff list at design volume.
 *
 * Sources: Doc 04/09 NFR-PERF-01..04; Doc 12 TC-PERF-001 (lab CWV), TC-PERF-002 (TTFB, local proxy), TC-PERF-003
 * (page weight), TC-PERF-005 (inbox with 10,000 rows), TC-PERF-006 (cache correctness), TC-I18N-004 (fonts);
 * Doc 09 §1.9 U1 (momos-ui.css ≤ 50 KB minified / 10 KB gzip — CHG-006, was 30 KB) and §5 supporting budgets (momos JS ≤ 10 KB gzip on public pages,
 * ≤ 40 KB on the reservation page); Doc 08 §2.3.4 / i18n-seo-analytics §4.3 (per-language font budgets; ja ≤ 650 KB
 * per page — CHG-008),
 * Doc 08 §2.10 + ui-design-system §10 (hero ≤ 200 KB, cards ≤ 80 KB, WebP/AVIF, explicit width/height,
 * hero fetchpriority=high, others lazy), gaps.md §4.1 (Cache-Control per response type), SEC-032.
 *
 * Measurement: Chromium + Chrome DevTools Protocol (Network.loadingFinished encodedDataLength, PerformanceObserver
 * for LCP / layout-shift / longtask), mobile emulation (Pixel 5), browser cache disabled. The PHP dev server does
 * not compress, so HTML/CSS/JS are counted at their gzip size (production serves gzip/Brotli, TRD H-rules);
 * fonts and images are counted as transferred. Lab throttling approximates the Lighthouse mobile preset
 * (4× CPU, 150 ms RTT, 1.6 Mbps): numbers are indicative on a shared CI box; budgets are the documented ones.
 */
import { test, expect, Browser, BrowserContext, Page, devices, request as pwRequest } from '@playwright/test';
import { execFileSync } from 'child_process';
import * as fs from 'fs';
import * as os from 'os';
import * as path from 'path';
import * as zlib from 'zlib';

const BASE = process.env.MOMOS_E2E_BASE_URL || process.env.MOMOS_URL || 'http://127.0.0.1:8080';
const ROOT = path.resolve(__dirname, '../../..');
const HAS_WP = !!process.env.MOMOS_DB_NAME;
const LANGS = ['ja', 'en', 'ar', 'ur', 'hi', 'bn'] as const;
type Lang = (typeof LANGS)[number];
const prefix = (l: string) => (l === 'ja' ? '' : `/${l}`);
const PASSWORD = process.env.MOMOS_DEMO_PASSWORD || 'Momos-Dev-2026!';
const KB = 1024;

function wp(...args: string[]): string {
	return execFileSync(path.join(ROOT, 'bin/wp'), args, { env: process.env, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
}

/** Key templates (NFR-PERF-01: home, branch, menu category, dish, reservation; offers measured as well [Rec]). */
const TEMPLATES: Record<string, string> = {
	home: '/',
	branch: '/branches/demo-kitahama/',
	category: '/menu/curry/',
	dish: '/menu/curry/butter-chicken-curry/',
	reservation: '/reservation/',
	offers: '/offers/',
};

/** Allowed font families per page language (i18n-seo-analytics §4.3, TC-I18N-004). */
const FONT_RULES: Record<Lang, { families: string[]; scriptBudget: number; latinBudget: number; maxFiles: number | null }> = {
	en: { families: ['epilogue', 'plus-jakarta-sans'], scriptBudget: 0, latinBudget: 140 * KB, maxFiles: 6 },
	// CHG-008 (CTO interim approval, QA-054): Japanese ≤ 650 KB per page (Noto Sans JP 400 + 700 in unicode-range
	// slices) instead of the provisional 300 KB; the other languages keep their Doc 08 §2.3.4 budgets.
	ja: { families: ['noto-sans-jp'], scriptBudget: 650 * KB, latinBudget: 0, maxFiles: null /* §21 C-9: unicode-range slices */ },
	ar: { families: ['noto-naskh-arabic', 'plus-jakarta-sans'], scriptBudget: 120 * KB, latinBudget: 70 * KB, maxFiles: 6 },
	ur: { families: ['noto-nastaliq-urdu', 'plus-jakarta-sans'], scriptBudget: 450 * KB, latinBudget: 70 * KB, maxFiles: 6 },
	hi: { families: ['noto-sans-devanagari', 'plus-jakarta-sans'], scriptBudget: 160 * KB, latinBudget: 70 * KB, maxFiles: 6 },
	bn: { families: ['noto-sans-bengali', 'plus-jakarta-sans'], scriptBudget: 180 * KB, latinBudget: 70 * KB, maxFiles: 6 },
};
const LATIN = new Set(['epilogue', 'plus-jakarta-sans']);

type Res = { url: string; type: string; mime: string; status: number; bytes: number; gz?: number };
type Shot = {
	url: string;
	status: number;
	res: Res[];
	weight: number;
	lcp: { t: number; tag: string | null; url: string; fp: string | null; loading: string | null } | null;
	imgs: Array<{ src: string; w: string | null; h: string | null; loading: string | null; fp: string | null; srcset: boolean; sizes: string | null }>;
};

async function newMobile(browser: Browser, locale = 'ja-JP'): Promise<BrowserContext> {
	const { defaultBrowserType, ...pixel } = devices['Pixel 5'];
	void defaultBrowserType;
	return browser.newContext({ ...pixel, locale });
}

const GZ = new Map<string, number>();
async function gzipSize(ctx: BrowserContext, url: string): Promise<number> {
	if (!GZ.has(url)) GZ.set(url, zlib.gzipSync(await (await ctx.request.get(url)).body(), { level: 6 }).length);
	return GZ.get(url)!;
}

const SHOTS = new Map<string, Shot>();
/** Load a page cold (cache disabled) in mobile emulation and record every response + LCP + images. */
async function shot(browser: Browser, lang: Lang, p: string): Promise<Shot> {
	const key = `${lang}${p}`;
	if (SHOTS.has(key)) return SHOTS.get(key)!;
	const ctx = await newMobile(browser, lang === 'ja' ? 'ja-JP' : lang);
	const page = await ctx.newPage();
	const cdp = await ctx.newCDPSession(page);
	await cdp.send('Network.enable');
	await cdp.send('Network.setCacheDisabled', { cacheDisabled: true });
	const byId = new Map<string, Res>();
	cdp.on('Network.responseReceived', (e: any) => byId.set(e.requestId, { url: e.response.url, type: e.type, mime: e.response.mimeType, status: e.response.status, bytes: 0 }));
	cdp.on('Network.loadingFinished', (e: any) => { const r = byId.get(e.requestId); if (r) r.bytes = e.encodedDataLength; });
	await page.addInitScript(() => {
		(window as any).__lcp = null;
		new PerformanceObserver((l) => {
			for (const e of l.getEntries() as any[]) {
				const el = e.element as Element | null;
				(window as any).__lcp = { t: e.startTime, tag: el ? el.tagName : null, url: e.url || '', fp: el ? el.getAttribute('fetchpriority') : null, loading: el ? el.getAttribute('loading') : null };
			}
		}).observe({ type: 'largest-contentful-paint', buffered: true });
	});
	const resp = await page.goto(BASE + prefix(lang) + p, { waitUntil: 'load' });
	await page.waitForTimeout(800);
	const data = await page.evaluate(() => ({
		lcp: (window as any).__lcp,
		imgs: [...document.images].map((i) => ({ src: i.currentSrc || i.src, w: i.getAttribute('width'), h: i.getAttribute('height'), loading: i.getAttribute('loading'), fp: i.getAttribute('fetchpriority'), srcset: !!i.getAttribute('srcset'), sizes: i.getAttribute('sizes') })),
	}));
	const res = [...byId.values()].filter((r) => !r.url.startsWith('data:'));
	let weight = 0;
	for (const r of res) {
		if (['Document', 'Stylesheet', 'Script'].includes(r.type) && r.url.startsWith(BASE)) r.gz = await gzipSize(ctx, r.url);
		weight += r.gz ?? r.bytes;
	}
	await ctx.close();
	const s: Shot = { url: BASE + prefix(lang) + p, status: resp?.status() ?? 0, res, weight, lcp: data.lcp, imgs: data.imgs };
	SHOTS.set(key, s);
	return s;
}

const file = (u: string) => u.split('?')[0].split('/').pop() || u;
const family = (u: string) => (u.match(/\/fonts\/([^/]+)\//) || [])[1] || file(u);

// ------------------------------------------------------------------------------------------------------------
test.describe('TC-PERF-003 / TC-I18N-004 — page weight, fonts and images per language (mobile, cold cache)', () => {
	for (const lang of LANGS) {
		test(`TC-PERF-003 initial load ≤ 1.5 MB on every key template — ${lang}`, async ({ browser }) => {
			test.setTimeout(240_000);
			const problems: string[] = [];
			for (const [name, p] of Object.entries(TEMPLATES)) {
				const s = await shot(browser, lang, p);
				expect(s.status, `${name} ${s.url}`).toBe(200);
				if (s.weight > 1.5 * KB * KB) problems.push(`${name}: ${(s.weight / KB).toFixed(0)} KB > 1536 KB`);
			}
			expect(problems).toEqual([]);
		});

		test(`TC-I18N-004 fonts: own origin, current language only, ≤ files/bytes budget — ${lang}`, async ({ browser }) => {
			test.setTimeout(240_000);
			const rule = FONT_RULES[lang];
			const problems: string[] = [];
			for (const [name, p] of Object.entries(TEMPLATES)) {
				const s = await shot(browser, lang, p);
				const fonts = s.res.filter((r) => r.type === 'Font');
				const fams = new Set(fonts.map((f) => family(f.url)));
				for (const f of fonts) if (!f.url.startsWith(BASE)) problems.push(`${name}: third-party font ${f.url}`);
				for (const fam of fams) if (!rule.families.includes(fam)) problems.push(`${name}: font family '${fam}' not allowed on ${lang} pages`);
				if (fams.size > 3) problems.push(`${name}: ${fams.size} families > 3 (DEC-061)`);
				if (rule.maxFiles !== null && fonts.length > rule.maxFiles) problems.push(`${name}: ${fonts.length} font files > ${rule.maxFiles}`);
				const latin = fonts.filter((f) => LATIN.has(family(f.url))).reduce((a, f) => a + f.bytes, 0);
				const script = fonts.filter((f) => !LATIN.has(family(f.url))).reduce((a, f) => a + f.bytes, 0);
				if (lang === 'en') {
					if (latin > rule.latinBudget) problems.push(`${name}: Latin fonts ${(latin / KB).toFixed(0)} KB > ${rule.latinBudget / KB} KB`);
				} else {
					if (script > rule.scriptBudget) problems.push(`${name}: ${lang} script fonts ${(script / KB).toFixed(0)} KB in ${fonts.length - [...fonts].filter((f) => LATIN.has(family(f.url))).length} files > ${rule.scriptBudget / KB} KB (Doc 08 §2.3.4)`);
					if (latin > rule.latinBudget) problems.push(`${name}: Latin text faces ${(latin / KB).toFixed(0)} KB > ${rule.latinBudget / KB} KB`);
				}
			}
			expect(problems).toEqual([]);
		});

		test(`TC-PERF-003 images: hero ≤ 200 KB, cards ≤ 80 KB, WebP/AVIF, width/height set — ${lang}`, async ({ browser }) => {
			test.setTimeout(240_000);
			const problems: string[] = [];
			for (const [name, p] of Object.entries(TEMPLATES)) {
				const s = await shot(browser, lang, p);
				const heroSrc = s.lcp && s.lcp.tag === 'IMG' && s.lcp.fp === 'high' ? s.lcp.url : '';
				for (const r of s.res.filter((x) => x.type === 'Image' && /\/uploads\//.test(x.url))) {
					const isHero = r.url === heroSrc;
					const budget = isHero ? 200 * KB : 80 * KB;
					if (r.bytes > budget) problems.push(`${name}: ${isHero ? 'hero' : 'card/content'} image ${file(r.url)} ${(r.bytes / KB).toFixed(0)} KB > ${budget / KB} KB`);
					if (!/image\/(webp|avif)/.test(r.mime)) problems.push(`${name}: ${file(r.url)} served as ${r.mime} (WebP/AVIF required)`);
				}
				for (const i of s.imgs.filter((x) => /\/uploads\//.test(x.src))) {
					if (!i.w || !i.h) problems.push(`${name}: <img ${file(i.src)}> without width/height`);
					if (!i.srcset) problems.push(`${name}: <img ${file(i.src)}> without srcset`);
				}
			}
			expect([...new Set(problems)]).toEqual([]);
		});
	}

	test('NFR-PERF-01 / ui-design-system §10: an LCP image is eager with fetchpriority=high (never lazy) — ja, en', async ({ browser }) => {
		test.setTimeout(240_000);
		const problems: string[] = [];
		for (const lang of ['ja', 'en'] as Lang[]) {
			for (const [name, p] of Object.entries({ ...TEMPLATES, 'menu hub': '/menu/' })) {
				const s = await shot(browser, lang, p);
				if (s.lcp && s.lcp.tag === 'IMG') {
					if (s.lcp.loading === 'lazy') problems.push(`${lang} ${name}: LCP image ${file(s.lcp.url)} has loading="lazy"`);
					if (s.lcp.fp !== 'high') problems.push(`${lang} ${name}: LCP image ${file(s.lcp.url)} without fetchpriority="high"`);
				}
			}
		}
		expect(problems).toEqual([]);
	});
});

// ------------------------------------------------------------------------------------------------------------
test.describe('Doc 09 U1 and §5 — CSS and JS budgets', () => {
	// CHG-006 (CTO approved, QA-056): budget raised from 30 KB to 50 KB minified / 10 KB gzip.
	test('U1: momos-ui.css ≤ 50 KB minified / 10 KB gzip (CHG-006)', async ({ request }) => {
		const html = await (await request.get(`${BASE}/`)).text();
		const href = (html.match(/href='([^']*momos-ui\.css[^']*)'/) || [])[1];
		expect(href, 'momos-ui.css enqueued').toBeTruthy();
		const css = await (await request.get(href)).text();
		const min = css.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\s+/g, ' ').replace(/\s*([{};:,>])\s*/g, '$1').replace(/;}/g, '}').trim();
		expect(Buffer.byteLength(min), `momos-ui.css minified ${(Buffer.byteLength(min) / KB).toFixed(1)} KB (raw ${(Buffer.byteLength(css) / KB).toFixed(1)} KB)`).toBeLessThanOrEqual(50 * KB);
		const gz = zlib.gzipSync(Buffer.from(min), { level: 9 }).length;
		expect(gz, `momos-ui.css minified + gzip ${(gz / KB).toFixed(1)} KB`).toBeLessThanOrEqual(10 * KB);
	});

	test('render-blocking font CSS stays small (fonts-{lang}.css ≤ 14 KB gzip) — every language', async ({ request }) => {
		// Doc 08 §2.3.4: per-language @font-face only; a large render-blocking stylesheet delays FCP/LCP and adds main-thread parse time (TBT).
		const problems: string[] = [];
		for (const lang of LANGS) {
			const html = await (await request.get(`${BASE}${prefix(lang)}/`)).text();
			const href = (html.match(/href='([^']*fonts-[a-z]{2}\.css[^']*)'/) || [])[1];
			if (!href) { problems.push(`${lang}: no fonts css`); continue; }
			const body = await (await request.get(href)).body();
			const gz = zlib.gzipSync(body, { level: 9 }).length;
			if (gz > 14 * KB) problems.push(`${lang}: ${file(href)} ${(body.length / KB).toFixed(0)} KB raw / ${(gz / KB).toFixed(1)} KB gzip, render-blocking`);
		}
		expect(problems).toEqual([]);
	});

	test('TRD §5: momos JS ≤ 10 KB gzip on public pages, ≤ 40 KB on the reservation page', async ({ browser }) => {
		test.setTimeout(240_000);
		const problems: string[] = [];
		for (const [name, p] of Object.entries(TEMPLATES)) {
			const s = await shot(browser, 'en', p);
			const js = s.res.filter((r) => r.type === 'Script' && r.url.startsWith(BASE) && /\/(themes\/momos|plugins\/momos-core)\//.test(r.url));
			const gz = js.reduce((a, r) => a + (r.gz ?? r.bytes), 0);
			const budget = name === 'reservation' ? 40 * KB : 10 * KB;
			if (gz > budget) problems.push(`${name}: ${(gz / KB).toFixed(1)} KB gzip > ${budget / KB} KB (${js.map((r) => file(r.url)).join(', ')})`);
		}
		expect(problems).toEqual([]);
	});
});

// ------------------------------------------------------------------------------------------------------------
type Lab = { cls: number; tbt: number; lcp: number; shifts: string[] };
async function labRun(browser: Browser, url: string, locale: string, network: boolean): Promise<Lab> {
	const ctx = await newMobile(browser, locale);
	const page = await ctx.newPage();
	const cdp = await ctx.newCDPSession(page);
	await cdp.send('Network.enable');
	await cdp.send('Network.setCacheDisabled', { cacheDisabled: true });
	await cdp.send('Emulation.setCPUThrottlingRate', { rate: 4 });
	if (network) await cdp.send('Network.emulateNetworkConditions', { offline: false, latency: 150, downloadThroughput: (1.6 * 1024 * 1024) / 8, uploadThroughput: (750 * 1024) / 8 });
	await page.addInitScript(() => {
		const w = window as any;
		w.__lab = { cls: 0, lt: [] as number[], lcp: 0, shifts: [] as string[] };
		new PerformanceObserver((l) => { for (const e of l.getEntries() as any[]) if (!e.hadRecentInput) { w.__lab.cls += e.value; w.__lab.shifts.push(e.value.toFixed(3) + ':' + (e.sources || []).map((s: any) => (s.node && (s.node.className || s.node.nodeName)) || '?').join('|')); } }).observe({ type: 'layout-shift', buffered: true });
		new PerformanceObserver((l) => { for (const e of l.getEntries()) w.__lab.lt.push(e.duration); }).observe({ type: 'longtask', buffered: true });
		new PerformanceObserver((l) => { for (const e of l.getEntries()) w.__lab.lcp = e.startTime; }).observe({ type: 'largest-contentful-paint', buffered: true });
	});
	await page.goto(url, { waitUntil: 'load', timeout: 60_000 });
	await page.waitForTimeout(1500);
	const r = await page.evaluate(() => (window as any).__lab);
	await ctx.close();
	return { cls: r.cls, tbt: r.lt.reduce((a: number, d: number) => a + Math.max(0, d - 50), 0), lcp: r.lcp, shifts: r.shifts };
}
const median = (xs: number[]) => [...xs].sort((a, b) => a - b)[Math.floor(xs.length / 2)];

test.describe('TC-PERF-001 lab Web Vitals (4× CPU, 150 ms / 1.6 Mbps), median of 3', () => {
	const LAB = ['home', 'branch', 'category', 'dish', 'reservation'];
	for (const lang of ['ja', 'en', 'ar', 'ur'] as Lang[]) {
		test(`CLS ≤ 0.1 on key templates (browser language = page language) — ${lang}`, async ({ browser }) => {
			test.setTimeout(600_000);
			const problems: string[] = [];
			for (const name of LAB) {
				const runs: Lab[] = [];
				for (let i = 0; i < 3; i++) runs.push(await labRun(browser, BASE + prefix(lang) + TEMPLATES[name], lang === 'ja' ? 'ja-JP' : lang, true));
				const cls = median(runs.map((r) => r.cls));
				if (cls > 0.1) problems.push(`${name}: CLS ${cls.toFixed(3)} (runs ${runs.map((r) => r.cls.toFixed(3)).join('/')}; shifts ${runs.map((r) => r.shifts.join(' ')).join(' || ')})`);
			}
			expect(problems).toEqual([]);
		});
	}

	test('CLS ≤ 0.1 for a tourist (English browser) on Japanese pages — language-suggestion bar + font swap', async ({ browser }) => {
		test.setTimeout(600_000);
		const problems: string[] = [];
		for (const name of ['home', 'branch']) {
			const runs: Lab[] = [];
			for (let i = 0; i < 3; i++) runs.push(await labRun(browser, BASE + TEMPLATES[name], 'en-US', true));
			const cls = median(runs.map((r) => r.cls));
			if (cls > 0.1) problems.push(`ja ${name}: CLS ${cls.toFixed(3)} (runs ${runs.map((r) => r.cls.toFixed(3)).join('/')}; shifts ${runs.map((r) => r.shifts.join(' ')).join(' || ')})`);
		}
		expect(problems).toEqual([]);
	});

	test('TBT ≤ 200 ms on home and dish (4× CPU) — ja, en, ar, ur', async ({ browser }) => {
		test.setTimeout(600_000);
		const problems: string[] = [];
		for (const lang of ['ja', 'en', 'ar', 'ur'] as Lang[]) {
			for (const name of ['home', 'dish']) {
				const runs: Lab[] = [];
				for (let i = 0; i < 3; i++) runs.push(await labRun(browser, BASE + prefix(lang) + TEMPLATES[name], lang === 'ja' ? 'ja-JP' : lang, false));
				const tbt = median(runs.map((r) => r.tbt));
				if (tbt > 200) problems.push(`${lang} ${name}: TBT ${tbt.toFixed(0)} ms (runs ${runs.map((r) => r.tbt.toFixed(0)).join('/')})`);
			}
		}
		expect(problems).toEqual([]);
	});

	test('LCP ≤ 2.5 s on offers (lazy LCP image) — en, ur', async ({ browser }) => {
		test.setTimeout(600_000);
		const problems: string[] = [];
		for (const lang of ['en', 'ur'] as Lang[]) {
			const runs: Lab[] = [];
			for (let i = 0; i < 3; i++) runs.push(await labRun(browser, BASE + prefix(lang) + TEMPLATES.offers, lang, true));
			const lcp = median(runs.map((r) => r.lcp));
			if (lcp > 2500) problems.push(`${lang} offers: LCP ${lcp.toFixed(0)} ms (runs ${runs.map((r) => r.lcp.toFixed(0)).join('/')})`);
		}
		expect(problems).toEqual([]);
	});
});

// ------------------------------------------------------------------------------------------------------------
/**
 * Deterministic CLS reproduction (no CPU throttling): web fonts and scripts arrive 1.5 s after the HTML — what a
 * slow mobile connection does. Doc 07 §"Performance": space is reserved for images, maps and fonts to avoid layout
 * shift; NFR-PERF-01 CLS ≤ 0.1. Late fonts expose missing fallback metrics (size-adjust); late scripts expose
 * content that is hidden server-side and revealed by JavaScript without reserved space.
 */
async function lateCls(browser: Browser, url: string, locale: string, delay: { fonts: boolean; scripts: boolean }): Promise<Lab> {
	const ctx = await newMobile(browser, locale);
	const page = await ctx.newPage();
	const hold = (ms: number) => new Promise((r) => setTimeout(r, ms));
	if (delay.fonts) await page.route(/\.woff2(\?|$)/, async (r) => { await hold(1500); await r.continue(); });
	if (delay.scripts) await page.route(/\/wp-content\/.*\.js(\?|$)/, async (r) => { await hold(1500); await r.continue(); });
	await page.addInitScript(() => {
		const w = window as any;
		w.__lab = { cls: 0, shifts: [] as string[] };
		new PerformanceObserver((l) => { for (const e of l.getEntries() as any[]) if (!e.hadRecentInput) { w.__lab.cls += e.value; w.__lab.shifts.push(e.value.toFixed(3) + ':' + (e.sources || []).map((s: any) => (s.node && (s.node.className || s.node.nodeName)) || '?').join('|')); } }).observe({ type: 'layout-shift', buffered: true });
	});
	await page.goto(url, { waitUntil: 'load', timeout: 60_000 });
	await page.waitForTimeout(1000);
	const r = await page.evaluate(() => (window as any).__lab);
	await ctx.close();
	return { cls: r.cls, tbt: 0, lcp: 0, shifts: r.shifts };
}

test.describe('NFR-PERF-01 CLS ≤ 0.1 when fonts / scripts arrive late (deterministic)', () => {
	for (const lang of ['ja', 'en', 'ar', 'ur'] as Lang[]) {
		test(`late web fonts (1.5 s): no font-swap layout shift on key templates — ${lang}`, async ({ browser }) => {
			test.setTimeout(300_000);
			const problems: string[] = [];
			for (const name of ['home', 'branch', 'category', 'dish', 'reservation']) {
				const r = await lateCls(browser, BASE + prefix(lang) + TEMPLATES[name], lang === 'ja' ? 'ja-JP' : lang, { fonts: true, scripts: false });
				if (r.cls > 0.1) problems.push(`${name}: CLS ${r.cls.toFixed(3)} [${r.shifts.join(' ')}]`);
			}
			expect(problems).toEqual([]);
		});
		test(`late scripts (1.5 s): no layout shift when JavaScript initialises — ${lang}`, async ({ browser }) => {
			test.setTimeout(300_000);
			const problems: string[] = [];
			for (const name of ['home', 'branch', 'category', 'dish', 'reservation']) {
				const r = await lateCls(browser, BASE + prefix(lang) + TEMPLATES[name], lang === 'ja' ? 'ja-JP' : lang, { fonts: false, scripts: true });
				if (r.cls > 0.1) problems.push(`${name}: CLS ${r.cls.toFixed(3)} [${r.shifts.join(' ')}]`);
			}
			expect(problems).toEqual([]);
		});
	}
	test('tourist with an English browser on Japanese pages, late fonts + scripts: suggestion bar + font swap ≤ 0.1', async ({ browser }) => {
		test.setTimeout(300_000);
		const problems: string[] = [];
		for (const name of ['home', 'branch', 'category', 'dish']) {
			const r = await lateCls(browser, BASE + TEMPLATES[name], 'en-US', { fonts: true, scripts: true });
			if (r.cls > 0.1) problems.push(`ja ${name}: CLS ${r.cls.toFixed(3)} [${r.shifts.join(' ')}]`);
		}
		expect(problems).toEqual([]);
	});
});

// ------------------------------------------------------------------------------------------------------------
test.describe('home hero image (Doc 08 §2.10, screens.md C01 "LCP = hero image fetchpriority=high")', () => {
	test.skip(!HAS_WP, 'needs WP-CLI access to the seeded site (MOMOS_DB_NAME)');
	test('hero is eager, fetchpriority=high, the LCP element, ≤ 200 KB and WebP/AVIF', async ({ browser }) => {
		test.setTimeout(180_000);
		const home = wp('eval', 'echo (int) \\Momos\\Presentation\\SystemPages::id("home");').trim();
		let before = '';
		try { before = wp('post', 'meta', 'get', home, '_thumbnail_id').trim(); } catch { /* no featured image (seed default) */ }
		const att = wp('post', 'list', '--post_type=attachment', '--post_status=inherit', '--name=demo-gallery-curry-naan-spread-10', '--field=ID', '--format=ids').trim().split(/\s+/)[0]
			|| wp('post', 'list', '--post_type=attachment', '--post_status=inherit', '--field=ID', '--format=ids', '--posts_per_page=1').trim().split(/\s+/)[0];
		wp('post', 'meta', 'update', home, '_thumbnail_id', att);
		try {
			SHOTS.delete('ja/');
			const s = await shot(browser, 'ja', '/');
			const hero = s.imgs.find((i) => i.fp === 'high');
			expect(hero, 'hero <img fetchpriority="high"> rendered').toBeTruthy();
			expect(hero!.loading).not.toBe('lazy');
			expect(s.lcp?.tag, 'LCP element is the hero image').toBe('IMG');
			const r = s.res.find((x) => x.url === hero!.src);
			expect(r, 'hero response recorded').toBeTruthy();
			const problems: string[] = [];
			if (r!.bytes > 200 * KB) problems.push(`hero ${file(r!.url)} ${(r!.bytes / KB).toFixed(0)} KB > 200 KB`);
			if (!/image\/(webp|avif)/.test(r!.mime)) problems.push(`hero ${file(r!.url)} served as ${r!.mime} (WebP/AVIF required)`);
			expect(problems).toEqual([]);
		} finally {
			if (before) wp('post', 'meta', 'update', home, '_thumbnail_id', before);
			else wp('post', 'meta', 'delete', home, '_thumbnail_id');
			SHOTS.delete('ja/');
		}
	});
});

// ------------------------------------------------------------------------------------------------------------
test.describe('TC-PERF-006 cache correctness (gaps.md §4.1, SEC-032)', () => {
	const PUBLIC = ['/', '/branches/', '/branches/demo-kitahama/', '/menu/', '/menu/curry/', '/menu/curry/butter-chicken-curry/', '/reservation/', '/offers/', '/halal/', '/about/'];
	for (const lang of LANGS) {
		test(`anonymous public pages are shared-cacheable; result/status pages and 404 are no-store — ${lang}`, async ({ request }) => {
			const problems: string[] = [];
			for (const p of PUBLIC) {
				const r = await request.get(BASE + prefix(lang) + p, { maxRedirects: 0 });
				const cc = r.headers()['cache-control'] || '';
				if (r.status() !== 200) problems.push(`${p}: ${r.status()}`);
				else if (!/public/.test(cc) || !/s-maxage=\d+/.test(cc) || /no-store|private/.test(cc)) problems.push(`${p}: Cache-Control '${cc}' (want public, s-maxage)`);
			}
			for (const p of ['/reservation/submitted/', '/reservation/status/ABCD2345/', '/no-such-page-qa-perf/']) {
				const r = await request.get(BASE + prefix(lang) + p, { maxRedirects: 0 });
				const cc = r.headers()['cache-control'] || '';
				if (!/no-store/.test(cc) || /public/.test(cc)) problems.push(`${p}: Cache-Control '${cc}' (want no-store)`);
			}
			expect(problems).toEqual([]);
		});
	}

	test('API-01 public 5 min; momos/v1 staff + POST API-02 no-store; logged-in front-end and admin no-store', async () => {
		const anon = await pwRequest.newContext();
		const problems: string[] = [];
		const api01 = await anon.get(`${BASE}/wp-json/momos/v1/branches/DKH/reservation-rules`);
		if (api01.status() === 200 && !/public, max-age=300/.test(api01.headers()['cache-control'] || '')) problems.push(`API-01: '${api01.headers()['cache-control']}'`);
		const staff = await anon.get(`${BASE}/wp-json/momos/v1/staff/reservations`);
		if (!/no-store/.test(staff.headers()['cache-control'] || '')) problems.push(`API-10 (anon): '${staff.headers()['cache-control']}'`);
		const post = await anon.post(`${BASE}/wp-json/momos/v1/reservations`, { data: {} });
		if (!/no-store/.test(post.headers()['cache-control'] || '')) problems.push(`API-02 POST: '${post.headers()['cache-control']}'`);
		await anon.dispose();

		const ctx = await pwRequest.newContext();
		await ctx.get(`${BASE}/wp-login.php`);
		const login = await ctx.post(`${BASE}/wp-login.php`, { form: { log: 'demo_bm_kitahama', pwd: PASSWORD, 'wp-submit': 'Log In', testcookie: '1' }, maxRedirects: 0 });
		expect([302, 303]).toContain(login.status());
		for (const p of ['/', '/en/menu/curry/', '/branches/demo-kitahama/', '/wp-admin/admin.php?page=momos-reservations']) {
			const r = await ctx.get(BASE + p, { maxRedirects: 0 });
			const cc = r.headers()['cache-control'] || '';
			if (!/no-store/.test(cc) || /public/.test(cc)) problems.push(`logged-in ${p}: '${cc}'`);
		}
		await ctx.dispose();
		expect(problems).toEqual([]);
	});
});

// ------------------------------------------------------------------------------------------------------------
test.describe('TC-PERF-002 (local proxy) — warm server time per template, p75 of 9', () => {
	test('server time to first byte p75 ≤ 800 ms per key template (ja, en)', async ({ request }) => {
		test.setTimeout(180_000);
		const problems: string[] = [];
		for (const lang of ['ja', 'en']) {
			for (const [name, p] of Object.entries(TEMPLATES)) {
				const url = BASE + prefix(lang) + p;
				await request.get(url); // warm
				const ts: number[] = [];
				for (let i = 0; i < 9; i++) {
					const t0 = performance.now();
					await request.get(url);
					ts.push(performance.now() - t0);
				}
				ts.sort((a, b) => a - b);
				const p75 = ts[Math.ceil(0.75 * ts.length) - 1];
				if (p75 > 800) problems.push(`${lang} ${name}: p75 ${p75.toFixed(0)} ms`);
			}
		}
		expect(problems).toEqual([]);
	});
});

// ------------------------------------------------------------------------------------------------------------
test.describe('TC-PERF-005 / NFR-PERF-04 — staff inbox (API-10, SCR-A08) with 10,000 reservations', () => {
	test.skip(!HAS_WP, 'needs WP-CLI access to the seeded site (MOMOS_DB_NAME)');

	test.beforeAll(() => {
		const n = parseInt(wp('db', 'query', 'SELECT COUNT(*) FROM wp_momo_reservations', '--skip-column-names').trim(), 10);
		if (n >= 10_000) return;
		const php = `<?php
global $wpdb; $t = $wpdb->prefix . 'momo_reservations';
$br = array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type='momo_branch' AND post_status='publish'" ) );
$st = [ 'completed','completed','completed','confirmed','confirmed','pending','pending','rejected','cancelled','expired','no_show' ];
$rows = []; $now = time(); $need = 10000 - ${n};
for ( $i = 1; $i <= $need; $i++ ) {
  $s = $st[ $i % count( $st ) ]; $d = in_array( $s, [ 'pending','confirmed' ], true ) ? ( $i % 60 ) + 1 : -( ( $i % 365 ) + 1 );
  $start = $now + $d * 86400 + ( $i % 10 ) * 1800; $c = min( $start - 86400 * ( 1 + $i % 14 ), $now - 60 );
  $rows[] = $wpdb->prepare( '(%s,%s,%d,%s,%s,%s,%s,%d,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)', strtoupper( substr( 'P' . md5( 'qaperf' . $i . microtime() ), 0, 8 ) ), wp_generate_uuid4(), $br[ $i % count( $br ) ], $s, gmdate( 'Y-m-d', $start + 32400 ), gmdate( 'H:i:00', $start + 32400 ), gmdate( 'Y-m-d H:i:s', $start ), 1 + $i % 8, 'Perf Guest ' . $i, 'perf' . $i . '@example.com', 'ja', 'ja', 'PP1.0-RP1.0', gmdate( 'Y-m-d H:i:s', $c ), hash( 'sha256', 't' . $i ), gmdate( 'Y-m-d H:i:s', $start + 2592000 ), gmdate( 'Y-m-d H:i:s', $c ), gmdate( 'Y-m-d H:i:s', $c + 600 ), 'QAPERF' );
  if ( count( $rows ) >= 500 || $i === $need ) { $wpdb->query( "INSERT INTO {$t} (public_ref,idempotency_key,branch_id,status,requested_date,requested_time,requested_start_utc,party_size,guest_name,email,preferred_lang,source_lang,consent_version,consent_at,token_hash,token_expires_utc,created_at,updated_at,external_ref) VALUES " . implode( ',', $rows ) ); $rows = []; }
}
echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" );`;
		const f = path.join(os.tmpdir(), `qa-perf-gen-${process.pid}.php`);
		fs.writeFileSync(f, php);
		try { wp('eval-file', f); } finally { fs.unlinkSync(f); }
	});

	// E2E isolation: remove the generated rows (external_ref QAPERF) again, so later specs do not find thousands of
	// synthetic reservations (e.g. qa-staff-daily-tasks: a closure day would turn into the "affected reservations" step).
	test.afterAll(() => {
		wp('eval', `global $wpdb; $r = \\Momos\\Kernel\\Db\\Tables::reservations(); $ids = "SELECT id FROM {$r} WHERE external_ref = 'QAPERF'";
			foreach ( [ \\Momos\\Kernel\\Db\\Tables::notifications(), \\Momos\\Kernel\\Db\\Tables::events() ] as $t ) { $wpdb->query( "DELETE FROM {$t} WHERE reservation_id IN ( SELECT id FROM ( {$ids} ) x )" ); }
			echo (int) $wpdb->query( "DELETE FROM {$r} WHERE external_ref = 'QAPERF'" );`);
	});

	for (const who of ['demo_ho', 'demo_bm_kitahama']) {
		test(`API-10 default view and filters p95 ≤ 2 s; inbox page ≤ 2 s — ${who}`, async () => {
			test.setTimeout(240_000);
			const ctx = await pwRequest.newContext();
			await ctx.get(`${BASE}/wp-login.php`);
			await ctx.post(`${BASE}/wp-login.php`, { form: { log: who, pwd: PASSWORD, 'wp-submit': 'Log In', testcookie: '1' }, maxRedirects: 0 });
			const nonce = (await (await ctx.get(`${BASE}/wp-admin/admin-ajax.php?action=rest-nonce`)).text()).trim();
			expect(nonce).toMatch(/^[0-9a-f]{10}$/);
			const queries = ['', '?status=all', '?status=all&page=40', '?status=all&q=Guest%201', '?status=confirmed&date_from=2026-11-19&date_to=2026-12-31', '?status=pending,confirmed&flags=needs_action', '?status=all&sort=created_at&order=desc', '?status=all&has_special_request=1'];
			const ts: number[] = [];
			for (let round = 0; round < 3; round++) {
				for (const q of queries) {
					const t0 = performance.now();
					const r = await ctx.get(`${BASE}/wp-json/momos/v1/staff/reservations${q}`, { headers: { 'X-WP-Nonce': nonce } });
					ts.push(performance.now() - t0);
					expect(r.status(), `API-10${q}`).toBe(200);
				}
			}
			ts.sort((a, b) => a - b);
			const p95 = ts[Math.ceil(0.95 * ts.length) - 1];
			expect(p95, `API-10 p95 ${p95.toFixed(0)} ms over ${ts.length} requests`).toBeLessThanOrEqual(2000);
			for (const v of ['', '&view=all', '&view=all&paged=50', '&view=today', '&view=needs']) {
				const t0 = performance.now();
				const r = await ctx.get(`${BASE}/wp-admin/admin.php?page=momos-reservations${v}`);
				const ms = performance.now() - t0;
				expect(r.status()).toBe(200);
				expect(ms, `inbox${v} ${ms.toFixed(0)} ms`).toBeLessThanOrEqual(2000);
			}
			await ctx.dispose();
		});
	}
});
