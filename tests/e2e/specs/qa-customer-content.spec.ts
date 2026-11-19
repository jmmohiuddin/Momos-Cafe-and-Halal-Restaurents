/**
 * QA (customer team) — content screens: SCR-C01 home (TC-HOME-001/003/004/005), C03 halal (TC-CORP-002,
 * TC-DIET-020), C21 offers (TC-CORP-011/013, AC-CORP-09), notices (TC-BR-012 customer side), C12 contact
 * (TC-CORP-009), C13 FAQ (TC-CORP-003), C22 gallery (TC-CORP-015), C19 404 (TC-CORP-006), C02 about,
 * C09/C10/C11 shells. Against docs/specs/screens.md §3 and Doc 07/08.
 */
import { test, expect } from '@playwright/test';
import { execFileSync } from 'child_process';
import * as fs from 'fs';
import * as path from 'path';

const BASE = process.env.MOMOS_E2E_BASE_URL || process.env.MOMOS_URL || 'http://127.0.0.1:8080';
const ROOT = path.resolve(__dirname, '../../..');
const HAS_WP = !!process.env.MOMOS_DB_NAME;
const LANGS = ['ja', 'en', 'ar', 'ur', 'hi', 'bn'] as const;
const prefix = (l: string) => (l === 'ja' ? '' : `/${l}`);
function wp(...args: string[]): string {
	return execFileSync(path.join(ROOT, 'bin/wp'), args, { env: process.env, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
}
const CLAIM = /100\s*%|fully halal|halal[- ]certified|certified halal|ハラール認証|完全ハラール|seal/i;

test.describe('SCR-C01 home (TC-HOME-001/003/004/005)', () => {
	for (const lang of LANGS) {
		test(`section order, no empty band, halal summary, CTAs same language — ${lang}`, async ({ page }) => {
			await page.goto(`${BASE}${prefix(lang)}/`);
			// Order: hero → offers → branches → featured dishes → halal summary → how reservations work
			const order = await page.locator('main > section, main > div').evaluateAll((els) => els.map((e) => e.className + ' ' + (e.getAttribute('aria-labelledby') || '')));
			const idx = (re: RegExp) => order.findIndex((c) => re.test(c));
			const hero = idx(/momo-hero/), offers = idx(/momo-home-offers/), br = idx(/momo-home-branches/), dishes = idx(/momo-home-dishes/), how = idx(/momo-home-how/);
			expect(hero).toBeGreaterThanOrEqual(0);
			expect(offers).toBe(hero + 1); // offers directly after the hero (DEC-060)
			expect(br).toBeGreaterThan(offers);
			expect(dishes).toBeGreaterThan(br);
			expect(how).toBeGreaterThan(dishes);
			// §3.1 #6 halal summary: never empty (brand.neutral text) and heading + link to /halal/
			const halal = page.locator('main .momo-halal');
			await expect(halal, 'halal summary (CMP-HalalStatement brand) missing on home').toHaveCount(1);
			await expect(halal.locator(`a[href$="${prefix(lang)}/halal/"]`)).toHaveCount(1);
			// No section wrapper without content (screens.md §2 "Empty: section with no data is not rendered")
			const empty = await page.locator('main > section, main > div').evaluateAll((els) => els.filter((e) => (e as HTMLElement).innerText.trim() === '').length);
			expect(empty, 'empty section band rendered on home').toBe(0);
			// No claim words / seal image (TC-HOME-003 variant A)
			expect(await page.locator('main').innerText()).not.toMatch(CLAIM);
			await expect(page.locator('main img[alt*="halal" i], main img[src*="seal" i]')).toHaveCount(0);
			// Hero CTAs (TC-HOME-005)
			const req = page.locator('main .momo-hero a[href$="/reservation/"]').first();
			expect(new URL((await req.getAttribute('href'))!).pathname).toBe(`${prefix(lang)}/reservation/`);
			const find = page.locator('main .momo-hero a[href$="/branches/"]').first();
			expect(new URL((await find.getAttribute('href'))!).pathname).toBe(`${prefix(lang)}/branches/`);
			// "See current offers" while ≥ 1 featured active offer
			await expect(page.locator(`main .momo-hero a[href$="${prefix(lang)}/offers/"]`)).toHaveCount(1);
		});
	}

	test('"How reservations work": no request-context terms (TC-HOME-004, Doc 07 §6.6)', async ({ page }) => {
		const terms: Record<string, RegExp> = {
			en: /\b(booked|reservation confirmed|booking confirmed|your table is reserved|guarantee)\b/i,
			ja: /予約完了|予約確定|予約が確定|予約を確定|ご予約を承りました|このご予約/,
		};
		for (const lang of ['en', 'ja']) {
			await page.goto(`${BASE}${prefix(lang)}/`);
			const sec = page.locator('main section[aria-labelledby="momo-home-how"]');
			expect(await sec.innerText()).not.toMatch(terms[lang]);
		}
	});

	test('hero category chips: every chip reachable at 390 px (no unreachable overflow)', async ({ browser }) => {
		for (const lang of ['en', 'ar']) {
			const ctx = await browser.newContext({ viewport: { width: 390, height: 844 } });
			const page = await ctx.newPage();
			await page.goto(`${BASE}/${lang}/`);
			const row = page.locator('main .momo-hero .momo-catchips ul');
			// Scroll to both ends; every chip must be fully inside the row at one of the two positions.
			const unreachable = await row.evaluate((ul) => {
				const box = ul.getBoundingClientRect();
				const seen = new Set<number>();
				const chips = Array.from(ul.querySelectorAll('a'));
				for (const pos of [-1e6, 0, 1e6]) {
					ul.scrollLeft = pos;
					chips.forEach((a, i) => { const r = a.getBoundingClientRect(); if (r.left >= box.left - 1 && r.right <= box.right + 1) seen.add(i); });
					// Fine sampling (QA-033 fix review): a half-row step skips scroll positions where a chip wider than half the row is
					// fully visible, and scroll-snap rounds each sampled position to the nearest chip start.
					const step = 16;
					for (let x = -ul.scrollWidth; x <= ul.scrollWidth; x += step) {
						ul.scrollLeft = x;
						chips.forEach((a, i) => { const r = a.getBoundingClientRect(); if (r.left >= box.left - 1 && r.right <= box.right + 1) seen.add(i); });
					}
				}
				return chips.filter((_, i) => !seen.has(i)).map((a) => a.textContent!.trim());
			});
			expect(unreachable, `${lang}: chips never scrollable into view`).toEqual([]);
			await ctx.close();
		}
	});

	for (const lang of ['ja', 'ar', 'ur'] as const) {
		test(`360×640: H1 and the hero "Request a table" visible without scrolling — ${lang}`, async ({ browser }) => {
			const ctx = await browser.newContext({ viewport: { width: 360, height: 640 }, locale: lang === 'ja' ? 'ja-JP' : lang });
			await ctx.addCookies([{ name: 'momo_lang_choice', value: lang, url: BASE }]);
			const page = await ctx.newPage();
			await page.goto(`${BASE}${prefix(lang)}/`);
			// dismiss nothing: first view as a visitor sees it (notice banner collapsed by default)
			const barTop = await page.evaluate(() => document.querySelector('nav.momo-bar')?.getBoundingClientRect().top ?? window.innerHeight);
			const h1 = (await page.locator('h1').boundingBox())!;
			expect.soft(h1.y + h1.height, 'H1 below the fold / under the action bar').toBeLessThanOrEqual(barTop);
			const cta = page.locator('main .momo-hero a[href$="/reservation/"]').first();
			const box = (await cta.boundingBox())!;
			expect(box.y + box.height, 'hero CTA below the fold / under the action bar').toBeLessThanOrEqual(barTop);
			await ctx.close();
		});
	}
});

test.describe('SCR-C03 halal page (TC-CORP-002, TC-DIET-003/020, GATE-02)', () => {
	for (const lang of LANGS) {
		test(`one row per public branch, no claim wording, no seal image, FAQ excerpt — ${lang}`, async ({ page }) => {
			const r = await page.goto(`${BASE}${prefix(lang)}/halal/`);
			expect(r?.status()).toBe(200);
			const main = page.locator('main');
			for (const slug of ['demo-kitahama', 'demo-namba-minami', 'demo-kyoto-kawaramachi'])
				await expect(main.locator(`a[href$="${prefix(lang)}/branches/${slug}/"]`).first()).toBeAttached();
			await expect(main.locator('a[href*="test-branch"]')).toHaveCount(0);
			await expect(main.locator('img')).toHaveCount(0); // no mark unless active certified + permission
			const t = await main.innerText();
			expect(t).not.toMatch(/100\s*%|完全ハラール|fully halal/i);
		});
	}
});

test.describe('SCR-C21 offers (TC-CORP-011/013, AC-CORP-09)', () => {
	for (const lang of LANGS) {
		test(`cards: illustration caption, validity (+JST non-ja), conditions, tax-incl. price, CTA; chips filter — ${lang}`, async ({ page }) => {
			await page.goto(`${BASE}${prefix(lang)}/offers/`);
			const cards = page.locator('main .momo-offer-card');
			await expect(cards).toHaveCount(2);
			for (const c of await cards.all()) {
				const t = await c.innerText();
				expect(t).toMatch(/2026/);
				if (lang === 'ja') expect(t).not.toContain('JST'); else expect(t).toMatch(/JST|جاپانی وقت|जापान समय|জাপান সময়|بتوقيت اليابان/);
				await expect(c.locator('h2')).toHaveCount(1);
				expect(t.toLowerCase()).not.toMatch(/coupon|redeem|クーポン/);
			}
			// image ratio 4:3 (screens.md §3.17 "image (4:3, alt …)")
			const r = await cards.first().locator('img').evaluate((img) => (img as HTMLImageElement).getBoundingClientRect());
			expect.soft(Math.abs(r.width / r.height - 4 / 3), `offer image ratio ${(r.width / r.height).toFixed(2)}`).toBeLessThan(0.05);
			// chip filter: DKH → only the all-branches offer
			const chips = page.locator('main [data-momo-offer-filter] a');
			await expect(chips).toHaveCount(3);
			await chips.nth(1).click();
			await expect(page.locator('main [data-momo-offer-list] > li:not([hidden])')).toHaveCount(2);
			await chips.first().click();
			await expect(page.locator('main [data-momo-offer-list] > li:not([hidden])')).toHaveCount(2);
		});
	}

	test('?branch=DKH deep link filters on load and is noindex', async ({ page }) => {
		await page.goto(`${BASE}/en/offers/?branch=DKH`);
		await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/);
		// DKH chip does not exist (only DNB/DKY have scoped offers); unknown/irrelevant code is ignored → both shown
		await expect(page.locator('main [data-momo-offer-list] > li:not([hidden])')).toHaveCount(2);
		await page.goto(`${BASE}/en/offers/?branch=DNB`);
		await expect(page.locator('main [data-momo-offer-filter] a[data-branch="DNB"]')).toHaveAttribute('aria-current', 'page');
	});

	test('single-branch offer prefill: Request CTA carries the branch (screens.md §3.17)', async ({ page }) => {
		test.skip(!HAS_WP, 'needs bin/wp');
		// make offer 108 a Request-CTA offer scoped to one branch
		const before = wp('post', 'meta', 'get', '108', 'momo_offer_cta_type').trim();
		wp('post', 'meta', 'update', '108', 'momo_offer_cta_type', 'request');
		const terms = wp('eval', 'echo implode(",", wp_get_object_terms(108, "momo_branch_scope", ["fields"=>"ids"]));').trim();
		wp('eval', 'wp_set_object_terms(108, [get_term_by("slug","dnb","momo_branch_scope")->term_id], "momo_branch_scope");');
		try {
			await page.goto(`${BASE}/en/offers/`);
			const cta = page.locator('#momo-offer-108 a[href*="/reservation/"]');
			await expect(cta).toHaveAttribute('href', /branch=DNB/);
		} finally {
			wp('post', 'meta', 'update', '108', 'momo_offer_cta_type', before || 'menu');
			wp('eval', `wp_set_object_terms(108, array_map("intval", explode(",", "${terms}")), "momo_branch_scope");`);
		}
	});
});

test.describe('notices (screens.md §3.1 #1, §3.5 #1)', () => {
	test('site-wide notice on home in every language; branch notice only on its branch page', async ({ page }) => {
		for (const lang of LANGS) {
			await page.goto(`${BASE}${prefix(lang)}/`);
			await expect(page.locator('#momo-notice-101')).toBeVisible();
		}
		await page.goto(`${BASE}/en/branches/demo-kitahama/`);
		await expect(page.locator('#momo-notice-103')).toBeVisible();
		await page.goto(`${BASE}/en/branches/demo-namba-minami/`);
		await expect(page.locator('#momo-notice-103')).toHaveCount(0);
	});

	test('dismissible site-wide notice stays dismissed after reload', async ({ page }) => {
		await page.goto(`${BASE}/en/`);
		await page.locator('#momo-notice-101 [data-momo-notice-dismiss], #momo-notice-101 button[aria-label="Close"]').first().click();
		await expect(page.locator('#momo-notice-101')).toBeHidden();
		await page.reload();
		await expect(page.locator('#momo-notice-101')).toBeHidden();
	});

	test('expired notice is not shown (end_at in the past via frozen clock)', async ({ page }) => {
		test.skip(!HAS_WP, 'needs bin/wp');
		wp('option', 'update', 'momos_frozen_now', '2026-12-25T12:00:00+09:00');
		try {
			await page.goto(`${BASE}/en/`);
			await expect(page.locator('#momo-notice-101')).toBeHidden();
		} finally { wp('option', 'delete', 'momos_frozen_now'); }
	});
});

test.describe('SCR-C12 contact (TC-CORP-009, BR-CNT-01)', () => {
	const catcher = path.join(ROOT, '.wp/wp-content/mail-catcher');
	// 5 submissions / 10 min / IP (screens.md §7): reset the counter so each case starts clean.
	test.beforeEach(() => { if (HAS_WP) wp('transient', 'delete', '--all'); });
	for (const lang of ['ja', 'ur', 'en'] as const) {
		test(`valid submission → localized success, mail to HO inbox, nothing stored — ${lang}`, async ({ page }) => {
			test.skip(!HAS_WP, 'needs bin/wp');
			const marker = `QA-CONTACT-${lang}-${Date.now()}`;
			const postsBefore = wp('db', 'query', 'SELECT COUNT(*) FROM wp_posts', '--skip-column-names').trim();
			await page.goto(`${BASE}${prefix(lang)}/contact/`);
			await page.fill('#contact-name', 'QA Tester');
			await page.fill('#contact-email', 'qa.customer@example.com');
			await page.selectOption('#contact-topic', 'general');
			await page.selectOption('#contact-branch', 'DNB');
			await page.fill('#contact-message', `Hello ${marker}`);
			await page.check('#contact-consent');
			await page.locator('#contact-form button[type="submit"]').click();
			await page.waitForLoadState('domcontentloaded');
			await expect(page.locator('main [role="status"]').filter({ hasText: /./ }).first()).toBeVisible();
			expect(new URL(page.url()).pathname).toBe(`${prefix(lang)}/contact/`);
			expect(page.url()).not.toMatch(/qa\.customer|QA%20Tester/);
			const files = fs.readdirSync(catcher).filter((f) => f.endsWith('.json'));
			const hit = files.map((f) => fs.readFileSync(path.join(catcher, f), 'utf8')).find((s) => s.includes(marker));
			expect(hit, 'contact mail not found in mail catcher').toBeTruthy();
			const body = JSON.parse(hit!).text as string;
			expect(body).not.toMatch(/127\.0\.0\.1|Mozilla|HeadlessChrome/); // no IP/UA in body (TC-PRV-015)
			expect(JSON.parse(hit!).to).not.toBe('qa.customer@example.com'); // goes to the HO inbox only
			const postsAfter = wp('db', 'query', 'SELECT COUNT(*) FROM wp_posts', '--skip-column-names').trim();
			expect(postsAfter, 'contact submission stored a post').toBe(postsBefore);
		});
	}

	test('2,001-character message is rejected server-side; consent required', async ({ page }) => {
		await page.goto(`${BASE}/en/contact/`);
		await page.fill('#contact-name', 'QA Tester');
		await page.fill('#contact-email', 'qa.customer@example.com');
		await page.selectOption('#contact-topic', 'general');
		await page.locator('#contact-message').evaluate((el) => { (el as HTMLTextAreaElement).removeAttribute('maxlength'); (el as HTMLTextAreaElement).value = 'x'.repeat(2001); });
		await page.check('#contact-consent');
		await page.locator('#contact-form').evaluate((f) => (f as HTMLFormElement).noValidate = true);
		await page.locator('#contact-form button[type="submit"]').click();
		await page.waitForLoadState('domcontentloaded');
		await expect(page.locator('#contact-errors [role="alert"], main [role="alert"]').first()).toBeVisible();
		await page.locator('#contact-form').evaluate((f) => (f as HTMLFormElement).noValidate = true);
		await page.fill('#contact-message', 'short');
		await page.uncheck('#contact-consent').catch(() => {});
		await page.locator('#contact-form button[type="submit"]').click();
		await page.waitForLoadState('domcontentloaded');
		await expect(page.locator('main [role="alert"]').first()).toBeVisible();
	});
});

test.describe('SCR-C13 FAQ (TC-CORP-003)', () => {
	for (const lang of ['ja', 'ar'] as const) {
		test(`accordion collapsed by default, keyboard toggles aria-expanded, #q- opens item — ${lang}`, async ({ page }) => {
			await page.goto(`${BASE}${prefix(lang)}/faq/`);
			const btns = page.locator('main .momo-accordion__btn');
			expect(await btns.count()).toBeGreaterThanOrEqual(6);
			await expect(btns.first()).toHaveAttribute('aria-expanded', 'false');
			await btns.first().focus();
			await page.keyboard.press('Enter');
			await expect(btns.first()).toHaveAttribute('aria-expanded', 'true');
			await expect(btns.first()).toBeFocused();
			await page.keyboard.press('Space');
			await expect(btns.first()).toHaveAttribute('aria-expanded', 'false');
			for (const b of await btns.all()) expect((await b.boundingBox())!.height).toBeGreaterThanOrEqual(44);
			await page.goto(`${BASE}${prefix(lang)}/faq/#q-food-allergy`);
			await expect(page.locator('#q-food-allergy .momo-accordion__btn')).toHaveAttribute('aria-expanded', 'true');
			// Payments group (TBD) hidden when empty
			await expect(page.locator('#faq-payments')).toHaveCount(0);
		});
	}
});

test.describe('SCR-C22 gallery / C14 news (Could)', () => {
	test('unpublished gallery: 404 and not linked anywhere', async ({ page, request }) => {
		expect((await request.get(`${BASE}/gallery/`)).status()).toBe(404);
		await page.goto(`${BASE}/`);
		await expect(page.locator('a[href$="/gallery/"]')).toHaveCount(0);
	});
});

test.describe('SCR-C19 404 (TC-CORP-006)', () => {
	for (const lang of LANGS) {
		test(`404 status, localized title, four same-language link cards, no stack trace — ${lang}`, async ({ page }) => {
			const r = await page.goto(`${BASE}${prefix(lang)}/no-such-page-qa-404/`);
			expect(r?.status()).toBe(404);
			const links = await page.locator('main .momo-card__link').evaluateAll((as) => as.map((a) => (a as HTMLAnchorElement).pathname));
			expect(links.sort()).toEqual([`${prefix(lang)}/`, `${prefix(lang)}/branches/`, `${prefix(lang)}/menu/`, `${prefix(lang)}/reservation/`].sort());
			expect(await page.content()).not.toMatch(/Fatal error|Stack trace|Warning:/);
		});
	}
});

test.describe('SCR-C02 about', () => {
	for (const lang of LANGS) {
		test(`no halal/claim wording, closing CTA links — ${lang}`, async ({ page }) => {
			await page.goto(`${BASE}${prefix(lang)}/about/`);
			expect(await page.locator('main').innerText()).not.toMatch(CLAIM);
			await expect(page.locator(`main a[href$="${prefix(lang)}/reservation/"]`).first()).toBeVisible();
			await expect(page.locator('main figcaption.momo-illustration, main .momo-illustration')).toHaveCount(0);
		});
	}
});

test.describe('SCR-C09/C10/C11 shells (screens.md §3.9–3.11)', () => {
	for (const lang of LANGS) {
		test(`form present in ${lang}; C10 empty state without storage; C11 bad token → link invalid`, async ({ page }) => {
			await page.goto(`${BASE}${prefix(lang)}/reservation/`);
			await expect(page.locator('main form')).toHaveCount(1);
			await expect(page.locator('main select, main input[name="branch"]').first()).toBeAttached();
			const t = await page.locator('main').innerText();
			if (lang === 'en') expect(t).not.toMatch(/\b(booked|reservation confirmed|booking confirmed)\b/i);
			if (lang === 'ja') expect(t).not.toMatch(/予約完了|予約確定|予約が確定/);
			await page.goto(`${BASE}${prefix(lang)}/reservation/submitted/`);
			await expect(page.locator(`main a[href$="${prefix(lang)}/branches/"]`).first()).toBeVisible();
			await page.goto(`${BASE}${prefix(lang)}/reservation/status/ABCD2345/#t=invalidtoken`);
			await expect(page.locator('main [role="alert"], main .momo-alert, main .momo-notice').filter({ hasText: /./ }).first()).toBeVisible({ timeout: 20_000 });
		});
	}
});
