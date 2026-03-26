/**
 * 整合測試：PHP 錯誤掃描
 *
 * 遍歷主要前後台頁面與 API 端點，確認沒有 PHP fatal error
 */
import { test, expect } from '@playwright/test'
import { ApiClient, setupApiFromBrowser } from '../helpers/api-client'

const BASE_URL = process.env.TEST_SITE_URL || 'http://localhost:8889'

let api: ApiClient
let dispose: () => Promise<void>

test.describe('PHP 錯誤掃描', () => {
	test.beforeAll(async ({ browser }) => {
		;({ api, dispose } = await setupApiFromBrowser(browser))
	})

	test.afterAll(async () => {
		await dispose()
	})

	test('後台管理頁面無 PHP fatal error', async ({ browser }) => {
		const ctx = await browser.newContext({
			storageState: '.auth/admin.json',
		})
		const page = await ctx.newPage()

		const adminRoutes = [
			'#/courses',
			'#/teachers',
			'#/students',
			'#/products',
			'#/emails',
			'#/settings',
			'#/analytics',
		]

		for (const route of adminRoutes) {
			const resp = await page.goto(
				`${BASE_URL}/wp-admin/admin.php?page=power-course${route}`,
				{ waitUntil: 'domcontentloaded' },
			)
			// hash 路由切換時 page.goto() 可能回傳 null（因基礎頁已載入）
			const status = resp?.status() ?? 200
			expect(status).toBeLessThan(500)

			// 檢查頁面是否包含 PHP fatal error 字串
			const body = await page.content()
			expect(body).not.toContain('Fatal error')
			expect(body).not.toContain('Parse error')
		}

		await ctx.close()
	})

	test('前台首頁和商店頁面無 PHP fatal error', async ({ browser }) => {
		const ctx = await browser.newContext()
		const page = await ctx.newPage()

		const frontPages = ['/', '/shop/', '/my-account/', '/cart/']

		for (const path of frontPages) {
			const resp = await page.goto(`${BASE_URL}${path}`, {
				waitUntil: 'domcontentloaded',
			})
			expect(resp?.status()).toBeLessThan(500)

			const body = await page.content()
			expect(body).not.toContain('Fatal error')
			expect(body).not.toContain('Parse error')
		}

		await ctx.close()
	})

	test('主要 REST API 端點回應正常', async () => {
		const endpoints = [
			'courses',
			'courses/options',
			'options',
		]

		for (const ep of endpoints) {
			const resp = await api.pcGet(ep)
			// 200 或 400（參數不足）都可以，只要不是 500
			expect(resp.status).toBeLessThan(500)
		}
	})

	test('WooCommerce REST API 可用', async () => {
		const resp = await api.wcGet('products', { per_page: '1' })
		expect(resp.status).toBe(200)
	})
})
