/**
 * 測試目標：課程銷售頁面渲染
 * 對應原始碼：inc/templates/pages/course-product/ (header.php, body.php, sider.php)
 * 前置條件：已建立測試課程（含價格、章節）
 * 預期結果：銷售頁各主要區塊皆可見
 */

import { test, expect } from '@playwright/test'
import { loadFrontendTestData, type FrontendTestData } from '../helpers/frontend-setup.js'
import { SELECTORS, FRONTEND_COURSE } from '../fixtures/test-data.js'

let td: FrontendTestData

test.beforeAll(() => {
	td = loadFrontendTestData()
})

test.describe('課程銷售頁渲染', () => {
	test('應顯示課程標題', async ({ page }) => {
		await page.goto(td.courseUrl)
		await expect(page.locator('h1, h2').filter({ hasText: FRONTEND_COURSE.name }).first()).toBeVisible()
	})

	test('應顯示價格區塊', async ({ page }) => {
		await page.goto(td.courseUrl)
		await expect(page.locator(SELECTORS.courseProduct.pricing)).toBeVisible()
	})

	test('應顯示課程內容區塊', async ({ page }) => {
		await page.goto(td.courseUrl)
		// featureContent 有 opacity:0 動畫，改用 DOM 存在判斷
		await expect(page.locator(SELECTORS.courseProduct.featureContent)).toHaveCount(1)
	})

	test('應顯示 CTA 按鈕', async ({ page }) => {
		await page.goto(td.courseUrl)
		await expect(page.locator(SELECTORS.courseProduct.ctaButton).first()).toBeVisible()
	})
})
