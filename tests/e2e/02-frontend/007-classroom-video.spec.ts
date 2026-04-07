/**
 * 測試目標：教室影片播放器
 * 對應原始碼：inc/templates/pages/classroom/body.php, inc/templates/components/video/
 * 前置條件：管理員已登入（管理員可預覽所有教室頁面），測試課程已建立含章節
 * 預期結果：教室頁面載入成功，顯示章節標題與主要區塊
 */

import { test, expect } from '@playwright/test'
import { loadFrontendTestData, loginAs, type FrontendTestData } from '../helpers/frontend-setup.js'
import { SELECTORS, WP_ADMIN, FRONTEND_COURSE } from '../fixtures/test-data.js'

let td: FrontendTestData

test.beforeAll(() => {
	td = loadFrontendTestData()
})

test.describe('教室影片播放器', () => {
	test.beforeEach(async ({ page }) => {
		// 以管理員身份登入（管理員可預覽所有教室）
		await loginAs(page, WP_ADMIN.username, WP_ADMIN.password)
	})

	test('教室頁面應正常載入', async ({ page }) => {
		const classroomUrl = `/classroom/${td.courseSlug}/${td.chapterSlugs[0]}/`
		await page.goto(classroomUrl)
		await page.waitForLoadState('networkidle')
		// 頁面不應顯示 404 或錯誤
		const title = await page.title()
		expect(title).not.toContain('找不到頁面')
		expect(title).not.toContain('Page not found')
	})

	test('應顯示教室主區塊', async ({ page }) => {
		const classroomUrl = `/classroom/${td.courseSlug}/${td.chapterSlugs[0]}/`
		await page.goto(classroomUrl)
		await page.waitForLoadState('networkidle')
		// 檢查教室主體區塊
		const body = page.locator(SELECTORS.classroom.body)
		const header = page.locator(SELECTORS.classroom.header)
		// 至少一個核心區塊存在
		const bodyExists = (await body.count()) > 0
		const headerExists = (await header.count()) > 0
		expect(bodyExists || headerExists).toBeTruthy()
	})

	test('應顯示章節標題', async ({ page }) => {
		const classroomUrl = `/classroom/${td.courseSlug}/${td.chapterSlugs[0]}/`
		await page.goto(classroomUrl)
		await page.waitForLoadState('networkidle')
		// 使用 #classroom-chapter_title 精確定位（header.php line 103）
		const titleEl = page.locator('#classroom-chapter_title')
		await expect(titleEl).toContainText(FRONTEND_COURSE.chapters[0].name, {
			timeout: 10_000,
		})
	})
})
