/**
 * AI 設定 Tab E2E 測試（Issue #217）
 *
 * 驗證：
 *  - AI Tab 出現在設定頁第 5 個位置
 *  - 內含「允許修改」「允許刪除」兩個 Switch，預設關閉
 *  - 教學連結指向 mcp.zh-TW.md
 */

import { test, expect } from '@playwright/test'
import {
	navigateToAdmin,
	waitForFormLoaded,
	clickTab,
} from '../helpers/admin-page'

test.describe('AI 設定 Tab（Issue #217）', () => {
	test.use({ storageState: '.auth/admin.json' })

	test('AI Tab 可被點擊並顯示對應 pane', async ({ page }) => {
		await navigateToAdmin(page, '/settings')
		await waitForFormLoaded(page)

		await clickTab(page, 'AI')

		const activePane = page.locator('.ant-tabs-tabpane-active')
		await expect(activePane).toBeVisible()
		// pane 中應出現「MCP 權限控制」標題
		await expect(
			activePane.getByText(/MCP 權限控制|MCP permission control/),
		).toBeVisible()
	})

	test('AI Tab 顯示兩個 Switch 預設皆為關閉', async ({ page }) => {
		await navigateToAdmin(page, '/settings')
		await waitForFormLoaded(page)

		await clickTab(page, 'AI')

		const activePane = page.locator('.ant-tabs-tabpane-active')
		const switches = activePane.locator('.ant-switch')
		await expect(switches).toHaveCount(2)

		// 預設兩個 Switch 都未啟用（無 ant-switch-checked class）
		await expect(switches.nth(0)).not.toHaveClass(/ant-switch-checked/)
		await expect(switches.nth(1)).not.toHaveClass(/ant-switch-checked/)
	})

	test('教學連結指向 mcp.zh-TW.md', async ({ page }) => {
		await navigateToAdmin(page, '/settings')
		await waitForFormLoaded(page)

		await clickTab(page, 'AI')

		const link = page
			.locator('.ant-tabs-tabpane-active')
			.getByRole('link', { name: /How to use MCP|如何使用 MCP/ })
		await expect(link).toBeVisible()
		await expect(link).toHaveAttribute('href', /mcp\.zh-TW\.md/)
		await expect(link).toHaveAttribute('target', '_blank')
	})
})
