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

	test('課程訂價 Tab 顯示虛擬商品 Switch 且預設 ON（Issue #237）', async ({
		page,
	}) => {
		await navigateToAdmin(page, `/courses/edit/${courseId}`)
		await waitForFormLoaded(page)
		await clickTab(page, '課程訂價')

		// Switch 容器應該存在
		const virtualFormItem = page.locator('[data-test-id="virtual-form-item"]')
		await expect(virtualFormItem).toBeVisible()

		// Label「虛擬商品」（zh_TW 翻譯）或 "Virtual product" 必須出現
		await expect(virtualFormItem).toContainText(/虛擬商品|Virtual product/)

		// Switch 預設 ON（新建課程後端 DB 為 yes）
		const virtualSwitch = virtualFormItem.locator('button[role="switch"]')
		await expect(virtualSwitch).toBeVisible()
		await expect(virtualSwitch).toHaveAttribute('aria-checked', 'true')
	})

	test('切換虛擬商品 Switch 後儲存並 reload，狀態保留（Issue #237）', async ({
		page,
	}) => {
		await navigateToAdmin(page, `/courses/edit/${courseId}`)
		await waitForFormLoaded(page)
		await clickTab(page, '課程訂價')

		const virtualSwitch = page
			.locator('[data-test-id="virtual-form-item"]')
			.locator('button[role="switch"]')

		// 預期為 ON，切到 OFF
		await expect(virtualSwitch).toHaveAttribute('aria-checked', 'true')
		await virtualSwitch.click()
		await expect(virtualSwitch).toHaveAttribute('aria-checked', 'false')

		// 儲存（共用 footer 的儲存按鈕）
		const saveButton = page
			.getByRole('button', { name: /^儲存$|^Save$/ })
			.first()
		await saveButton.click()

		// 等 success message 出現（任一可見即可）
		await expect(
			page.locator('.ant-message-success, .ant-message-notice-success').first()
		).toBeVisible({ timeout: 10000 })

		// Reload 後狀態仍為 OFF
		await page.reload()
		await waitForFormLoaded(page)
		await clickTab(page, '課程訂價')

		await expect(
			page
				.locator('[data-test-id="virtual-form-item"]')
				.locator('button[role="switch"]')
		).toHaveAttribute('aria-checked', 'false')

		// 復原：再切回 ON 以免影響其他測試
		await page
			.locator('[data-test-id="virtual-form-item"]')
			.locator('button[role="switch"]')
			.click()
		await saveButton.click()
		await expect(
			page.locator('.ant-message-success, .ant-message-notice-success').first()
		).toBeVisible({ timeout: 10000 })
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
