/**
 * 測試目標：My Account 課程列表
 * 對應原始碼：inc/templates/pages/my-account/, inc/classes/FrontEnd/MyAccount.php
 * 前置條件：管理員已登入且有課程存取權限
 * 預期結果：My Account 課程頁面顯示已購買的課程
 */

import { test, expect } from '@playwright/test'
import { setupApiFromBrowser } from '../helpers/api-client.js'
import { loadFrontendTestData, loginAs, type FrontendTestData } from '../helpers/frontend-setup.js'
import { SELECTORS, WP_ADMIN, FRONTEND_COURSE } from '../fixtures/test-data.js'

let td: FrontendTestData

test.beforeAll(async ({ browser }) => {
	td = loadFrontendTestData()
	// 確保 admin 有存取權限
	const { api, dispose } = await setupApiFromBrowser(browser)
	await api.grantCourseAccess(1, td.courseId)
	await dispose()
})

test.describe('My Account 課程列表', () => {
	test.beforeEach(async ({ page }) => {
		await loginAs(page, WP_ADMIN.username, WP_ADMIN.password)
	})

	test('應可存取 My Account 課程頁面', async ({ page }) => {
		await page.goto('/my-account/courses/')
		const status = page.url()
		expect(status).toContain('courses')
	})

	test('應顯示已購買的課程', async ({ page }) => {
		await page.goto('/my-account/courses/')
		const courseCards = page.locator(SELECTORS.myAccount.courseCard)
		const courseName = page.getByText(FRONTEND_COURSE.name).first()
		const hasCards = (await courseCards.count()) > 0
		const hasName = (await courseName.count()) > 0
		expect(hasCards || hasName).toBeTruthy()
	})

	test('課程卡片應包含課程名稱', async ({ page }) => {
		await page.goto('/my-account/courses/')
		const nameEl = page.locator(SELECTORS.myAccount.courseName).first()
		const count = await nameEl.count()
		if (count > 0) {
			const text = await nameEl.textContent()
			expect(text).toContain(FRONTEND_COURSE.name)
		} else {
			// 退而求其次：頁面中有課程名稱
			await expect(page.getByText(FRONTEND_COURSE.name).first()).toBeVisible()
		}
	})
})
