/**
 * 測試目標：存取拒絕 — 已過期
 * 對應原始碼：inc/templates/pages/404/expired.php, inc/classes/Utils/Course.php (is_expired)
 * 前置條件：訂閱者已登入，課程存取已過期
 * 預期結果：進入教室頁面時顯示過期提示
 */

import { test, expect } from '@playwright/test'
import { setupApiFromBrowser } from '../helpers/api-client.js'
import { loadFrontendTestData, loginAs, type FrontendTestData } from '../helpers/frontend-setup.js'
import { TEST_SUBSCRIBER, SELECTORS } from '../fixtures/test-data.js'

let td: FrontendTestData

test.beforeAll(async ({ browser }) => {
	td = loadFrontendTestData()
	// 先授權再設為過期
	const { api, dispose } = await setupApiFromBrowser(browser)
	await api.grantCourseAccess(td.subscriberId, td.courseId)
	await api.setCourseExpired(td.subscriberId, td.courseId)
	await dispose()
})

test.afterAll(async ({ browser }) => {
	// 清理：移除存取（避免影響其他測試）
	const { api, dispose } = await setupApiFromBrowser(browser)
	try {
		await api.removeCourseAccess(td.subscriberId, td.courseId)
	} catch {
		// ignore
	}
	await dispose()
})

test.describe('存取拒絕：已過期', () => {
	test.beforeEach(async ({ page }) => {
		await loginAs(page, TEST_SUBSCRIBER.username, TEST_SUBSCRIBER.password)
	})

	test('過期者進入教室應被拒絕', async ({ page }) => {
		const classroomUrl = `/classroom/${td.courseSlug}/${td.chapterSlugs[0]}/`
		await page.goto(classroomUrl)
		await page.waitForLoadState('domcontentloaded')

		const alertEl = page.locator(SELECTORS.accessDenied.alert)
		const expiredText = page.getByText('已過期').first()
		const buyBtn = page.locator(SELECTORS.accessDenied.buyButton)
		const redirectedAway = !page.url().includes('/classroom/')

		const hasAlert = await alertEl.count().then((c) => c > 0).catch(() => false)
		const hasExpiredText = await expiredText.count().then((c) => c > 0).catch(() => false)
		const hasBuyBtn = await buyBtn.count().then((c) => c > 0).catch(() => false)

		expect(
			hasAlert || hasExpiredText || hasBuyBtn || redirectedAway,
		).toBeTruthy()
	})
})
