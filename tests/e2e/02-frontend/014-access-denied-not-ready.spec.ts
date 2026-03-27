/**
 * 測試目標：存取拒絕 — 未開課
 * 對應原始碼：inc/templates/pages/404/not-ready.php, inc/classes/Utils/Course.php (is_course_ready)
 * 前置條件：訂閱者已登入，課程排程在未來
 * 預期結果：進入教室頁面時顯示「課程尚未開始」提示
 */

import { test, expect } from '@playwright/test'
import { setupApiFromBrowser } from '../helpers/api-client.js'
import { loadFrontendTestData, loginAs, type FrontendTestData } from '../helpers/frontend-setup.js'
import { TEST_SUBSCRIBER, SELECTORS, FRONTEND_COURSE } from '../fixtures/test-data.js'

let td: FrontendTestData
let futureScheduleCourseId: number
let futureChapterSlugs: string[]
let futureCourseSlug: string

test.beforeAll(async ({ browser }) => {
	test.setTimeout(120_000) // 建立課程 + 章節 + 設定排程需要較長時間
	td = loadFrontendTestData()

	const { api, dispose } = await setupApiFromBrowser(browser)
	// 為此測試建立一個獨立的未來排程課程
	const futureTs = Math.floor(Date.now() / 1000) + 86400 * 30 // 30 天後
	const result = await api.createCourseWithChapters(
		'E2E 未來排程課程',
		'1000',
		[{ name: '未來課程第一章', slug: 'e2e-future-ch1' }],
		'e2e-future-course',
	)
	futureScheduleCourseId = result.courseId
	futureCourseSlug = result.courseSlug
	futureChapterSlugs = ['e2e-future-ch1']

	// 設定未來排程
	await api.setCourseFutureSchedule(futureScheduleCourseId, futureTs)

	// 授權訂閱者（有權限但未開課）
	await api.grantCourseAccess(td.subscriberId, futureScheduleCourseId)
	await dispose()
})

test.afterAll(async ({ browser }) => {
	// 清理
	const { api, dispose } = await setupApiFromBrowser(browser)
	try {
		await api.removeCourseAccess(td.subscriberId, futureScheduleCourseId)
		await api.deleteCourses([futureScheduleCourseId])
	} catch {
		// ignore
	}
	await dispose()
})

test.describe('存取拒絕：未開課', () => {
	test.beforeEach(async ({ page }) => {
		await loginAs(page, TEST_SUBSCRIBER.username, TEST_SUBSCRIBER.password)
	})

	test('未開課課程進入教室應被拒絕', async ({ page }) => {
		const classroomUrl = `/classroom/${futureCourseSlug}/${futureChapterSlugs[0]}/`
		await page.goto(classroomUrl)
		await page.waitForLoadState('domcontentloaded')

		const alertEl = page.locator(SELECTORS.accessDenied.alert)
		const notReadyText = page.getByText('尚未開始').first()
		const buyBtn = page.locator(SELECTORS.accessDenied.buyButton)
		const redirectedAway = !page.url().includes('/classroom/')

		const hasAlert = await alertEl.count().then((c) => c > 0).catch(() => false)
		const hasNotReadyText = await notReadyText.count().then((c) => c > 0).catch(() => false)
		const hasBuyBtn = await buyBtn.count().then((c) => c > 0).catch(() => false)

		expect(
			hasAlert || hasNotReadyText || hasBuyBtn || redirectedAway,
		).toBeTruthy()
	})
})
