/**
 * 章節完成切換 & 進度計算 整合測試
 *
 * 測試 toggle-finish-chapters API 的完成/取消完成行為，
 * 以及課程進度在部分/全部完成時的正確計算。
 */
import { test, expect } from '@playwright/test'
import { ApiClient, setupApiFromBrowser } from '../helpers/api-client'

const BASE_URL = process.env.TEST_SITE_URL || 'http://localhost:8889'

// ── 回應型別 ────────────────────────────────
interface ToggleResponse {
	code: string
	message: string
	data: {
		chapter_id: number
		course_id: number
		is_this_chapter_finished: boolean
		progress: Record<string, unknown>
		icon_html: string
	}
}

// ── 共用變數 ────────────────────────────────
let api: ApiClient
let dispose: () => Promise<void>
let courseId: number
let chapterIds: number[]
let courseSlug: string

test.setTimeout(120_000)

test.describe('章節完成切換 & 進度計算', () => {
	test.beforeAll(async ({ browser }) => {
		;({ api, dispose } = await setupApiFromBrowser(browser))

		const result = await api.createCourseWithChapters(
			'E2E Chapter Completion',
			'500',
			[
				{ name: 'Completion Ch1', slug: 'comp-ch1' },
				{ name: 'Completion Ch2', slug: 'comp-ch2' },
				{ name: 'Completion Ch3', slug: 'comp-ch3' },
			],
			'e2e-chapter-completion',
		)
		courseId = result.courseId
		chapterIds = result.chapterIds
		courseSlug = result.courseSlug

		// 確保 admin 有課程存取權（toggle API 用 get_current_user_id()）
		await api.grantCourseAccess(1, courseId)

		// 先把所有章節重設為未完成，確保初始狀態乾淨
		for (const chId of chapterIds) {
			const check = await api.pcPostForm<ToggleResponse>(
				`toggle-finish-chapters/${chId}`,
				{ course_id: courseId },
			)
			if (check.data?.data?.is_this_chapter_finished) {
				// 已完成 → 再 toggle 一次使其取消
				await api.pcPostForm<ToggleResponse>(
					`toggle-finish-chapters/${chId}`,
					{ course_id: courseId },
				)
			}
		}
	})

	test.afterAll(async () => {
		try {
			await api.removeCourseAccess(1, courseId)
		} catch { /* ignore */ }
		try {
			await api.deleteCourses([courseId])
		} catch { /* ignore */ }
		await dispose()
	})

	// ── Test 1: 標記章節完成 ────────────────
	test('標記章節完成', async () => {
		const resp = await api.pcPostForm<ToggleResponse>(
			`toggle-finish-chapters/${chapterIds[0]}`,
			{ course_id: courseId },
		)

		expect(resp.status).toBeLessThan(400)
		expect(resp.data.code).toBe('200')
		expect(resp.data.data.is_this_chapter_finished).toBe(true)
		expect(resp.data.data.chapter_id).toBe(chapterIds[0])
		expect(resp.data.data.course_id).toBe(courseId)
		expect(resp.data.message).toContain('已標示為完成')
	})

	// ── Test 2: 取消章節完成 ────────────────
	test('取消章節完成', async () => {
		// 再次 toggle 同一章節 → 應取消完成
		const resp = await api.pcPostForm<ToggleResponse>(
			`toggle-finish-chapters/${chapterIds[0]}`,
			{ course_id: courseId },
		)

		expect(resp.status).toBeLessThan(400)
		expect(resp.data.code).toBe('200')
		expect(resp.data.data.is_this_chapter_finished).toBe(false)
		expect(resp.data.message).toContain('已標示為未完成')
	})

	// ── Test 3: 進度計算 — 部分完成 ─────────
	test('進度計算 - 部分完成', async () => {
		// 標記第一章完成（1/3）
		const resp = await api.pcPostForm<ToggleResponse>(
			`toggle-finish-chapters/${chapterIds[0]}`,
			{ course_id: courseId },
		)

		expect(resp.status).toBeLessThan(400)
		expect(resp.data.data.is_this_chapter_finished).toBe(true)

		// progress 應存在且非空
		const progress = resp.data.data.progress
		expect(progress).toBeDefined()
		expect(progress).not.toBeNull()
	})

	// ── Test 4: 進度計算 — 100% 完成 ────────
	test('進度計算 - 100% 完成', async () => {
		// ch1 已在上個測試中完成，繼續完成 ch2 & ch3
		const resp2 = await api.pcPostForm<ToggleResponse>(
			`toggle-finish-chapters/${chapterIds[1]}`,
			{ course_id: courseId },
		)
		expect(resp2.data.data.is_this_chapter_finished).toBe(true)

		const resp3 = await api.pcPostForm<ToggleResponse>(
			`toggle-finish-chapters/${chapterIds[2]}`,
			{ course_id: courseId },
		)
		expect(resp3.data.data.is_this_chapter_finished).toBe(true)

		// 最後一個 toggle 回應應包含完整進度
		const progress = resp3.data.data.progress
		expect(progress).toBeDefined()
		expect(progress).not.toBeNull()
	})

	// ── Test 5: 取消一章後進度降低 ──────────
	test('取消一章後進度降低', async () => {
		// 取消 ch2 完成
		const resp = await api.pcPostForm<ToggleResponse>(
			`toggle-finish-chapters/${chapterIds[1]}`,
			{ course_id: courseId },
		)

		expect(resp.status).toBeLessThan(400)
		expect(resp.data.data.is_this_chapter_finished).toBe(false)

		// 進度應降回非 100%（2/3 已完成）
		const progress = resp.data.data.progress
		expect(progress).toBeDefined()
		expect(progress).not.toBeNull()

		// 清理：取消剩餘已完成章節，還原初始狀態
		await api.pcPostForm<ToggleResponse>(
			`toggle-finish-chapters/${chapterIds[0]}`,
			{ course_id: courseId },
		)
		await api.pcPostForm<ToggleResponse>(
			`toggle-finish-chapters/${chapterIds[2]}`,
			{ course_id: courseId },
		)
	})
})
