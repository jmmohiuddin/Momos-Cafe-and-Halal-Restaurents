/**
 * Theme/presentation gap fixes (PRD audit): FR-CORP-012(c) / AC-CORP-10-01 gallery lightbox (keyboard),
 * FR-BR-001(b) / AC-BR-01-04 "Copy Japanese address" on every directory card (+ clipboard fallback) and the reopen
 * date (Doc 07 §6.10 long date), FR-BR-002 A3 / AC-BR-02-03 "Show map", FR-CORP-011(e) offers re-checked client-side
 * (frozen clock), FR-I18N-003(d) polite language bar, FR-CORP-007 A1 maintenance (503, ROLE-01 bypass),
 * AC-FND-01-10 footer ©, F-BR-01 A2 "Call to reserve", facility enums, AC-I18N-01-06 Urdu 200 % (no clipped text),
 * AC-SEO-01-05 every branch/dish ≤ 3 clicks from home.
 *
 * Needs the demo seed (tests/e2e/fixtures/seed.sh) and the same MOMOS_DB_NAME/MOMOS_PORT/MOMOS_URL as the server so
 * bin/wp reaches the same database. Every test that changes data restores it. The "Show map" click test runs only
 * against a server started with MOMOS_GMAPS_EMBED_KEY (set MOMOS_E2E_MAPS_KEY=1 for this run).
 */
import { test, expect, Page } from '@playwright/test';
import { execFileSync } from 'child_process';
import * as path from 'path';

const BASE = process.env.MOMOS_E2E_BASE_URL || process.env.MOMOS_URL || 'http://127.0.0.1:8080';
const ROOT = path.resolve(__dirname, '../../..');
const HAS_WP = !!process.env.MOMOS_DB_NAME;
const KITA = '/branches/demo-kitahama/';
const KYOTO = '/branches/demo-kyoto-kawaramachi/';

function wp(...args: string[]): string {
	return execFileSync(path.join(ROOT, 'bin/wp'), args, { env: process.env, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();
}
function php(code: string): string {
	return wp('eval', code);
}
function meta(id: string, key: string): string | null {
	try { return wp('post', 'meta', 'get', id, key, '--format=json'); } catch { return null; }
}
function restoreMeta(id: string, key: string, json: string | null): void {
	try { wp('post', 'meta', 'delete', id, key); } catch { /* absent */ }
	if (json !== null && json !== '' && json !== 'null') wp('post', 'meta', 'update', id, key, json, '--format=json');
}
function branchId(slug: string): string {
	return wp('post', 'list', '--post_type=momo_branch', `--name=${slug}`, '--post_status=any', '--field=ID');
}

// ---------------------------------------------------------------------------------------------------------------
test.describe('SCR-C22 gallery lightbox (FR-CORP-012(c), AC-CORP-10-01)', () => {
	test.skip(!HAS_WP, 'needs bin/wp access to the seeded database');
	let ids: string[] = [];
	const saved: Record<string, { ill: string | null; inc: string | null; cap: string }> = {};
	let pageId = '';
	let pageStatus = '';
	let savedBranch: string | null = null;
	let savedCat: string | null = null;

	test.beforeAll(() => {
		ids = wp('post', 'list', '--post_type=attachment', '--post_mime_type=image', '--posts_per_page=3', '--orderby=ID', '--order=ASC', '--field=ID').split(/\s+/).filter(Boolean);
		ids.forEach((id, i) => {
			saved[id] = { ill: meta(id, 'momo_image_illustrative'), inc: meta(id, 'momo_gallery_include'), cap: wp('post', 'get', id, '--field=post_excerpt') };
			wp('post', 'meta', 'update', id, 'momo_gallery_include', '1');
			wp('post', 'meta', 'update', id, 'momo_image_illustrative', '0');
			wp('post', 'update', id, `--post_excerpt=写真キャプション${i + 1}`, `--menu_order=${i + 1}`);
		});
		savedBranch = meta(ids[0], 'momo_media_branch');
		wp('post', 'meta', 'update', ids[0], 'momo_media_branch', branchId('demo-kitahama'));
		savedCat = meta(ids[1], 'momo_media_category');
		wp('post', 'meta', 'update', ids[1], 'momo_media_category', wp('term', 'get', 'momo_menu_category', 'curry', '--by=slug', '--field=term_id'));
		pageId = php('echo \\Momos\\Presentation\\SystemPages::map()["gallery"] ?? 0;');
		pageStatus = wp('post', 'get', pageId, '--field=post_status');
		wp('post', 'update', pageId, '--post_status=publish');
	});
	test.afterAll(() => {
		for (const id of ids) {
			restoreMeta(id, 'momo_gallery_include', saved[id].inc);
			restoreMeta(id, 'momo_image_illustrative', saved[id].ill);
			wp('post', 'update', id, `--post_excerpt=${saved[id].cap}`, '--menu_order=0');
		}
		if (ids.length) { restoreMeta(ids[0], 'momo_media_branch', savedBranch); restoreMeta(ids[1], 'momo_media_category', savedCat); }
		if (pageId) wp('post', 'update', pageId, `--post_status=${pageStatus}`);
	});

	test('filter chips (gallery.js): a branch chip shows only that branch\'s photos; All restores', async ({ page }) => {
		await page.goto(`${BASE}/gallery/`);
		const chips = page.locator('[data-momo-gallery-filter] button[data-filter]');
		await expect(chips).toHaveCount(3);
		await page.locator('[data-momo-gallery-filter] button[data-filter^="b-"]').click();
		await expect(page.locator('[data-momo-gallery] > li:visible')).toHaveCount(1);
		await expect(page.locator('[data-momo-gallery-filter] button[data-filter^="b-"]')).toHaveAttribute('aria-pressed', 'true');
		await page.locator('[data-momo-gallery-filter] button[data-filter=""]').click();
		await expect(page.locator('[data-momo-gallery] > li:visible')).toHaveCount(3);
	});

	test('without JS every photo links to the full-size image; gallery.js loads on this page only', async ({ page, request }) => {
		await page.goto(`${BASE}/gallery/`);
		const links = page.locator('main a[data-momo-lb]');
		await expect(links).toHaveCount(3);
		const href = (await links.first().getAttribute('href')) || '';
		expect(href).toMatch(/\/wp-content\/uploads\/.+\.(webp|jpe?g|png)$/);
		const img = await request.get(href);
		expect(img.status()).toBe(200);
		expect(img.headers()['content-type']).toMatch(/^image\//);
		expect(await page.locator('script[src*="gallery.js"]').count()).toBe(1);
		expect(await page.locator('script[src*="screens.js"]').count(), 'screens.js widgets do not occur on C22').toBe(0);
		const home = await (await request.get(`${BASE}/`)).text();
		expect(home).not.toContain('gallery.js');
	});

	test('keyboard: Enter opens, labelled prev/next + arrows move, Esc closes and focus returns to the photo', async ({ page }) => {
		await page.goto(`${BASE}/gallery/`);
		const links = page.locator('main a[data-momo-lb]');
		const dlg = page.locator('dialog[data-momo-lightbox]');
		await links.nth(1).focus();
		await page.keyboard.press('Enter');
		await expect(dlg).toHaveJSProperty('open', true);
		await expect(dlg.locator('[data-momo-lb-cap]')).toHaveText(/写真キャプション2/);
		await expect(dlg.locator('[data-momo-lb-count]')).toHaveText('3枚中2枚目');
		expect(await dlg.locator('[data-momo-lb-img]').getAttribute('src')).toBe(await links.nth(1).getAttribute('href'));
		await expect(dlg.locator('button[data-momo-lb-step="-1"]')).toHaveAttribute('aria-label', '前の写真');
		await expect(dlg.locator('button[data-momo-lb-step="1"]')).toHaveAttribute('aria-label', '次の写真');
		await expect(dlg.locator('[data-momo-lb-close]')).toBeFocused();
		await page.keyboard.press('ArrowRight');
		await expect(dlg.locator('[data-momo-lb-cap]')).toHaveText(/写真キャプション3/);
		await dlg.locator('button[data-momo-lb-step="1"]').click();
		await expect(dlg.locator('[data-momo-lb-cap]')).toHaveText(/写真キャプション1/); // wraps around
		await page.keyboard.press('ArrowLeft');
		await expect(dlg.locator('[data-momo-lb-cap]')).toHaveText(/写真キャプション3/);
		// Focus stays inside the modal dialog while it is open (Tab cycles through its controls).
		for (const key of ['Tab', 'Tab', 'Tab', 'Tab', 'Shift+Tab', 'Shift+Tab', 'Shift+Tab', 'Shift+Tab']) {
			await page.keyboard.press(key);
			expect(await page.evaluate(() => !!document.activeElement?.closest('dialog[data-momo-lightbox]'))).toBe(true);
		}
		await page.keyboard.press('Escape');
		await expect(dlg).toHaveJSProperty('open', false);
		await expect(links.nth(1)).toBeFocused();
	});

	test('click opens; Close button closes and returns focus', async ({ page }) => {
		await page.goto(`${BASE}/gallery/`);
		const first = page.locator('main a[data-momo-lb]').first();
		await first.click();
		const dlg = page.locator('dialog[data-momo-lightbox]');
		await expect(dlg).toHaveJSProperty('open', true);
		await dlg.locator('[data-momo-lb-close]').click();
		await expect(dlg).toHaveJSProperty('open', false);
		await expect(first).toBeFocused();
	});
});

// ---------------------------------------------------------------------------------------------------------------
test.describe('SCR-C04 directory card copy button + reopen date (FR-BR-001(b), AC-BR-01-04, AC-BR-01-01)', () => {
	test('every card has "Copy Japanese address"; clipboard holds the exact address; "Address copied" in role=status', async ({ browser }) => {
		const ctx = await browser.newContext({ permissions: ['clipboard-read', 'clipboard-write'] });
		const page = await ctx.newPage();
		await page.goto(`${BASE}/en/branches/`);
		const cards = page.locator('main .momo-branch-card');
		const n = await cards.count();
		expect(n).toBeGreaterThan(0);
		for (let i = 0; i < n; i++) await expect(cards.nth(i).locator('button[data-momo-copy]')).toHaveCount(1);
		const btn = cards.nth(1).locator('button[data-momo-copy]');
		await expect(btn).toContainText('Copy Japanese address');
		const want = (await btn.getAttribute('data-momo-copy')) || '';
		expect(want).toMatch(/^〒\d{3}-\d{4} /);
		await btn.click();
		await expect(page.locator('[role="status"]').filter({ hasText: 'Address copied' })).toHaveCount(1);
		expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(want);
		await ctx.close();
	});

	test('without the Clipboard API the address is shown in a field and selected', async ({ page }) => {
		await page.addInitScript(() => { Object.defineProperty(navigator, 'clipboard', { value: undefined, configurable: true }); });
		await page.goto(`${BASE}/en/branches/`);
		const btn = page.locator('main .momo-branch-card button[data-momo-copy]').first();
		await expect(btn).toBeVisible();
		const want = (await btn.getAttribute('data-momo-copy')) || '';
		await btn.click();
		const field = page.locator('main .momo-branch-card input[data-momo-copy-text]').first();
		await expect(field).toBeVisible();
		await expect(field).toHaveValue(want);
		await expect(field).toBeFocused();
		const sel = await field.evaluate((el: HTMLInputElement) => [el.selectionStart, el.selectionEnd]);
		expect(sel).toEqual([0, want.length]);
	});

	test('temporarily closed card: "Reopens" with the long date incl. year, no Request', async ({ page }) => {
		test.skip(!HAS_WP, 'needs bin/wp');
		const id = branchId('demo-kyoto-kawaramachi');
		const st = meta(id, 'momo_operating_status');
		const rd = meta(id, 'momo_reopen_date');
		try {
			wp('post', 'meta', 'update', id, 'momo_operating_status', 'temporarily_closed');
			wp('post', 'meta', 'update', id, 'momo_reopen_date', '2026-12-10');
			await page.goto(`${BASE}/en/branches/`);
			const card = page.locator('main .momo-branch-card').filter({ hasText: 'Kyoto' });
			await expect(card).toContainText(/Temporarily closed — reopens Thu 10 Dec 2026/i);
			await expect(card.locator('a[href*="/reservation/?branch="]')).toHaveCount(0);
		} finally {
			restoreMeta(id, 'momo_operating_status', st);
			restoreMeta(id, 'momo_reopen_date', rd);
		}
	});
});

// ---------------------------------------------------------------------------------------------------------------
test.describe('F-BR-01 A2 / facilities on SCR-C05', () => {
	test.skip(!HAS_WP, 'needs bin/wp');

	test('open but not accepting: "Call to reserve" replaces Request a table on the card and the detail page', async ({ page }) => {
		const id = branchId('demo-kyoto-kawaramachi');
		const en = meta(id, 'momo_res_enabled');
		try {
			wp('post', 'meta', 'update', id, 'momo_res_enabled', '0');
			await page.goto(`${BASE}/en/branches/`);
			const card = page.locator('main .momo-branch-card').filter({ hasText: 'Kyoto' });
			await expect(card.locator('a[href*="/reservation/?branch="]')).toHaveCount(0);
			const call = card.locator('a[href^="tel:+81"]').filter({ hasText: 'Call to reserve' });
			await expect(call).toHaveCount(1);
			await page.goto(`${BASE}/en${KYOTO}`);
			const aside = page.locator('main .momo-branch-summary');
			await expect(aside.locator('a[href*="/reservation/?branch="]')).toHaveCount(0);
			await expect(aside.locator('a[href^="tel:+81"]')).toContainText('Call to reserve');
			// An accepting branch keeps Request a table and a plain Call button.
			await page.goto(`${BASE}/en${KITA}`);
			await expect(page.locator('main .momo-branch-summary a[href*="/reservation/?branch="]')).toHaveCount(1);
			await expect(page.locator('main .momo-branch-summary a[href^="tel:+81"]')).not.toContainText('Call to reserve');
		} finally {
			restoreMeta(id, 'momo_res_enabled', en);
		}
	});

	test('facility enums render: prayer yes/no/nearby/unknown, smoking, step-free, seating (multi)', async ({ page }) => {
		const id = branchId('demo-kyoto-kawaramachi');
		const keys = ['momo_prayer_space', 'momo_smoking_policy', 'momo_step_free_access', 'momo_seating_types'];
		const orig = Object.fromEntries(keys.map((k) => [k, meta(id, k)]));
		const facilities = () => page.locator('main .momo-facilities');
		try {
			wp('post', 'meta', 'update', id, 'momo_smoking_policy', 'non_smoking');
			wp('post', 'meta', 'update', id, 'momo_step_free_access', 'partial');
			wp('post', 'meta', 'update', id, 'momo_seating_types', '["table","counter","tatami"]', '--format=json');
			for (const [v, text] of [['yes', 'Prayer space available'], ['no', 'No prayer space'], ['nearby', 'Prayer space nearby']] as const) {
				wp('post', 'meta', 'update', id, 'momo_prayer_space', v);
				await page.goto(`${BASE}/en${KYOTO}`);
				await expect(facilities()).toContainText(text);
				await expect(facilities()).toContainText('Non-smoking');
				await expect(facilities()).toContainText('Partly step-free');
				await expect(facilities()).toContainText('Table, Counter, Tatami');
			}
			wp('post', 'meta', 'update', id, 'momo_prayer_space', 'unknown');
			await page.goto(`${BASE}/en${KYOTO}`);
			await expect(facilities()).not.toContainText('Prayer space');
			await expect(facilities()).toContainText('Non-smoking');
		} finally {
			for (const k of keys) restoreMeta(id, k, orig[k]);
		}
	});
});

// ---------------------------------------------------------------------------------------------------------------
test.describe('SCR-C05 map facade "Show map" (FR-BR-002 A3, AC-BR-02-03)', () => {
	test('without key/setting: links only, no Google request', async ({ page }) => {
		test.skip(process.env.MOMOS_E2E_MAPS_KEY === '1', 'server runs with a Maps key');
		const google: string[] = [];
		page.on('request', (r) => { if (/maps\.googleapis\.com|google\.com\/maps\/embed/.test(r.url())) google.push(r.url()); });
		await page.goto(`${BASE}/en${KITA}`);
		await expect(page.locator('main .momo-map a[href*="google.com/maps/"]').first()).toBeVisible();
		await expect(page.locator('[data-momo-map-embed]')).toHaveCount(0);
		expect(google).toEqual([]);
	});

	test('with key + setting: nothing from Google before the click; the click inserts a titled embed iframe', async ({ page }) => {
		test.skip(process.env.MOMOS_E2E_MAPS_KEY !== '1', 'needs a server started with MOMOS_GMAPS_EMBED_KEY');
		const google: string[] = [];
		await page.route(/https:\/\/www\.google\.com\/maps\/embed\/.*/, (r) => r.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>map</title>' }));
		page.on('request', (r) => { if (/maps\.googleapis\.com|google\.com\/maps\/embed/.test(r.url())) google.push(r.url()); });
		await page.goto(`${BASE}/en${KITA}`);
		const btn = page.locator('main button[data-momo-map-embed]');
		await expect(btn).toHaveText('Show map');
		await page.waitForTimeout(500);
		expect(google, 'no Google request before "Show map"').toEqual([]);
		await btn.click();
		const frame = page.locator('main .momo-map iframe');
		await expect(frame).toHaveAttribute('title', /Map of .*Kitahama/);
		await expect(frame).toHaveAttribute('src', /^https:\/\/www\.google\.com\/maps\/embed\/v1\/place\?key=/);
		await expect(frame).toHaveAttribute('referrerpolicy', 'no-referrer-when-downgrade');
		await expect.poll(() => google.length).toBeGreaterThan(0);
		const csp = (await (await page.request.get(`${BASE}/en${KITA}`)).headers())['content-security-policy-report-only'] || '';
		if (csp) expect(csp).toMatch(/frame-src[^;]*https:\/\/www\.google\.com/);
	});
});

// ---------------------------------------------------------------------------------------------------------------
test.describe('offers re-checked client-side (FR-CORP-011(e))', () => {
	test('a cached card past its end is hidden at view time (frozen clock), visible before', async ({ browser }) => {
		const probe = await browser.newPage();
		await probe.goto(`${BASE}/en/offers/`);
		const card = probe.locator('main [data-momo-offer]').first();
		await expect(card).toHaveCount(1);
		const end = Number(await card.getAttribute('data-end'));
		const id = await card.getAttribute('id');
		expect(end).toBeGreaterThan(0);
		await probe.close();

		const after = await browser.newPage();
		await after.clock.install({ time: end + 60_000 });
		await after.goto(`${BASE}/en/offers/`);
		await expect(after.locator(`#${id}`)).toBeHidden();
		expect(await after.locator(`#${id}`).evaluate((el) => getComputedStyle(el.closest('li') as Element).display)).toBe('none');
		await after.close();

		const before = await browser.newPage();
		await before.clock.install({ time: end - 3_600_000 });
		await before.goto(`${BASE}/en/offers/`);
		await expect(before.locator(`#${id}`)).toBeVisible();
		await before.close();
	});
});

// ---------------------------------------------------------------------------------------------------------------
test.describe('language suggestion bar is announced politely (FR-I18N-003(d))', () => {
	test('role=status / aria-live=polite region receives the suggestion after load', async ({ browser, request }) => {
		const html = await (await request.get(`${BASE}/`)).text();
		expect(html).toMatch(/<span class="momo-visually-hidden" role="status" aria-live="polite" data-momo-langbar-live><\/span>/);
		const ctx = await browser.newContext({ locale: 'en-US' });
		const page = await ctx.newPage();
		await page.goto(`${BASE}/`, { waitUntil: 'load' });
		const bar = page.locator('[data-momo-langbar]');
		await expect(bar).toBeVisible();
		const live = bar.locator('[role="status"][aria-live="polite"]');
		await expect(live).toHaveText('This page is available in English.');
		await ctx.close();
	});
});

// ---------------------------------------------------------------------------------------------------------------
test.describe('maintenance mode toggle (FR-CORP-007 A1, AC-CORP-07-02)', () => {
	test.skip(!HAS_WP, 'needs bin/wp');
	test('anonymous: 503 + Retry-After + RTL ar/ur blocks; logged-in ROLE-01 sees the site', async ({ request, page }) => {
		try {
			php('\\Momos\\Kernel\\Settings::set("momo_maintenance_mode", "1");');
			const r = await request.get(`${BASE}/en/menu/`, { headers: { cookie: '' } });
			expect(r.status()).toBe(503);
			expect(r.headers()['retry-after']).toBe('600');
			const body = await r.text();
			expect(body).toMatch(/<section lang="ar" dir="rtl"/);
			expect(body).toMatch(/<section lang="ur" dir="rtl"/);
			await page.goto(`${BASE}/wp-login.php`);
			await page.fill('#user_login', 'superadmin');
			await page.fill('#user_pass', 'Momos-Dev-2026!');
			await page.click('#wp-submit');
			await page.waitForURL(/wp-admin/);
			const res = await page.goto(`${BASE}/en/menu/`);
			expect(res?.status()).toBe(200);
			await expect(page.locator('main')).toBeVisible();
		} finally {
			php('\\Momos\\Kernel\\Settings::set("momo_maintenance_mode", "0");');
		}
		expect((await request.get(`${BASE}/en/menu/`)).status()).toBe(200);
	});
});

// ---------------------------------------------------------------------------------------------------------------
test.describe('footer © only with an operator name (AC-FND-01-10)', () => {
	test.skip(!HAS_WP, 'needs bin/wp');
	test('no name → no ©; saved → ja shows it; en once the approved translation has it', async ({ page }) => {
		const brand = wp('option', 'get', 'momos_opt_momo-brand', '--format=json');
		let i18n: string | null = null;
		try { i18n = wp('option', 'get', 'momos_option_i18n', '--format=json'); } catch { /* none */ }
		const footer = () => page.locator('footer');
		try {
			php('\\Momos\\Kernel\\Settings::set("momo_legal_operator_name", "");');
			for (const p of ['/', '/en/']) {
				await page.goto(`${BASE}${p}`);
				await expect(footer()).not.toContainText('©');
			}
			php('\\Momos\\Kernel\\Settings::set("momo_legal_operator_name", "デモ運営株式会社");');
			await page.goto(`${BASE}/`);
			await expect(footer()).toContainText('©');
			await expect(footer()).toContainText('デモ運営株式会社');
			await page.goto(`${BASE}/en/`);
			await expect(footer(), 'no approved English operator name yet → no mixed-language ©').not.toContainText('デモ運営株式会社');
			await expect(footer()).not.toContainText('©');
			php(`\\Momos\\Kernel\\I18n\\Translations::save("option", "momo-brand", "en", array_merge(\\Momos\\Kernel\\I18n\\Translations::option_data("momo-brand", "en"), ["momo_legal_operator_name" => "Demo Operator KK"]), "approved");`);
			await page.goto(`${BASE}/en/`);
			await expect(footer()).toContainText('©');
			await expect(footer()).toContainText('Demo Operator KK');
			php('\\Momos\\Kernel\\Settings::set("momo_legal_operator_name", "");');
			await page.goto(`${BASE}/en/`);
			await expect(footer(), 'source cleared → hidden in every language').not.toContainText('©');
		} finally {
			wp('option', 'update', 'momos_opt_momo-brand', brand, '--format=json');
			if (i18n !== null) wp('option', 'update', 'momos_option_i18n', i18n, '--format=json');
		}
	});
});

// ---------------------------------------------------------------------------------------------------------------
test.describe('Urdu branch page at 200 % zoom (AC-I18N-01-06)', () => {
	test('no element in main clips its text (overflow hidden/clip with scrollHeight > clientHeight)', async ({ browser }) => {
		// 200 % browser zoom of a 1280 × 900 window = 640 × 450 CSS px at device scale factor 2.
		const ctx = await browser.newContext({ viewport: { width: 640, height: 450 }, deviceScaleFactor: 2 });
		const page = await ctx.newPage();
		const res = await page.goto(`${BASE}/ur${KITA}`);
		expect(res?.status()).toBe(200);
		await page.evaluate(() => document.fonts.ready);
		// Intentional truncation with a visible expand control (notice "more", offer "Show all conditions") is opened
		// first: the check is about glyphs/diacritics cut off by a box, not about collapsed-by-design text.
		for (const t of await page.locator('main [data-momo-notice-more]:visible, main [data-momo-clamp-toggle]:visible').all()) await t.click();
		const clipped = await page.evaluate(() => {
			const out: string[] = [];
			for (const el of Array.from(document.querySelectorAll('main *')) as HTMLElement[]) {
				const cs = getComputedStyle(el);
				if (!/hidden|clip/.test(cs.overflowY + cs.overflow) || cs.display === 'none' || !el.offsetParent) continue;
				if (el.closest('.momo-visually-hidden') || (el.clientWidth <= 1 && el.clientHeight <= 1)) continue; // a11y-only text
				if (!(el.innerText || '').trim()) continue; // images, maps, decorative boxes
				if (el.scrollHeight > el.clientHeight + 1) out.push(`${el.tagName.toLowerCase()}.${el.className} ${el.scrollHeight}>${el.clientHeight} "${el.innerText.trim().slice(0, 40)}"`);
			}
			return out;
		});
		expect(clipped).toEqual([]);
		await ctx.close();
	});
});

// ---------------------------------------------------------------------------------------------------------------
test.describe('crawl depth (AC-SEO-01-05)', () => {
	test.skip(!HAS_WP, 'needs bin/wp for the list of published branches and dishes');
	test('every published branch and dish is reachable within 3 clicks from the home page', async ({ request }) => {
		test.setTimeout(600_000);
		const want = php('foreach (\\Momos\\Branch\\Repository\\BranchRepository::public_ids("ja") as $i) { echo get_permalink($i), "\\n"; } foreach (get_posts(["post_type" => "momo_dish", "post_status" => "publish", "numberposts" => -1, "fields" => "ids"]) as $i) { echo get_permalink($i), "\\n"; }')
			.split('\n').map((s) => s.trim()).filter(Boolean).map((u) => new URL(u).pathname);
		expect(want.length).toBeGreaterThan(10);
		const norm = (href: string, from: string): string | null => {
			try {
				const u = new URL(href, from);
				if (u.origin !== new URL(BASE).origin) return null;
				if (/^\/(en|ar|ur|hi|bn)\//.test(u.pathname)) return null; // same-language crawl (ja root)
				if (/\/wp-(content|admin|json|includes)|wp-login|\.(xml|webp|jpe?g|png|svg|css|js|pdf)$/.test(u.pathname)) return null;
				return u.pathname;
			} catch { return null; }
		};
		const depth = new Map<string, number>([['/', 0]]);
		let frontier = ['/'];
		for (let d = 0; d < 3; d++) {
			const next: string[] = [];
			for (const p of frontier) {
				const r = await request.get(`${BASE}${p}`);
				if (r.status() !== 200 || !(r.headers()['content-type'] || '').includes('text/html')) continue;
				const html = await r.text();
				for (const m of html.matchAll(/<a\s[^>]*href="([^"#]+)/g)) {
					const n = norm(m[1].replace(/&#038;|&amp;/g, '&'), `${BASE}${p}`);
					if (n && !depth.has(n)) { depth.set(n, d + 1); next.push(n); }
				}
			}
			frontier = next;
		}
		const missing = want.filter((p) => !depth.has(p) || (depth.get(p) as number) > 3);
		expect(missing, 'not reachable within 3 clicks').toEqual([]);
	});
});
