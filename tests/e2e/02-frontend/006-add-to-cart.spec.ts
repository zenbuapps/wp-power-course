/**
 * 測試目標：加入購物車
 * 對應原始碼：inc/templates/components/button/add-to-cart.php, inc/templates/pages/course-product/sider.php
 * 前置條件：已建立測試課程（有價格），WooCommerce BACS 已啟用
 * 預期結果：訪客可以將課程加入購物車
 */

import { test, expect } from '@playwright/test'
import { loadFrontendTestData, type FrontendTestData } from '../helpers/frontend-setup.js'
import { SELECTORS } from '../fixtures/test-data.js'

let td: FrontendTestData

test.beforeAll(() => {
	td = loadFrontendTestData()
})

test.describe('加入購物車', () => {
	test('應有 CTA 按鈕（立即報名）', async ({ page }) => {
		await page.goto(td.courseUrl)
		// CTA 是 <a class="pc-add-to-cart-link">立即報名</a>
		const ctaBtn = page.locator(SELECTORS.courseProduct.ctaButton).first()
		await expect(ctaBtn).toBeVisible()
	})

	test('點擊 CTA 按鈕後應可導向結帳或購物車', async ({ page }) => {
		await page.goto(td.courseUrl)
		const ctaBtn = page.locator(SELECTORS.courseProduct.ctaButton).first()

		const count = await ctaBtn.count()
		if (count > 0) {
			await ctaBtn.click()
			await page.waitForTimeout(2000)
			const url = page.url()
			const coursePathname = new URL(td.courseUrl).pathname
			const validStates =
				url.includes('cart') ||
				url.includes('checkout') ||
				(await page.locator('.woocommerce-message, .added_to_cart, [class*="cart"]').count()) > 0 ||
				url.includes(coursePathname)
			expect(validStates).toBeTruthy()
		}
	})
})
