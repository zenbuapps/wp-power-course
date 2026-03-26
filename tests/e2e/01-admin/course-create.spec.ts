/**
 * 課程建立測試
 *
 * 驗證透過 Admin SPA「新增課程」按鈕建立課程的流程。
 * useCreate 不會自動跳轉，建立後停留在列表頁、列表自動刷新。
 */

import { test, expect } from '@playwright/test'
import {
	navigateToAdmin,
	waitForProTableLoaded,
} from '../helpers/admin-page'
import { setupApiFromBrowser } from '../helpers/api-client'

test.describe('課程建立', () => {
	test.use({ storageState: '.auth/admin.json' })

	const createdCourseIds: number[] = []

	test.afterAll(async ({ browser }) => {
		if (createdCourseIds.length === 0) return
		const { api, dispose } = await setupApiFromBrowser(browser)
		try {
			await api.deleteCourses(createdCourseIds)
		} finally {
			await dispose()
		}
	})

	test('點擊新增按鈕後列表出現新課程', async ({ page }) => {
		await navigateToAdmin(page, '/courses')
		await waitForProTableLoaded(page)

		// 記錄點擊前的列表行數
		const rowsBefore = await page.locator('.ant-table-row').count()

		// 攔截 API 回應以取得新課程 ID
		const responsePromise = page.waitForResponse(
			(resp) =>
				resp.url().includes('/power-course/courses') &&
				resp.request().method() === 'POST' &&
				resp.status() === 200,
		)

		await page.getByRole('button', { name: '新增課程' }).click()

		const resp = await responsePromise
		const body = await resp.json()
		const newId = Number(body?.data?.id)
		if (newId) createdCourseIds.push(newId)

		// 列表應刷新，新課程出現
		await waitForProTableLoaded(page)
		const rowsAfter = await page.locator('.ant-table-row').count()
		expect(rowsAfter).toBeGreaterThanOrEqual(rowsBefore)

		// 表格中應有「新課程」文字
		await expect(page.locator('.ant-table-wrapper')).toContainText('新課程')
	})

	test('從列表點進新課程可載入編輯表單', async ({ page }) => {
		await navigateToAdmin(page, '/courses')
		await waitForProTableLoaded(page)

		// 課程名稱是 <p> 標籤 + onClick handler（非 <a> link）
		await page
			.locator('.ant-table-row')
			.filter({ hasText: '新課程' })
			.first()
			.locator('p.cursor-pointer')
			.click()

		// 等待 hash 路由跳轉到編輯頁
		await page.waitForFunction(
			() => window.location.hash.includes('/courses/edit/'),
			{ timeout: 15_000 },
		)

		// 編輯頁有表單和 Tabs
		await expect(page.locator('.ant-form').first()).toBeVisible()
		await expect(page.locator('.ant-tabs')).toBeVisible()
	})
})
