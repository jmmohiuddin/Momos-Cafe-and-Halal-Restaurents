/**
 * QA (customer team) — SCR-C04 branch directory and SCR-C05 branch detail against docs/specs/screens.md §3.4/3.5,
 * branch.md, Doc 07 §6.10 and Doc 12: TC-BR-001/002/003/004/005/016, AC-BR-04-01, TC-NAV-002 (branch bar),
 * TC-I18N-005 (24 h times), DEF-009 (postal code twice), DEF-010 (after-midnight display).
 *
 * Needs the demo seed (tests/e2e/fixtures/seed.sh) and — for the frozen-clock and state-changing tests — the
 * same MOMOS_DB_NAME/MOMOS_PORT/MOMOS_URL env as the server so bin/wp reaches the same database. Every test that
 * changes data restores it in afterEach/afterAll.
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
function freeze(iso: string | null): void {
	try {
		if (iso) wp('option', 'update', 'momos_frozen_now', iso);
		else wp('option', 'delete', 'momos_frozen_now');
	} catch { /* option absent */ }
}
async function overflow(page: Page) {
	return page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
}

const NAMBA = '/branches/demo-namba-minami/';
const KITA = '/branches/demo-kitahama/';
const KYOTO = '/branches/demo-kyoto-kawaramachi/';

test.describe('SCR-C04 directory (TC-BR-001/002)', () => {
	for (const lang of LANGS) {
		test(`every public branch, no TEST branch, card fields — ${lang}`, async ({ page }) => {
			const res = await page.goto(`${BASE}${prefix(lang)}/branches/`);
			expect(res?.status()).toBe(200);
			const cards = page.locator('main .momo-branch-card');
			await expect(cards).toHaveCount(3);
			await expect(page.locator('main')).not.toContainText(/TST|test-branch|TEST BRANCH/i);
			for (const card of await cards.all()) {
				// name heading, tel: link (E.164), Request (?branch=CODE), Directions, Details
				await expect(card.locator('h2, h3').first()).not.toHaveText('');
				await expect(card.locator('a[href^="tel:+81"]')).toHaveCount(1);
				await expect(card.locator('a[href*="/reservation/?branch="]')).toHaveCount(1);
				await expect(card.locator('a[href*="google.com/maps/dir/"]')).toHaveCount(1);
				await expect(card.locator('.momo-open')).toHaveCount(1);
				// screens.md §3.4 #3 / TC-BR-002: today's periods + last order on every card.
				expect(await card.innerText(), 'directory card shows no "today" opening periods').toMatch(/\d{2}:\d{2}\s*–\s*\d{2}:\d{2}/);
			}
			expect(await overflow(page)).toBeLessThanOrEqual(0);
		});
	}
});

test.describe('SCR-C05 address block (DEF-009, TC-BR-002)', () => {
	for (const lang of ['ja', 'en', 'ar'] as const) {
		test(`Japanese address shows the postal code once (detail + directory) — ${lang}`, async ({ page }) => {
			await page.goto(`${BASE}${prefix(lang)}${NAMBA}`);
			const ja = page.locator('main .momo-address [lang="ja"]').first();
			const txt = (await ja.innerText()).trim();
			expect((txt.match(/556-0000/g) || []).length, `address text: "${txt}"`).toBe(1);
			expect(txt.startsWith('〒556-0000')).toBeTruthy();
			const copy = await page.locator('main .momo-address__copy').getAttribute('data-momo-copy');
			expect((copy!.match(/556-0000/g) || []).length, `copy payload: "${copy}"`).toBe(1);
			await page.goto(`${BASE}${prefix(lang)}/branches/`);
			const dir = await page.locator('main .momo-branch-card').nth(1).innerText();
			expect((dir.match(/556-0000/g) || []).length, `directory card: "${dir}"`).toBe(1);
		});
	}
});

test.describe('SCR-C05 weekly hours (DEF-010, AC-BR-04-01, Doc 07 §6.10 h23)', () => {
	for (const lang of LANGS) {
		test(`after-midnight close is "02:00" with a next-day marker; times are 2-digit 24 h — ${lang}`, async ({ page }) => {
			await page.goto(`${BASE}${prefix(lang)}${NAMBA}`);
			const table = page.locator('main table.momo-hours');
			await expect(table).toBeVisible();
			const friday = table.locator('tbody tr').nth(4);
			const hoursCell = (await friday.locator('td').first().innerText()).trim();
			const loCell = (await friday.locator('td').nth(1).innerText()).trim();
			// Every time printed in the table is HH:MM (h23, e.g. 02:00 — never 2:00).
			const all = (await table.innerText()).match(/\d{1,2}:\d{2}/g) || [];
			for (const t of all) expect(t, `time "${t}" in hours table`).toMatch(/^\d{2}:\d{2}$/);
			expect(hoursCell).toContain('17:00–02:00');
			expect(loCell).toContain('01:30');
			// Next-day marker: the cell must say more than the bare range (e.g. "(next day)" / 「翌」).
			expect(hoursCell.replace(/11:30–15:00|17:00–02:00|[·\s]/g, '').length, `next-day label missing in "${hoursCell}"`).toBeGreaterThan(0);
		});
	}

	test('regular closed weekday shows a "Closed" text cell (Kyoto, Tuesday)', async ({ page }) => {
		await page.goto(`${BASE}/en${KYOTO}`);
		const tue = page.locator('main table.momo-hours tbody tr').nth(1);
		await expect(tue).toContainText('Closed');
	});
});

test.describe('SCR-C05 content and empty rules (TC-BR-004/005)', () => {
	for (const lang of LANGS) {
		test(`sections, no placeholders, halal block present, no illustration label — ${lang}`, async ({ page }) => {
			const res = await page.goto(`${BASE}${prefix(lang)}${NAMBA}`);
			expect(res?.status()).toBe(200);
			await expect(page.locator('h1')).toHaveCount(1);
			const main = page.locator('main');
			const text = await main.innerText();
			for (const bad of ['null', 'undefined', '[acf', 'please inquire', 'N/A', 'お問い合わせください']) expect(text).not.toContain(bad);
			// Breadcrumb Home › Branches › {Branch}
			await expect(page.locator('main nav.momo-breadcrumbs li')).toHaveCount(3);
			// Action row: Request (?branch=DNB) · Call · Directions
			await expect(page.locator('main a[href*="/reservation/?branch=DNB"]').first()).toBeVisible();
			await expect(page.locator('main a[href="tel:+81600000002"]').first()).toBeVisible();
			// Map facade: links present; no Google Maps JS (TC-BR-016) is asserted below.
			await expect(page.locator('main a[href*="google.com/maps/search/"]')).toHaveCount(1);
			// Section 9: CMP-HalalStatement (branch) is "never omitted" — no_claim template when no record (screens.md §3.5 #9).
			// Exactly one CMP-HalalStatement inside the #branch-halal section (the union selector `#branch-halal, .momo-halal`
			// counted the section wrapper and its card twice once the block rendered — QA-006).
			await expect(page.locator('main #branch-halal .momo-halal'), 'branch halal + alcohol section (#9) missing').toHaveCount(1);
			// Section 12: branch menu link → {P}/menu/?branch=DNB
			await expect(page.locator(`main a[href$="${prefix(lang)}/menu/?branch=DNB"]`).first()).toBeAttached();
			// No illustration label anywhere on a branch page (DEC-047, screens.md §3.5 AC) — branch facts only.
			await expect(page.locator('main .momo-branch-sec:not(#branch-offers) figcaption')).toHaveCount(0);
		});
	}

	test('closures in the next 30 days are listed; closed-all-day date shown (Kitahama 29 Nov)', async ({ page }) => {
		test.skip(!HAS_WP, 'needs bin/wp');
		freeze('2026-11-19T12:00:00+09:00');
		try {
			await page.goto(`${BASE}/en${KITA}`);
			await expect(page.locator('main .momo-closures')).toContainText('29 Nov 2026');
			await page.goto(`${BASE}/en${NAMBA}`);
			await expect(page.locator('main .momo-closures')).toContainText(/1 Dec 2026[\s\S]*18:00–21:00/);
		} finally { freeze(null); }
	});

	test('closure beyond 30 days is not listed', async ({ page }) => {
		test.skip(!HAS_WP, 'needs bin/wp');
		freeze('2026-10-15T12:00:00+09:00'); // 45 days before 29 Nov
		try {
			await page.goto(`${BASE}/en${KITA}`);
			await expect(page.locator('main')).not.toContainText('29 Nov 2026');
		} finally { freeze(null); }
	});

	test('map facade loads no Google Maps JS before activation (TC-BR-016)', async ({ page }) => {
		const google: string[] = [];
		page.on('request', (r) => { if (/maps\.googleapis\.com|maps\.gstatic\.com/.test(r.url())) google.push(r.url()); });
		await page.goto(`${BASE}/en${NAMBA}`, { waitUntil: 'networkidle' });
		expect(google).toEqual([]);
		const dir = await page.locator('main .momo-map a[href*="/maps/dir/"]').getAttribute('href');
		expect(dir).toContain('destination=34.66094%2C135.50121');
	});
});

test.describe('open-now status with a frozen JST clock (TC-BR-003, AC-BR-04-01)', () => {
	test.skip(!HAS_WP, 'needs bin/wp');
	test.afterEach(() => freeze(null));

	test('Sat 00:30 JST: Namba open (Friday period until 02:00), Kitahama closed — device zone independent', async ({ browser }) => {
		freeze('2026-11-21T00:30:00+09:00');
		for (const tz of ['Asia/Tokyo', 'America/New_York', 'Asia/Dhaka']) {
			const ctx = await browser.newContext({ timezoneId: tz });
			const page = await ctx.newPage();
			await page.goto(`${BASE}/en${NAMBA}`);
			const st = page.locator('main .momo-branch-aside .momo-open');
			await expect(st).toHaveClass(/momo-open--(open|closing_soon)/);
			await expect(st).toContainText('02:00');
			await page.goto(`${BASE}/en${KITA}`);
			await expect(page.locator('main .momo-branch-aside .momo-open')).toHaveClass(/momo-open--(closed|opens_later)/);
			await ctx.close();
		}
	});

	test('Sun 01:00 JST: Namba open now (Saturday 17:00–02:00) — AC-BR-04-01', async ({ page }) => {
		freeze('2026-11-22T01:00:00+09:00');
		await page.goto(`${BASE}/en${NAMBA}`);
		await expect(page.locator('main .momo-branch-aside .momo-open')).toHaveClass(/momo-open--(open|closing_soon)/);
		// "Today" in the hours table is the business day (Saturday period still running) or Sunday — but never "2:00".
		expect(await page.locator('main .momo-branch-aside').innerText()).not.toMatch(/(^|[^\d])2:00/);
	});

	test('closure day: Kitahama 29 Nov at 12:00 is closed (closure), not "open"', async ({ page }) => {
		freeze('2026-11-29T12:00:00+09:00');
		await page.goto(`${BASE}/en${KITA}`);
		await expect(page.locator('main .momo-branch-aside .momo-open')).not.toHaveClass(/momo-open--open/);
		// The sticky panel's "Today" line must not advertise the regular Sunday hours on a closed-all-day date.
		expect.soft(await page.locator('main .momo-branch-aside').innerText(), 'panel shows regular hours on a closure day').not.toMatch(/11:00–21:00/);
		await page.goto(`${BASE}/en/branches/`);
		await expect(page.locator('main .momo-branch-card').first().locator('.momo-open')).not.toHaveClass(/momo-open--open/);
	});

	test('special hours: Namba 1 Dec 12:00 closed, opens 18:00; 19:00 open until 21:00', async ({ page }) => {
		freeze('2026-12-01T12:00:00+09:00');
		await page.goto(`${BASE}/en${NAMBA}`);
		let st = page.locator('main .momo-branch-aside .momo-open');
		await expect(st).not.toHaveClass(/momo-open--open/);
		await expect(st).toContainText('18:00');
		await expect(page.locator('main .momo-branch-summary__today'), 'sticky panel "Today" ignores special hours').toContainText('18:00–21:00');
		freeze('2026-12-01T19:00:00+09:00');
		await page.goto(`${BASE}/en${NAMBA}`);
		st = page.locator('main .momo-branch-aside .momo-open');
		await expect(st).toHaveClass(/momo-open--(open|closing_soon)/);
		await expect(st).toContainText('21:00');
	});

	test('"(today)" marker follows the frozen JST date', async ({ page }) => {
		freeze('2026-11-24T10:00:00+09:00'); // Tuesday
		await page.goto(`${BASE}/en${KYOTO}`);
		const today = page.locator('main table.momo-hours tbody tr.is-today th');
		await expect(today).toContainText('Tuesday');
	});
});

test.describe('branch states (screens.md §3.5, TC-BR-001/014, BR-RES-10)', () => {
	test.skip(!HAS_WP, 'needs bin/wp');
	// Look the Kyoto branch up by slug: post IDs shift when other fixtures create posts before seeding.
	const KY_ID = HAS_WP ? wp('post', 'list', '--post_type=momo_branch', '--name=demo-kyoto-kawaramachi', '--post_status=any', '--field=ID', '--format=ids').trim() : '0';
	test.afterEach(() => {
		wp('post', 'meta', 'update', KY_ID, 'momo_operating_status', 'open');
		try { wp('post', 'meta', 'delete', KY_ID, 'momo_reopen_date'); } catch { /* none */ }
	});

	test('temporarily closed: chip on directory, no Request anywhere, mobile bar Call · Directions', async ({ browser }) => {
		wp('post', 'meta', 'update', KY_ID, 'momo_operating_status', 'temporarily_closed');
		wp('post', 'meta', 'update', KY_ID, 'momo_reopen_date', '2026-12-20');
		const ctx = await browser.newContext({ viewport: { width: 390, height: 844 } });
		const page = await ctx.newPage();
		await page.goto(`${BASE}/en/branches/`);
		const card = page.locator('main .momo-branch-card').nth(2);
		await expect(card).toContainText(/temporarily closed/i);
		await expect(card.locator('a[href*="/reservation/?branch=DKY"]')).toHaveCount(0);
		await page.goto(`${BASE}/en${KYOTO}`);
		expect((await page.goto(`${BASE}/en${KYOTO}`))?.status()).toBe(200);
		await expect(page.locator('a[href*="/reservation/?branch=DKY"]')).toHaveCount(0);
		const bar = page.locator('nav.momo-bar a');
		await expect(bar).toHaveCount(2);
		await expect(bar.nth(0)).toHaveAttribute('href', /^tel:/);
		await expect(bar.nth(1)).toHaveAttribute('href', /maps\/dir/);
		await ctx.close();
	});

	test('permanently closed: 301 to the same-language directory and absent from lists', async ({ request, page }) => {
		wp('post', 'meta', 'update', KY_ID, 'momo_operating_status', 'closed_permanently');
		for (const lang of ['ja', 'en', 'ar']) {
			const r = await request.get(`${BASE}${prefix(lang)}${KYOTO}`, { maxRedirects: 0 });
			expect(r.status(), lang).toBe(301);
			expect(new URL(r.headers()['location'], BASE).pathname).toBe(`${prefix(lang)}/branches/`);
		}
		await page.goto(`${BASE}/en/branches/`);
		await expect(page.locator('main .momo-branch-card')).toHaveCount(2);
		await expect(page.locator('footer')).not.toContainText('Kyoto');
	});

	test('reservations off for a branch (momo_res_enabled=0): no Request, bar Call · Directions', async ({ browser }) => {
		wp('post', 'meta', 'update', KY_ID, 'momo_res_enabled', '0');
		try {
			const ctx = await browser.newContext({ viewport: { width: 390, height: 844 } });
			const page = await ctx.newPage();
			await page.goto(`${BASE}/en${KYOTO}`);
			await expect(page.locator('a[href*="/reservation/?branch=DKY"]')).toHaveCount(0);
			await expect(page.locator('nav.momo-bar a')).toHaveCount(2);
			await ctx.close();
		} finally { wp('post', 'meta', 'update', KY_ID, 'momo_res_enabled', '1'); }
	});

	test('TEST branch is 404 for visitors', async ({ request }) => {
		const r = await request.get(`${BASE}/branches/test-branch/`, { maxRedirects: 0 });
		expect(r.status()).toBe(404);
	});
});

test.describe('branch detail at 390 / 1280 (screens.md §2, §3.5 responsive)', () => {
	for (const [w, h] of [[390, 844], [1280, 900]] as const) {
		for (const lang of LANGS) {
			test(`no horizontal scroll; bar only on mobile — ${lang} @${w}`, async ({ browser }) => {
				const ctx = await browser.newContext({ viewport: { width: w, height: h } });
				const page = await ctx.newPage();
				await page.goto(`${BASE}${prefix(lang)}${NAMBA}`);
				expect(await overflow(page)).toBeLessThanOrEqual(0);
				if (w < 768) {
					const bar = page.locator('nav.momo-bar a');
					await expect(bar).toHaveCount(3);
					await expect(bar.nth(0)).toHaveAttribute('href', /reservation\/\?branch=DNB/);
					await expect(bar.nth(1)).toHaveAttribute('href', 'tel:+81600000002');
					await expect(bar.nth(2)).toHaveAttribute('href', /maps\/dir/);
					for (const a of await bar.all()) expect((await a.boundingBox())!.height).toBeGreaterThanOrEqual(48);
				} else {
					await expect(page.locator('nav.momo-bar')).toBeHidden();
				}
				await ctx.close();
			});
		}
	}
});
