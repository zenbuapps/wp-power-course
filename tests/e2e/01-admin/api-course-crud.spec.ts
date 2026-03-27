/**
 * Course CRUD API 測試
 *
 * 驗證 Power Course REST API 的課程 CRUD 操作：
 * 建立、讀取、更新、刪除、分頁、錯誤處理。
 */

import { test, expect } from '@playwright/test'
import { ApiClient, setupApiFromBrowser } from '../helpers/api-client'

/** POST /courses 成功回應 */
interface CreateResponse {
	code: string
	message: string
	data: { id: string }
}

/** POST /courses/{id} 成功回應 */
interface UpdateResponse {
	code: string
	message: string
	data: { id: string }
}

/** DELETE /courses 批量刪除回應 */
interface BulkDeleteResponse {
	code: string
	message: string
	data: string[]
}

/** DELETE /courses/{id} 單筆刪除回應 */
interface SingleDeleteResponse {
	code: string
	message: string
	data: { id: number }
}

/** GET /courses/{id} 404 回應 */
interface NotFoundResponse {
	message: string
}

test.describe('Course CRUD API', () => {
	test.use({ storageState: '.auth/admin.json' })

	let api: ApiClient
	let dispose: () => Promise<void>

	/** 收集所有建立的課程 ID，afterAll 統一清除 */
	const createdCourseIds: number[] = []

	test.beforeAll(async ({ browser }) => {
		const setup = await setupApiFromBrowser(browser)
		api = setup.api
		dispose = setup.dispose
	})

	test.afterAll(async () => {
		try {
			if (createdCourseIds.length > 0) {
				await api.deleteCourses(createdCourseIds)
			}
		} catch {
			// 清除失敗不影響測試結果
		} finally {
			await dispose()
		}
	})

	// ── 建立課程 ──────────────────────────────────

	test.describe('建立課程', () => {
		test('基本 — 僅提供名稱即可建立', async () => {
			const resp = await api.pcPostForm<CreateResponse>('courses', {
				name: 'CRUD 測試 — 基本建立',
				type: 'simple',
				course_schedule: '0',
				editor: 'power-editor',
			})

			expect(resp.status).toBe(200)
			expect(resp.data.code).toBe('create_success')
			expect(resp.data.message).toBe('新增成功')

			const id = Number(resp.data.data.id)
			expect(id).toBeGreaterThan(0)
			createdCourseIds.push(id)
		})

		test('完整欄位 — 包含價格、描述、限制設定', async () => {
			const resp = await api.pcPostForm<CreateResponse>('courses', {
				name: 'CRUD 測試 — 完整欄位',
				type: 'simple',
				course_schedule: '0',
				editor: 'power-editor',
			})

			expect(resp.status).toBe(200)
			const id = Number(resp.data.data.id)
			expect(id).toBeGreaterThan(0)
			createdCourseIds.push(id)

			// 透過 update 設定完整欄位
			const updateResp = await api.pcPostForm<UpdateResponse>(
				`courses/${id}`,
				{
					regular_price: '1999',
					sale_price: '999',
					status: 'publish',
					description: '<p>這是一堂完整的測試課程</p>',
					short_description: '完整測試課程簡述',
					limit_type: 'fixed',
					limit_value: '30',
					limit_unit: 'day',
					is_free: 'no',
					is_popular: 'yes',
					is_featured: 'yes',
					type: 'simple',
					course_schedule: '0',
					editor: 'power-editor',
				},
			)

			expect(updateResp.status).toBe(200)
			expect(updateResp.data.code).toBe('update_success')
		})
	})

	// ── 取得課程 ──────────────────────────────────

	test.describe('取得課程', () => {
		let courseId: number

		test.beforeAll(async () => {
			courseId = await api.createCourse('CRUD 測試 — 取得用')
			createdCourseIds.push(courseId)

			// 設定一些欄位以便驗證回傳內容
			await api.pcPostForm(`courses/${courseId}`, {
				regular_price: '500',
				status: 'publish',
				description: '<p>取得測試用課程</p>',
				short_description: '取得測試簡述',
				type: 'simple',
				course_schedule: '0',
				editor: 'power-editor',
			})
		})

		test('取得單一課程 — 回傳完整欄位', async () => {
			const resp = await api.pcGet<Record<string, unknown>>(
				`courses/${courseId}`,
			)

			expect(resp.status).toBe(200)
			expect(resp.data).toBeDefined()

			// 驗證基本欄位存在
			const course = resp.data
			expect(String(course.id)).toBe(String(courseId))
			expect(course.name).toBe('CRUD 測試 — 取得用')
			expect(course.status).toBe('publish')
			expect(course.description).toContain('取得測試用課程')
			expect(course.short_description).toContain('取得測試簡述')
			expect(course.regular_price).toBe('500')
		})

		test('取得課程列表 — 包含分頁 headers', async () => {
			const resp = await api.pcGet<Record<string, unknown>[]>('courses')

			expect(resp.status).toBe(200)
			expect(Array.isArray(resp.data)).toBe(true)

			// 驗證分頁 headers
			expect(resp.headers['x-wp-total']).toBeDefined()
			expect(resp.headers['x-wp-totalpages']).toBeDefined()

			const total = Number(resp.headers['x-wp-total'])
			expect(total).toBeGreaterThan(0)
		})

		test('取得不存在的課程 — 回傳 404', async () => {
			const fakeId = 9999999
			const resp = await api.pcGet<NotFoundResponse>(
				`courses/${fakeId}`,
			)

			expect(resp.status).toBe(404)
			expect(resp.data.message).toBeDefined()
		})
	})

	// ── 更新課程 ──────────────────────────────────

	test.describe('更新課程', () => {
		let courseId: number

		test.beforeAll(async () => {
			courseId = await api.createCourse('CRUD 測試 — 更新用')
			createdCourseIds.push(courseId)
		})

		test('更新基本資訊 — 名稱、價格、描述', async () => {
			const resp = await api.pcPostForm<UpdateResponse>(
				`courses/${courseId}`,
				{
					name: 'CRUD 測試 — 已更新名稱',
					regular_price: '2500',
					description: '<p>已更新的課程描述</p>',
					short_description: '已更新簡述',
					type: 'simple',
					course_schedule: '0',
					editor: 'power-editor',
				},
			)

			expect(resp.status).toBe(200)
			expect(resp.data.code).toBe('update_success')
			expect(resp.data.message).toBe('更新成功')

			// 驗證更新結果
			const getResp = await api.pcGet<Record<string, unknown>>(
				`courses/${courseId}`,
			)
			expect(getResp.data.name).toBe('CRUD 測試 — 已更新名稱')
			expect(getResp.data.regular_price).toBe('2500')
			expect(getResp.data.description).toContain('已更新的課程描述')
		})

		test('更新限制設定 — unlimited', async () => {
			const resp = await api.pcPostForm<UpdateResponse>(
				`courses/${courseId}`,
				{
					limit_type: 'unlimited',
					type: 'simple',
					course_schedule: '0',
					editor: 'power-editor',
				},
			)

			expect(resp.status).toBe(200)
			expect(resp.data.code).toBe('update_success')

			const getResp = await api.pcGet<Record<string, unknown>>(
				`courses/${courseId}`,
			)
			expect(getResp.data.limit_type).toBe('unlimited')
		})

		test('更新限制設定 — fixed 30 天', async () => {
			const resp = await api.pcPostForm<UpdateResponse>(
				`courses/${courseId}`,
				{
					limit_type: 'fixed',
					limit_value: '30',
					limit_unit: 'day',
					type: 'simple',
					course_schedule: '0',
					editor: 'power-editor',
				},
			)

			expect(resp.status).toBe(200)
			expect(resp.data.code).toBe('update_success')

			const getResp = await api.pcGet<Record<string, unknown>>(
				`courses/${courseId}`,
			)
			expect(getResp.data.limit_type).toBe('fixed')
			expect(String(getResp.data.limit_value)).toBe('30')
			expect(getResp.data.limit_unit).toBe('day')
		})

		test('更新限制設定 — assigned 指定時間', async () => {
			const futureTimestamp = String(
				Math.floor(Date.now() / 1000) + 365 * 24 * 60 * 60,
			)

			const resp = await api.pcPostForm<UpdateResponse>(
				`courses/${courseId}`,
				{
					limit_type: 'assigned',
					limit_value: futureTimestamp,
					limit_unit: 'timestamp',
					type: 'simple',
					course_schedule: '0',
					editor: 'power-editor',
				},
			)

			expect(resp.status).toBe(200)
			expect(resp.data.code).toBe('update_success')

			const getResp = await api.pcGet<Record<string, unknown>>(
				`courses/${courseId}`,
			)
			expect(getResp.data.limit_type).toBe('assigned')
			expect(getResp.data.limit_unit).toBe('timestamp')
		})

		test('排程開課 — 設定未來時間', async () => {
			const futureTimestamp = String(
				Math.floor(Date.now() / 1000) + 7 * 24 * 60 * 60,
			)

			const resp = await api.pcPostForm<UpdateResponse>(
				`courses/${courseId}`,
				{
					course_schedule: futureTimestamp,
					type: 'simple',
					editor: 'power-editor',
				},
			)

			expect(resp.status).toBe(200)
			expect(resp.data.code).toBe('update_success')

			const getResp = await api.pcGet<Record<string, unknown>>(
				`courses/${courseId}`,
			)
			expect(Number(getResp.data.course_schedule)).toBeGreaterThan(0)
		})

		test('免費課程 — is_free 設為 yes', async () => {
			const resp = await api.pcPostForm<UpdateResponse>(
				`courses/${courseId}`,
				{
					is_free: 'yes',
					regular_price: '0',
					type: 'simple',
					course_schedule: '0',
					editor: 'power-editor',
				},
			)

			expect(resp.status).toBe(200)
			expect(resp.data.code).toBe('update_success')

			const getResp = await api.pcGet<Record<string, unknown>>(
				`courses/${courseId}`,
			)
			expect(getResp.data.is_free).toBe('yes')
		})
	})

	// ── 刪除課程 ──────────────────────────────────

	test.describe('刪除課程', () => {
		test('刪除單一課程', async () => {
			const courseId = await api.createCourse('CRUD 測試 — 待刪除單筆')
			// 不加入 createdCourseIds，因為這邊會直接刪

			const resp = await api.pcDelete<SingleDeleteResponse>(
				`courses/${courseId}`,
			)

			expect(resp.status).toBe(200)
			expect(resp.data.code).toBe('delete_success')
			expect(resp.data.message).toBe('刪除成功')
			expect(resp.data.data.id).toBe(courseId)

			// 確認已刪除
			const getResp = await api.pcGet(`courses/${courseId}`)
			expect(getResp.status).toBe(404)
		})

		test('批量刪除課程', async ({ }, testInfo) => {
			testInfo.setTimeout(60_000)
			const id1 = await api.createCourse('CRUD 測試 — 批量刪除 1')
			const id2 = await api.createCourse('CRUD 測試 — 批量刪除 2')
			const id3 = await api.createCourse('CRUD 測試 — 批量刪除 3')

			const resp = await api.pcDelete<BulkDeleteResponse>('courses', {
				ids: [id1, id2, id3],
			})

			expect(resp.status).toBe(200)
			expect(resp.data.code).toBe('delete_success')
			expect(resp.data.message).toBe('刪除成功')
			expect(resp.data.data).toHaveLength(3)

			// 確認全部已刪除
			for (const id of [id1, id2, id3]) {
				const getResp = await api.pcGet(`courses/${id}`)
				expect(getResp.status).toBe(404)
			}
		})
	})

	// ── 課程封面圖（image_id）────────────────────

	/**
	 * 驗證課程 API 的 image_id 欄位處理
	 *
	 * TDD 紅燈階段：測試預期在 MediaLibraryModal 重構完成後通過。
	 * 重構目標：前端改用 image_id 傳送，後端需正確處理此欄位。
	 */
	test.describe('課程封面圖 API', () => {
		let imageId: number
		let imageCourseId: number

		test.beforeAll(async () => {
			// 建立測試用課程
			imageCourseId = await api.createCourse('CRUD 測試 — 封面圖')
			createdCourseIds.push(imageCourseId)

			// 取得媒體庫中的第一張圖片 ID（若無則使用 0）
			const mediaResp = await api.wpGet<{ id: number }[]>('media', {
				per_page: '1',
				media_type: 'image',
			})
			const media = mediaResp.data as { id: number }[]
			imageId = Array.isArray(media) && media.length > 0 ? media[0].id : 0
		})

		test('更新課程時傳送有效 image_id — API 應回傳成功', async () => {
			if (imageId === 0) {
				test.skip()
				return
			}

			// 傳送 image_id（新的欄位格式，重構後支援）
			const resp = await api.pcPostForm<UpdateResponse>(
				`courses/${imageCourseId}`,
				{
					image_id: String(imageId),
					type: 'simple',
					course_schedule: '0',
					editor: 'power-editor',
				},
			)

			expect(resp.status).toBe(200)
			expect(resp.data.code).toBe('update_success')

			// 驗證圖片已正確設定 — GET 後 images 陣列應包含該圖片
			const getResp = await api.pcGet<Record<string, unknown>>(
				`courses/${imageCourseId}`,
			)
			expect(getResp.status).toBe(200)

			const images = getResp.data.images as { id: number; url: string }[] | undefined
			expect(Array.isArray(images)).toBe(true)
			expect(images?.length).toBeGreaterThan(0)
			expect(images?.[0].id).toBe(imageId)
			expect(images?.[0].url).toBeTruthy()
		})

		test('更新課程時傳送 image_id 為 0 — 應清除封面圖並回傳成功', async () => {
			// 先設定一張圖片
			if (imageId !== 0) {
				await api.wcPost(`products/${imageCourseId}`, {
					images: [{ id: imageId }],
				})
			}

			// 傳送 image_id: '0' 清除封面圖
			const resp = await api.pcPostForm<UpdateResponse>(
				`courses/${imageCourseId}`,
				{
					image_id: '0',
					type: 'simple',
					course_schedule: '0',
					editor: 'power-editor',
				},
			)

			expect(resp.status).toBe(200)
			expect(resp.data.code).toBe('update_success')

			// 驗證封面圖已清除 — images 陣列應為空或不含主圖
			const getResp = await api.pcGet<Record<string, unknown>>(
				`courses/${imageCourseId}`,
			)
			expect(getResp.status).toBe(200)

			const images = getResp.data.images as { id: number }[] | undefined
			// 清除封面圖後 images 應為空陣列
			expect(Array.isArray(images)).toBe(true)
			expect(images?.length).toBe(0)
		})

		test('GET 課程後 images 陣列應包含正確的圖片資訊結構', async () => {
			if (imageId === 0) {
				test.skip()
				return
			}

			// 設定封面圖
			await api.wcPost(`products/${imageCourseId}`, {
				images: [{ id: imageId }],
			})

			const resp = await api.pcGet<Record<string, unknown>>(
				`courses/${imageCourseId}`,
			)
			expect(resp.status).toBe(200)

			// 驗證 images 陣列的資料結構正確
			const images = resp.data.images as Record<string, unknown>[] | undefined
			expect(Array.isArray(images)).toBe(true)

			if (images && images.length > 0) {
				const firstImage = images[0]
				// 每張圖片應包含 id 和 url 欄位
				expect(firstImage).toHaveProperty('id')
				expect(firstImage).toHaveProperty('url')
				expect(typeof firstImage.id).toBe('number')
				expect(typeof firstImage.url).toBe('string')
				expect(firstImage.url).toMatch(/^https?:\/\//)
			}
		})
	})

	// ── 分頁 ──────────────────────────────────────

	test.describe('列表分頁', () => {
		const paginationIds: number[] = []

		test.beforeAll(async () => {
			// 建立 3 筆課程
			for (let i = 1; i <= 3; i++) {
				const id = await api.createCourse(`CRUD 測試 — 分頁 ${i}`)
				paginationIds.push(id)
				createdCourseIds.push(id)
			}
		})

		test('建立多個課程後驗證分頁', async () => {
			// Power Course API 使用 WC 的 posts_per_page 參數
			const resp = await api.pcGet<Record<string, unknown>[]>('courses', {
				posts_per_page: '2',
				paged: '1',
			})

			expect(resp.status).toBe(200)
			expect(Array.isArray(resp.data)).toBe(true)
			expect(resp.data.length).toBeLessThanOrEqual(2)

			const total = Number(resp.headers['x-wp-total'])
			const totalPages = Number(resp.headers['x-wp-totalpages'])

			expect(total).toBeGreaterThanOrEqual(3)
			expect(totalPages).toBeGreaterThanOrEqual(2)

			// 取得第 2 頁
			const page2 = await api.pcGet<Record<string, unknown>[]>(
				'courses',
				{
					posts_per_page: '2',
					paged: '2',
				},
			)

			expect(page2.status).toBe(200)
			expect(Array.isArray(page2.data)).toBe(true)
			expect(page2.data.length).toBeGreaterThanOrEqual(1)

			// 確認兩頁資料不重複
			const page1Ids = resp.data.map((c) => c.id)
			const page2Ids = page2.data.map((c) => c.id)
			const overlap = page1Ids.filter((id) => page2Ids.includes(id))
			expect(overlap).toHaveLength(0)
		})
	})
})
