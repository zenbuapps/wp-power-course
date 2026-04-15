/**
 * 測試目標：評論區段
 * 對應原始碼：inc/templates/components/review/, inc/templates/pages/course-product/tabs/
 * 前置條件：已建立測試課程
 * 預期結果：銷售頁包含評論/Q&A Tab 區段
 */

import { test, expect } from '@playwright/test'
import { loadFrontendTestData, type FrontendTestData } from '../helpers/frontend-setup.js'
import { SELECTORS } from '../fixtures/test-data.js'

let td: FrontendTestData

test.beforeAll(() => {
	td = loadFrontendTestData()
})

test.describe('評論區段', () => {
	test('應顯示 Tabs 導航', async ({ page }) => {
		await page.goto(td.courseUrl)
		const tabsNav = page.locator(SELECTORS.courseProduct.tabsNav)
		const count = await tabsNav.count()
		if (count > 0) {
			await expect(tabsNav).toBeVisible()
		} else {
			// Tabs 可能使用不同選擇器
			const tabs = page.locator('.pc-tabs, [role="tablist"]').first()
			await expect(tabs).toBeVisible()
		}
	})

	test('應包含可點擊的 Tab 項目', async ({ page }) => {
		await page.goto(td.courseUrl)
		const tabs = page.locator(SELECTORS.courseProduct.tabNavItem)
		const count = await tabs.count()
		expect(count).toBeGreaterThan(0)
	})
})
