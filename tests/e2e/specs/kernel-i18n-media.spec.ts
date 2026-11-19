/**
 * Kernel / i18n fixes on the seeded site:
 *  - FR-FND-001(d), AC-FND-01-03, FR-CORP-007: a hidden language URL returns the JAPANESE 404 — HTTP 404, noindex,
 *    <html lang="ja" dir="ltr">, Japanese 404 strings, no Arabic 404 strings and no links into the hidden language.
 *  - PRD §18.4: external_ref (Phase 2 POS IDs) never appears in HTML, JSON-LD or REST output.
 */
import { test, expect } from '@playwright/test';
import { execFileSync } from 'child_process';
import * as path from 'path';

const BASE = process.env.MOMOS_E2E_BASE_URL || process.env.MOMOS_URL || 'http://127.0.0.1:8080';
const ROOT = path.resolve(__dirname, '../../..');
const HAS_WP = !!process.env.MOMOS_DB_NAME;
function wp(...args: string[]): string {
	return execFileSync(path.join(ROOT, 'bin/wp'), args, { env: process.env, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
}

test.describe('hidden language → Japanese 404 (AC-FND-01-03)', () => {
	test.skip(!HAS_WP, 'needs MOMOS_DB_NAME for WP-CLI');
	test.afterAll(() => { wp('option', 'delete', 'momos_languages_enabled'); });

	test('/ar/branches/ with Arabic hidden renders the Japanese 404 page', async ({ page }) => {
		wp('option', 'update', 'momos_languages_enabled', '["ja","en","ur","hi","bn"]', '--format=json');
		const res = await page.goto(`${BASE}/ar/branches/`);
		expect(res?.status()).toBe(404);
		const html = await page.content();
		await expect(page.locator('html')).toHaveAttribute('lang', 'ja');
		await expect(page.locator('html')).toHaveAttribute('dir', 'ltr');
		await expect(page.locator('h1')).toContainText('ページが見つかりません');
		expect(html).not.toContain('الصفحة غير موجودة');
		expect(html).not.toContain('الصفحة التي تبحث عنها');
		expect(html).not.toMatch(/lang="ar"/);
		expect(html).not.toMatch(/href="[^"]*\/ar\//);
		const robots = (await page.locator('meta[name="robots"]').getAttribute('content')) || res?.headers()['x-robots-tag'] || '';
		expect(robots).toContain('noindex');
	});
});

test.describe('external_ref is never exposed (PRD §18.4)', () => {
	test.skip(!HAS_WP, 'needs MOMOS_DB_NAME for WP-CLI');
	const MARK = 'QA-EXTREF-7731';
	let branch = '';
	let dish = '';
	test.beforeAll(() => {
		branch = wp('post', 'list', '--post_type=momo_branch', '--name=demo-kitahama', '--field=ID').trim();
		dish = wp('post', 'list', '--post_type=momo_dish', '--name=mixed-grill-platter', '--field=ID').trim();
		for (const id of [branch, dish]) wp('post', 'meta', 'update', id, 'momo_external_ref', MARK);
		wp('momos', 'cache');
	});
	test.afterAll(() => {
		for (const id of [branch, dish]) if (id) wp('post', 'meta', 'delete', id, 'momo_external_ref');
	});

	test('branch and dish pages, JSON-LD and REST do not contain it', async ({ request }) => {
		expect(branch).toMatch(/^\d+$/);
		expect(dish).toMatch(/^\d+$/);
		for (const p of ['/branches/demo-kitahama/', '/en/branches/demo-kitahama/', '/menu/grill-kebab/mixed-grill-platter/', '/en/menu/grill-kebab/mixed-grill-platter/', '/menu/']) {
			const r = await request.get(`${BASE}${p}`);
			expect(r.status(), p).toBe(200);
			const body = await r.text();
			expect(body, p).not.toContain(MARK);
			expect(body, p).not.toContain('external_ref');
		}
		for (const p of [`/wp-json/wp/v2/momo_dish/${dish}`, `/wp-json/wp/v2/momo_branch/${branch}`, '/wp-json/momos/v1/branches/KIT/reservation-rules', '/wp-json/wp/v2/search?search=grill']) {
			const r = await request.get(`${BASE}${p}`);
			expect(await r.text(), p).not.toContain(MARK);
		}
	});
});
