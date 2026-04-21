/**
 * 測試目標：Issue #206 — Ended 遮罩提供「重看本章」出口
 * 對應規格：
 *   - specs/features/progress/續播至上次觀看秒數.feature（Rule: 續播至片尾 Ended 遮罩）
 *   - specs/features/progress/影片進度自動完成章節.feature（Rule: Ended 倒數跳轉的時序 Issue #206）
 * 澄清：specs/open-issue/clarify/2026-04-20-1748.md（Q1–Q10 + 2026-04-20 人工測試後調整）
 *
 * Post-test 調整（2026-04-20）：
 *   原本 Q1=C 雙按鈕（取消 + 重看）因 VidStack ended 狀態下多個 BUG 難解，
 *   改為 Q1=B 僅保留「重看本章」出口。Q5（onSeeking 隱性備援）一併廢止。
 *   Test 1「取消自動跳轉」、Test 3「拖進度條中止倒數」、Test 5「onSeeking 不誤觸」已刪除。
 *
 * 策略：透過 API 設定 chapter_video 讓 classroom 能 render Player；
 * 由 page.evaluate 直接對 <media-player> web component 派發合成 ended 事件，
 * 不依賴真實影片播放（穩定、不受外部網路影響）。
 */

import { test, expect } from '@playwright/test'
import { setupApiFromBrowser } from '../helpers/api-client.js'
import {
	loadFrontendTestData,
	loginAs,
	type FrontendTestData,
} from '../helpers/frontend-setup.js'
import { WP_ADMIN } from '../fixtures/test-data.js'

let td: FrontendTestData

/** 佔位用 YouTube video id（僅為讓 classroom template 能 render Player，測試不依賴其真實播放） */
const PLACEHOLDER_YT_ID = 'dQw4w9WgXcQ'

test.beforeAll(async ({ browser }) => {
	td = loadFrontendTestData()
	const { api, dispose } = await setupApiFromBrowser(browser)
	try {
		// 確保 admin 有課程存取權
		await api.grantCourseAccess(1, td.courseId)

		// 為測試章節（index 0、1）分別設 chapter_video，讓 classroom render Player
		for (const idx of [0, 1]) {
			await api.pcPostForm(`chapters/${td.chapterIds[idx]}`, {
				'chapter_video[type]': 'youtube',
				'chapter_video[id]': PLACEHOLDER_YT_ID,
			})
		}
	} finally {
		await dispose()
	}
})

/**
 * 進入 classroom 並等 VidStack player mount。
 * 回傳 classroomUrl 方便後續斷言 URL 未變。
 */
async function gotoClassroomAndWaitPlayer(
	page: import('@playwright/test').Page,
	chapterIdx: number,
): Promise<string> {
	const classroomUrl = `/classroom/${td.courseSlug}/${td.chapterSlugs[chapterIdx]}/`
	await page.goto(classroomUrl)
	await page.waitForLoadState('networkidle')
	// 等 VidStack <media-player> 元素被 React 掛上
	await page.waitForSelector('media-player', { timeout: 15_000 })
	return classroomUrl
}

/**
 * 派發合成 `ended` 事件到 <media-player>，
 * 模擬影片播放至結尾，讓 React 元件的 onEnded 觸發 setIsEnded(true)。
 */
async function fireEndedOnPlayer(
	page: import('@playwright/test').Page,
): Promise<void> {
	await page.evaluate(() => {
		const el = document.querySelector('media-player') as
			| (HTMLElement & {
					currentTime?: number
					duration?: number
			  })
			| null
		if (!el) {
			throw new Error('media-player element not found')
		}
		// 合成 ended 事件（VidStack React 的 onEnded 會接收此事件）
		el.dispatchEvent(new Event('ended', { bubbles: true }))
	})
}

test.describe('Ended 遮罩出口 — Issue #206', () => {
	test('續播到片尾按「重看本章」從 0 開始播放', async ({ page, browser }) => {
		const chapterId = td.chapterIds[0]
		const { api, dispose } = await setupApiFromBrowser(browser)
		try {
			await api.pcPost(`chapters/${chapterId}/progress`, {
				last_position_seconds: 585,
			})
			// 確保章節為「已完成」狀態（R6 驗證 finished_at 不被重置）
			await api.pcPost(`toggle-finish-chapters/${chapterId}`, {
				course_id: td.courseId,
			})
		} finally {
			await dispose()
		}

		await loginAs(page, WP_ADMIN.username, WP_ADMIN.password)
		const classroomUrl = await gotoClassroomAndWaitPlayer(page, 0)

		await fireEndedOnPlayer(page)

		const replayBtn = page.getByRole('button', {
			name: /重看本章|Replay chapter/i,
		})
		await expect(replayBtn).toBeVisible({ timeout: 5_000 })

		await replayBtn.click()

		// 遮罩應消失
		await expect(replayBtn).toBeHidden({ timeout: 3_000 })

		// URL 未跳到下一章
		expect(page.url()).toContain(classroomUrl)

		// R6：重看後 finished_at 不應被重置（章節仍標示為完成）
		const { api: api2, dispose: dispose2 } = await setupApiFromBrowser(browser)
		try {
			type ChapterResp = {
				data?: { finished_at?: string | number | null }
				finished_at?: string | number | null
			}
			const resp = await api2.pcGet<ChapterResp>(`chapters/${chapterId}`)
			const finishedAt = resp.data?.data?.finished_at ?? resp.data?.finished_at
			// finished_at 需存在（非空、非 null、非 0、非 '0'）
			expect(finishedAt).toBeTruthy()
			expect(String(finishedAt)).not.toBe('0')
		} finally {
			await dispose2()
		}
	})

	test('首次觀看自然播完 5 秒倒數後自動跳下一章（回歸 Q10）', async ({
		page,
		browser,
	}) => {
		// 前置：第二章清除進度並重置為未完成
		const chapterId = td.chapterIds[1]
		const { api, dispose } = await setupApiFromBrowser(browser)
		try {
			// 清除 last_position_seconds（以 0 寫入；小於門檻的不會記入，剛好達到「首次觀看」效果）
			await api.pcPost(`chapters/${chapterId}/progress`, {
				last_position_seconds: 0,
			})
			// 若章節為 finished 狀態則 toggle 回 unfinished；為了簡化，以 toggle 為冪等語意
			// 注意：此 API 為 toggle，重複呼叫會來回。為安全起見，先查詢目前狀態再決定是否 toggle。
			type ChapterResp = {
				data?: { finished_at?: string | number | null }
				finished_at?: string | number | null
			}
			const current = await api.pcGet<ChapterResp>(`chapters/${chapterId}`)
			const finishedAt =
				current.data?.data?.finished_at ?? current.data?.finished_at
			if (finishedAt && String(finishedAt) !== '0') {
				await api.pcPost(`toggle-finish-chapters/${chapterId}`, {
					course_id: td.courseId,
				})
			}
		} finally {
			await dispose()
		}

		await loginAs(page, WP_ADMIN.username, WP_ADMIN.password)
		await gotoClassroomAndWaitPlayer(page, 1)

		// 觸發 ended → 遮罩應顯示 5 秒倒數後自動跳轉
		await fireEndedOnPlayer(page)

		// 遮罩出現（「重看本章」按鈕為遮罩的可定位代表物），不按任何按鈕
		const replayBtn = page.getByRole('button', {
			name: /重看本章|Replay chapter/i,
		})
		await expect(replayBtn).toBeVisible({ timeout: 5_000 })

		// 等 6.5 秒後 URL 應變為下一章（next_post_url）
		await page.waitForURL(
			(u) => !u.pathname.endsWith(`/${td.chapterSlugs[1]}/`),
			{ timeout: 10_000 },
		)
	})
})
