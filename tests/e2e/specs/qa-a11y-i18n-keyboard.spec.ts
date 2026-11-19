/**
 * QA (a11y-i18n team) — keyboard, focus and semantics (WCAG 2.2 AA; qa.md §6.1 manual checks automated where
 * possible; TC-A11Y-002, TC-A11Y-004, TC-A11Y-006, TC-A11Y-012, AC-I18N-02-04/05, TC-NAV-002).
 *  - Tab walk on key templates (ja/en/ar × 1280/390): every stop has a visible focus indicator (2.4.7),
 *    is not entirely hidden by the sticky header / action bar (2.4.11), no keyboard trap (2.1.2).
 *  - Skip link, landmarks, heading order, labelled navs (1.3.1, 2.4.1, 2.4.6).
 *  - Language switcher and mobile menu dialog by keyboard: open, Tab stays inside, Esc closes, focus returns.
 *  - Menu filters by keyboard.
 *  - Reservation form keyboard-only: empty submit → focus on error summary; summary link → field;
 *    complete + submit by keyboard → C10; status page cancel by keyboard.
 *  - Form semantics (labels, autocomplete, aria-describedby error linkage, required not by colour only).
 *  - Status messages via live regions (4.1.3); prefers-reduced-motion; 320 px reflow / 200 % text (1.4.4/1.4.10).
 */
import { test, expect, Page, Browser } from '@playwright/test';
import { execFileSync } from 'child_process';
import * as path from 'path';

const BASE = process.env.MOMOS_E2E_BASE_URL || process.env.MOMOS_URL || 'http://127.0.0.1:8080';
const ROOT = path.resolve(__dirname, '../../..');
const LANGS = ['ja', 'en', 'ar'] as const;
const prefix = (l: string) => (l === 'ja' ? '' : `/${l}`);
const RUN = Date.now().toString(36);

function wp(...args: string[]): string {
	return execFileSync(path.join(ROOT, 'bin/wp'), args, { cwd: ROOT, env: process.env, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
}

type Stop = { desc: string; visible: boolean; obscured: boolean; inViewport: boolean };

/** Describes document.activeElement: focus indicator present, entirely obscured by another layer? */
async function focusInfo(page: Page): Promise<Stop | null> {
	return page.evaluate(() => {
		const el = document.activeElement as HTMLElement | null;
		if (!el || el === document.body || el === document.documentElement) return null;
		const desc = `${el.tagName.toLowerCase()}${el.id ? '#' + el.id : ''}${el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : ''} "${(el.textContent || el.getAttribute('aria-label') || '').trim().replace(/\s+/g, ' ').slice(0, 30)}"`;
		// Visually hidden native controls (chips, custom radios) show focus on their label; stretched card links on
		// the card (:has()). Compare indicator-like styles of the element, its label and 3 ancestors (and ::before/
		// ::after) while focused and after blur: any difference = a visible focus indicator.
		const r0 = el.getBoundingClientRect();
		const tiny = r0.width <= 2 || r0.height <= 2 || getComputedStyle(el).opacity === '0';
		const label = el.closest('label') || (el.id ? document.querySelector(`label[for="${el.id}"]`) : null);
		const indicator: Element = tiny ? (label || el) : el;
		const nodes: Element[] = [el];
		if (label) nodes.push(label);
		let up: Element | null = el.parentElement;
		for (let i = 0; i < 3 && up && up !== document.body; i++, up = up.parentElement) nodes.push(up);
		const sig = (n: Element) => {
			const parts: string[] = [];
			for (const pseudo of [null, '::before', '::after']) {
				const cs = getComputedStyle(n, pseudo);
				parts.push(cs.outlineStyle, cs.outlineWidth, cs.outlineColor, cs.boxShadow, cs.borderTopColor, cs.borderTopWidth, cs.borderBottomColor, cs.borderBottomWidth, cs.backgroundColor, cs.textDecorationLine);
			}
			return parts.join('|');
		};
		const focused = nodes.map(sig);
		const hadOutline = (() => { const cs = getComputedStyle(el); return cs.outlineStyle !== 'none' && parseFloat(cs.outlineWidth) >= 1; })();
		el.blur();
		const blurred = nodes.map(sig);
		(el as HTMLElement & { focus(o?: { focusVisible?: boolean; preventScroll?: boolean }): void }).focus({ focusVisible: true, preventScroll: true });
		const visible = hadOutline || focused.some((f, i) => f !== blurred[i]);
		const r = indicator.getBoundingClientRect();
		const inViewport = r.bottom > 0 && r.top < innerHeight && r.right > 0 && r.left < innerWidth;
		let obscured = false;
		if (inViewport && r.width > 0 && r.height > 0) {
			const pts = [[0.5, 0.5], [0.1, 0.1], [0.9, 0.1], [0.1, 0.9], [0.9, 0.9]].map(([x, y]) => [
				Math.min(innerWidth - 1, Math.max(0, r.left + r.width * x)), Math.min(innerHeight - 1, Math.max(0, r.top + r.height * y))]);
			obscured = pts.every(([x, y]) => {
				const hit = document.elementFromPoint(x, y);
				return !!hit && !(hit === el || el.contains(hit) || hit === indicator || indicator.contains(hit) || hit.contains(indicator));
			});
		}
		return { desc, visible, obscured, inViewport };
	});
}

/** Tabs through the page; returns problems (no visible focus, obscured, trap). */
async function tabWalk(page: Page, label: string, max = 160): Promise<string[]> {
	const problems: string[] = [];
	await page.locator('body').click({ position: { x: 1, y: 1 } }).catch(() => undefined);
	await page.evaluate(() => { (document.activeElement as HTMLElement | null)?.blur(); window.scrollTo(0, 0); });
	const seen: string[] = [];
	for (let i = 0; i < max; i++) {
		await page.keyboard.press('Tab');
		const info = await focusInfo(page);
		if (!info) { if (i > 3) break; continue; } // wrapped to the document → walk complete
		if (seen.length >= 3 && seen.slice(-3).every((d) => d === info.desc)) { problems.push(`${label}: keyboard trap at ${info.desc}`); break; }
		if (seen.includes(info.desc) && info.desc === seen[0]) break; // cycled
		seen.push(info.desc);
		if (!info.visible) problems.push(`${label}: no visible focus indicator on ${info.desc}`);
		if (info.obscured) problems.push(`${label}: focused element entirely obscured (2.4.11) ${info.desc}`);
	}
	if (seen.length < 5) problems.push(`${label}: only ${seen.length} tab stops`);
	return problems;
}

const WALK_PAGES = ['/', '/branches/demo-kitahama/', '/menu/curry/', '/menu/curry/butter-chicken-curry/', '/halal/', '/faq/', '/contact/', '/reservation/'];

test.describe('Tab walk: visible focus, not obscured, no trap (2.1.1, 2.1.2, 2.4.7, 2.4.11; TC-A11Y-002/004)', () => {
	for (const [w, h] of [[1280, 900], [390, 844]] as const) {
		for (const lang of LANGS) {
			test(`${lang} @${w}`, async ({ browser }) => {
				test.setTimeout(400_000);
				const ctx = await browser.newContext({ viewport: { width: w, height: h } });
				const page = await ctx.newPage();
				const problems: string[] = [];
				for (const p of WALK_PAGES) {
					await page.goto(`${BASE}${prefix(lang)}${p}`, { waitUntil: 'networkidle' });
					problems.push(...(await tabWalk(page, `${lang}${p}@${w}`)));
				}
				await ctx.close();
				expect([...new Set(problems)]).toEqual([]);
			});
		}
	}
});

test.describe('skip link, landmarks, headings (2.4.1, 1.3.1, 2.4.6)', () => {
	for (const lang of LANGS) {
		test(`skip link first and moves focus to main — ${lang} @390`, async ({ page }) => {
			await page.setViewportSize({ width: 390, height: 844 });
			await page.goto(`${BASE}${prefix(lang)}/menu/curry/`, { waitUntil: 'networkidle' });
			await page.keyboard.press('Tab');
			const skip = page.locator('a.momo-skip');
			await expect(skip).toBeFocused();
			await expect(skip).toBeInViewport();
			expect((await skip.innerText()).trim().length).toBeGreaterThan(1);
			await page.keyboard.press('Enter');
			await expect(page.locator('main#main')).toBeFocused();
		});
	}

	test('every customer template: 1 main, 1 banner, 1 contentinfo, named navs unique, no skipped heading levels', async ({ page }) => {
		test.setTimeout(300_000);
		const PAGES = ['/', '/about/', '/halal/', '/branches/', '/branches/demo-kitahama/', '/menu/', '/menu/curry/', '/menu/curry/butter-chicken-curry/',
			'/reservation/', '/reservation/submitted/', '/contact/', '/faq/', '/offers/', '/gallery/', '/privacy-policy/', '/accessibility/', '/no-such-page-kbd/'];
		const problems: string[] = [];
		for (const lang of LANGS) {
			for (const p of PAGES) {
				const res = await page.goto(`${BASE}${prefix(lang)}${p}`, { waitUntil: 'networkidle' });
				if (res?.status() === 404 && !p.startsWith('/no-such')) continue;
				const r = await page.evaluate(() => {
					const vis = (e: Element) => !!(e as HTMLElement).offsetParent || getComputedStyle(e).position === 'fixed';
					const out: string[] = [];
					const mains = document.querySelectorAll('main, [role="main"]').length;
					if (mains !== 1) out.push(`${mains} main landmarks`);
					const banners = Array.from(document.querySelectorAll('header')).filter((h) => !h.closest('main, article, aside, section, nav, dialog')).length;
					if (banners !== 1) out.push(`${banners} banner landmarks`);
					const footers = Array.from(document.querySelectorAll('footer')).filter((f) => !f.closest('main, article, aside, section, nav, dialog')).length;
					if (footers !== 1) out.push(`${footers} contentinfo landmarks`);
					const navs = Array.from(document.querySelectorAll('nav')).filter((n) => !n.closest('dialog'));
					const names = navs.map((n) => n.getAttribute('aria-label') || (n.getAttribute('aria-labelledby') ? document.getElementById(n.getAttribute('aria-labelledby') as string)?.textContent?.trim() : '') || '');
					if (navs.length > 1 && names.some((n) => !n)) out.push(`unnamed nav among ${navs.length}`);
					const dup = names.filter((n, i) => n && names.indexOf(n) !== i);
					if (dup.length) out.push(`duplicate nav names: ${dup.join(', ')}`);
					const hs = Array.from(document.querySelectorAll('h1,h2,h3,h4,h5,h6')).filter((h) => vis(h) && !h.closest('dialog:not([open])'));
					let prev = 0;
					for (const hEl of hs) {
						const lvl = Number(hEl.tagName[1]);
						if (prev && lvl > prev + 1) out.push(`heading skip h${prev}→h${lvl} "${(hEl.textContent || '').trim().slice(0, 40)}"`);
						prev = lvl;
					}
					if (hs.length && hs[0].tagName !== 'H1' && !hs[0].closest('header, [role="region"], .momo-notice')) out.push(`first heading is ${hs[0].tagName}`);
					// Content outside landmarks (axe "region" is only "moderate")
					const outside = Array.from(document.body.children).filter((c) => !['SCRIPT', 'STYLE', 'TEMPLATE', 'NOSCRIPT', 'DIALOG', 'LINK'].includes(c.tagName)
						&& !c.matches('header, footer, main, nav, aside, [role], a.momo-skip, [hidden]') && vis(c) && (c.textContent || '').trim());
					for (const c of outside) out.push(`content outside landmarks: ${c.tagName.toLowerCase()}.${c.className}`);
					return out;
				});
				for (const x of r) problems.push(`${lang}${p}: ${x}`);
			}
		}
		expect(problems).toEqual([]);
	});

	test('icon-only buttons have an accessible name (language bar dismiss, notice close, steppers)', async ({ browser }) => {
		const ctx = await browser.newContext({ locale: 'en-US', viewport: { width: 1280, height: 900 } });
		const page = await ctx.newPage();
		await page.goto(`${BASE}/menu/`, { waitUntil: 'networkidle' }); // ja page, English browser → suggestion bar
		const bar = page.locator('[data-momo-langbar]');
		await expect(bar).toBeVisible();
		const dismiss = page.locator('[data-momo-langbar-dismiss]');
		const name = await dismiss.evaluate((b) => (b.getAttribute('aria-label') || b.getAttribute('title') || (b.textContent || '').trim()));
		expect(name, 'suggestion bar × needs lang.dismiss as accessible name (i18n-seo-analytics §5.2)').toBeTruthy();
		expect(await bar.getAttribute('aria-label'), 'suggestion bar region label = lang.suggest').toBeTruthy();
		expect(await bar.getAttribute('lang')).toBe('en');
		await ctx.close();
	});
});

test.describe('language switcher by keyboard (FR-I18N-002, AC-I18N-02-04/05)', () => {
	for (const lang of LANGS) {
		test(`Enter opens, Esc closes and returns focus, Enter on option navigates — ${lang}`, async ({ page }) => {
			await page.setViewportSize({ width: 1280, height: 900 });
			await page.goto(`${BASE}${prefix(lang)}/menu/curry/`, { waitUntil: 'networkidle' });
			const btn = page.locator('header summary.momo-lang__btn');
			await btn.focus();
			await page.keyboard.press('Enter');
			const list = page.locator('header .momo-lang__list');
			await expect(list).toBeVisible();
			// Expanded state is exposed (details/summary or aria-expanded).
			const expanded = await page.evaluate(() => {
				const s = document.querySelector('header summary.momo-lang__btn') as HTMLElement;
				return s.getAttribute('aria-expanded') ?? String((s.parentElement as HTMLDetailsElement).open);
			});
			expect(expanded).toBe('true');
			await page.keyboard.press('Escape');
			await expect(list).toBeHidden();
			await expect(btn).toBeFocused();
			await page.keyboard.press('Space');
			await expect(list).toBeVisible();
			const target = lang === 'en' ? 'ar' : 'en';
			for (let i = 0; i < 8; i++) {
				await page.keyboard.press('Tab');
				if (await page.evaluate((t) => (document.activeElement as HTMLElement)?.getAttribute('data-momo-to') === t, target)) break;
			}
			await Promise.all([page.waitForURL(new RegExp(`/${target}/menu/curry/$`)), page.keyboard.press('Enter')]);
			await expect(page.locator('html')).toHaveAttribute('lang', target);
		});
	}
});

test.describe('mobile menu dialog by keyboard (TC-NAV-002, 2.1.2, 2.4.3)', () => {
	for (const lang of LANGS) {
		test(`open with Enter, focus inside, Tab/Shift+Tab stay inside, Esc closes, focus returns — ${lang}`, async ({ page }) => {
			await page.setViewportSize({ width: 390, height: 844 });
			await page.goto(`${BASE}${prefix(lang)}/halal/`, { waitUntil: 'networkidle' });
			const opener = page.locator('header [data-momo-menu-open]');
			await opener.focus();
			await page.keyboard.press('Enter');
			const dlg = page.locator('dialog#momo-mmenu');
			await expect(dlg).toBeVisible();
			expect(await page.evaluate(() => !!document.activeElement?.closest('dialog#momo-mmenu'))).toBe(true);
			await page.waitForTimeout(600); // slide-in animation
			const box = await dlg.boundingBox();
			expect(Math.round(box!.x)).toBeGreaterThanOrEqual(0);
			expect(Math.round(box!.x + box!.width)).toBeLessThanOrEqual(390);
			// Native modal dialog: the page behind is inert; Tab may pass to the browser UI (activeElement = <body>)
			// and come back, but must never land on page content behind the dialog.
			expect(await dlg.evaluate((d) => d.matches(':modal'))).toBe(true);
			const where = () => page.evaluate(() => { const a = document.activeElement; return a?.closest('dialog#momo-mmenu') ? 'in' : (a === document.body || !a ? 'chrome' : `behind: ${a.tagName} ${(a.textContent || '').trim().slice(0, 20)}`); });
			for (let i = 0; i < 40; i++) {
				await page.keyboard.press('Tab');
				expect(await where(), `Tab #${i + 1}`).toMatch(/^(in|chrome)$/);
			}
			for (let i = 0; i < 5; i++) {
				await page.keyboard.press('Shift+Tab');
				expect(await where()).toMatch(/^(in|chrome)$/);
			}
			await page.locator('dialog#momo-mmenu [data-momo-mmenu-close]').focus();
			const vis = await focusInfo(page);
			expect(vis?.visible, `focus indicator inside dialog: ${vis?.desc}`).toBe(true);
			await page.keyboard.press('Escape');
			await expect(dlg).toBeHidden();
			await expect(opener).toBeFocused();
		});
	}
});

test.describe('menu filters by keyboard (FR-MENU filters; 2.1.1)', () => {
	for (const lang of LANGS) {
		test(`open filters, tick Vegetarian with Space, choose spice with arrows, apply with Enter — ${lang}`, async ({ page }) => {
			await page.setViewportSize({ width: 390, height: 844 });
			await page.goto(`${BASE}${prefix(lang)}/menu/curry/`, { waitUntil: 'networkidle' });
			const summary = page.locator('details.momo-filters > summary');
			await summary.focus();
			await page.keyboard.press('Enter');
			await expect(page.locator('form.momo-filters__panel')).toBeVisible();
			await page.keyboard.press('Tab');
			const veg = page.locator('input[name="diet[]"][value="vegetarian"]');
			await expect(veg).toBeFocused();
			let vis = await focusInfo(page);
			expect(vis?.visible, 'focus indicator on the Vegetarian chip').toBe(true);
			await page.keyboard.press('Space');
			await expect(veg).toBeChecked();
			// Move to the spice radios and pick "Mild" with the arrow keys.
			for (let i = 0; i < 6 && !(await page.evaluate(() => (document.activeElement as HTMLInputElement)?.name === 'max_spice')); i++) await page.keyboard.press('Tab');
			await page.keyboard.press(lang === 'ar' ? 'ArrowLeft' : 'ArrowRight');
			await page.keyboard.press(lang === 'ar' ? 'ArrowLeft' : 'ArrowRight');
			await expect(page.locator('input[name="max_spice"][value="1"]')).toBeChecked();
			vis = await focusInfo(page);
			expect(vis?.visible, 'focus indicator on the spice chip').toBe(true);
			// With JS the filters apply live (Apply button hidden); the URL is updated and the result count is a
			// role=status message (4.1.3).
			await expect.poll(() => page.url()).toContain('vegetarian');
			expect(page.url()).toContain('max_spice=1');
			const count = page.locator('[data-momo-result-count]');
			await expect(count).toHaveAttribute('role', 'status');
			expect(await page.locator('[data-momo-dish-list] > li:not([hidden])').count()).toBeLessThan(5);
			await expect(page.locator('[data-momo-filter-label]')).toContainText('2');
		});
	}
});

async function tabTo(page: Page, selector: string, max = 80): Promise<boolean> {
	for (let i = 0; i < max; i++) {
		if (await page.evaluate((s) => !!document.activeElement?.matches(s), selector)) return true;
		await page.keyboard.press('Tab');
	}
	return page.evaluate((s) => !!document.activeElement?.matches(s), selector);
}

test.describe('reservation form keyboard-only (TC-A11Y-002, TC-A11Y-006, FR-RES-002)', () => {
	let fixture = false;
	test.beforeAll(() => {
		// Relax public rate limits for repeated runs from 127.0.0.1 (fixture is idempotent, dev/CI only).
		try { wp('eval-file', 'tests/e2e/fixtures/reservation-fixture.php'); fixture = true; } catch { /* fixture optional */ }
	});
	test.afterAll(() => {
		// E2E isolation: the fixture publishes the fictional branches XEA/XEB and relaxes the rate limits; remove
		// them again so later specs see only the demo branches (e.g. qa-customer-branch expects 3 directory cards).
		if (fixture) wp('eval-file', 'tests/e2e/fixtures/reservation-fixture.php', 'teardown');
	});

	for (const lang of LANGS) {
		test(`form semantics — ${lang}`, async ({ page }) => {
			await page.goto(`${BASE}${prefix(lang)}/reservation/`, { waitUntil: 'networkidle' });
			const r = await page.evaluate(() => {
				const out: string[] = [];
				const form = document.querySelector('#momo-res-form') as HTMLFormElement;
				if (!form.noValidate) out.push('form must be novalidate (localised messages)');
				for (const el of Array.from(form.querySelectorAll('input, select, textarea')) as HTMLInputElement[]) {
					if (el.type === 'hidden' || el.closest('[aria-hidden="true"]') || el.closest('[hidden]')) continue;
					const lab = (el.labels && el.labels.length) || el.getAttribute('aria-label') || el.getAttribute('aria-labelledby');
					if (!lab) out.push(`no label: ${el.id || el.name}`);
					if (el.required && el.type !== 'checkbox') {
						const ls = [...Array.from(el.labels || []), el.closest('fieldset')?.querySelector('legend')].filter(Boolean) as Element[];
						if (!ls.some((l) => /\S/.test((l.querySelector('.momo-field__req')?.textContent || '').replace(/\*/g, '')))) out.push(`required not stated in text: ${el.id}`);
					}
				}
				const ac: Record<string, string> = { 'res-name': 'name', 'res-email': 'email', 'res-phone': 'tel' };
				for (const [id, v] of Object.entries(ac)) if (document.getElementById(id)?.getAttribute('autocomplete') !== v) out.push(`autocomplete ${id} ≠ ${v}`);
				for (const id of ['res-email', 'res-phone']) if (document.getElementById(id)?.getAttribute('dir') !== 'ltr') out.push(`${id} not dir=ltr`);
				for (const id of ['res-name', 'res-note']) if (document.getElementById(id)?.getAttribute('dir') !== 'auto') out.push(`${id} not dir=auto`);
				return out;
			});
			expect(r).toEqual([]);
		});

		test(`accommodation channel (phone/email) visible before the form fields — ${lang} (TC-A11Y-012)`, async ({ page }) => {
			await page.goto(`${BASE}${prefix(lang)}/reservation/`, { waitUntil: 'networkidle' });
			const r = await page.evaluate(() => {
				const branch = document.getElementById('res-branch') as HTMLElement;
				const contacts = Array.from(document.querySelectorAll('main a[href^="tel:"], main a[href^="mailto:"]'))
					.filter((a) => (a as HTMLElement).offsetParent !== null && !a.closest('noscript'));
				const before = contacts.filter((a) => !!(a.compareDocumentPosition(branch) & Node.DOCUMENT_POSITION_FOLLOWING));
				return { visible: contacts.length, before: before.length };
			});
			expect(r.before, 'before branch selection: a visible Head Office tel:/mailto: alternative must precede the branch field').toBeGreaterThan(0);
			// After choosing a branch: that branch's tel: is offered (keyboard reachable).
			await page.locator('#res-branch').selectOption({ index: 1 });
			const tel = page.locator('main a[href^="tel:"]:visible');
			await expect(tel.first(), 'after branch selection: branch tel: link visible').toBeVisible();
		});

		test(`keyboard-only: errors → summary focus → link to field → complete → submit → status → cancel — ${lang}`, async ({ browser }) => {
			test.setTimeout(240_000);
			const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
			const page = await ctx.newPage();
			await page.goto(`${BASE}${prefix(lang)}/reservation/`, { waitUntil: 'networkidle' });
			await expect(page.locator('#momo-res-form')).toBeVisible();

			// Submit empty with keyboard.
			expect(await tabTo(page, '[data-res-submit]', 120), 'submit button reachable by Tab').toBe(true);
			await page.keyboard.press('Enter');
			const sum = page.locator('#res-errsum');
			await expect(sum).toBeVisible();
			await expect(sum).toBeFocused();
			expect((await sum.innerText()).trim()).not.toMatch(/\bres\.[a-z_.]+/);
			// Each invalid field: aria-invalid + error id in aria-describedby + visible text.
			const linkage = await page.evaluate(() => {
				const out: string[] = [];
				for (const id of ['res-branch', 'res-name', 'res-email', 'res-consent']) {
					const el = document.getElementById(id) as HTMLElement;
					const err = document.getElementById(`${id}-error`) as HTMLElement;
					if (el.getAttribute('aria-invalid') !== 'true') out.push(`${id} aria-invalid`);
					if (!(el.getAttribute('aria-describedby') || '').split(/\s+/).includes(`${id}-error`)) out.push(`${id} describedby`);
					if (!err || err.hidden || !(err.textContent || '').trim()) out.push(`${id} error text`);
				}
				return out;
			});
			expect(linkage).toEqual([]);
			// First summary link moves focus to its field.
			await page.keyboard.press('Tab');
			const href = await page.evaluate(() => (document.activeElement as HTMLAnchorElement)?.getAttribute('href'));
			expect(href, 'first Tab from the summary lands on an error link').toMatch(/^#res-/);
			await page.keyboard.press('Enter');
			expect(await page.evaluate(() => document.activeElement?.id)).toBe((href as string).slice(1));

			// Branch by keyboard (first accepting branch = first option after the placeholder).
			await page.locator('#res-branch').focus();
			await page.keyboard.press('ArrowDown');
			await expect(page.locator('#res-branch')).not.toHaveValue('');
			// Date: date chips (if shown) or the select.
			await expect(page.locator('#res-date')).toBeEnabled({ timeout: 20_000 });
			await page.locator('#res-date').focus();
			for (let i = 0; i < 4; i++) await page.keyboard.press('ArrowDown');
			await expect(page.locator('#res-date')).not.toHaveValue('');
			// Time: first time radio via Tab, select with Space.
			await expect(page.locator('[data-res-times] input[type="radio"]').first()).toBeAttached({ timeout: 20_000 });
			expect(await tabTo(page, '[data-res-times] input[type="radio"]', 30), 'time radios reachable by Tab').toBe(true);
			let vis = await focusInfo(page);
			expect(vis?.visible, `focus visible on time chip: ${vis?.desc}`).toBe(true);
			await page.keyboard.press('Space');
			expect(await page.evaluate(() => !!document.querySelector('[data-res-times] input:checked'))).toBe(true);
			// Party: chips → Tab then arrow to 2.
			expect(await tabTo(page, '[data-res-party-chips] input[type="radio"]', 20), 'party chips reachable by Tab').toBe(true);
			await page.keyboard.press('Space');
			await page.keyboard.press(lang === 'ar' ? 'ArrowLeft' : 'ArrowRight');
			expect(await page.evaluate(() => !!document.querySelector('[data-res-party-chips] input:checked'))).toBe(true);
			vis = await focusInfo(page);
			expect(vis?.visible, `focus visible on party chip: ${vis?.desc}`).toBe(true);
			expect(await tabTo(page, '#res-name', 20)).toBe(true);
			await page.keyboard.type(lang === 'ar' ? 'سارة' : 'Kbd Tester');
			expect(await tabTo(page, '#res-email', 5)).toBe(true);
			await page.keyboard.type(`kbd-${lang}-${RUN}@example.test`);
			expect(await tabTo(page, '#res-consent', 30), 'consent reachable by Tab').toBe(true);
			await page.keyboard.press('Space');
			await expect(page.locator('#res-consent')).toBeChecked();
			expect(await tabTo(page, '[data-res-submit]', 20)).toBe(true);
			await Promise.all([page.waitForURL(/\/reservation\/submitted\/$/, { timeout: 30_000 }), page.keyboard.press('Enter')]);

			// C10: heading announced (focus on heading or live region), reference isolated LTR.
			const c10 = await page.evaluate(() => {
				const h = document.querySelector('[data-res-heading]') as HTMLElement | null;
				const a = document.activeElement as HTMLElement;
				const live = !!h?.closest('[role="status"], [role="alert"], [aria-live]');
				return { focusedOnHeading: !!h && (a === h || h.contains(a) || a.contains(h)), live, focused: a?.tagName };
			});
			expect(c10.focusedOnHeading || c10.live, `"request received" status must be announced (focus or live region), focus on ${c10.focused}`).toBe(true);
			const status = page.locator('a[href*="/reservation/status/"]');
			expect(await tabTo(page, 'a[href*="/reservation/status/"]', 60), 'status link reachable').toBe(true);
			await Promise.all([page.waitForURL(/\/reservation\/status\//), page.keyboard.press('Enter')]);
			await expect(page.locator('[data-res-heading]')).toBeVisible();
			const cancel = page.locator('button[aria-controls="res-cancel-panel"]');
			await expect(cancel).toBeVisible();
			expect(await tabTo(page, 'button[aria-controls="res-cancel-panel"]', 60), 'cancel button reachable').toBe(true);
			await page.keyboard.press('Enter');
			await expect(page.locator('#res-cancel-q')).toBeFocused();
			expect(await tabTo(page, '#res-cancel-panel .momo-btn--danger', 10), 'confirm cancel reachable').toBe(true);
			await page.keyboard.press('Enter');
			await expect(page.locator('[data-res-heading]')).toHaveClass(/momo-status--cancelled/);
			const after = await page.evaluate(() => {
				const h = document.querySelector('[data-res-heading]') as HTMLElement;
				const a = document.activeElement as HTMLElement;
				return { onHeading: a === h || h.contains(a) || a.contains(h) || !!h.closest('[role="status"],[aria-live]'), body: a === document.body };
			});
			expect(after.onHeading, 'cancelled status announced (focus or live region), focus not lost to <body>').toBe(true);
			void status;
			await ctx.close();
		});
	}
});

test.describe('status messages via live regions (4.1.3)', () => {
	test('branch info, time loading, character counter, error summary, form banner are live regions', async ({ page }) => {
		await page.goto(`${BASE}/en/reservation/`, { waitUntil: 'networkidle' });
		const r = await page.evaluate(() => {
			const roleOf = (sel: string) => {
				const e = document.querySelector(sel);
				if (!e) return 'missing';
				return e.getAttribute('role') || e.getAttribute('aria-live') || (e.closest('[role="status"],[role="alert"],[aria-live]') ? 'inherited' : 'none');
			};
			return {
				branchInfo: roleOf('[data-res-branch-info]'), counter: roleOf('[data-momo-counter-for="res-note"]'),
				errsum: roleOf('#res-errsum'), banner: roleOf('#res-banner'), noTimes: roleOf('[data-res-no-times]'),
			};
		});
		expect(r.branchInfo).toMatch(/status|polite|inherited/);
		expect(r.counter).toMatch(/polite|status|inherited/);
		expect(r.errsum).toMatch(/alert|assertive/);
		expect(r.banner).toMatch(/alert|assertive/);
		expect(r.noTimes, '"No times available on this date" must be announced').toMatch(/status|polite|alert|inherited/);
	});

	for (const lang of LANGS) {
		test(`contact form: novalidate (localised messages), error summary role=alert receives focus — ${lang} (TC-A11Y-006)`, async ({ page }) => {
			await page.goto(`${BASE}${prefix(lang)}/contact/`, { waitUntil: 'networkidle' });
			const form = page.locator('main form#contact-form');
			test.skip(!(await form.count()), 'contact form not published');
			// Browser-native validation bubbles speak the browser's UI language, not the page language.
			expect(await form.evaluate((f) => (f as HTMLFormElement).noValidate), 'contact form must be novalidate').toBe(true);
			await form.evaluate((f) => { (f as HTMLFormElement).noValidate = true; });
			await Promise.all([page.waitForNavigation(), form.locator('button[type="submit"]').click()]);
			await page.waitForLoadState('networkidle');
			const r = await page.evaluate(() => {
				const a = document.activeElement as HTMLElement;
				const sum = document.querySelector('main [data-momo-errsum]') as HTMLElement | null;
				return { sum: !!sum && sum.getAttribute('role') === 'alert' && (sum.textContent || '').trim().length > 0, focused: !!sum && (sum === a || sum.contains(a)), active: a.tagName };
			});
			expect(r.sum, 'error summary with role=alert').toBe(true);
			expect(r.focused, `focus must move to the error summary (focus is on <${r.active}>)`).toBe(true);
		});
	}
});

test.describe('prefers-reduced-motion (2.3.3 / Doc 08 motion)', () => {
	test('no smooth scrolling or transitions/animations longer than 0.01s when reduced motion is requested', async ({ browser }) => {
		const ctx = await browser.newContext({ reducedMotion: 'reduce', viewport: { width: 390, height: 844 } });
		const page = await ctx.newPage();
		const bad: string[] = [];
		for (const p of ['/en/', '/en/menu/', '/en/reservation/', '/ar/halal/']) {
			await page.goto(`${BASE}${p}`, { waitUntil: 'networkidle' });
			await page.locator('header [data-momo-menu-open]').click().catch(() => undefined);
			bad.push(...(await page.evaluate((u) => {
				const out: string[] = [];
				if (getComputedStyle(document.documentElement).scrollBehavior === 'smooth') out.push(`${u}: html scroll-behavior smooth`);
				const secs = (v: string) => Math.max(...v.split(',').map((x) => (x.trim().endsWith('ms') ? parseFloat(x) / 1000 : parseFloat(x) || 0)));
				for (const el of Array.from(document.querySelectorAll('body *')).slice(0, 3000)) {
					const cs = getComputedStyle(el);
					const t = secs(cs.transitionDuration);
					const a = cs.animationName !== 'none' ? secs(cs.animationDuration) * (cs.animationIterationCount === 'infinite' ? 100 : 1) : 0;
					if (t > 0.01 || a > 0.01) { out.push(`${u}: ${el.tagName.toLowerCase()}.${String(el.className).split(' ')[0]} transition ${cs.transitionDuration} animation ${cs.animationName} ${cs.animationDuration}`); if (out.length > 5) break; }
				}
				return out;
			}, p)));
		}
		await ctx.close();
		expect(bad).toEqual([]);
	});
});

async function reflow(browser: Browser, lang: string, w: number, h: number, textZoom: boolean): Promise<string[]> {
	const ctx = await browser.newContext({ viewport: { width: w, height: h } });
	const page = await ctx.newPage();
	const problems: string[] = [];
	for (const p of ['/', '/branches/', '/branches/demo-kitahama/', '/menu/', '/menu/curry/', '/menu/curry/butter-chicken-curry/', '/halal/', '/reservation/', '/faq/', '/contact/', '/offers/']) {
		const res = await page.goto(`${BASE}${prefix(lang)}${p}`, { waitUntil: 'networkidle' });
		if (res?.status() !== 200) continue;
		if (textZoom) await page.addStyleTag({ content: 'html{font-size:200% !important}' });
		await page.waitForTimeout(100);
		const r = await page.evaluate(() => {
			const out: string[] = [];
			const ov = document.documentElement.scrollWidth - window.innerWidth;
			if (ov > 1) out.push(`horizontal scroll ${ov}px`);
			// Header controls must stay on screen (no loss of content): language switcher and the primary CTA / menu button.
			for (const sel of ['header .momo-lang__btn', 'header .momo-header__cta', 'header [data-momo-menu-open]']) {
				const e = document.querySelector(sel) as HTMLElement | null;
				if (!e || !e.offsetParent) continue;
				const r = e.getBoundingClientRect();
				if (r.right > window.innerWidth + 1 || r.left < -1) out.push(`${sel} off-screen (left ${Math.round(r.left)}, right ${Math.round(r.right)} > ${window.innerWidth})`);
			}
			return out.slice(0, 6);
		});
		for (const x of r) problems.push(`${lang}${p}@${w}${textZoom ? ' text200%' : ''}: ${x}`);
	}
	await ctx.close();
	return problems;
}

test.describe('reflow and resize (1.4.4, 1.4.10; TC-A11Y-005)', () => {
	for (const lang of [...LANGS, 'ur'] as const) {
		test(`320 px reflow (= 1280 @ 400 %) — ${lang}`, async ({ browser }) => {
			test.setTimeout(240_000);
			expect(await reflow(browser, lang, 320, 640, false)).toEqual([]);
		});
		test(`200 % text at 1280 px — ${lang}`, async ({ browser }) => {
			test.setTimeout(240_000);
			expect(await reflow(browser, lang, 1280, 900, true)).toEqual([]);
		});
	}
});
