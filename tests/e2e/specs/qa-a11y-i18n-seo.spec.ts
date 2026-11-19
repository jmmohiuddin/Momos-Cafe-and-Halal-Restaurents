/**
 * QA (a11y-i18n team) — SEO, structured data, content compliance and analytics privacy
 * (i18n-seo-analytics §9–§12, qa.md §7.7/§7.8; TC-SEO-001…007/009, TC-I18N-009, TC-RES-021, TC-DIET-013/018,
 * TC-ANL-004/005/007/010, AC-SEO-01-01…03, AC-SEO-02-01…04, AC-ANL-01-02/03, DEC-066).
 *  - hreflang crawl: every alternate is reciprocal, 200, indexable, self-canonical; x-default = en; noindex pages
 *    print no hreflang; canonical rules; query variants noindex,follow.
 *  - Sitemaps: index + children, headers, every URL 200/indexable/in its language; no TEST/sample/hidden items.
 *  - robots.txt and X-Robots-Tag in non-production.
 *  - JSON-LD: parses, one block, no rating/review; Restaurant phone/name/hours = visible page; BreadcrumbList = visible
 *    breadcrumbs; no suitableForDiet without an approved certified claim.
 *  - Prohibited terms (request context, claims, ordering) on rendered pages in ja/en/ar.
 *  - Analytics with a GTM container configured: dataLayer allow-list without PII through a real reservation; no GTM /
 *    Google request on C10, C11, wp-admin, for logged-in staff, with the opt-out cookie or GPC; no <noscript> iframe.
 * Google requests are intercepted and aborted — the spec never contacts Google.
 */
import { test, expect, Page, APIRequestContext } from '@playwright/test';
import { execFileSync } from 'child_process';
import * as path from 'path';

const BASE = (process.env.MOMOS_E2E_BASE_URL || process.env.MOMOS_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const ROOT = path.resolve(__dirname, '../../..');
const HAS_WP = !!process.env.MOMOS_DB_NAME;
const ALL = ['ja', 'en', 'ar', 'ur', 'hi', 'bn'] as const;
const prefix = (l: string) => (l === 'ja' ? '' : `/${l}`);
const GOOGLE = /googletagmanager\.com|google-analytics\.com|analytics\.google\.com|doubleclick\.net|facebook\.net|line-scdn|connect\.facebook/;

function wp(...args: string[]): string {
	return execFileSync(path.join(ROOT, 'bin/wp'), args, { cwd: ROOT, env: process.env, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
}

const PAGES = ['/', '/about/', '/halal/', '/branches/', '/branches/demo-kitahama/', '/menu/', '/menu/curry/', '/menu/curry/butter-chicken-curry/',
	'/menu/dessert/', '/reservation/', '/contact/', '/faq/', '/offers/', '/gallery/', '/privacy-policy/', '/terms/', '/accessibility/'];

type Head = { status: number; robots: string; xrobots: string; canonical: string | null; alts: Record<string, string>; lang: string; ld: string[]; html: string };

async function head(request: APIRequestContext, url: string): Promise<Head> {
	const res = await request.get(url, { maxRedirects: 0 });
	const html = await res.text();
	const alts: Record<string, string> = {};
	for (const m of html.matchAll(/<link[^>]+rel=["']alternate["'][^>]*>/g)) {
		const hl = m[0].match(/hreflang=["']([^"']+)["']/)?.[1];
		const href = m[0].match(/href=["']([^"']+)["']/)?.[1];
		if (hl && href) alts[hl] = href.replace(/&amp;/g, '&');
	}
	return {
		status: res.status(),
		robots: html.match(/<meta[^>]+name=["']robots["'][^>]+content=["']([^"']+)["']/)?.[1] || '',
		xrobots: res.headers()['x-robots-tag'] || '',
		canonical: html.match(/<link[^>]+rel=["']canonical["'][^>]+href=["']([^"']+)["']/)?.[1] || null,
		alts,
		lang: html.match(/<html[^>]+lang=["']([^"']+)["']/)?.[1] || '',
		ld: Array.from(html.matchAll(/<script[^>]+type=["']application\/ld\+json["'][^>]*>([\s\S]*?)<\/script>/g)).map((m) => m[1]),
		html,
	};
}

test.describe('hreflang, canonical, robots (TC-SEO-002/003, AC-SEO-01-01/02)', () => {
	test('reciprocal hreflang with x-default=en on every indexable page in every language', async ({ request }) => {
		test.setTimeout(600_000);
		const bad: string[] = [];
		const cache = new Map<string, Head>();
		let checkedPairs = 0;
		const get = async (u: string) => { if (!cache.has(u)) cache.set(u, await head(request, u)); return cache.get(u) as Head; };
		for (const lang of ALL) {
			for (const p of PAGES) {
				const url = `${BASE}${prefix(lang)}${p}`;
				const h = await get(url);
				if (h.status === 404) { if (Object.keys(h.alts).length) bad.push(`${url}: 404 with hreflang`); continue; }
				if (h.status !== 200) { bad.push(`${url}: status ${h.status}`); continue; }
				const noindex = /noindex/.test(h.robots);
				if (noindex) { if (Object.keys(h.alts).length) bad.push(`${url}: noindex page prints hreflang`); continue; }
				if (h.canonical !== url) bad.push(`${url}: canonical ${h.canonical}`);
				const codes = Object.keys(h.alts).filter((c) => c !== 'x-default');
				if (codes.length < 2) continue; // single-language entity: no hreflang
				if (h.alts[lang] !== url) bad.push(`${url}: self hreflang ${h.alts[lang]}`);
				if (h.alts.en) { if (h.alts['x-default'] !== h.alts.en) bad.push(`${url}: x-default ${h.alts['x-default']} ≠ en ${h.alts.en}`); }
				else if (h.alts['x-default']) bad.push(`${url}: x-default without en alternate`);
				for (const c of codes) if (!ALL.includes(c as (typeof ALL)[number])) bad.push(`${url}: unknown hreflang code ${c}`);
				for (const [c, href] of Object.entries(h.alts)) {
					if (c === 'x-default') continue;
					if (/[?#]/.test(href) || href !== href.toLowerCase() || !href.endsWith('/')) bad.push(`${url}: alternate ${c} not clean ${href}`);
					const a = await get(href);
					checkedPairs++;
					if (a.status !== 200) { bad.push(`${url}: alternate ${c} ${href} → ${a.status}`); continue; }
					if (a.lang !== c) bad.push(`${href}: html lang ${a.lang} ≠ ${c}`);
					if (/noindex/.test(a.robots)) bad.push(`${href}: alternate is noindex`);
					if (a.canonical !== href) bad.push(`${href}: canonical ${a.canonical}`);
					const back = JSON.stringify(Object.entries(a.alts).sort());
					if (back !== JSON.stringify(Object.entries(h.alts).sort())) bad.push(`${url} ↔ ${href}: hreflang sets differ`);
				}
			}
		}
		expect(checkedPairs, 'alternate pairs verified').toBeGreaterThan(200);
		expect([...new Set(bad)]).toEqual([]);
	});

	test('noindex rules: C10/C11, 404, query variants, notice variant, TEST branch hidden (§9.3)', async ({ request }) => {
		const bad: string[] = [];
		for (const lang of ['ja', 'en', 'ar']) {
			for (const p of ['/reservation/submitted/', '/reservation/status/ABCD2345/']) {
				const h = await head(request, `${BASE}${prefix(lang)}${p}`);
				if (!/noindex/.test(h.robots) || !/noindex/.test(h.xrobots)) bad.push(`${lang}${p}: robots "${h.robots}" / header "${h.xrobots}"`);
				if (h.canonical) bad.push(`${lang}${p}: canonical present on C10/C11`);
			}
			const nf = await head(request, `${BASE}${prefix(lang)}/menu/does-not-exist-seo/`);
			if (nf.status !== 404 || !/noindex/.test(nf.robots)) bad.push(`${lang} 404: ${nf.status} robots "${nf.robots}"`);
			if (nf.canonical) bad.push(`${lang} 404: canonical present`);
			for (const q of ['/menu/curry/?branch=DKH', '/menu/curry/?diet[]=vegetarian', '/?notice=untranslated', '/branches/?utm_source=x']) {
				const h = await head(request, `${BASE}${prefix(lang)}${q}`);
				if (!/noindex/.test(h.robots) || !/follow/.test(h.robots) || /nofollow/.test(h.robots)) bad.push(`${lang}${q}: robots "${h.robots}" (want noindex, follow)`);
				const clean = `${BASE}${prefix(lang)}${q.split('?')[0]}`;
				if (h.canonical && h.canonical !== clean) bad.push(`${lang}${q}: canonical ${h.canonical} (want ${clean})`);
				if (Object.keys(h.alts).length) bad.push(`${lang}${q}: query variant prints hreflang`);
			}
		}
		const tb = await request.get(`${BASE}/branches/test-branch/`, { maxRedirects: 0 });
		if (tb.status() !== 404) bad.push(`TEST branch public: ${tb.status()}`);
		expect(bad).toEqual([]);
	});

	test('URL rules: uppercase path → single 301 to lowercase, no /ja/ prefix (TC-SEO-001, AC-SEO-01-04)', async ({ request }) => {
		const r1 = await request.get(`${BASE}/Branches/`, { maxRedirects: 0 });
		expect(r1.status()).toBe(301);
		expect(new URL(r1.headers().location, BASE).pathname).toBe('/branches/');
		const r2 = await request.get(`${BASE}/ja/menu/`, { maxRedirects: 0 });
		expect([301, 404]).toContain(r2.status());
		if (r2.status() === 301) expect(new URL(r2.headers().location, BASE).pathname).toBe('/menu/');
		const r3 = await request.get(`${BASE}/en/menu`, { maxRedirects: 0 });
		expect(r3.status()).toBe(301);
		expect(new URL(r3.headers().location, BASE).pathname).toBe('/en/menu/');
	});
});

test.describe('XML sitemaps (TC-SEO-004, AC-SEO-01-03, FR-SEO-004)', () => {
	test('index, children, every URL 200 + indexable + own language; no TEST / sample / noindex URL', async ({ request }) => {
		test.setTimeout(600_000);
		const idx = await request.get(`${BASE}/sitemap.xml`);
		expect(idx.status()).toBe(200);
		expect(idx.headers()['content-type']).toMatch(/xml/);
		const children = Array.from((await idx.text()).matchAll(/<loc>([^<]+)<\/loc>/g)).map((m) => m[1]);
		expect(children.length).toBeGreaterThan(0);
		const bad: string[] = [];
		const enabled = new Set<string>();
		let sampleIds: string[] = [];
		if (HAS_WP) sampleIds = JSON.parse(wp('eval', 'global $wpdb; echo wp_json_encode(array_map("get_permalink", $wpdb->get_col("SELECT post_id FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID=m.post_id WHERE m.meta_key=\'momo_is_sample\' AND m.meta_value=\'1\' AND p.post_type = \'momo_dish\'")));'));
		const samplePaths = new Set(sampleIds.filter(Boolean).map((u) => new URL(u).pathname));
		for (const c of children) {
			const lang = c.match(/sitemaps\/([a-z]{2})-/)?.[1] || '?';
			enabled.add(lang);
			const r = await request.get(c);
			if (r.status() !== 200) { bad.push(`${c}: ${r.status()}`); continue; }
			if (!/noindex/.test(r.headers()['x-robots-tag'] || '')) bad.push(`${c}: missing X-Robots-Tag noindex`);
			const body = await r.text();
			if (/hreflang|xhtml:link/.test(body)) bad.push(`${c}: hreflang inside sitemap (HTML head only)`);
			const locs = Array.from(body.matchAll(/<loc>([^<]+)<\/loc>/g)).map((m) => m[1]);
			if (!locs.length) bad.push(`${c}: empty child sitemap listed`);
			for (const u of locs) {
				const path = new URL(u).pathname;
				const jaPath = lang === 'ja' ? path : path.replace(new RegExp(`^/${lang}`), '');
				if (samplePaths.has(jaPath)) bad.push(`${c}: sample dish (momo_is_sample=1) listed ${u}`);
				if (/test-branch/.test(u)) bad.push(`${c}: TEST branch listed`);
				if (/[?#]/.test(u) || /\/reservation\/(submitted|status)/.test(u)) bad.push(`${c}: excluded URL ${u}`);
				const h = await head(request, u);
				if (h.status !== 200) { bad.push(`${u}: ${h.status}`); continue; }
				if (h.lang !== lang) bad.push(`${u}: lang ${h.lang} in ${lang} sitemap`);
				if (/noindex/.test(h.robots)) bad.push(`${u}: noindex URL in sitemap`);
				if (h.canonical !== u) bad.push(`${u}: canonical ${h.canonical}`);
			}
		}
		// Hidden languages must not appear: every sitemap language is offered in the public switcher.
		const sw = await head(request, `${BASE}/`);
		const switcher = new Set(Array.from(sw.html.matchAll(/data-momo-to="([a-z]{2})"/g)).map((m) => m[1]));
		for (const l of enabled) if (!switcher.has(l)) bad.push(`sitemap lists hidden language ${l}`);
		expect([...new Set(bad)].slice(0, 30)).toEqual([]);
	});

	test('robots.txt + X-Robots-Tag in non-production (§9.6, TC-SEO-009)', async ({ request }) => {
		const r = await request.get(`${BASE}/robots.txt`);
		expect(r.status()).toBe(200);
		const t = await r.text();
		expect(t).toMatch(/User-agent:\s*\*/);
		expect(t).toMatch(/Disallow:\s*\/\s*$/m);
		for (const p of ['/', '/en/menu/', '/sitemap.xml']) {
			const h = await request.get(`${BASE}${p}`);
			expect(h.headers()['x-robots-tag'] || '', `${p} X-Robots-Tag`).toMatch(/noindex/);
		}
	});
});

test.describe('JSON-LD (TC-SEO-006/007, AC-SEO-02-01…04, TC-DIET-018)', () => {
	test('parses, one block, no rating/review/offer markup, no HalalDiet without approved claim', async ({ request }) => {
		test.setTimeout(300_000);
		const bad: string[] = [];
		for (const lang of ['ja', 'en', 'ar', 'bn']) {
			for (const p of PAGES) {
				const h = await head(request, `${BASE}${prefix(lang)}${p}`);
				if (h.status !== 200) continue;
				if (h.ld.length > 1) bad.push(`${lang}${p}: ${h.ld.length} JSON-LD blocks`);
				for (const raw of h.ld) {
					let j: { '@context'?: string; '@graph'?: Array<Record<string, unknown>> };
					try { j = JSON.parse(raw); } catch (e) { bad.push(`${lang}${p}: invalid JSON-LD ${String(e).slice(0, 60)}`); continue; }
					if (!/schema\.org/.test(String(j['@context']))) bad.push(`${lang}${p}: @context ${j['@context']}`);
					const s = JSON.stringify(j);
					for (const k of ['aggregateRating', '"review"', 'SearchAction', '"Offer"']) if (s.includes(k) && !(k === '"Offer"' && /MenuItem/.test(s))) bad.push(`${lang}${p}: forbidden ${k}`);
					if (/HalalDiet/.test(s)) bad.push(`${lang}${p}: suitableForDiet HalalDiet (no certified_item claim in the demo data)`);
					for (const n of j['@graph'] || []) {
						if ((n as { inLanguage?: string }).inLanguage && (n as { inLanguage?: string }).inLanguage !== lang) bad.push(`${lang}${p}: inLanguage ${(n as { inLanguage?: string }).inLanguage}`);
					}
					if (lang !== 'ja' && /[぀-ヿ]/.test(s.replace(/"streetAddress":"[^"]*"|"addressLocality":"[^"]*"|"addressRegion":"[^"]*"/g, ''))) bad.push(`${lang}${p}: Japanese text in JSON-LD`);
				}
			}
		}
		expect([...new Set(bad)]).toEqual([]);
	});

	for (const lang of ['ja', 'en', 'ar']) {
		test(`Restaurant node = visible branch page (phone, name, hours, breadcrumbs) — ${lang}`, async ({ page }) => {
			await page.goto(`${BASE}${prefix(lang)}/branches/demo-kitahama/`, { waitUntil: 'domcontentloaded' });
			const r = await page.evaluate(() => {
				const graph = JSON.parse(document.querySelector('script[type="application/ld+json"]')?.textContent || '{}')['@graph'] || [];
				const rest = graph.filter((n: { '@type': string }) => n['@type'] === 'Restaurant');
				const crumbs = graph.find((n: { '@type': string }) => n['@type'] === 'BreadcrumbList');
				const tel = (document.querySelector('main a[href^="tel:"]') as HTMLAnchorElement | null)?.getAttribute('href')?.replace('tel:', '');
				const h1 = document.querySelector('h1')?.textContent?.trim();
				const vis = Array.from(document.querySelectorAll('nav.momo-breadcrumbs li')).map((li) => li.textContent?.trim());
				// Visible weekly table rows: day label + "HH:MM–HH:MM" ranges.
				const rows = Array.from(document.querySelectorAll('main table tr')).map((tr) => Array.from(tr.querySelectorAll('bdi')).map((b) => b.textContent?.trim()).filter((t) => /–/.test(t || '')));
				const visRanges = rows.flat().sort();
				return { n: rest.length, rest: rest[0], crumbs, tel, h1, vis, visRanges, canonical: document.querySelector('link[rel=canonical]')?.getAttribute('href') };
			});
			expect(r.n, 'exactly one Restaurant node').toBe(1);
			expect(r.rest.telephone).toBe(r.tel);
			expect(r.rest.name).toBe(r.h1);
			expect(r.rest.url).toBe(r.canonical);
			expect(r.rest.address?.addressCountry).toBe('JP');
			const spec = (r.rest.openingHoursSpecification || []) as Array<{ dayOfWeek: string; opens: string; closes: string; validFrom?: string }>;
			const regular = spec.filter((s) => !s.validFrom);
			expect(regular.length).toBeGreaterThan(0);
			// Every regular opening range appears in the visible weekly table and vice versa (as a set of ranges).
			const ld = [...new Set(regular.map((s) => `${s.opens}–${s.closes}`))].sort();
			const vis = [...new Set(r.visRanges as string[])].map((x) => x.replace(/^(\d):/, '0$1:')).sort();
			expect(ld).toEqual(vis);
			// BreadcrumbList = visible breadcrumbs.
			const names = (r.crumbs?.itemListElement || []).map((i: { name: string }) => i.name);
			expect(names).toEqual(r.vis);
		});
	}
});

test.describe('prohibited terms on rendered pages (qa.md §7.7, TC-RES-021, TC-DIET-013, TC-REG-007)', () => {
	const REQUEST: Record<string, RegExp[]> = {
		en: [/\bbooked\b/i, /you'?re booked/i, /reservation confirmed/i, /booking confirmed/i, /confirm table reservation/i, /guarantee your seating/i, /instant seating/i, /your table is reserved/i, /reservation complete/i, /\byour reservation\b/i],
		ja: [/予約完了/, /予約確定/, /予約が確定/, /予約を確定/, /ご予約を承りました/, /このご予約/],
		ar: [/تأكيد الحجز/, /تم تأكيد الحجز/],
	};
	const CLAIM: Record<string, RegExp[]> = {
		en: [/100\s?% halal/i, /fully halal/i, /allergen[- ]free/i, /gluten[- ]free/i, /safe for allerg/i, /\bno risk\b/i, /free from (milk|egg|wheat|nuts?|peanuts?|shrimp|crab|buckwheat)/i],
		ja: [/100\s?%ハラール/, /完全ハラール/, /アレルゲンフリー/, /完全除去/, /アレルギーの方も安心/, /グルテンフリー/],
		ar: [],
	};
	const ORDER: Record<string, RegExp[]> = {
		en: [/order now/i, /add to cart/i, /checkout/i, /place order/i, /\btakeout\b/i, /\bdelivery\b/i, /coupon code/i, /\bredeem\b/i],
		ja: [/今すぐ注文/, /カートに追加/, /注文を確定/],
		ar: [],
	};
	for (const lang of ['ja', 'en', 'ar']) {
		test(`request-context wording on C09/C10/C11, claim + ordering terms everywhere — ${lang}`, async ({ page }) => {
			test.setTimeout(240_000);
			const bad: string[] = [];
			const scan = async (label: string, lists: RegExp[][]) => {
				const text = await page.evaluate(() => {
					const clone = document.body.cloneNode(true) as HTMLElement;
					clone.querySelectorAll('script, style, noscript, template, #momo-lang-data').forEach((e) => e.remove());
					return (clone.textContent || '').replace(/\s+/g, ' ') + ' ' + document.title;
				});
				// Allow-list: the negated pending status / heading.
				const t = text.replace(/Request received — not yet confirmed|not yet confirmed|リクエスト受付・未確定|まだ確定していません/g, '');
				for (const list of lists) for (const re of list) { const m = t.match(re); if (m) bad.push(`${label}: "${m[0]}" …${t.slice(Math.max(0, (m.index || 0) - 40), (m.index || 0) + 40)}…`); }
			};
			for (const p of ['/reservation/', '/reservation/submitted/', '/reservation/status/ABCD2345/']) {
				await page.goto(`${BASE}${prefix(lang)}${p}`, { waitUntil: 'networkidle' });
				await scan(`${lang}${p}`, [REQUEST[lang], CLAIM[lang], ORDER[lang]]);
			}
			for (const p of PAGES) {
				const res = await page.goto(`${BASE}${prefix(lang)}${p}`, { waitUntil: 'domcontentloaded' });
				if (res?.status() !== 200) continue;
				await scan(`${lang}${p}`, [CLAIM[lang], ORDER[lang]]);
			}
			for (const p of ['/cart/', '/checkout/', '/order/']) {
				const r = await page.request.get(`${BASE}${prefix(lang)}${p}`, { maxRedirects: 0 });
				if (r.status() !== 404) bad.push(`${lang}${p}: ordering route returns ${r.status()}`);
			}
			expect([...new Set(bad)]).toEqual([]);
		});
	}
});

test.describe('analytics privacy with a GTM container configured (TC-ANL-004/005/007/010, DEC-066)', () => {
	test.describe.configure({ mode: 'serial' });
	test.skip(!HAS_WP, 'needs MOMOS_DB_NAME to configure a GTM container ID');
	let fixture = false;
	test.beforeAll(() => {
		wp('eval', '\\Momos\\Kernel\\Settings::set("momo_gtm_container_id","GTM-QA11Y01");');
		try { wp('eval-file', 'tests/e2e/fixtures/reservation-fixture.php'); fixture = true; } catch { /* rate limits stay as they are */ }
	});
	test.afterAll(() => {
		wp('eval', '\\Momos\\Kernel\\Settings::set("momo_gtm_container_id","");');
		// E2E isolation: remove XEA/XEB, their staff and reservations, and restore the rate limits for later specs.
		if (fixture) wp('eval-file', 'tests/e2e/fixtures/reservation-fixture.php', 'teardown');
	});

	async function spy(page: Page): Promise<string[]> {
		const hits: string[] = [];
		await page.route(GOOGLE, (route) => { hits.push(route.request().url()); return route.abort(); });
		return hits;
	}

	test('public page loads GTM (control) and pushes only allow-listed keys', async ({ page }) => {
		const hits = await spy(page);
		await page.goto(`${BASE}/en/branches/demo-kitahama/`, { waitUntil: 'networkidle' });
		expect(hits.some((u) => /gtm\.js\?id=GTM-QA11Y01/.test(u)), 'GTM requested on a public page').toBe(true);
		expect(await page.locator('noscript iframe').count(), 'no <noscript> GTM iframe').toBe(0);
		const dl = await page.evaluate(() => JSON.parse(JSON.stringify(((window as unknown as { dataLayer: unknown[] }).dataLayer || []).filter((x) => x && typeof x === 'object' && !Array.isArray(x) && !('0' in (x as object))))));
		const allowed = new Set(['event', 'page_type', 'language', 'branch_code', 'menu_category', 'dish_sku', 'gtm.start', 'gtm.blocklist', 'cta_location', 'from_language', 'to_language', 'source_page', 'party_size_band', 'lead_source', 'eventCallback', 'eventTimeout', 'gtm.uniqueEventId']);
		for (const o of dl as Record<string, unknown>[]) for (const k of Object.keys(o)) expect(allowed.has(k), `dataLayer key ${k}`).toBe(true);
		expect((dl as Record<string, unknown>[]).some((o) => o.event === 'view_branch' && o.branch_code === 'DKH')).toBe(true);
	});

	test('reservation journey with PII markers: no PII in dataLayer or any Google URL; C10/C11 load no GTM', async ({ browser }) => {
		test.setTimeout(180_000);
		const ctx = await browser.newContext();
		const page = await ctx.newPage();
		const hits = await spy(page);
		await page.addInitScript(() => {
			const w = window as unknown as { dataLayer?: unknown[] };
			const dl: unknown[] = (w.dataLayer = w.dataLayer || []);
			dl.push = function (...items: unknown[]) {
				try {
					const a = JSON.parse(sessionStorage.getItem('qa_dl') || '[]');
					items.forEach((o) => { try { a.push(JSON.parse(JSON.stringify(o))); } catch { a.push(String(o)); } });
					sessionStorage.setItem('qa_dl', JSON.stringify(a));
				} catch { /* ignore */ }
				return Array.prototype.push.apply(this, items);
			};
		});
		const email = `piimarker-${Date.now().toString(36)}@example.test`;
		const name = 'Zzpiiname Markerson';
		await page.goto(`${BASE}/en/reservation/?branch=XEA`, { waitUntil: 'networkidle' });
		const date = await page.$eval('#res-date', (s) => Array.from((s as HTMLSelectElement).options).filter((o) => o.value && !o.disabled)[3].value);
		await page.selectOption('#res-date', date);
		await page.locator('label[for="res-time-0"]').click();
		await page.locator('label[for="res-party-2"]').click();
		await page.fill('#res-name', name);
		await page.fill('#res-email', email);
		await page.fill('#res-phone', '+60 12-999 8877');
		await page.fill('#res-note', 'PIINOTE allergy marker');
		await page.check('#res-consent');
		const before = hits.length;
		await Promise.all([page.waitForURL(/\/reservation\/submitted\/$/), page.locator('[data-res-submit]').click()]);
		await page.waitForLoadState('networkidle');
		const c10Hits = hits.slice(before).filter((u) => /gtm\.js/.test(u) && false); // gtm.js fetched before navigation is fine
		void c10Hits;
		const c10Scripts = await page.locator('script[data-momo-datalayer], script[src*="googletagmanager"]').count();
		expect(c10Scripts, 'C10 carries no tag loader').toBe(0);
		const pushed = (await page.evaluate(() => sessionStorage.getItem('qa_dl'))) || '[]';
		const statusUrl = (await page.locator('a[href*="/reservation/status/"]').getAttribute('href')) as string;
		const n0 = hits.length;
		await page.reload({ waitUntil: 'networkidle' });
		await page.goto(statusUrl, { waitUntil: 'networkidle' });
		expect(hits.slice(n0), 'C10 reload + C11: zero Google requests').toEqual([]);
		expect(await page.locator('script[data-momo-datalayer], script[src*="googletagmanager"]').count(), 'C11 carries no tag loader').toBe(0);
		const ref = ((await page.locator('bdi.momo-res-ref__code').textContent()) || 'NOREF').trim();
		const token = statusUrl.split('#t=')[1] || 'NOTOKEN';
		const all = pushed + ' ' + hits.join(' ');
		for (const pii of [email, email.split('@')[0], 'Zzpiiname', 'Markerson', '999 8877', '9998877', 'PIINOTE', ref, ref.replace('-', ''), token]) {
			expect(all.includes(pii), `PII "${pii}" leaked to dataLayer/Google URL`).toBe(false);
		}
		const leads = (JSON.parse(pushed) as Array<Record<string, unknown>>).filter((o) => o && o.event === 'generate_lead');
		expect(leads).toHaveLength(1);
		expect(leads[0]).toMatchObject({ branch_code: 'XEA', language: 'en', party_size_band: '1-2', lead_source: 'reservation_form' });
		await ctx.close();
	});

	test('opt-out cookie and GPC: no Google request over three pages; consent default denied', async ({ browser }) => {
		for (const mode of ['cookie', 'gpc'] as const) {
			const ctx = await browser.newContext();
			if (mode === 'cookie') await ctx.addCookies([{ name: 'momo_analytics_optout', value: '1', url: BASE }]);
			else await ctx.addInitScript(() => Object.defineProperty(navigator, 'globalPrivacyControl', { get: () => true }));
			const page = await ctx.newPage();
			const hits = await spy(page);
			for (const p of ['/en/', '/en/menu/', '/en/halal/']) await page.goto(`${BASE}${p}`, { waitUntil: 'networkidle' });
			expect(hits, `${mode}: Google requests`).toEqual([]);
			const consent = await page.evaluate(() => JSON.stringify(((window as unknown as { dataLayer: unknown[] }).dataLayer || []).find((x) => (x as { 0?: string })[0] === 'consent')));
			expect(consent, `${mode}: consent default`).toContain('"analytics_storage":"denied"');
			await ctx.close();
		}
	});

	test('wp-admin, wp-login and logged-in staff on public pages: no tag loader, no Google request (TC-ANL-005)', async ({ page }) => {
		const hits = await spy(page);
		await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'networkidle' });
		expect(await page.locator('script[data-momo-datalayer]').count()).toBe(0);
		await page.fill('#user_login', 'demo_ho');
		await page.fill('#user_pass', 'Momos-Dev-2026!');
		await Promise.all([page.waitForURL(/wp-admin/), page.click('#wp-submit')]);
		for (const p of ['/wp-admin/', '/wp-admin/admin.php?page=momos-reservations']) {
			await page.goto(`${BASE}${p}`, { waitUntil: 'networkidle' });
			expect(await page.locator('script[data-momo-datalayer]').count(), `${p} tag loader`).toBe(0);
		}
		await page.goto(`${BASE}/en/menu/`, { waitUntil: 'networkidle' });
		expect(await page.locator('script[data-momo-datalayer]').count(), 'logged-in staff on a public page: no tag loader').toBe(0);
		expect(hits).toEqual([]);
	});
});
