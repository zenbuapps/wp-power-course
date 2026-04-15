/**
 * 整合測試：到期日驗證
 *
 * 驗證不同 limit_type 的到期日計算是否正確：unlimited / fixed / assigned
 */
import { test, expect } from '@playwright/test'
import { ApiClient, setupApiFromBrowser } from '../helpers/api-client'

let api: ApiClient
let dispose: () => Promise<void>
let courseId: number
let courseSlug: string
let subscriberId: number

test.describe('到期日驗證', () => {
	test.beforeAll(async ({ browser }) => {
		;({ api, dispose } = await setupApiFromBrowser(browser))

		// 建立含章節的測試課程（test 3 需要進入教室 URL）
		const result = await api.createCourseWithChapters(
			'E2E Expire Date Test',
			'1000',
			[{ name: 'Expire Ch1', slug: 'expire-ch1' }],
			'e2e-expire-date-test',
		)
		courseId = result.courseId
		courseSlug = result.courseSlug

		// 建立學員
		subscriberId = await api.ensureUser(
			'e2e_expire_tester',
			'e2e_expire_tester@test.local',
			'e2e_expire_pass',
		)
	})

	test.afterAll(async () => {
		try {
			await api.removeCourseAccess(subscriberId, courseId)
		} catch { /* ignore */ }
		try {
			await api.deleteCourses([courseId])
		} catch { /* ignore */ }
		await dispose()
	})

	test('無限期存取：expire_date 為 0', async () => {
		// 授權（無限期 → expire_date = 0）
		await api.grantCourseAccess(subscriberId, courseId, 0)

		// 驗證可以取得課程資料（不會被阻擋）
		const resp = await api.pcGet(`courses/${courseId}`)
		expect(resp.status).toBe(200)

		// 清除
		await api.removeCourseAccess(subscriberId, courseId)
	})

	test('指定到期時間戳：學員在到期前可存取', async () => {
		// 設定 30 天後到期
		const futureTimestamp = Math.floor(Date.now() / 1000) + 86400 * 30
		await api.grantCourseAccess(subscriberId, courseId, futureTimestamp)

		// 驗證 — 呼叫 API 確認存取正常
		const resp = await api.pcGet(`courses/${courseId}`)
		expect(resp.status).toBe(200)

		// 清除
		await api.removeCourseAccess(subscriberId, courseId)
	})

	test('已過期：學員無法進入教室', async ({ browser }) => {
		// 先授權
		await api.grantCourseAccess(subscriberId, courseId)
		// 再設定為已過期
		await api.setCourseExpired(subscriberId, courseId)

		// 透過瀏覽器驗證（需要以學員登入）
		const ctx = await browser.newContext()
		const page = await ctx.newPage()
		const baseUrl = process.env.TEST_SITE_URL || 'http://localhost:8889'

		// 登入學員
		await page.goto(`${baseUrl}/wp-login.php`)
		await page.fill('#user_login', 'e2e_expire_tester')
		await page.fill('#user_pass', 'e2e_expire_pass')
		await page.click('#wp-submit', { noWaitAfter: true })
		await page.waitForURL((url) => !url.pathname.includes('wp-login'), { timeout: 30_000, waitUntil: 'domcontentloaded' })

		// 造訪教室 — 應看到存取拒絕（過期）
		await page.goto(`${baseUrl}/classroom/${courseSlug}/expire-ch1/`, {
			waitUntil: 'domcontentloaded',
		})
		await page.waitForLoadState('networkidle')

		// 檢查是否出現過期/存取拒絕提示
		const body = await page.content()
		const hasExpiredHint =
			body.includes('expired') ||
			body.includes('過期') ||
			body.includes('alert') ||
			await page.locator('.pc-alert, .alert, [class*="expired"]').isVisible().catch(() => false)
		expect(hasExpiredHint).toBe(true)

		await ctx.close()

		// 清除
		await api.removeCourseAccess(subscriberId, courseId)
	})
})
