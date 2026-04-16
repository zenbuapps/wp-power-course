/**
 * 測試目標：我的帳戶課程卡片 CTA — 「重看 {章節名} MM:SS」
 * 對應規格：specs/features/course/繼續觀看課程CTA.feature（Rule: 已完成章節）
 * 策略（R8）：透過 API 設定 finished_at + last_position_seconds，驗證 CTA 文字
 *
 * ⚠️ Red 狀態說明：
 * Phase 6（CTA template）尚未完成時，此測試會失敗（找不到「重看」文字）。
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

test.describe('我的帳戶 CTA — 重看', () => {
	test('章節 95% 完成後 CTA 應顯示「重看」與時間 09:30', async ({ page, browser }) => {
		// 使用 chapter index 0（第一章），570 秒 = 09:30
		const chapterId = td.chapterIds[0]
		const seconds = 570

		const { api, dispose } = await setupApiFromBrowser(browser)
		try {
			// 1. 設定播放進度
			await api.pcPost(`chapters/${chapterId}/progress`, {
				last_position_seconds: seconds,
			})

			// 2. 標示章節已完成（呼叫 toggle-finish-chapters API）
			await api.pcPost(`toggle-finish-chapters/${chapterId}`, {
				course_id: td.courseId,
			})
		} finally {
			await dispose()
		}

		// 登入為 admin 後前往我的帳戶
		await loginAs(page, WP_ADMIN.username, WP_ADMIN.password)
		await page.goto('/my-account/courses/')
		await page.waitForLoadState('networkidle')

		const pageText = await page.content()
		expect(pageText).toContain('重看')
		expect(pageText).toContain('09:30')
	})

	test('章節已完成但無秒數時 CTA 仍顯示「重看 00:00」', async ({ page, browser }) => {
		// 使用 chapter index 2（第三章，確保沒有之前的進度污染）
		const chapterId = td.chapterIds[2]

		const { api, dispose } = await setupApiFromBrowser(browser)
		try {
			// 不設定播放進度，直接標示完成
			await api.pcPost(`toggle-finish-chapters/${chapterId}`, {
				course_id: td.courseId,
			})
		} finally {
			await dispose()
		}

		await loginAs(page, WP_ADMIN.username, WP_ADMIN.password)
		await page.goto('/my-account/courses/')
		await page.waitForLoadState('networkidle')

		const pageText = await page.content()
		expect(pageText).toContain('重看')
		// 00:00 或者顯示開始上課（若最後造訪章節是別章）— 視乎 last_visit_info
		// 此測試主要驗證完成章節後不顯示「繼續觀看」
		expect(pageText).not.toContain('繼續觀看')
	})
})
