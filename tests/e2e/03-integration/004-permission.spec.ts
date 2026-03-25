/**
 * 整合測試：角色權限驗證
 *
 * 驗證 Admin / Subscriber / Guest 呼叫 REST API 的權限差異
 */
import { test, expect, type APIRequestContext } from '@playwright/test'
import { ApiClient, setupApiFromBrowser, getNonceFromPage } from '../helpers/api-client'

const BASE_URL = process.env.TEST_SITE_URL || 'http://localhost:8889'

let adminApi: ApiClient
let dispose: () => Promise<void>
let subscriberId: number

test.describe('角色權限驗證', () => {
	test.beforeAll(async ({ browser }) => {
		;({ api: adminApi, dispose } = await setupApiFromBrowser(browser))

		// 確保學員存在
		subscriberId = await adminApi.ensureUser(
			'e2e_perm_sub',
			'e2e_perm_sub@test.local',
			'e2e_perm_pass',
		)
	})

	test.afterAll(async () => {
		await dispose()
	})

	test('Admin 可以呼叫課程管理 API', async () => {
		const resp = await adminApi.pcGet('courses')
		expect(resp.status).toBe(200)
	})

	test('Admin 可以建立課程', async () => {
		const courseId = await adminApi.createCourse('E2E Perm Test', 'e2e-perm-test')
		expect(courseId).toBeGreaterThan(0)

		// 清除
		await adminApi.deleteCourses([courseId])
	})

	test('Subscriber 呼叫管理 API 應被拒絕', async ({ browser }) => {
		// 以學員身份登入取得 nonce
		const ctx = await browser.newContext()
		const page = await ctx.newPage()
		await page.goto(`${BASE_URL}/wp-login.php`)
		await page.fill('#user_login', 'e2e_perm_sub')
		await page.fill('#user_pass', 'e2e_perm_pass')
		await page.click('#wp-submit', { noWaitAfter: true })
		await page.waitForURL((url) => !url.pathname.includes('wp-login'), { timeout: 15_000 })

		// 取得學員 nonce
		await page.goto(`${BASE_URL}/wp-admin/`)
		const nonce = await getNonceFromPage(page)

		const subscriberApi = new ApiClient(ctx.request, nonce)

		// 嘗試建立課程 — 應該失敗
		const resp = await subscriberApi.pcPostForm('courses', {
			name: 'Forbidden Course',
			type: 'simple',
			course_schedule: '0',
			editor: 'power-editor',
		})

		// 期望 403 Forbidden（也可能是 401）
		expect([401, 403]).toContain(resp.status)

		await ctx.close()
	})

	test('未登入訪客呼叫 API 應回傳 401', async ({ browser }) => {
		// 建立一個沒有 cookie 的 context
		const ctx = await browser.newContext()
		const noAuthApi = new ApiClient(ctx.request, '')

		const resp = await noAuthApi.pcGet('courses')
		// 未認證 → 401
		expect([401, 403]).toContain(resp.status)

		await ctx.close()
	})
})
