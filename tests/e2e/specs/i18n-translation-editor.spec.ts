/**
 * i18n / content editors — regression for QA-049 and QA-069.
 *  - QA-049 (DEC-062, FR-I18N-006, TC-I18N-005): the bn allergen table headings use Western digits.
 *  - QA-069 (DEF-016): the stand-alone translation editor (SCR-A11 "Edit translation") saves with the
 *    nonce it issues, and a forged POST without that nonce saves nothing.
 *
 * Accounts: seeded demo staff (password Momos-Dev-2026!). Data created here is synthetic (QA-I18N marker).
 */
import { test, expect, Browser, Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import * as path from 'node:path';

const BASE = (process.env.MOMOS_E2E_BASE_URL || process.env.MOMOS_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const ROOT = path.resolve(__dirname, '../../..');
const PASSWORD = 'Momos-Dev-2026!';
const NATIVE_DIGITS = /[٠-٩۰-۹०-९০-৯０-９]/;

function wpEval(php: string): string {
	return execFileSync(path.join(ROOT, 'bin/wp'), ['eval', php], { cwd: ROOT, env: { ...process.env, MOMOS_URL: process.env.MOMOS_URL || BASE }, encoding: 'utf8' }).trim();
}

async function login(browser: Browser, user: string): Promise<Page> {
	wpEval(`global $wpdb; $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%momos_lt_%'"); wp_cache_flush(); echo 'ok';`);
	const page = await (await browser.newContext()).newPage();
	await page.goto(`${BASE}/wp-login.php`);
	await page.fill('#user_login', user);
	await page.fill('#user_pass', PASSWORD);
	await Promise.all([page.waitForURL(/wp-admin/), page.click('#wp-submit')]);
	return page;
}

test('QA-049: bn dish allergen group headings use Western digits (DEC-062)', async ({ page }) => {
	await page.goto(`${BASE}/bn/menu/`);
	const category = page.locator('a[href*="/bn/menu/"][href$="/"]').filter({ hasNotText: /^$/ });
	const hrefs = await category.evaluateAll((as) => as.map((a) => (a as HTMLAnchorElement).href));
	let dish = '';
	for (const href of hrefs.filter((h) => /\/bn\/menu\/[^/]+\/$/.test(h))) {
		await page.goto(href);
		const d = await page.locator('a[href*="/bn/menu/"]').evaluateAll((as) => as.map((a) => (a as HTMLAnchorElement).href).find((h) => /\/bn\/menu\/[^/]+\/[^/]+\/$/.test(h)) || '');
		if (d) {
			dish = d;
			break;
		}
	}
	test.skip(!dish, 'no bn-translated dish in the seed');
	await page.goto(dish);
	const main = (await page.locator('main').innerText()).replace(/\s+/g, ' ');
	expect(main).toContain('(9টি');
	expect(main).toContain('(20টি)');
	expect(main.match(NATIVE_DIGITS)?.[0] ?? null).toBeNull();
});

test('QA-069: translation editor saves with its nonce; a forged POST without it saves nothing', async ({ browser }) => {
	const id = parseInt(wpEval(`echo (int) wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'QA-I18N 翻訳テスト ' . wp_generate_password( 6, false ) ] );`), 10);
	const status = (lang: string) => wpEval(`echo \\Momos\\Kernel\\I18n\\Translations::status( 'post', ${id}, '${lang}' );`);
	try {
		const page = await login(browser, 'demo_tr');

		// Forged cross-site style POST: right action/target, no nonce → refused, nothing stored.
		const forged = await page.request.post(`${BASE}/wp-admin/admin-post.php`, {
			form: { action: 'momos_save_translation', type: 'post', id: String(id), lang: 'en', 'momos_tr[en][title]': 'Forged', 'momos_tr_status[en]': 'draft' },
			maxRedirects: 0,
		});
		expect(forged.status()).toBe(403);
		expect(status('en')).toBe('missing');

		// The real form carries the nonce persist() verifies.
		await page.goto(`${BASE}/wp-admin/admin.php?page=momos-translate&type=post&id=${id}&lang=en`);
		await page.fill('#momos-tr-en-title', 'QA-I18N translation test');
		await page.selectOption('#momos-tr-status-en', 'draft');
		await Promise.all([page.waitForURL(/saved=1/), page.locator('#wpbody-content form input[type="submit"]').click()]);
		await expect(page.locator('.notice-success')).toBeVisible();
		expect(status('en')).toBe('draft');
	} finally {
		wpEval(`wp_delete_post( ${id}, true ); echo 'ok';`);
	}
});
