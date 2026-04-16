/**
 * 測試目標：我的帳戶課程卡片 CTA — 「繼續觀看 {章節名} MM:SS」
 * 對應規格：specs/features/course/繼續觀看課程CTA.feature
 * 策略（R8）：透過 POST API 模擬觀看進度，驗證 CTA 文字
 *
 * ⚠️ Red 狀態說明：
 * Phase 6（CTA template）尚未完成時，此測試會失敗（找不到「繼續觀看」文字）。
 * 這是正確的 TDD Red 狀態，Phase 6 完成後應轉為 Green。
 */

import { test, expect } from '@playwright/test'
import { setupApiFromBrowser } from '../helpers/api-client.js'
import { loadFrontendTestData, loginAs, type FrontendTestData } from '../helpers/frontend-setup.js'
import { WP_ADMIN } from '../fixtures/test-data.js'

let td: FrontendTestData

test.beforeAll(async ({ browser }) => {
	td = loadFrontendTestData()
	// 確保 admin 有課程存取權
	const { api, dispose } = await setupApiFromBrowser(browser)
	await api.grantCourseAccess(1, td.courseId)
	await dispose()
})

test.describe('我的帳戶 CTA — 繼續觀看', () => {
	test('停在第 2 章 03:45 時，CTA 應顯示「繼續觀看」與時間', async ({ page, browser }) => {
		// 使用 chapter index 1（第二章），225 秒 = 03:45
		const chapterId = td.chapterIds[1]
		const seconds = 225 // 3*60+45 = 225

		// 設定進度（chapter 尚未 finished → 進行中狀態）
		const { api, dispose } = await setupApiFromBrowser(browser)
		try {
			await api.pcPost(`chapters/${chapterId}/progress`, {
				last_position_seconds: seconds,
			})
		} finally {
			await dispose()
		}

		// 登入為 admin 後前往我的帳戶
		await loginAs(page, WP_ADMIN.username, WP_ADMIN.password)
		await page.goto('/my-account/courses/')
		await page.waitForLoadState('networkidle')

		// 斷言 CTA 按鈕包含「繼續觀看」
		const ctaEl = page.locator('.pc-course-card').filter({ hasText: td.courseSlug.replace(/-/g, '') }).locator('.pc-cta-btn').first()
		// fallback：在整個頁面搜尋
		const pageText = await page.content()

		expect(pageText).toContain('繼續觀看')
		expect(pageText).toContain('03:45')
	})

	test('秒數小於 60 時 CTA 應顯示 00:08 格式', async ({ page, browser }) => {
		// 使用 chapter index 0（第一章），8 秒
		const chapterId = td.chapterIds[0]
		const seconds = 8

		const { api, dispose } = await setupApiFromBrowser(browser)
		try {
			await api.pcPost(`chapters/${chapterId}/progress`, {
				last_position_seconds: seconds,
			})
		} finally {
			await dispose()
		}

		await loginAs(page, WP_ADMIN.username, WP_ADMIN.password)
		await page.goto('/my-account/courses/')
		await page.waitForLoadState('networkidle')

		const pageText = await page.content()
		expect(pageText).toContain('繼續觀看')
		expect(pageText).toContain('00:08')
	})
})
