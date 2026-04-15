/**
 * 外掛設定頁測試
 *
 * 驗證設定頁面的 Tab 與表單渲染
 */

import { test, expect } from '@playwright/test'
import {
	navigateToAdmin,
	waitForFormLoaded,
	clickTab,
} from '../helpers/admin-page'

test.describe('外掛設定', () => {
	test.use({ storageState: '.auth/admin.json' })

	test('一般設定 Tab 正確載入', async ({ page }) => {
		await navigateToAdmin(page, '/settings')
		await waitForFormLoaded(page)
		await expect(page.locator('.ant-tabs')).toBeVisible()
		await expect(page.locator('.ant-form')).toBeVisible()
	})

	test('外觀設定 Tab 切換', async ({ page }) => {
		await navigateToAdmin(page, '/settings')
		await waitForFormLoaded(page)
		await clickTab(page, '外觀設定')
		await expect(page.locator('.ant-tabs-tabpane-active')).toBeVisible()
	})

	test('儲存按鈕存在', async ({ page }) => {
		await navigateToAdmin(page, '/settings')
		await waitForFormLoaded(page)
		const saveBtn = page.locator('button[type="submit"], .ant-btn-primary').first()
		await expect(saveBtn).toBeVisible()
	})
})
