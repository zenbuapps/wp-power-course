/**
 * 測試目標：管理端「啟用線性觀看」設定開關
 * 對應原始碼：js/src/pages/admin/Courses/Edit/tabs/CourseOther/index.tsx
 * 前置條件：管理員已登入，測試課程已建立
 * 預期結果：開關可切換、儲存後持久化、外部課程不顯示
 */

import { test, expect } from '@playwright/test'
import {
	navigateToAdmin,
	waitForFormLoaded,
	clickTab,
	waitForMessage,
} from '../helpers/admin-page.js'
import { setupApiFromBrowser } from '../helpers/api-client.js'

test.describe('線性觀看設定開關', () => {
	test.use({ storageState: '.auth/admin.json' })

	let courseId: number

	test.beforeAll(async ({ browser }) => {
		const { api, dispose } = await setupApiFromBrowser(browser)
		try {
			courseId = await api.createCourse('E2E 線性觀看設定測試課程')
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

	test('其他設定分頁應包含「教室設定」區塊與線性觀看開關', async ({
		page,
	}) => {
		await navigateToAdmin(page, `/courses/edit/${courseId}`)
		await waitForFormLoaded(page)
		await clickTab(page, '其他設定')

		// 應存在「教室設定」區塊標題
		const heading = page.getByText('教室設定', { exact: false })
		await expect(heading).toBeVisible({ timeout: 10_000 })

		// 應存在「啟用線性觀看」標籤的開關
		const switchLabel = page.getByText('啟用循序學習模式', { exact: false })
		await expect(switchLabel).toBeVisible({ timeout: 5_000 })
	})

	test('線性觀看開關預設為關閉', async ({ page }) => {
		await navigateToAdmin(page, `/courses/edit/${courseId}`)
		await waitForFormLoaded(page)
		await clickTab(page, '其他設定')

		// 找到 enable_linear_viewing 對應的 Switch
		const switchEl = page.locator(
			'.ant-form-item:has(.ant-form-item-label:has-text("啟用循序學習模式")) .ant-switch',
		)
		await expect(switchEl).toBeVisible({ timeout: 10_000 })
		// 預設應未選中（沒有 ant-switch-checked class）
		await expect(switchEl).not.toHaveClass(/ant-switch-checked/)
	})

	test('開啟線性觀看開關 → 儲存 → 重新載入 → 開關仍為開啟', async ({
		page,
	}) => {
		await navigateToAdmin(page, `/courses/edit/${courseId}`)
		await waitForFormLoaded(page)
		await clickTab(page, '其他設定')

		// 開啟開關
		const switchEl = page.locator(
			'.ant-form-item:has(.ant-form-item-label:has-text("啟用循序學習模式")) .ant-switch',
		)
		await expect(switchEl).toBeVisible({ timeout: 10_000 })
		await switchEl.click()
		await expect(switchEl).toHaveClass(/ant-switch-checked/)

		// 儲存課程（使用常見的儲存按鈕）
		const saveButton = page
			.locator('button:has-text("儲存"), button:has-text("更新")')
			.first()
		await saveButton.click()
		await waitForMessage(page, 'success')

		// 重新載入頁面
		await navigateToAdmin(page, `/courses/edit/${courseId}`)
		await waitForFormLoaded(page)
		await clickTab(page, '其他設定')

		// 開關應仍為開啟狀態
		const switchElReloaded = page.locator(
			'.ant-form-item:has(.ant-form-item-label:has-text("啟用循序學習模式")) .ant-switch',
		)
		await expect(switchElReloaded).toHaveClass(/ant-switch-checked/)
	})

	test('關閉線性觀看開關 → 儲存 → 重新載入 → 開關仍為關閉', async ({
		page,
	}) => {
		// 先確保開關是開啟的（延續上一個測試的狀態）
		await navigateToAdmin(page, `/courses/edit/${courseId}`)
		await waitForFormLoaded(page)
		await clickTab(page, '其他設定')

		const switchEl = page.locator(
			'.ant-form-item:has(.ant-form-item-label:has-text("啟用循序學習模式")) .ant-switch',
		)
		await expect(switchEl).toBeVisible({ timeout: 10_000 })

		// 若開關為開啟狀態，點擊關閉
		const isChecked = await switchEl.evaluate((el) =>
			el.classList.contains('ant-switch-checked'),
		)
		if (!isChecked) {
			// 先開啟再關閉
			await switchEl.click()
			await expect(switchEl).toHaveClass(/ant-switch-checked/)
		}

		// 關閉開關
		await switchEl.click()
		await expect(switchEl).not.toHaveClass(/ant-switch-checked/)

		// 儲存
		const saveButton = page
			.locator('button:has-text("儲存"), button:has-text("更新")')
			.first()
		await saveButton.click()
		await waitForMessage(page, 'success')

		// 重新載入
		await navigateToAdmin(page, `/courses/edit/${courseId}`)
		await waitForFormLoaded(page)
		await clickTab(page, '其他設定')

		// 開關應為關閉
		const switchElReloaded = page.locator(
			'.ant-form-item:has(.ant-form-item-label:has-text("啟用循序學習模式")) .ant-switch',
		)
		await expect(switchElReloaded).not.toHaveClass(/ant-switch-checked/)
	})

	test('外部課程不顯示線性觀看開關', async ({ page, browser }) => {
		// 建立外部課程
		const { api, dispose } = await setupApiFromBrowser(browser)
		let externalCourseId: number | undefined
		try {
			externalCourseId = await api.createCourse('E2E 外部課程')
			await api.updateCourse(externalCourseId, {
				is_external: true,
				type: 'external',
			})
		} finally {
			await dispose()
		}

		if (!externalCourseId) return

		try {
			await navigateToAdmin(page, `/courses/edit/${externalCourseId}`)
			await waitForFormLoaded(page)
			await clickTab(page, '其他設定')

			// 外部課程不應顯示「教室設定」區塊
			const heading = page.getByText('教室設定', { exact: false })
			await expect(heading).not.toBeVisible({ timeout: 5_000 })

			// 不應顯示線性觀看開關
			const switchLabel = page.getByText('啟用循序學習模式', {
				exact: false,
			})
			await expect(switchLabel).not.toBeVisible({ timeout: 3_000 })
		} finally {
			// 清理外部課程
			const { api: cleanApi, dispose: cleanDispose } =
				await setupApiFromBrowser(browser)
			try {
				await cleanApi.deleteCourses([externalCourseId])
			} finally {
				await cleanDispose()
			}
		}
	})
})
