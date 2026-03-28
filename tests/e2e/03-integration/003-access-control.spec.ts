/**
 * 整合測試：課程存取控制
 *
 * 驗證授權→進入、移除→拒絕、過期→拒絕的完整存取控制流程
 */
import { test, expect } from '@playwright/test'
import { ApiClient, setupApiFromBrowser } from '../helpers/api-client'
import { loginAs } from '../helpers/frontend-setup'
import { SELECTORS, FRONTEND_COURSE } from '../fixtures/test-data'

const BASE_URL = process.env.TEST_SITE_URL || 'http://localhost:8889'

let api: ApiClient
let dispose: () => Promise<void>
let courseId: number
let chapterIds: number[]
let courseSlug: string
let subscriberId: number

test.describe('課程存取控制', () => {
	test.beforeAll(async ({ browser }) => {
		;({ api, dispose } = await setupApiFromBrowser(browser))

		const result = await api.createCourseWithChapters(
			'E2E Access Control',
			'1000',
			[{ name: 'AC Chapter 1', slug: 'ac-ch1' }],
			'e2e-access-control',
		)
		courseId = result.courseId
		chapterIds = result.chapterIds
		courseSlug = result.courseSlug

		subscriberId = await api.ensureUser(
			'e2e_access_ctrl',
			'e2e_access_ctrl@test.local',
			'e2e_access_pass',
		)
	})

	test.afterAll(async () => {
		try { await api.removeCourseAccess(subscriberId, courseId) } catch { /* ignore */ }
		try { await api.deleteCourses([courseId]) } catch { /* ignore */ }
		await dispose()
	})

	test('有權限的學員可以進入教室', async ({ browser }) => {
		await api.grantCourseAccess(subscriberId, courseId)

		const ctx = await browser.newContext()
		const page = await ctx.newPage()
		await loginAs(page, 'e2e_access_ctrl', 'e2e_access_pass')

		await page.goto(`${BASE_URL}/classroom/${courseSlug}/ac-ch1/`, {
			waitUntil: 'domcontentloaded',
		})
		await page.waitForLoadState('networkidle')

		// 不應該看到存取拒絕警告
		const hasAlert = await page.locator(SELECTORS.accessDenied.alertError).isVisible().catch(() => false)
		expect(hasAlert).toBe(false)

		// 應該看到教室元素
		const hasHeader = await page.locator(SELECTORS.classroom.header).isVisible().catch(() => false)
		const hasBody = await page.locator(SELECTORS.classroom.body).isVisible().catch(() => false)
		expect(hasHeader || hasBody).toBe(true)

		await ctx.close()
	})

	test('移除權限後無法進入教室', async ({ browser }) => {
		// 先授權再移除
		await api.grantCourseAccess(subscriberId, courseId)
		await api.removeCourseAccess(subscriberId, courseId)

		const ctx = await browser.newContext()
		const page = await ctx.newPage()
		await loginAs(page, 'e2e_access_ctrl', 'e2e_access_pass')

		await page.goto(`${BASE_URL}/classroom/${courseSlug}/ac-ch1/`, {
			waitUntil: 'domcontentloaded',
		})
		await page.waitForLoadState('networkidle')

		// 應該看到「未購買」的存取拒絕頁
		const alertVisible = await page.locator(SELECTORS.accessDenied.alertError).isVisible().catch(() => false)
		expect(alertVisible).toBe(true)

		await ctx.close()
	})

	test('過期的學員無法進入教室', async ({ browser }) => {
		// 授權後設定為過期
		await api.grantCourseAccess(subscriberId, courseId)
		await api.setCourseExpired(subscriberId, courseId)

		const ctx = await browser.newContext()
		const page = await ctx.newPage()
		await loginAs(page, 'e2e_access_ctrl', 'e2e_access_pass')

		await page.goto(`${BASE_URL}/classroom/${courseSlug}/ac-ch1/`, {
			waitUntil: 'domcontentloaded',
		})
		await page.waitForLoadState('networkidle')

		// 應該看到過期相關的存取拒絕頁
		const alertVisible = await page.locator(SELECTORS.accessDenied.alertError).isVisible().catch(() => false)
		expect(alertVisible).toBe(true)

		await ctx.close()

		// 清除
		await api.removeCourseAccess(subscriberId, courseId)
	})
})
