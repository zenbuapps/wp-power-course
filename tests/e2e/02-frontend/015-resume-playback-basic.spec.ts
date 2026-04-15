/**
 * 測試目標：章節續播 — API 層驗證
 * 對應規格：specs/features/progress/紀錄章節續播秒數.feature
 * 策略（R8）：不真正播放影片，改以直接呼叫 REST API 驗證後端寫入
 * 前置條件：管理員已登入，已有課程存取權
 *
 * 這些測試驗證：
 * 1. POST /chapters/{id}/progress 能正確寫入 last_position_seconds
 * 2. GET /chapters/{id}/progress 能正確讀取
 */

import { test, expect } from '@playwright/test'
import { setupApiFromBrowser } from '../helpers/api-client.js'
import { loadFrontendTestData, type FrontendTestData } from '../helpers/frontend-setup.js'

let td: FrontendTestData

test.beforeAll(async ({ browser }) => {
	td = loadFrontendTestData()
	// 確保 admin 有課程存取權（user ID 1 = admin）
	const { api, dispose } = await setupApiFromBrowser(browser)
	await api.grantCourseAccess(1, td.courseId)
	await dispose()
})

test.describe('章節續播 API — 基本寫入與讀取', () => {
	test('POST /chapters/{id}/progress 應寫入 last_position_seconds', async ({ browser }) => {
		const { api, dispose } = await setupApiFromBrowser(browser)

		try {
			const chapterId = td.chapterIds[0]
			const targetSeconds = 120

			// 呼叫 POST API 寫入進度
			const resp = await api.pcPost<{
				code: string
				data: {
					chapter_id: number
					course_id: number
					last_position_seconds: number
					updated_at: string
					written: boolean
				}
			}>(`chapters/${chapterId}/progress`, {
				last_position_seconds: targetSeconds,
			})

			// 驗證回應
			expect(resp.status).toBe(200)
			expect(resp.data.data.written).toBe(true)
			expect(resp.data.data.last_position_seconds).toBe(targetSeconds)
			expect(resp.data.data.chapter_id).toBe(chapterId)
		} finally {
			await dispose()
		}
	})

	test('GET /chapters/{id}/progress 應回傳已儲存的 last_position_seconds', async ({ browser }) => {
		const { api, dispose } = await setupApiFromBrowser(browser)

		try {
			const chapterId = td.chapterIds[0]
			const targetSeconds = 120

			// 先寫入
			await api.pcPost(`chapters/${chapterId}/progress`, {
				last_position_seconds: targetSeconds,
			})

			// 再讀取
			const getResp = await api.pcGet<{
				code: string
				data: {
					chapter_id: number
					course_id: number
					last_position_seconds: number
					updated_at: string | null
				}
			}>(`chapters/${chapterId}/progress`)

			expect(getResp.status).toBe(200)
			expect(getResp.data.data.last_position_seconds).toBe(targetSeconds)
		} finally {
			await dispose()
		}
	})

	test('< 5 秒不應寫入（written === false）', async ({ browser }) => {
		const { api, dispose } = await setupApiFromBrowser(browser)

		try {
			const chapterId = td.chapterIds[0]

			const resp = await api.pcPost<{
				code: string
				data: { written: boolean; last_position_seconds: number }
			}>(`chapters/${chapterId}/progress`, {
				last_position_seconds: 3,
			})

			expect(resp.status).toBe(200)
			expect(resp.data.data.written).toBe(false)
		} finally {
			await dispose()
		}
	})

	test('upsert — 重複寫入應更新秒數（不重複新增列）', async ({ browser }) => {
		const { api, dispose } = await setupApiFromBrowser(browser)

		try {
			const chapterId = td.chapterIds[0]

			// 第一次寫入 60 秒
			await api.pcPost(`chapters/${chapterId}/progress`, {
				last_position_seconds: 60,
			})

			// 第二次寫入 180 秒
			const resp = await api.pcPost<{
				code: string
				data: { last_position_seconds: number; written: boolean }
			}>(`chapters/${chapterId}/progress`, {
				last_position_seconds: 180,
			})

			expect(resp.status).toBe(200)
			expect(resp.data.data.last_position_seconds).toBe(180)
			expect(resp.data.data.written).toBe(true)

			// GET 確認最終值為 180
			const getResp = await api.pcGet<{
				data: { last_position_seconds: number }
			}>(`chapters/${chapterId}/progress`)
			expect(getResp.data.data.last_position_seconds).toBe(180)
		} finally {
			await dispose()
		}
	})
})
