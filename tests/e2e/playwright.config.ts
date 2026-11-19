import { defineConfig, devices, Project } from '@playwright/test';

/**
 * Shared Playwright configuration (E2E, accessibility, isolation, i18n/RTL).
 * Base URL: MOMOS_E2E_BASE_URL (a seeded dev/CI site, see tests/e2e/fixtures/seed.sh).
 * The PHP built-in dev server is slow and single-process unless started with PHP_CLI_SERVER_WORKERS,
 * so tests run serially with generous timeouts.
 *
 * Browsers (NFR-CMP-01): locally only the `desktop` project (Chromium) runs. With MOMOS_E2E_ALL_BROWSERS=1
 * (set in CI, after `npx playwright install --with-deps`) the compatibility projects are added: Firefox and
 * WebKit desktop plus Android (Pixel 7, Chromium) and iOS (iPhone 14, WebKit) device emulation. They run the
 * customer-facing compatibility specs below (layout frame, navigation, focus, RTL, the reservation journey);
 * the full suite stays on Chromium. Pick one with --project=firefox|webkit|mobile-android|mobile-ios.
 */
const COMPAT_SPECS = [
	'**/theme-smoke.spec.ts',
	'**/theme-frame.spec.ts',
	'**/theme-pointer-focus.spec.ts',
	'**/qa-customer-frame.spec.ts',
	'**/qa-a11y-i18n-rtl.spec.ts',
	'**/reservation-lifecycle.spec.ts',
];

const compat: Project[] = process.env.MOMOS_E2E_ALL_BROWSERS === '1'
	? [
		{ name: 'firefox', testMatch: COMPAT_SPECS, use: { ...devices['Desktop Firefox'], viewport: { width: 1280, height: 900 } } },
		{ name: 'webkit', testMatch: COMPAT_SPECS, use: { ...devices['Desktop Safari'], viewport: { width: 1280, height: 900 } } },
		{ name: 'mobile-android', testMatch: COMPAT_SPECS, use: { ...devices['Pixel 7'] } },
		{ name: 'mobile-ios', testMatch: COMPAT_SPECS, use: { ...devices['iPhone 14'] } },
	]
	: [];

export default defineConfig({
	testDir: './specs',
	timeout: 120_000,
	expect: { timeout: 15_000 },
	fullyParallel: false,
	workers: 1,
	retries: process.env.CI ? 1 : 0,
	reporter: process.env.CI ? [['list'], ['junit', { outputFile: 'test-results/junit.xml' }]] : 'list',
	use: {
		baseURL: process.env.MOMOS_E2E_BASE_URL || 'http://127.0.0.1:8080',
		trace: 'off',
		screenshot: 'only-on-failure',
		locale: 'ja-JP',
		timezoneId: 'Asia/Tokyo',
	},
	projects: [
		{ name: 'desktop', use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 900 } } },
		...compat,
	],
});
