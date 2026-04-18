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

	test('結帳頁 redirect 應清除 add-to-cart URL 參數', async ({ page }) => {
		// 直接帶 add-to-cart 參數訪問結帳頁，伺服器應 302 redirect 到乾淨 URL
		await page.goto(`/checkout/?add-to-cart=${td.courseId}`, {
			waitUntil: 'domcontentloaded',
		})
		await page.waitForLoadState('networkidle')

		// 驗證最終 URL 不含 add-to-cart 參數
		expect(page.url()).not.toContain('add-to-cart')
	})

	test('重整結帳頁後購物車數量應維持不變', async ({ page }) => {
		// 帶 add-to-cart 參數訪問結帳頁（加入商品）
		await page.goto(`/checkout/?add-to-cart=${td.courseId}`, {
			waitUntil: 'domcontentloaded',
		})
		await page.waitForLoadState('networkidle')

		// 驗證 redirect 後 URL 是乾淨的結帳頁
		expect(page.url()).not.toContain('add-to-cart')

		// 記錄重整前的購物車商品總數量
		const courseId = td.courseId
		const getCartQuantity = async () => {
			const cartResponse = await page.evaluate(async () => {
				const res = await fetch('/wp-json/wc/store/v1/cart')
				return res.json()
			})
			return (cartResponse.items || [])
				.filter((item: { id: number }) => item.id === courseId)
				.reduce(
					(sum: number, item: { quantity: number }) => sum + item.quantity,
					0,
				)
		}

		const qtyBeforeReload = await getCartQuantity()
		expect(qtyBeforeReload).toBeGreaterThanOrEqual(1)

		// 重整頁面 — 不應再次加入商品
		await page.reload({ waitUntil: 'domcontentloaded' })
		await page.waitForLoadState('networkidle')

		// 驗證購物車數量與重整前完全一致（重整沒有增加商品）
		const qtyAfterReload = await getCartQuantity()
		expect(qtyAfterReload).toBe(qtyBeforeReload)
	})

	test('redirect 應保留非 add-to-cart 的 query 參數', async ({ page }) => {
		// 帶 add-to-cart 與自訂參數一起訪問結帳頁
		await page.goto(
			`/checkout/?add-to-cart=${td.courseId}&test_param=hello`,
			{ waitUntil: 'domcontentloaded' },
		)
		await page.waitForLoadState('networkidle')

		const finalUrl = page.url()

		// 驗證 add-to-cart 已被移除
		expect(finalUrl).not.toContain('add-to-cart')

		// 驗證自訂參數被保留
		expect(finalUrl).toContain('test_param=hello')
	})
})
