/**
 * Email 模板管理測試
 *
 * 驗證 Email 模板列表頁面的 Tab 切換與渲染
 */

import { test, expect } from '@playwright/test'
import {
	navigateToAdmin,
	waitForTableLoaded,
	clickTab,
} from '../helpers/admin-page'

test.describe('Email 模板管理', () => {
	test.use({ storageState: '.auth/admin.json' })

	test('Email 模板管理 Tab 正確載入', async ({ page }) => {
		await navigateToAdmin(page, '/emails')
		await waitForTableLoaded(page)
		await expect(page.locator('.ant-tabs')).toBeVisible()
		await expect(page.locator('.ant-table')).toBeVisible()
	})

	test('排程紀錄 Tab 切換', async ({ page }) => {
		await navigateToAdmin(page, '/emails')
		await waitForTableLoaded(page)
		await clickTab(page, '排程紀錄')
		await expect(page.locator('.ant-tabs-tabpane-active')).toBeVisible()
	})

	test('新增 Email 模板按鈕存在', async ({ page }) => {
		await navigateToAdmin(page, '/emails')
		await waitForTableLoaded(page)
		const addBtn = page.locator('.anticon-plus').first()
		await expect(addBtn).toBeVisible()
	})
})
