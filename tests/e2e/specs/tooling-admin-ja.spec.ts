/**
 * Japanese admin translation (momos-core-ja.mo, ENGINEERING.md §3 "admin strings are gettext"):
 *  1. A Head Office user whose profile language is `ja` sees the Momo's admin menus and the reservation
 *     inbox heading in Japanese (予約リクエスト, 監査ログ, 受信箱 …) and the admin <html lang> is ja.
 *  2. The same screens stay English for a user whose profile language is en_US (no leakage).
 *  3. A Branch Manager (scoped role) with locale ja sees the reservation menu in Japanese too.
 * Data: synthetic users created through bin/wp with the caller's MOMOS_DB_NAME / MOMOS_URL.
 * Base URL: MOMOS_E2E_BASE_URL (or MOMOS_URL), default http://127.0.0.1:8080.
 */
import { test, expect, Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import * as path from 'node:path';

const BASE = (process.env.MOMOS_E2E_BASE_URL || process.env.MOMOS_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const ROOT = path.resolve(__dirname, '../../..');
const PASSWORD = 'E2E-Tooling-Ja-2026!';

function wpEval(php: string): string {
  return execFileSync(path.join(ROOT, 'bin/wp'), ['eval', php], { cwd: ROOT, env: { ...process.env, MOMOS_URL: process.env.MOMOS_URL || BASE }, encoding: 'utf8' }).trim();
}

test.describe.configure({ mode: 'serial' });

test.beforeAll(() => {
  wpEval(`
    $pw = ${JSON.stringify(PASSWORD)};
    $user = static function ( string $login, string $role, string $locale ) use ( $pw ): int {
      $u  = get_user_by( 'login', $login );
      $id = $u ? (int) $u->ID : (int) wp_insert_user( [ 'user_login' => $login, 'user_pass' => $pw, 'user_email' => $login . '@momos.example.test', 'role' => $role ] );
      wp_set_password( $pw, $id );
      ( new WP_User( $id ) )->set_role( $role );
      update_user_meta( $id, 'locale', $locale );
      return $id;
    };
    $p = get_page_by_path( 'xja', OBJECT, 'momo_branch' );
    $branch = $p ? (int) $p->ID : (int) wp_insert_post( [ 'post_type' => 'momo_branch', 'post_status' => 'draft', 'post_title' => 'E2E XJA', 'post_name' => 'xja' ] );
    update_post_meta( $branch, 'momo_branch_code', 'XJA' );
    $user( 'e2e_ho_ja', 'momo_head_office', 'ja' );
    $user( 'e2e_ho_en', 'momo_head_office', 'en_US' );
    $bm = $user( 'e2e_bm_ja', 'momo_branch_manager', 'ja' );
    \\Momos\\Access\\Users\\AssignmentService::set( $bm, [ $branch ], 1 );
    echo 'ok';
  `);
});

async function login(page: Page, user: string) {
  await page.goto(`${BASE}/wp-login.php`);
  await page.fill('#user_login', user);
  await page.fill('#user_pass', PASSWORD);
  await Promise.all([page.waitForURL(/wp-admin/), page.click('#wp-submit')]);
}

test('Head Office with locale ja sees Japanese admin menus', async ({ page }) => {
  await login(page, 'e2e_ho_ja');
  await page.goto(`${BASE}/wp-admin/admin.php?page=momos-audit`);
  await expect(page.locator('html')).toHaveAttribute('lang', /^ja/);
  const menu = page.locator('#adminmenu');
  await expect(menu.getByRole('link', { name: /予約リクエスト/ }).first()).toBeVisible();
  await expect(menu.getByRole('link', { name: '受信箱' })).toBeAttached();
  await expect(menu.getByRole('link', { name: '監査ログ' })).toBeAttached();
  await expect(menu.getByRole('link', { name: '通知ログ' })).toBeAttached();
  await expect(menu.getByText('Reservations', { exact: true })).toHaveCount(0);
  await expect(menu.getByText('Audit log', { exact: true })).toHaveCount(0);
});

test('Head Office with locale en_US keeps the English admin', async ({ page }) => {
  await login(page, 'e2e_ho_en');
  await page.goto(`${BASE}/wp-admin/`);
  const menu = page.locator('#adminmenu');
  await expect(menu.getByRole('link', { name: 'Audit log' })).toBeAttached();
  await expect(menu.getByRole('link', { name: '監査ログ' })).toHaveCount(0);
});

test('Branch Manager with locale ja sees the reservation inbox in Japanese', async ({ page }) => {
  await login(page, 'e2e_bm_ja');
  await page.goto(`${BASE}/wp-admin/admin.php?page=momos-reservations`);
  await expect(page.locator('h1').first()).toContainText('予約リクエスト');
  await expect(page.locator('#adminmenu').getByRole('link', { name: /予約リクエスト/ }).first()).toBeVisible();
});
