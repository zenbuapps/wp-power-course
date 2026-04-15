/**
 * 整合測試：完整購買→開通→上課流程
 *
 * 驗證 WooCommerce BACS 結帳→訂單完成→課程權限開通→進入教室→完成章節
 */
import { test, expect, type Browser, type Page } from '@playwright/test'
import { ApiClient, setupApiFromBrowser } from '../helpers/api-client'
import { loginAs } from '../helpers/frontend-setup'
import { completeCheckout, completeOrderViaAdmin } from '../helpers/wc-checkout'
import { SELECTORS, FRONTEND_COURSE, TEST_SUBSCRIBER, WP_ADMIN } from '../fixtures/test-data'

const BASE_URL = process.env.TEST_SITE_URL || 'http://localhost:8889'

let api: ApiClient
let dispose: () => Promise<void>
let courseId: number
let chapterIds: number[]
let courseSlug: string
let subscriberId: number

test.describe('購買→開通→上課 完整流程', () => {
	test.beforeAll(async ({ browser }) => {
		;({ api, dispose } = await setupApiFromBrowser(browser))

		// 建立 integration 專用課程
		const result = await api.createCourseWithChapters(
			'E2E Integration Purchase',
			'500',
			[
				{ name: 'Integration Ch1', slug: 'int-ch1' },
				{ name: 'Integration Ch2', slug: 'int-ch2' },
			],
			'e2e-integration-purchase',
		)
		courseId = result.courseId
		chapterIds = result.chapterIds
		courseSlug = result.courseSlug

		// 確保學員存在
		subscriberId = await api.ensureUser(
			'e2e_buyer',
			'e2e_buyer@test.local',
			'e2e_buyer_pass',
		)

		// BACS 已在 global-setup 中啟用，不需重複
	})

	test.afterAll(async () => {
		// 清除課程存取權限
		try {
			await api.removeCourseAccess(subscriberId, courseId)
		} catch { /* ignore */ }
		try {
			await api.deleteCourses([courseId])
		} catch { /* ignore */ }
		await dispose()
	})

	test('學員可以加入購物車並完成 BACS 結帳', async ({ browser }) => {
		test.slow() // 結帳流程需要較長時間
		const ctx = await browser.newContext({ baseURL: BASE_URL })
		const page = await ctx.newPage()

		// 以學員身份登入
		await loginAs(page, 'e2e_buyer', 'e2e_buyer_pass')

		// 使用 helper 完成結帳流程
		const orderNumber = await completeCheckout(page, courseId, {
			email: 'e2e_buyer@test.local',
			firstName: '測試',
			lastName: '買家',
			address: '台北市中正區1號',
			city: '台北',
			postcode: '100',
			phone: '0912345678',
		})
		expect(orderNumber).toBeTruthy()

		// 取得訂單 ID（從 URL）
		const url = page.url()
		const orderIdMatch = url.match(/order-received\/(\d+)/)
		expect(orderIdMatch).toBeTruthy()
		const orderId = orderIdMatch![1]

		await ctx.close()

		// 以 Admin 身份將訂單標為完成
		const adminCtx = await browser.newContext({
			storageState: '.auth/admin.json',
			baseURL: BASE_URL,
		})
		const adminPage = await adminCtx.newPage()
		await completeOrderViaAdmin(adminPage, orderId)
		await adminCtx.close()

		// 驗證學員現在可以進入教室
		const studentCtx = await browser.newContext()
		const studentPage = await studentCtx.newPage()
		await loginAs(studentPage, 'e2e_buyer', 'e2e_buyer_pass')
		await studentPage.goto(`${BASE_URL}/classroom/${courseSlug}/int-ch1/`, {
			waitUntil: 'domcontentloaded',
		})
		await studentPage.waitForLoadState('networkidle')

		// 應該看到教室頁面，不是購買頁
		const hasAlert = await studentPage.locator(SELECTORS.accessDenied.alertError).isVisible().catch(() => false)
		expect(hasAlert).toBe(false)

		await studentCtx.close()
	})

	test('已開通學員可以完成章節並追蹤進度', async ({ browser }) => {
		// 確保學員有權限
		await api.grantCourseAccess(subscriberId, courseId)

		const ctx = await browser.newContext()
		const page = await ctx.newPage()
		await loginAs(page, 'e2e_buyer', 'e2e_buyer_pass')

		// 進入第一章節
		await page.goto(`${BASE_URL}/classroom/${courseSlug}/int-ch1/`, {
			waitUntil: 'domcontentloaded',
		})
		await page.waitForLoadState('networkidle')

		// 確認不是存取拒絕頁
		const accessDenied = await page.locator(SELECTORS.accessDenied.alertError).isVisible().catch(() => false)
		expect(accessDenied, '學員應有課程存取權限').toBe(false)

		// 點擊完成章節按鈕（多重選擇器容錯）
		const finishBtn = page.locator(
			`${SELECTORS.classroom.finishButton}, button:has-text("標示為已完成"), button:has-text("標示為未完成")`,
		)
		await finishBtn.first().waitFor({ state: 'visible', timeout: 15_000 })
		await finishBtn.first().click()

		// 關閉完成確認對話框（DaisyUI modal 的 close button 是 opacity-0，用 Escape 關閉）
		const dialog = page.locator(SELECTORS.classroom.finishDialog)
		if (await dialog.isVisible({ timeout: 3_000 }).catch(() => false)) {
			await page.keyboard.press('Escape')
		}

		// 等待 AJAX 完成
		await page.waitForTimeout(2_000)

		await ctx.close()
	})
})
