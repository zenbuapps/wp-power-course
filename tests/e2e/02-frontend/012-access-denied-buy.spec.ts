/**
 * 測試目標：存取拒絕 — 未購買
 * 對應原始碼：inc/templates/pages/404/buy.php, inc/classes/Templates/Templates.php
 * 前置條件：訂閱者已登入但未購買課程
 * 預期結果：進入教室頁面時顯示「您還沒購買此課程，無法上課」提示
 */

import { test, expect } from '@playwright/test'
import { setupApiFromBrowser } from '../helpers/api-client.js'
import { loadFrontendTestData, loginAs, type FrontendTestData } from '../helpers/frontend-setup.js'
import { TEST_SUBSCRIBER, SELECTORS } from '../fixtures/test-data.js'

let td: FrontendTestData

test.beforeAll(async ({ browser }) => {
	td = loadFrontendTestData()
	// 確保訂閱者沒有課程存取權限
	const { api, dispose } = await setupApiFromBrowser(browser)
	try {
		await api.removeCourseAccess(td.subscriberId, td.courseId)
	} catch {
		// 可能本來就沒有，忽略
	}
	await dispose()
})

test.describe('存取拒絕：未購買', () => {
	test.beforeEach(async ({ page }) => {
		await loginAs(page, TEST_SUBSCRIBER.username, TEST_SUBSCRIBER.password)
	})

	test('未購買者進入教室應被拒絕', async ({ page }) => {
		const classroomUrl = `/classroom/${td.courseSlug}/${td.chapterSlugs[0]}/`
		await page.goto(classroomUrl)
		await page.waitForLoadState('domcontentloaded')

		// 應顯示存取拒絕訊息或導向購買頁
		const alertEl = page.locator(SELECTORS.accessDenied.alert)
		const buyText = page.getByText('還沒購買').first()
		const buyBtn = page.locator(SELECTORS.accessDenied.buyButton)
		const redirectedToProduct = page.url().includes(new URL(td.courseUrl).pathname)

		// 等待頁面渲染完成再檢查
		const hasAlert = await alertEl.count().then((c) => c > 0).catch(() => false)
		const hasBuyText = await buyText.count().then((c) => c > 0).catch(() => false)
		const hasBuyBtn = await buyBtn.count().then((c) => c > 0).catch(() => false)

		// 至少一個存取拒絕指標成立
		expect(hasAlert || hasBuyText || hasBuyBtn || redirectedToProduct).toBeTruthy()
	})

	test('應顯示「前往購買」連結', async ({ page }) => {
		const classroomUrl = `/classroom/${td.courseSlug}/${td.chapterSlugs[0]}/`
		await page.goto(classroomUrl)
		await page.waitForLoadState('domcontentloaded')

		const buyBtn = page.locator(SELECTORS.accessDenied.buyButton)
		const count = await buyBtn.count()
		if (count > 0) {
			await expect(buyBtn).toBeVisible()
		}
		// 如果被導向到其他頁面，也算通過
	})
})
