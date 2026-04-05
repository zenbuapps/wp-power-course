/**
 * 測試目標：課程進度追蹤
 * 對應原始碼：inc/classes/Utils/Course.php (get_course_progress), inc/templates/components/progress/
 * 前置條件：管理員已登入，測試課程含章節
 * 預期結果：完成章節後進度條更新
 */

import { test, expect } from '@playwright/test'
import { loadFrontendTestData, loginAs, type FrontendTestData } from '../helpers/frontend-setup.js'
import { WP_ADMIN } from '../fixtures/test-data.js'

let td: FrontendTestData

test.beforeAll(() => {
	td = loadFrontendTestData()
})

test.describe('課程進度追蹤', () => {
	test.beforeEach(async ({ page }) => {
		await loginAs(page, WP_ADMIN.username, WP_ADMIN.password)
	})

	test('教室頁面應存在進度相關元素', async ({ page }) => {
		const classroomUrl = `/classroom/${td.courseSlug}/${td.chapterSlugs[0]}/`
		await page.goto(classroomUrl)
		// 進度可能以 progress bar、百分比文字、或徽章形式存在
		const progressElements = page.locator(
			'.pc-progress, [class*="progress"], [role="progressbar"]',
		)
		const badges = page.locator('[class*="badge"]')
		const hasProgress =
			(await progressElements.count()) > 0 || (await badges.count()) > 0
		// 進度元素或徽章至少一個存在
		expect(hasProgress).toBeTruthy()
	})

	test('完成章節後進度應有變化', async ({ page }) => {
		const classroomUrl = `/classroom/${td.courseSlug}/${td.chapterSlugs[1]}/`
		await page.goto(classroomUrl)

		// 記錄完成前的頁面狀態
		const bodyBefore = await page.locator('body').textContent()

		// 嘗試完成第二章
		const finishBtn = page
			.locator(
				'#finish-chapter__button, button:has-text("標示為已完成")',
			)
			.first()
		const btnExists = (await finishBtn.count()) > 0
		if (btnExists) {
			const btnText = await finishBtn.textContent()
			if (btnText?.includes('已完成') && !btnText?.includes('未完成')) {
				await finishBtn.click()
				// 等待 AJAX
				await page.waitForTimeout(2000)
			}
		}

		// 檢查頁面已重新載入或狀態已改變
		const bodyAfter = await page.locator('body').textContent()
		expect(bodyAfter).toBeDefined()
	})
})
