/**
 * 測試目標：課程章節折疊列表
 * 對應原始碼：inc/templates/components/collapse/chapters.php
 * 前置條件：已建立測試課程，含 3 個章節
 * 預期結果：銷售頁顯示章節折疊區塊，且包含所有章節名稱
 */

import { test, expect } from '@playwright/test'
import { loadFrontendTestData, type FrontendTestData } from '../helpers/frontend-setup.js'
import { FRONTEND_COURSE, SELECTORS } from '../fixtures/test-data.js'

let td: FrontendTestData

test.beforeAll(() => {
	td = loadFrontendTestData()
})

test.describe('章節折疊列表', () => {
	async function activateChapterTab(page: import('@playwright/test').Page) {
		await page.goto(td.courseUrl)
		// 章節折疊區在非預設的 chapter tab 裡，需先點擊切換
		await page.locator('#tab-nav-chapter').click()
		// 等待 jQuery $el.show() 讓容器可見
		await page.waitForSelector(SELECTORS.chapterCollapse.container, {
			state: 'visible',
			timeout: 10_000,
		})
	}

	test('應顯示章節區塊', async ({ page }) => {
		await activateChapterTab(page)
		const chapterSection = page.locator(SELECTORS.chapterCollapse.container)
		await expect(chapterSection).toBeVisible()
	})

	test('應顯示所有章節名稱', async ({ page }) => {
		await activateChapterTab(page)
		for (const ch of FRONTEND_COURSE.chapters) {
			await expect(page.getByText(ch.name).first()).toBeVisible()
		}
	})

	test('應顯示正確的章節數量', async ({ page }) => {
		await activateChapterTab(page)
		const items = page.locator(SELECTORS.chapterCollapse.item)
		const count = await items.count()
		expect(count).toBeGreaterThanOrEqual(FRONTEND_COURSE.chapters.length)
	})
})
