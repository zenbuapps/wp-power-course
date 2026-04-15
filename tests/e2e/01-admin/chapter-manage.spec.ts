/**
 * 章節管理測試
 *
 * 驗證在課程編輯頁的章節管理 Tab 內操作章節
 */

import { test, expect } from '@playwright/test'
import {
	navigateToAdmin,
	waitForFormLoaded,
	clickTab,
} from '../helpers/admin-page'
import { setupApiFromBrowser } from '../helpers/api-client'

test.describe('章節管理', () => {
	test.use({ storageState: '.auth/admin.json' })

	let courseId: number

	test.beforeAll(async ({ browser }) => {
		const { api, dispose } = await setupApiFromBrowser(browser)
		try {
			courseId = await api.createCourse('E2E 章節測試課程')
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

	test('章節管理 Tab 正確載入', async ({ page }) => {
		await navigateToAdmin(page, `/courses/edit/${courseId}`)
		await waitForFormLoaded(page)
		await clickTab(page, '章節管理')
		await expect(page.locator('.ant-tabs-tabpane-active')).toBeVisible()
	})

	test('新增章節按鈕存在', async ({ page }) => {
		await navigateToAdmin(page, `/courses/edit/${courseId}`)
		await waitForFormLoaded(page)
		await clickTab(page, '章節管理')
		const tabContent = page.locator('.ant-tabs-tabpane-active')
		const addBtn = tabContent.locator('.ant-btn').first()
		await expect(addBtn).toBeVisible()
	})
})
