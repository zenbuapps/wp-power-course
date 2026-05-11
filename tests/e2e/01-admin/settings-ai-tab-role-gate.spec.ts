/**
 * Issue #221 E2E：Settings AI Tab 角色可見性
 *
 * 驗收：
 *   - administrator 看到 4 個 tab（含 AI）
 *   - shop_manager 看到 3 個 tab（不含 AI）
 *
 * 信任邊界：本測試僅驗證 UI 層可見性（UX 改善），
 * 真正的權限執行點由後端 REST permission_callback 負責，
 * 不在本 spec 內覆蓋（既有 003-permission / 004-permission 已測）。
 */
import { test, expect, type BrowserContext } from '@playwright/test'

import { navigateToAdmin, waitForFormLoaded } from '../helpers/admin-page'
import { setupApiFromBrowser, type ApiClient } from '../helpers/api-client'

const BASE_URL = process.env.TEST_SITE_URL || 'http://localhost:8889'

const MANAGER = {
	username: 'e2e_role_manager_221',
	password: 'e2e_role_manager_221_pass',
	email: 'e2e_role_manager_221@test.local',
}

let adminApi: ApiClient
let disposeAdmin: () => Promise<void>

/**
 * 以指定帳號登入並把 session 留在 context 內（透過 wp-login.php form）
 */
async function loginToContext(
	ctx: BrowserContext,
	username: string,
	password: string,
): Promise<void> {
	const page = await ctx.newPage()
	await page.goto(`${BASE_URL}/wp-login.php`, {
		waitUntil: 'domcontentloaded',
		timeout: 30_000,
	})
	await page.fill('#user_login', username)
	await page.fill('#user_pass', password)
	await page.locator('#wp-submit').click()
	await page.waitForURL((url) => !url.pathname.includes('wp-login.php'), {
		timeout: 30_000,
	})
	await page.close()
}

test.describe('Settings AI Tab 角色可見性（Issue #221）', () => {
	test.beforeAll(async ({ browser }) => {
		const setup = await setupApiFromBrowser(browser)
		adminApi = setup.api
		disposeAdmin = setup.dispose
		// 冪等建立 shop_manager 測試帳號（非 admin 角色，無 manage_options）
		await adminApi.ensureUser(
			MANAGER.username,
			MANAGER.email,
			MANAGER.password,
			['shop_manager'],
		)
	})

	test.afterAll(async () => {
		if (disposeAdmin) {
			await disposeAdmin()
		}
	})

	test('administrator 看得到 AI Tab（共 4 個 tab）', async ({ page }) => {
		// 預設 storageState 為 admin（global setup 已登入）
		await navigateToAdmin(page, '/settings')
		await waitForFormLoaded(page)

		// 等 React 完成首輪 render（Tabs 完成 mount）
		const tabs = page.locator('.ant-tabs-tab')
		await expect(tabs).toHaveCount(4)

		// AI tab 應該存在且可見
		await expect(page.getByRole('tab', { name: /^AI$/ })).toBeVisible()
	})

	test('shop_manager 看不到 AI Tab（僅 3 個 tab）', async ({ browser }) => {
		// 切換成 shop_manager session，獨立 context 避免污染 admin storageState
		const ctx = await browser.newContext({ ignoreHTTPSErrors: true })
		try {
			await loginToContext(ctx, MANAGER.username, MANAGER.password)
			const page = await ctx.newPage()

			await navigateToAdmin(page, '/settings')
			await waitForFormLoaded(page)

			const tabs = page.locator('.ant-tabs-tab')
			await expect(tabs).toHaveCount(3)

			// AI tab 完全不應出現在 DOM 中
			await expect(page.getByRole('tab', { name: /^AI$/ })).toHaveCount(0)
		} finally {
			await ctx.close()
		}
	})
})
