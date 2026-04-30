/**
 * Issue #203：課程編輯頁選填欄位空值儲存 — API/DB 層整合測試（等價於 PHPUnit Integration）
 *
 * 覆蓋 Planner 計劃中的 Phase E（PHPUnit Red）所列的 18 個 test methods，
 * 以 Playwright + Power Course REST API + WP REST API 為驅動，
 * 直接 assert 後端 API 行為與 DB meta 狀態。
 *
 * 驗收條件：14 個可清空欄位 × 清空 scenario + GET response 空值契約（null vs ""）
 *
 * 此 spec 屬於 TDD Red Phase：執行時應全部失敗，由 Phase A/B 後端實作驅動為綠燈。
 */

import { test, expect } from '@playwright/test'
import { setupApiFromBrowser, ApiClient } from '../helpers/api-client'

type CourseRecord = {
	id: string
	sale_price?: string
	date_on_sale_from?: number | null
	date_on_sale_to?: number | null
	sale_date_range?: [number | null, number | null] | null
	on_sale?: boolean
	short_description?: string
	slug?: string
	sku?: string
	course_schedule?: number | null
	button_text?: string
	type?: string
	purchase_note?: string
}

/** 取得 post_meta 原始值（走 WC REST API 的 meta_data[]）*/
async function getPostMetaRaw(
	api: ApiClient,
	postId: number,
	metaKey: string,
): Promise<string> {
	const wcResp = await api.wcGet<{
		meta_data?: { key: string; value: string }[]
	}>(`products/${postId}`)
	const meta = (wcResp.data as { meta_data?: { key: string; value: string }[] })
		?.meta_data
	if (!Array.isArray(meta)) return ''
	const item = meta.find((m) => m.key === metaKey)
	return item ? String(item.value) : ''
}

async function getProductRaw(
	api: ApiClient,
	productId: number,
): Promise<Record<string, unknown>> {
	const resp = await api.wcGet(`products/${productId}`)
	return resp.data as Record<string, unknown>
}

async function getCoursePcApi(
	api: ApiClient,
	courseId: number,
): Promise<CourseRecord> {
	const resp = await api.pcGet<CourseRecord | CourseRecord[]>(`courses`, {
		id: String(courseId),
	})
	const rows = resp.data as CourseRecord[] | CourseRecord
	if (Array.isArray(rows)) {
		const hit = rows.find((r) => String(r.id) === String(courseId))
		if (!hit) {
			throw new Error(`Course ${courseId} not found in GET /courses response`)
		}
		return hit
	}
	return rows
}

test.describe('Issue #203 - 課程選填欄位空值儲存（API/DB 層）', () => {
	// 提高 timeout：LocalSites 單次 POST 可能超過 10s（plugin I/O + Powerhouse hooks）
	test.describe.configure({ mode: 'serial', timeout: 180_000 })

	let api: ApiClient
	let dispose: () => Promise<void>
	const createdCourseIds: number[] = []

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
				/* ignore */
			}
		}
		await dispose()
	})

	/** Helper：建立一個帶完整選填欄位資料的課程，回傳 courseId */
	async function createFilledCourse(name: string): Promise<number> {
		const id = await api.createCourse(`E2E-203 ${name}`)
		createdCourseIds.push(id)

		// 填入完整資料供後續清空測試
		await api.updateCourse(id, {
			regular_price: '1200',
			sale_price: '888',
			date_on_sale_from: '1735689600', // 2025-01-01
			date_on_sale_to: '1767225599', // 2025-12-31
			short_description: '入門必修',
			purchase_note: '感謝購買',
			sku: `PHP-${id}`,
			limit_type: 'fixed',
			limit_value: '30',
			limit_unit: 'day',
			course_schedule: '1735689600',
			feature_video: JSON.stringify({ id: 'demo1', type: 'bunny-stream' }),
			trial_videos: [{ id: 'demo2', type: 'bunny-stream', meta: {} }],
		})

		return id
	}

	// ========== Write Path：14 個可清空欄位 ==========

	test('test_清空 sale_price 後 DB meta 應為空字串', async () => {
		const courseId = await createFilledCourse('sale_price')
		await api.updateCourse(courseId, { sale_price: '' })

		const product = await getProductRaw(api, courseId)
		// WooCommerce product.sale_price (透過 wc-rest 回傳) 應為空字串
		expect(product.sale_price).toBe('')
		expect(product.on_sale).toBe(false)

		const record = await getCoursePcApi(api, courseId)
		expect(record.sale_price).toBe('')
	})

	test('test_清空 date_on_sale_from 與 to 後 DB meta 應為空字串', async () => {
		const courseId = await createFilledCourse('date_both')
		await api.updateCourse(courseId, {
			date_on_sale_from: '',
			date_on_sale_to: '',
		})

		const record = await getCoursePcApi(api, courseId)
		expect(record.date_on_sale_from).toBeNull()
		expect(record.date_on_sale_to).toBeNull()
		expect(record.sale_date_range).toBeNull()
	})

	test('test_單側 date_on_sale_from 空 to 有值 後 兩側同步清空', async () => {
		const courseId = await createFilledCourse('date_from_empty')
		await api.updateCourse(courseId, {
			date_on_sale_from: '',
			date_on_sale_to: '1769817599', // 2026-01-30
		})

		const record = await getCoursePcApi(api, courseId)
		expect(record.date_on_sale_from).toBeNull()
		expect(record.date_on_sale_to).toBeNull()
		expect(record.sale_date_range).toBeNull()
	})

	test('test_單側 date_on_sale_to 空 from 有值 後 兩側同步清空', async () => {
		const courseId = await createFilledCourse('date_to_empty')
		await api.updateCourse(courseId, {
			date_on_sale_from: '1767225600', // 2026-01-01
			date_on_sale_to: '',
		})

		const record = await getCoursePcApi(api, courseId)
		expect(record.date_on_sale_from).toBeNull()
		expect(record.date_on_sale_to).toBeNull()
		expect(record.sale_date_range).toBeNull()
	})

	test('test_清空 short_description 後 應為空字串', async () => {
		const courseId = await createFilledCourse('short_desc')
		await api.updateCourse(courseId, { short_description: '' })

		const product = await getProductRaw(api, courseId)
		const shortDesc = (product.short_description as { rendered?: string })
			?.rendered
		expect(shortDesc === '' || shortDesc === undefined).toBeTruthy()
	})

	test('test_清空 purchase_note 後 meta 應為空字串', async () => {
		const courseId = await createFilledCourse('purchase_note')
		await api.updateCourse(courseId, { purchase_note: '' })

		const product = await getProductRaw(api, courseId)
		expect(product.purchase_note).toBe('')
	})

	test('test_清空 sku 後 應為空字串', async () => {
		const courseId = await createFilledCourse('sku')
		await api.updateCourse(courseId, { sku: '' })

		const product = await getProductRaw(api, courseId)
		expect(product.sku).toBe('')
	})

	test('test_清空 slug 後 WP 依 post_title 重建 post_name', async () => {
		const courseId = await createFilledCourse('slug')
		await api.updateCourse(courseId, { slug: '' })

		const product = await getProductRaw(api, courseId)
		const slug = product.slug as string
		// WordPress 對空 post_name 會 fallback 為 sanitize_title(post_title)，不應為空字串
		expect(typeof slug).toBe('string')
		expect(slug.length).toBeGreaterThan(0)
	})

	test('test_清空 limit_value 與 limit_unit 且切換 unlimited 後 meta 應為空字串', async () => {
		const courseId = await createFilledCourse('limit')
		await api.updateCourse(courseId, {
			limit_type: 'unlimited',
			limit_value: '',
			limit_unit: '',
		})

		const limitType = await getPostMetaRaw(api, courseId, 'limit_type')
		const limitValue = await getPostMetaRaw(api, courseId, 'limit_value')
		const limitUnit = await getPostMetaRaw(api, courseId, 'limit_unit')

		expect(limitType).toBe('unlimited')
		expect(limitValue).toBe('')
		expect(limitUnit).toBe('')
	})

	test('test_清空 course_schedule 後 meta 應為空字串', async () => {
		const courseId = await createFilledCourse('course_schedule')
		await api.updateCourse(courseId, { course_schedule: '' })

		const schedule = await getPostMetaRaw(api, courseId, 'course_schedule')
		expect(schedule).toBe('')
	})

	test('test_清空 feature_video 後 meta 應為空字串', async () => {
		const courseId = await createFilledCourse('feature_video')
		await api.updateCourse(courseId, { feature_video: '' })

		const v = await getPostMetaRaw(api, courseId, 'feature_video')
		expect(v).toBe('')
	})

	test('test_清空 trial_videos 後 meta 應為空陣列且舊 trial_video 已刪除 (Issue #10)', async () => {
		const courseId = await createFilledCourse('trial_videos')
		// JSON body 才能精準表達空陣列；form-encoded 無法區分 [] 與 undefined
		await api.updateCourseJson(courseId, { trial_videos: [] })

		const newMeta = await getPostMetaRaw(api, courseId, 'trial_videos')
		expect(newMeta).toBe('[]')

		const legacy = await getPostMetaRaw(api, courseId, 'trial_video')
		expect(legacy).toBe('')
	})

	test('test_外部課程清空 button_text 後 fallback（或清空）生效', async () => {
		const courseId = await api.createCourse(`E2E-203 external_btn`)
		createdCourseIds.push(courseId)

		// 將課程轉為 external 並設定 button_text
		await api.updateCourse(courseId, {
			is_external: 'true',
			product_url: 'https://example.com',
			button_text: 'Buy now',
		})

		// 清空 button_text
		await api.updateCourse(courseId, { button_text: '' })

		const product = await getProductRaw(api, courseId)
		// 清空後不應保留舊值 'Buy now'；fallback 為 i18n 後的 'Visit course'
		// （zh_TW 翻成「前往課程」）或空字串都算清空成功
		expect(product.button_text).not.toBe('Buy now')
	})

	test('test_未送 sale_price key 時 保持原狀（向下相容）', async () => {
		const courseId = await createFilledCourse('compat')
		// 只更新 name，不傳 sale_price
		await api.updateCourse(courseId, { name: 'E2E-203 compat updated' })

		const record = await getCoursePcApi(api, courseId)
		expect(record.sale_price).toBe('888')
	})

	// ========== Read Path：GET response 空值契約 ==========

	test('test_date_on_sale_from_to 為空時 GET 回 null', async () => {
		const courseId = await createFilledCourse('read_date_null')
		await api.updateCourse(courseId, {
			date_on_sale_from: '',
			date_on_sale_to: '',
		})

		const record = await getCoursePcApi(api, courseId)
		expect(record.date_on_sale_from).toBeNull()
		expect(record.date_on_sale_to).toBeNull()
		expect(record.sale_date_range).toBeNull()
	})

	test('test_只有 from 有值時 from 回 timestamp to 回 null', async () => {
		const courseId = await createFilledCourse('read_date_partial')
		// 先清空，再單側設定
		await api.updateCourse(courseId, {
			date_on_sale_from: '1767225600',
			date_on_sale_to: '',
		})
		// 但 Phase A 規則：單側空 → 兩側同步空，這個 case 會被 normalize
		// 改用直接 post_meta 寫入來模擬「DB 中只有一側」的狀態
		// 透過 wc-rest 直接寫 meta
		// 這個 scenario 驗的是 read path：若 DB 只有 from 有值，GET 應 from=ts, to=null
		// 因為 write path 已強制同步，此 case 需要繞過 normalize → 用 wcPost 直接改
		await api.wcPost(`products/${courseId}`, {
			date_on_sale_from: '2026-01-01T00:00:00',
			date_on_sale_to: null,
		})

		const record = await getCoursePcApi(api, courseId)
		expect(typeof record.date_on_sale_from).toBe('number')
		expect(record.date_on_sale_to).toBeNull()
	})

	test('test_sale_price 為空時 GET 回空字串 on_sale 為 false', async () => {
		const courseId = await createFilledCourse('read_sale_price_empty')
		await api.updateCourse(courseId, { sale_price: '' })

		const record = await getCoursePcApi(api, courseId)
		expect(record.sale_price).toBe('')
		expect(record.on_sale).toBe(false)
	})

	test('test_course_schedule 為空時 GET 回 null', async () => {
		const courseId = await createFilledCourse('read_schedule_null')
		await api.updateCourse(courseId, { course_schedule: '' })

		const record = await getCoursePcApi(api, courseId)
		expect(record.course_schedule).toBeNull()
	})
})
