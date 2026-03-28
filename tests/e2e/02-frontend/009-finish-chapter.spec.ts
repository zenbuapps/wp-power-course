/**
 * 測試目標：完成/取消完成章節
 * 對應原始碼：inc/classes/Resources/Chapter/Core/Api.php (toggle-finish-chapters), inc/templates/pages/classroom/header.php
 * 前置條件：管理員已登入，測試課程含章節
 * 預期結果：點擊完成按鈕可切換章節完成狀態
 */

import { test, expect } from '@playwright/test'
import { loadFrontendTestData, loginAs, type FrontendTestData } from '../helpers/frontend-setup.js'
import { SELECTORS, WP_ADMIN } from '../fixtures/test-data.js'

let td: FrontendTestData

test.beforeAll(() => {
	td = loadFrontendTestData()
})

test.describe('完成/取消完成章節', () => {
	test.beforeEach(async ({ page }) => {
		await loginAs(page, WP_ADMIN.username, WP_ADMIN.password)
	})

	test('應顯示完成章節按鈕', async ({ page }) => {
		const classroomUrl = `/classroom/${td.courseSlug}/${td.chapterSlugs[0]}/`
		await page.goto(classroomUrl)
		await page.waitForLoadState('networkidle')
		const finishBtn = page.locator(SELECTORS.classroom.finishButton)
		await expect(finishBtn).toBeVisible({ timeout: 10_000 })
	})

	test('點擊完成按鈕應改變狀態', async ({ page }) => {
		const classroomUrl = `/classroom/${td.courseSlug}/${td.chapterSlugs[0]}/`
		await page.goto(classroomUrl)
		await page.waitForLoadState('networkidle')

		const finishBtn = page
			.locator(
				`${SELECTORS.classroom.finishButton}, button:has-text("標示為已完成"), button:has-text("標示為未完成")`,
			)
			.first()

		await expect(finishBtn).toBeVisible({ timeout: 10_000 })
		const initialText = await finishBtn.textContent()

		// 點擊並等待 AJAX 完成（無對話框，直接 fetch 切換狀態）
		await Promise.all([
			page.waitForResponse(
				(r) => r.url().includes('toggle-finish-chapters') && r.status() === 200,
				{ timeout: 15_000 },
			),
			finishBtn.click(),
		])

		// 等待按鈕文字改變
		if (initialText?.includes('已完成')) {
			await expect(finishBtn).toContainText('未完成', { timeout: 5_000 })
		} else {
			await expect(finishBtn).toContainText('已完成', { timeout: 5_000 })
		}
	})
})
