/**
 * 字幕管理 - 影片類型支援 E2E 測試
 *
 * 驗證管理端 YouTube 和 Vimeo 影片類型在章節編輯中
 * 能正確顯示 SubtitleManager 字幕管理區塊。
 *
 * 測試策略：UI 渲染驗證
 * 1. YouTube 影片類型 → 填入 URL → SubtitleManager 出現 → 切回無影片 → 消失
 * 2. Vimeo 影片類型 → 填入 URL → SubtitleManager 出現
 */

import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { test, expect } from '@playwright/test'
import { ApiClient } from '../helpers/api-client'
import {
	waitForFormLoaded,
	clickTab,
} from '../helpers/admin-page'

const __filename = fileURLToPath(import.meta.url)
const __dirname = path.dirname(__filename)
const STORAGE_STATE_PATH = path.join(__dirname, '..', '.auth', 'admin.json')
const BASE_URL = process.env.TEST_SITE_URL || 'http://localhost:8889'

/** 測試用 YouTube URL（公開影片） */
const YOUTUBE_URL = 'https://www.youtube.com/watch?v=fqcPIPczRVA'

/** 測試用 Vimeo URL（公開影片） */
const VIMEO_URL = 'https://vimeo.com/900151069'

/** 本地 WordPress 環境較慢，給予充足的測試時間 */
test.setTimeout(60_000)

/**
 * 使用 storageState 建立高 timeout 的 API context
 *
 * setupApiFromBrowser 繼承 config 的 actionTimeout: 10s，
 * 本地環境 API 偶爾超過 10s 會導致 flaky。
 * 改用獨立的 context 並設定 60s 預設 timeout。
 */
async function setupApiWithLongTimeout(
	browser: import('@playwright/test').Browser,
): Promise<{ api: ApiClient; dispose: () => Promise<void> }> {
	const context = await browser.newContext({
		storageState: STORAGE_STATE_PATH,
		ignoreHTTPSErrors: true,
		serviceWorkers: 'block',
	})
	context.setDefaultTimeout(60_000)

	// 透過 admin-ajax.php 取得 REST nonce（比載入完整 wp-admin 快）
	const resp = await context.request.get(
		`${BASE_URL}/wp-admin/admin-ajax.php?action=rest-nonce`,
		{ timeout: 60_000 },
	)
	const nonce = (await resp.text()).trim()
	if (!nonce || nonce === '0' || nonce === '-1') {
		throw new Error(`無法取得 REST nonce，回應: ${nonce}`)
	}

	return {
		api: new ApiClient(context.request, nonce),
		dispose: async () => {
			await context.close()
		},
	}
}

test.describe('字幕管理 - 影片類型支援', () => {
	test.use({ storageState: '.auth/admin.json' })

	let courseId: number

	test.beforeAll(async ({ browser }) => {
		const { api, dispose } = await setupApiWithLongTimeout(browser)
		try {
			const result = await api.createCourseWithChapters(
				'E2E 字幕 Video Type 測試課程',
				'0',
				[{ name: 'E2E 字幕測試章節', slug: 'e2e-subtitle-vtype-ch' }],
				'e2e-subtitle-vtype-test',
			)
			courseId = result.courseId
		} finally {
			await dispose()
		}
	})

	test.afterAll(async ({ browser }) => {
		if (!courseId) return
		const { api, dispose } = await setupApiWithLongTimeout(browser)
		try {
			await api.deleteCourses([courseId])
		} finally {
			await dispose()
		}
	})

	/**
	 * 導航到課程編輯頁並進入章節編輯面板
	 */
	async function navigateToChapterEdit(
		page: import('@playwright/test').Page,
	): Promise<void> {
		await page.goto(
			`/wp-admin/admin.php?page=power-course#/courses/edit/${courseId}`,
			{ waitUntil: 'domcontentloaded', timeout: 30_000 },
		)

		// 等待 React SPA 根節點掛載
		await page.waitForSelector('#power_course', {
			state: 'attached',
			timeout: 30_000,
		})

		// 等待 Ant Design Spin 消失
		await page.waitForFunction(
			() => document.querySelectorAll('.ant-spin-spinning').length === 0,
			{ timeout: 30_000 },
		)

		await waitForFormLoaded(page)
		await clickTab(page, '章節管理')

		// 等待章節列表載入完成
		await page.waitForFunction(
			() => document.querySelectorAll('.ant-spin-spinning').length === 0,
			{ timeout: 15_000 },
		)

		// 點擊章節名稱進入編輯面板
		await page.locator('p.text-primary', {
			hasText: 'E2E 字幕測試章節',
		}).click()

		// 等待章節編輯面板出現
		await expect(
			page.locator('text=《編輯》'),
		).toBeVisible({ timeout: 10_000 })
	}

	/**
	 * 在 VideoInput 中選擇影片類型
	 */
	async function selectVideoType(
		page: import('@playwright/test').Page,
		label: string,
	): Promise<void> {
		const videoSection = page.locator('text=上傳課程內容').locator('..')
		const select = videoSection.locator('.ant-select').first()
		await select.click()

		const option = page.locator('.ant-select-item-option', {
			hasText: label,
		})
		await option.click()
	}

	test('YouTube 影片類型顯示字幕管理區塊，切換無影片後消失', async ({ page }) => {
		await navigateToChapterEdit(page)

		// ── Step 1: 切換為 YouTube 並填入 URL ──
		await selectVideoType(page, 'Youtube 嵌入')

		const urlInput = page.locator(
			'input[placeholder="請輸入 YOUTUBE 影片連結"]',
		)
		await expect(urlInput).toBeVisible({ timeout: 5_000 })
		await urlInput.fill(YOUTUBE_URL)

		// 等待影片預覽 iframe 出現（video ID 已成功解析）
		await expect(
			page.locator('iframe[title="影片播放器"]'),
		).toBeVisible({ timeout: 10_000 })

		// 斷言：SubtitleManager 區塊可見
		const subtitleHeading = page.locator('h4', { hasText: '字幕管理' })
		await expect(subtitleHeading).toBeVisible({ timeout: 10_000 })

		// 斷言：顯示「尚未上傳字幕」空狀態
		await expect(page.locator('text=尚未上傳字幕')).toBeVisible()

		// ── Step 2: 切換回「無影片」，字幕區塊應消失 ──
		await selectVideoType(page, '無影片')

		await expect(subtitleHeading).not.toBeVisible({ timeout: 5_000 })
		await expect(
			page.locator('iframe[title="影片播放器"]'),
		).not.toBeVisible()
	})

	test('Vimeo 影片類型顯示字幕管理區塊', async ({ page }) => {
		await navigateToChapterEdit(page)

		// 切換為 Vimeo 並填入 URL
		await selectVideoType(page, 'Vimeo 嵌入')

		const urlInput = page.locator(
			'input[placeholder="請輸入 VIMEO 影片連結"]',
		)
		await expect(urlInput).toBeVisible({ timeout: 5_000 })
		await urlInput.fill(VIMEO_URL)

		// 等待影片預覽 iframe 出現
		await expect(
			page.locator('iframe[title="影片播放器"]'),
		).toBeVisible({ timeout: 10_000 })

		// 斷言：SubtitleManager 區塊可見
		await expect(
			page.locator('h4', { hasText: '字幕管理' }),
		).toBeVisible({ timeout: 10_000 })

		// 斷言：顯示「尚未上傳字幕」空狀態
		await expect(page.locator('text=尚未上傳字幕')).toBeVisible()
	})
})
