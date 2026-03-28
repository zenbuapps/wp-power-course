/**
 * 測試目標：課程價格顯示
 * 對應原始碼：inc/templates/components/card/pricing.php, inc/templates/pages/course-product/sider.php
 * 前置條件：已建立測試課程（regular_price = 1500）
 * 預期結果：價格正確顯示在銷售頁
 */

import { test, expect } from '@playwright/test'
import { loadFrontendTestData, type FrontendTestData } from '../helpers/frontend-setup.js'
import { SELECTORS } from '../fixtures/test-data.js'

let td: FrontendTestData

test.beforeAll(() => {
	td = loadFrontendTestData()
})

test.describe('課程價格顯示', () => {
	test('應顯示正確價格', async ({ page }) => {
		await page.goto(td.courseUrl)
		const priceEl = page.locator(SELECTORS.courseProduct.priceHtml).first()
		await expect(priceEl).toBeVisible()
		// 價格應包含 1,500 或 1500
		const text = await priceEl.textContent()
		expect(text).toMatch(/1[,.]?500/)
	})

	test('應存在定價區塊容器', async ({ page }) => {
		await page.goto(td.courseUrl)
		await expect(page.locator(SELECTORS.courseProduct.pricing)).toBeVisible()
	})
})
