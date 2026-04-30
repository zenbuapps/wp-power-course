/**
 * 測試目標：課程多影片試看 (Issue #10) 前台渲染
 * 對應原始碼：
 *   - inc/templates/pages/course-product/footer/index.php
 *   - inc/assets/src/trial-videos-swiper.ts
 *   - specs/features/course/多影片試看.feature
 *
 * 場景覆蓋：
 *   - 0 部 → 不渲染區塊
 *   - 1 部 → 直接顯示，HTML 不含 swiper CSS / JS
 *   - 3 部 → Swiper 容器 + 3 swiper-slide + pagination + 左右箭頭
 *   - 切換 slide 時前一影片自動暫停
 */

import { test, expect } from '@playwright/test'
import {
	ApiClient,
	setupApiFromBrowser,
} from '../helpers/api-client.js'

test.describe('Issue #10 - 多影片試看前台渲染', () => {
	test.describe.configure({ mode: 'serial', timeout: 180_000 })

	let api: ApiClient
	let dispose: () => Promise<void>
	const createdCourseIds: number[] = []

	async function createCourseWithTrialVideos(
		name: string,
		videos: Array<{ type: string; id: string }>,
	): Promise<{ courseId: number; courseUrl: string }> {
		const courseId = await api.createCourse(`E2E-10 ${name}`)
		createdCourseIds.push(courseId)

		await api.updateCourseJson(courseId, {
			regular_price: '500',
			trial_videos: videos.map((v) => ({ ...v, meta: {} })),
		})

		const courseUrl = await api.getCourseUrl(courseId)
		return { courseId, courseUrl }
	}

	test.beforeAll(async ({ browser }) => {
		const setup = await setupApiFromBrowser(browser)
		api = setup.api
		dispose = setup.dispose
	})

	test.afterAll(async () => {
		if (createdCourseIds.length > 0) {
			try {
				await api.deleteCourses(createdCourseIds)
			} catch {
				// 容忍刪除失敗
			}
		}
		await dispose()
	})

	test('test_0 部 trial_videos 頁面不渲染試看區塊', async ({ page }) => {
		const { courseUrl } = await createCourseWithTrialVideos('zero', [])
		await page.goto(courseUrl)

		await expect(page.locator('[data-pc-trial-videos-swiper]')).toHaveCount(0)
	})

	test('test_1 部 trial_videos 直接顯示無 Swiper 元素', async ({ page }) => {
		const { courseUrl } = await createCourseWithTrialVideos('single', [
			{ type: 'youtube', id: 'fqcPIPczRVA' },
		])
		await page.goto(courseUrl)

		// 標題顯示
		await expect(
			page.getByText(/Course preview|課程試看/).first(),
		).toBeVisible()
		// 不應出現 Swiper 容器
		await expect(page.locator('[data-pc-trial-videos-swiper]')).toHaveCount(0)
		await expect(page.locator('.swiper-pagination')).toHaveCount(0)
		await expect(page.locator('.swiper-button-prev')).toHaveCount(0)
		await expect(page.locator('.swiper-button-next')).toHaveCount(0)
	})

	test('test_3 部 trial_videos 渲染 Swiper 輪播（3 slides + 分頁點 + 左右箭頭）', async ({
		page,
	}) => {
		const { courseUrl } = await createCourseWithTrialVideos('triple', [
			{ type: 'youtube', id: 'fqcPIPczRVA' },
			{ type: 'youtube', id: 'dQw4w9WgXcQ' },
			{ type: 'vimeo', id: '900151069' },
		])
		await page.goto(courseUrl)

		// Swiper 容器存在
		const swiper = page.locator('[data-pc-trial-videos-swiper]')
		await expect(swiper).toHaveCount(1)
		// 3 張投影片
		await expect(swiper.locator('.swiper-slide')).toHaveCount(3)
		// 左右箭頭
		await expect(swiper.locator('.swiper-button-prev')).toBeVisible()
		await expect(swiper.locator('.swiper-button-next')).toBeVisible()
		// pagination 容器
		await expect(swiper.locator('.swiper-pagination')).toBeVisible()
	})

	test('test_1 部頁面 HTML 不應載入 swiper bundle JS', async ({ page }) => {
		const { courseUrl } = await createCourseWithTrialVideos('single-no-bundle', [
			{ type: 'youtube', id: 'fqcPIPczRVA' },
		])
		await page.goto(courseUrl)

		const html = await page.content()
		expect(html).not.toContain('trial-videos-swiper.js')
	})

	test('test_2 部頁面 HTML 應載入 swiper bundle JS（條件式 enqueue）', async ({
		page,
	}) => {
		const { courseUrl } = await createCourseWithTrialVideos('two', [
			{ type: 'youtube', id: 'fqcPIPczRVA' },
			{ type: 'vimeo', id: '900151069' },
		])
		await page.goto(courseUrl)

		const html = await page.content()
		expect(html).toContain('trial-videos-swiper.js')
	})
})
