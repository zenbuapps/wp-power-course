/**
 * Shortcode 管理頁測試
 *
 * 驗證 Shortcode 頁面的 Tab 與程式碼區塊渲染
 */

import { test, expect } from '@playwright/test'
import { navigateToAdmin, clickTab } from '../helpers/admin-page'

test.describe('Shortcode 管理', () => {
	test.use({ storageState: '.auth/admin.json' })

	test('一般 Tab 正確載入', async ({ page }) => {
		await navigateToAdmin(page, '/shortcodes')
		await page.waitForSelector('.ant-tabs', { timeout: 15_000 })
		await expect(page.locator('.ant-tabs')).toBeVisible()
	})

	test('銷售卡片 Tab 切換', async ({ page }) => {
		await navigateToAdmin(page, '/shortcodes')
		await page.waitForSelector('.ant-tabs', { timeout: 15_000 })
		await clickTab(page, '銷售卡片')
		await expect(page.locator('.ant-tabs-tabpane-active')).toBeVisible()
	})

	test('程式碼區塊存在', async ({ page }) => {
		await navigateToAdmin(page, '/shortcodes')
		await page.waitForSelector('.ant-tabs', { timeout: 15_000 })
		// Shortcode 頁面應有 code/pre 元素或複製按鈕
		const codeElement = page.locator(
			'code, pre, .ant-typography-copy, .ant-tag',
		).first()
		await expect(codeElement).toBeVisible({ timeout: 10_000 })
	})
})
