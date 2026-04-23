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

	test('API round-trip：設定 enable_linear_viewing 為 yes 後 GET 應正確回傳', async ({
		browser,
	}) => {
		// 此測試直接走 REST API，繞過 UI，用來定位 bug 是在 API 層還是 UI 層
		// 對應 fix：inc/classes/Api/Course.php:292 補上 enable_linear_viewing 欄位
		const { api, dispose } = await setupApiFromBrowser(browser)
		try {
			// 1. 透過 PC API POST 將設定寫入 DB
			await api.updateCourse(courseId, {
				enable_linear_viewing: 'yes',
			})

			// 2. 透過 PC API GET 讀回單一課程
			const resp = await api.pcGet<Record<string, unknown>>(
				`courses/${courseId}`,
			)
			expect(resp.status).toBe(200)

			const course = resp.data as Record<string, unknown>

			// 3. response body 必須包含 enable_linear_viewing key（防止 API 層漏回傳）
			expect(course).toHaveProperty('enable_linear_viewing')

			// 4. 值必須是寫入時的 'yes'
			expect(course.enable_linear_viewing).toBe('yes')
		} finally {
			// 還原成 'no' 避免汙染後續 UI round-trip 測試
			await api.updateCourse(courseId, {
				enable_linear_viewing: 'no',
			})
			await dispose()
		}
	})

	test('API round-trip：全新課程的 enable_linear_viewing 預設值應為 no', async ({
		browser,
	}) => {
		// 驗證未設定該 meta 時，API 回傳 fallback 預設值 'no'
		// 對應 fix 中的 `?: 'no'` fallback 邏輯
		const { api, dispose } = await setupApiFromBrowser(browser)
		let freshCourseId: number | undefined
		try {
			// 建立全新課程，不主動設定 enable_linear_viewing
			freshCourseId = await api.createCourse(
				'E2E 線性觀看 API 預設值測試課程',
			)

			const resp = await api.pcGet<Record<string, unknown>>(
				`courses/${freshCourseId}`,
			)
			expect(resp.status).toBe(200)

			const course = resp.data as Record<string, unknown>
			expect(course).toHaveProperty('enable_linear_viewing')
			expect(course.enable_linear_viewing).toBe('no')
		} finally {
			if (freshCourseId) {
				await api.deleteCourses([freshCourseId])
			}
			await dispose()
		}
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
