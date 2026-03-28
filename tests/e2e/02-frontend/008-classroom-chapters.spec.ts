/**
 * 測試目標：教室章節導航側邊欄
 * 對應原始碼：inc/templates/pages/classroom/sider.php, chapters.php
 * 前置條件：管理員已登入，測試課程含 3 個章節
 * 預期結果：側邊欄顯示所有章節，可在章節間導航
 */

import { test, expect } from '@playwright/test'
import { loadFrontendTestData, loginAs, type FrontendTestData } from '../helpers/frontend-setup.js'
import { SELECTORS, WP_ADMIN, FRONTEND_COURSE } from '../fixtures/test-data.js'

let td: FrontendTestData

test.beforeAll(() => {
	td = loadFrontendTestData()
})

test.describe('教室章節導航', () => {
	test.beforeEach(async ({ page }) => {
		await loginAs(page, WP_ADMIN.username, WP_ADMIN.password)
	})

	test('應顯示章節側邊欄', async ({ page }) => {
		const classroomUrl = `/classroom/${td.courseSlug}/${td.chapterSlugs[0]}/`
		await page.goto(classroomUrl)
		await page.waitForLoadState('networkidle')
		// 等待 jQuery .show() 讓章節列表可見（初始由 CSS class 隱藏）
		await page.locator('#pc-sider__main-chapters').waitFor({
			state: 'visible',
			timeout: 15_000,
		})
		const sider = page.locator(SELECTORS.classroom.sider)
		const siderExists = (await sider.count()) > 0
		if (siderExists) {
			await expect(sider).toBeVisible()
		}
		// 側邊欄或章節列表至少一個存在
		const chapterList = page.locator(SELECTORS.classroom.chapterList)
		const mainChapters = page.locator(SELECTORS.classroom.mainChapters)
		const hasChapterNav =
			(await chapterList.count()) > 0 || (await mainChapters.count()) > 0
		expect(siderExists || hasChapterNav).toBeTruthy()
	})

	test('章節列表應包含所有章節', async ({ page }) => {
		const classroomUrl = `/classroom/${td.courseSlug}/${td.chapterSlugs[0]}/`
		await page.goto(classroomUrl)
		await page.waitForLoadState('networkidle')
		await page.locator('#pc-sider__main-chapters').waitFor({
			state: 'visible',
			timeout: 15_000,
		})
		for (const ch of FRONTEND_COURSE.chapters) {
			await expect(page.getByText(ch.name).first()).toBeVisible({ timeout: 10_000 })
		}
	})

	test('點擊其他章節應可導航', async ({ page }) => {
		const classroomUrl = `/classroom/${td.courseSlug}/${td.chapterSlugs[0]}/`
		await page.goto(classroomUrl)
		await page.waitForLoadState('networkidle')
		await page.locator('#pc-sider__main-chapters').waitFor({
			state: 'visible',
			timeout: 15_000,
		})
		// 點擊第二章的 <li> 元素（jQuery handler 讀取 data-href 做 window.location.href 導航）
		const ch2Li = page
			.locator('#pc-sider__main-chapters li')
			.filter({ hasText: FRONTEND_COURSE.chapters[1].name })
			.first()
		await ch2Li.waitFor({ state: 'visible', timeout: 10_000 })
		// 同時啟動 URL 監聽與點擊，避免導航競態
		await Promise.all([
			page.waitForURL(`**/${td.chapterSlugs[1]}/**`, { timeout: 15_000 }),
			ch2Li.click(),
		])
		expect(page.url()).toContain(td.chapterSlugs[1])
	})
})
