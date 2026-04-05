/**
 * 銷售方案測試
 *
 * 驗證課程編輯頁的銷售方案 Tab
 */

import { test, expect } from '@playwright/test'
import {
	navigateToAdmin,
	waitForFormLoaded,
	clickTab,
} from '../helpers/admin-page'
import { setupApiFromBrowser } from '../helpers/api-client'

test.describe('銷售方案', () => {
	test.use({ storageState: '.auth/admin.json' })

	let courseId: number

	test.beforeAll(async ({ browser }) => {
		const { api, dispose } = await setupApiFromBrowser(browser)
		try {
			courseId = await api.createCourse('E2E 銷售方案測試課程')
		} finally {
			await dispose()
		}
	})

	test.afterAll(async ({ browser }) => {
		if (!courseId) return
		const { api, dispose } = await setupApiFromBrowser(browser)
		try {
			await api.deleteCourses([courseId])
		} finally {
			await dispose()
		}
	})

	test('銷售方案 Tab 正確載入', async ({ page }) => {
		await navigateToAdmin(page, `/courses/edit/${courseId}`)
		await waitForFormLoaded(page)
		await clickTab(page, '銷售方案')
		await expect(page.locator('.ant-tabs-tabpane-active')).toBeVisible()
	})

	test('銷售方案區域有操作按鈕', async ({ page }) => {
		await navigateToAdmin(page, `/courses/edit/${courseId}`)
		await waitForFormLoaded(page)
		await clickTab(page, '銷售方案')
		const tabContent = page.locator('.ant-tabs-tabpane-active')
		const btn = tabContent.locator('.ant-btn').first()
		await expect(btn).toBeVisible()
	})
})
