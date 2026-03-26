/**
 * 課程封面圖上傳元件 E2E 測試（TDD 紅燈階段）
 *
 * 驗證課程編輯頁「課程描述」Tab 的封面圖上傳已從
 * FileUpload（Dragger + ImgCrop）重構為 MediaLibraryModal 選圖器。
 *
 * 測試目前預期失敗（紅燈），待實作完成後才會通過（綠燈）。
 */

import { test, expect } from '@playwright/test'
import {
	navigateToAdmin,
	waitForFormLoaded,
} from '../helpers/admin-page'
import { ApiClient, setupApiFromBrowser } from '../helpers/api-client'

test.describe('課程封面圖選圖器', () => {
	test.use({ storageState: '.auth/admin.json' })

	let api: ApiClient
	let dispose: () => Promise<void>
	let courseId: number

	test.beforeAll(async ({ browser }) => {
		const setup = await setupApiFromBrowser(browser)
		api = setup.api
		dispose = setup.dispose

		// 建立測試用課程
		courseId = await api.createCourse('E2E 封面圖測試課程')
	})

	test.afterAll(async () => {
		try {
			if (courseId) {
				await api.deleteCourses([courseId])
			}
		} catch {
			// 清除失敗不影響測試結果
		} finally {
			await dispose()
		}
	})

	// ── 冒煙測試 ──────────────────────────────────

	test('課程描述 Tab 載入後應顯示封面圖選圖器 @smoke', async ({ page }) => {
		await navigateToAdmin(page, `/courses/edit/${courseId}`)
		await waitForFormLoaded(page)

		// 預期：新的 Gallery-style MediaLibraryModal 選圖器存在
		// 此元素在重構完成後才會出現，目前為紅燈
		const mediaLibraryTrigger = page.locator(
			'[data-testid="media-library-trigger"], .media-library-modal-trigger, .course-cover-image-selector',
		)
		await expect(mediaLibraryTrigger).toBeVisible({ timeout: 10_000 })
	})

	// ── UI 結構驗證（舊元件不應存在）─────────────

	test('不應存在 Dragger 拖曳上傳區域 @smoke', async ({ page }) => {
		await navigateToAdmin(page, `/courses/edit/${courseId}`)
		await waitForFormLoaded(page)

		// 重構後，舊的 ant-upload-drag 拖曳區域應消失
		const draggerArea = page.locator('.ant-upload-drag')
		await expect(draggerArea).not.toBeVisible()
	})

	test('不應存在 antd-img-crop 裁剪 Modal @smoke', async ({ page }) => {
		await navigateToAdmin(page, `/courses/edit/${courseId}`)
		await waitForFormLoaded(page)

		// 重構後，ImgCrop 元件相關的 class 不應出現在課程描述 Tab
		// ImgCrop 注入的容器通常帶有 img-crop 相關 class
		const imgCropContainer = page.locator('.img-crop-container, [class*="img-crop"]')
		await expect(imgCropContainer).not.toBeAttached()
	})

	// ── 封面圖回顯測試 ────────────────────────────

	test('透過 API 設定 image_id 後，課程編輯頁應正確回顯封面圖', async ({ page }) => {
		// Arrange：先透過 WP REST API 取得一個媒體庫圖片 ID
		// 此測試假設測試環境中已有上傳的媒體（若無則跳過驗證 src）
		const mediaResp = await api.wpGet<{ id: number; source_url: string }[]>('media', {
			per_page: '1',
			media_type: 'image',
		})

		if (!Array.isArray(mediaResp.data) || mediaResp.data.length === 0) {
			test.skip()
			return
		}

		const mediaItem = (mediaResp.data as { id: number; source_url: string }[])[0]
		const imageId = mediaItem.id
		const imageUrl = mediaItem.source_url

		// Act：透過 WC REST API 直接設定課程的封面圖（模擬舊有圖片資料）
		await api.wcPost(`products/${courseId}`, {
			images: [{ id: imageId }],
		})

		// 導航到課程編輯頁
		await navigateToAdmin(page, `/courses/edit/${courseId}`)
		await waitForFormLoaded(page)

		// Assert：課程封面圖應正確顯示已設定的圖片
		// 重構後使用 Gallery-style 選圖器，圖片應出現在選圖器預覽區
		const coverImagePreview = page.locator(
			'[data-testid="course-cover-preview"] img, .course-cover-image-preview img',
		)
		await expect(coverImagePreview).toBeVisible({ timeout: 10_000 })

		// 驗證圖片 src 包含正確的圖片 URL（部分匹配即可）
		const imgSrc = await coverImagePreview.getAttribute('src')
		expect(imgSrc).toBeTruthy()
		// 圖片 URL 應包含媒體庫圖片的路徑特徵
		expect(imgSrc).toContain(imageUrl.split('/').pop()?.split('-')[0] ?? '')
	})

	test('未設定封面圖時，選圖器應顯示佔位符或新增按鈕', async ({ page }) => {
		// 建立一個全新課程（無封面圖）
		const newCourseId = await api.createCourse('E2E 無封面圖課程')

		try {
			await navigateToAdmin(page, `/courses/edit/${newCourseId}`)
			await waitForFormLoaded(page)

			// 預期：未設定圖片時，應顯示「選擇圖片」或佔位符
			// 重構後的 MediaLibraryModal 選圖器在無圖片時應顯示新增提示
			const addImageButton = page.locator(
				'[data-testid="media-library-trigger"], .course-cover-add-btn, [aria-label*="選擇"], [aria-label*="新增"]',
			)
			await expect(addImageButton).toBeVisible({ timeout: 10_000 })
		} finally {
			await api.deleteCourses([newCourseId])
		}
	})
})
