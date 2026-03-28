/**
 * 學員生命週期 整合測試
 *
 * 測試從新增學員→查詢→更新到期日→移除的完整流程，
 * 以及前端教室存取權限隨生命週期變化的行為。
 */
import { test, expect } from '@playwright/test'
import { ApiClient, setupApiFromBrowser } from '../helpers/api-client'
import { loginAs } from '../helpers/frontend-setup'
import { SELECTORS } from '../fixtures/test-data'

const BASE_URL = process.env.TEST_SITE_URL || 'http://localhost:8889'

// ── 共用變數 ────────────────────────────────
let api: ApiClient
let dispose: () => Promise<void>
let courseId: number
let chapterIds: number[]
let courseSlug: string
let testUserId: number

const TEST_USER = {
	username: 'e2e_lifecycle',
	email: 'e2e_lifecycle@test.local',
	password: 'e2e_lifecycle_pass',
}

test.setTimeout(120_000)

test.describe('學員生命週期', () => {
	test.beforeAll(async ({ browser }) => {
		;({ api, dispose } = await setupApiFromBrowser(browser))

		const result = await api.createCourseWithChapters(
			'E2E Student Lifecycle',
			'800',
			[
				{ name: 'Lifecycle Ch1', slug: 'lc-ch1' },
				{ name: 'Lifecycle Ch2', slug: 'lc-ch2' },
				{ name: 'Lifecycle Ch3', slug: 'lc-ch3' },
			],
			'e2e-student-lifecycle',
		)
		courseId = result.courseId
		chapterIds = result.chapterIds
		courseSlug = result.courseSlug

		testUserId = await api.ensureUser(
			TEST_USER.username,
			TEST_USER.email,
			TEST_USER.password,
		)
	})

	test.afterAll(async () => {
		try {
			await api.removeCourseAccess(testUserId, courseId)
		} catch { /* ignore */ }
		try {
			await api.deleteCourses([courseId])
		} catch { /* ignore */ }
		await dispose()
	})

	// ── Test 1: 完整 CRUD 生命週期 ──────────
	test('完整生命週期：新增→查詢→更新→移除', async () => {
		// 1. 新增學員
		const addResp = await api.pcPostForm('courses/add-students', {
			user_ids: [testUserId],
			course_ids: [courseId],
			expire_date: 0, // 無限期
		})
		expect(addResp.status).toBeLessThan(400)

		// 2. 查詢學員列表 — 確認學員存在
		const listResp = await api.pcGet('students', {
			meta_value: String(courseId),
			posts_per_page: '100',
			paged: '1',
		})
		expect(listResp.status).toBeLessThan(400)
		// 確認學員出現在列表中
		const studentIds = (listResp.data as Array<{ id: string }>).map((u) => u.id)
		expect(studentIds).toContain(String(testUserId))

		// 3. 更新到期日（30 天後）
		const futureTimestamp = Math.floor(Date.now() / 1000) + 30 * 86400
		const updateResp = await api.pcPostForm('courses/update-students', {
			user_ids: [testUserId],
			course_ids: [courseId],
			timestamp: futureTimestamp,
		})
		expect(updateResp.status).toBeLessThan(400)

		// 4. 移除學員
		const removeResp = await api.pcPostForm('courses/remove-students', {
			user_ids: [testUserId],
			course_ids: [courseId],
		})
		expect(removeResp.status).toBeLessThan(400)
	})

	// ── Test 2: 授權→進教室→完成章節→移除→無法進入 ─
	test('授權→進教室→完成章節→移除→無法進入', async ({ browser }) => {
		test.slow()

		// 1. 授權存取
		await api.grantCourseAccess(testUserId, courseId)

		// 2. 以學員身分登入並進入教室
		const ctx = await browser.newContext()
		const page = await ctx.newPage()
		await loginAs(page, TEST_USER.username, TEST_USER.password)

		await page.goto(
			`${BASE_URL}/classroom/${courseSlug}/lc-ch1/`,
			{ waitUntil: 'domcontentloaded' },
		)
		await page.waitForLoadState('networkidle')

		// 驗證：應可進入教室（無存取拒絕訊息）
		const hasAlert = await page
			.locator(SELECTORS.accessDenied.alertError)
			.isVisible()
			.catch(() => false)
		expect(hasAlert, '學員應可進入教室').toBe(false)

		// 驗證：教室元素存在
		const hasHeader = await page
			.locator(SELECTORS.classroom.header)
			.isVisible()
			.catch(() => false)
		const hasBody = await page
			.locator(SELECTORS.classroom.body)
			.isVisible()
			.catch(() => false)
		expect(hasHeader || hasBody, '教室頁面應有 header 或 body').toBe(true)

		// 3. 嘗試完成章節（點擊完成按鈕）
		const finishBtn = page.locator(
			`${SELECTORS.classroom.finishButton}, button:has-text("標示為已完成"), button:has-text("標示為未完成")`,
		)
		const btnVisible = await finishBtn.first().isVisible({ timeout: 10_000 }).catch(() => false)
		if (btnVisible) {
			await finishBtn.first().click()
			// 關閉可能出現的對話框
			const dialog = page.locator(SELECTORS.classroom.finishDialog)
			if (await dialog.isVisible({ timeout: 3_000 }).catch(() => false)) {
				await page.keyboard.press('Escape')
			}
			await page.waitForTimeout(2_000)
		}

		await ctx.close()

		// 4. 移除存取權限
		await api.removeCourseAccess(testUserId, courseId)

		// 5. 重新登入確認無法進入教室
		const ctx2 = await browser.newContext()
		const page2 = await ctx2.newPage()
		await loginAs(page2, TEST_USER.username, TEST_USER.password)

		await page2.goto(
			`${BASE_URL}/classroom/${courseSlug}/lc-ch1/`,
			{ waitUntil: 'domcontentloaded' },
		)
		await page2.waitForLoadState('networkidle')

		// 驗證：應看到存取拒絕
		const alertVisible = await page2
			.locator(SELECTORS.accessDenied.alertError)
			.isVisible()
			.catch(() => false)
		expect(alertVisible, '移除權限後應顯示存取拒絕').toBe(true)

		await ctx2.close()
	})

	// ── Test 3: 過期後重新授權 ──────────────
	test('過期後重新授權', async ({ browser }) => {
		test.slow()

		// 1. 授權存取（無限期）
		await api.grantCourseAccess(testUserId, courseId)

		// 2. 設定為已過期
		await api.setCourseExpired(testUserId, courseId)

		// 3. 確認已過期 → 無法進入教室
		const ctx1 = await browser.newContext()
		const page1 = await ctx1.newPage()
		await loginAs(page1, TEST_USER.username, TEST_USER.password)

		await page1.goto(
			`${BASE_URL}/classroom/${courseSlug}/lc-ch1/`,
			{ waitUntil: 'domcontentloaded' },
		)
		await page1.waitForLoadState('networkidle')

		const expiredAlert = await page1
			.locator(SELECTORS.accessDenied.alertError)
			.isVisible()
			.catch(() => false)
		expect(expiredAlert, '過期學員應看到存取拒絕').toBe(true)

		await ctx1.close()

		// 4. 重新授權（新到期日 = 90 天後）
		const newExpire = Math.floor(Date.now() / 1000) + 90 * 86400
		await api.grantCourseAccess(testUserId, courseId, newExpire)

		// 5. 確認重新授權後可進入教室
		const ctx2 = await browser.newContext()
		const page2 = await ctx2.newPage()
		await loginAs(page2, TEST_USER.username, TEST_USER.password)

		await page2.goto(
			`${BASE_URL}/classroom/${courseSlug}/lc-ch1/`,
			{ waitUntil: 'domcontentloaded' },
		)
		await page2.waitForLoadState('networkidle')

		// 驗證：不應有存取拒絕
		const hasAlert = await page2
			.locator(SELECTORS.accessDenied.alertError)
			.isVisible()
			.catch(() => false)
		expect(hasAlert, '重新授權後不應顯示存取拒絕').toBe(false)

		// 驗證：教室元素存在
		const hasHeader = await page2
			.locator(SELECTORS.classroom.header)
			.isVisible()
			.catch(() => false)
		const hasBody = await page2
			.locator(SELECTORS.classroom.body)
			.isVisible()
			.catch(() => false)
		expect(hasHeader || hasBody, '重新授權後教室應正常顯示').toBe(true)

		await ctx2.close()

		// 清理
		await api.removeCourseAccess(testUserId, courseId)
	})
})
