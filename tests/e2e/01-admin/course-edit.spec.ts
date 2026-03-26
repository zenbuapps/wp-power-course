/**
 * 課程編輯頁測試
 *
 * 驗證課程編輯頁面的各個 Tab 正確渲染
 */

import { test, expect } from '@playwright/test'
import {
	navigateToAdmin,
	waitForFormLoaded,
	clickTab,
} from '../helpers/admin-page'
import { setupApiFromBrowser } from '../helpers/api-client'

test.describe('課程編輯', () => {
	test.use({ storageState: '.auth/admin.json' })

	let courseId: number

	test.beforeAll(async ({ browser }) => {
		const { api, dispose } = await setupApiFromBrowser(browser)
		try {
			courseId = await api.createCourse('E2E 編輯測試課程')
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

	test('課程描述 Tab 載入', async ({ page }) => {
		await navigateToAdmin(page, `/courses/edit/${courseId}`)
		await waitForFormLoaded(page)
		// 頁面有多個 .ant-form（每個 tab panel 各一個），使用 first() 避免 strict mode
		await expect(page.locator('.ant-form').first()).toBeVisible()
		await expect(page.locator('.ant-tabs')).toBeVisible()
	})

	test('課程訂價 Tab', async ({ page }) => {
		await navigateToAdmin(page, `/courses/edit/${courseId}`)
		await waitForFormLoaded(page)
		await clickTab(page, '課程訂價')
		await expect(page.locator('.ant-tabs-tabpane-active')).toBeVisible()
	})

	test('銷售方案 Tab', async ({ page }) => {
		await navigateToAdmin(page, `/courses/edit/${courseId}`)
		await waitForFormLoaded(page)
		await clickTab(page, '銷售方案')
		await expect(page.locator('.ant-tabs-tabpane-active')).toBeVisible()
	})

	test('章節管理 Tab', async ({ page }) => {
		await navigateToAdmin(page, `/courses/edit/${courseId}`)
		await waitForFormLoaded(page)
		await clickTab(page, '章節管理')
		await expect(page.locator('.ant-tabs-tabpane-active')).toBeVisible()
	})

	test('QA設定 Tab', async ({ page }) => {
		await navigateToAdmin(page, `/courses/edit/${courseId}`)
		await waitForFormLoaded(page)
		await clickTab(page, 'QA設定')
		await expect(page.locator('.ant-tabs-tabpane-active')).toBeVisible()
	})

	test('學員管理 Tab', async ({ page }) => {
		await navigateToAdmin(page, `/courses/edit/${courseId}`)
		await waitForFormLoaded(page)
		await clickTab(page, '學員管理')
		await expect(page.locator('.ant-tabs-tabpane-active')).toBeVisible()
	})

	test('其他設定 Tab', async ({ page }) => {
		await navigateToAdmin(page, `/courses/edit/${courseId}`)
		await waitForFormLoaded(page)
		await clickTab(page, '其他設定')
		await expect(page.locator('.ant-tabs-tabpane-active')).toBeVisible()
	})

	test('分析 Tab', async ({ page }) => {
		await navigateToAdmin(page, `/courses/edit/${courseId}`)
		await waitForFormLoaded(page)
		await clickTab(page, '分析')
		await expect(page.locator('.ant-tabs-tabpane-active')).toBeVisible()
	})
})
