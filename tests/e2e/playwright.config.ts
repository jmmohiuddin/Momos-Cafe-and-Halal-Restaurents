import { defineConfig, devices } from '@playwright/test';

/**
 * Shared Playwright configuration (E2E, accessibility, isolation, i18n/RTL).
 * Base URL: MOMOS_E2E_BASE_URL (a seeded dev/CI site, see tests/e2e/fixtures/seed.sh).
 * The PHP built-in dev server is slow and single-process unless started with PHP_CLI_SERVER_WORKERS,
 * so tests run serially with generous timeouts.
 */
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
	],
});
