/**
 * 商品綁定頁測試
 *
 * 驗證商品綁定頁面正確渲染
 */

import { test, expect } from '@playwright/test'
import { navigateToAdmin, waitForTableLoaded } from '../helpers/admin-page'

test.describe('商品綁定', () => {
	test.use({ storageState: '.auth/admin.json' })

	test('頁面正確渲染', async ({ page }) => {
		await navigateToAdmin(page, '/products')
		await waitForTableLoaded(page)
		await expect(page.locator('.ant-table')).toBeVisible()
	})

	test('表格標頭存在', async ({ page }) => {
		await navigateToAdmin(page, '/products')
		await waitForTableLoaded(page)
		await expect(page.locator('.ant-table-thead')).toBeVisible()
	})
})
