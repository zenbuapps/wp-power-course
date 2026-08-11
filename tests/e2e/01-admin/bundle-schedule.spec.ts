/**
 * 銷售方案自動上下線排程 E2E（Issue #247）
 *
 * 對應規格：
 * - specs/features/bundle/設定銷售方案排程.feature
 * - specs/features/bundle/銷售方案自動上下線.feature
 *
 * 以真實 REST 全棧驗證（power-course namespace）：
 * - 設定未來下線時間 → 儲存、狀態維持 publish、API 回傳 bundle_schedule_offline
 * - Q3=B：設定過去下線時間 → 回應含 schedule_notice、方案立即轉 draft
 *
 * 時間一律相對 now（秒）設定，與時鐘無關。
 */

import { test, expect } from '@playwright/test'
import { setupApiFromBrowser } from '../helpers/api-client'

type TBundleRecord = {
	id: string
	status: string
	bundle_schedule_online: number | null
	bundle_schedule_offline: number | null
}

test.describe('銷售方案自動上下線排程', () => {
	// 本檔每個 case 要打數次 power-course REST（建立方案 → 更新排程 → 讀回列表）。
	// 在較慢的本機站上單次呼叫就可能超過預設的 actionTimeout 10s / timeout 30s，
	// 逾時後看到的會是 beforeAll 失敗，而不是真正的斷言結果——故在此放寬。
	test.use({ storageState: '.auth/admin.json', actionTimeout: 45_000 })
	test.describe.configure({ timeout: 180_000 })

	let courseId: number
	const bundleIds: number[] = []

	test.beforeAll(async ({ browser }) => {
		const { api, dispose } = await setupApiFromBrowser(browser)
		try {
			courseId = await api.createCourse('E2E 排程銷售方案測試課程')
		} finally {
			await dispose()
		}
	})

	test.afterAll(async ({ browser }) => {
		const { api, dispose } = await setupApiFromBrowser(browser)
		try {
			// 刪除課程會連帶清除其銷售方案（delete_course_and_related_items）
			if (courseId) {
				await api.deleteCourses([courseId])
			}
		} finally {
			await dispose()
		}
	})

	/**
	 * 建立一個發佈中的銷售方案，回傳 id
	 */
	async function createBundle(
		api: Awaited<ReturnType<typeof setupApiFromBrowser>>['api'],
		name: string,
	): Promise<number> {
		const resp = await api.pcPostForm('bundle_products', {
			name,
			type: 'simple',
			bundle_type: 'single_course',
			status: 'publish',
			regular_price: '399',
			link_course_ids: [courseId],
		})
		const body = resp.data as { data?: { id?: string } }
		const id = Number(body?.data?.id)
		expect(id, `bundle 建立失敗：${JSON.stringify(resp.data)}`).toBeGreaterThan(0)
		bundleIds.push(id)
		return id
	}

	/**
	 * 透過列表 API 取得單一 bundle 記錄
	 */
	async function getBundle(
		api: Awaited<ReturnType<typeof setupApiFromBrowser>>['api'],
		id: number,
	): Promise<TBundleRecord | undefined> {
		// 列表預設 status 含 publish + draft。
		//
		// 不能用 `include`：這支端點走 get_products_callback() → wc_get_products()，
		// 實測 `include` 參數不會生效（永遠回 0 筆），改以 link_course_ids 撈同課程的
		// 全部方案再自行 find。
		const resp = await api.pcGet<TBundleRecord[]>('bundle_products', {
			meta_key: 'link_course_ids',
			meta_value: String(courseId),
		})
		const list = Array.isArray(resp.data) ? resp.data : []
		return list.find((b) => String(b.id) === String(id))
	}

	test('設定未來下線時間：儲存成功、維持發佈、API 回傳排程', async ({
		browser,
	}) => {
		const { api, dispose } = await setupApiFromBrowser(browser)
		try {
			const bundleId = await createBundle(api, 'E2E 未來下線方案')
			const future = Math.floor(Date.now() / 1000) + 86400 // 明天

			await api.pcPostForm(`bundle_products/${bundleId}`, {
				type: 'simple',
				bundle_schedule_offline: String(future),
			})

			const record = await getBundle(api, bundleId)
			expect(record, '應能取得方案').toBeTruthy()
			expect(record?.status).toBe('publish')
			expect(Number(record?.bundle_schedule_offline)).toBe(future)
		} finally {
			await dispose()
		}
	})

	test('Issue #260 設定未來上線時間：方案自動轉草稿等待排程', async ({
		browser,
	}) => {
		const { api, dispose } = await setupApiFromBrowser(browser)
		try {
			// 新建方案的 status 是 publish（前端建立時明確送出 status: 'publish'）
			const bundleId = await createBundle(api, 'E2E 未來上線方案')
			const before = await getBundle(api, bundleId)
			expect(before?.status, '前置條件：新建方案應為發佈中').toBe('publish')

			const future = Math.floor(Date.now() / 1000) + 86400 // 明天

			const resp = await api.pcPostForm<{ schedule_notice?: string | null }>(
				`bundle_products/${bundleId}`,
				{
					type: 'simple',
					bundle_schedule_online: String(future),
				},
			)

			// 應回傳「已轉草稿、將於排程時間自動上線」的提示
			expect(
				resp.data?.schedule_notice,
				'設定未來上線時間應回傳 schedule_notice',
			).toBeTruthy()

			const record = await getBundle(api, bundleId)
			expect(record, '應能取得方案').toBeTruthy()
			expect(
				record?.status,
				'已發佈方案設定未來上線時間後應轉為草稿，否則上線時間形同虛設（Issue #260）',
			).toBe('draft')
			expect(Number(record?.bundle_schedule_online)).toBe(future)
		} finally {
			await dispose()
		}
	})

	test('Issue #260 草稿方案設定未來上線時間：維持草稿', async ({ browser }) => {
		const { api, dispose } = await setupApiFromBrowser(browser)
		try {
			const bundleId = await createBundle(api, 'E2E 草稿未來上線方案')

			// 先轉為草稿
			await api.pcPostForm(`bundle_products/${bundleId}`, {
				type: 'simple',
				status: 'draft',
			})
			expect((await getBundle(api, bundleId))?.status).toBe('draft')

			const future = Math.floor(Date.now() / 1000) + 86400
			await api.pcPostForm(`bundle_products/${bundleId}`, {
				type: 'simple',
				bundle_schedule_online: String(future),
			})

			const record = await getBundle(api, bundleId)
			expect(record?.status, '草稿方案應維持草稿').toBe('draft')
			expect(Number(record?.bundle_schedule_online)).toBe(future)
		} finally {
			await dispose()
		}
	})

	test('Q3=B 設定過去下線時間：回應含提示、方案立即轉草稿', async ({
		browser,
	}) => {
		const { api, dispose } = await setupApiFromBrowser(browser)
		try {
			const bundleId = await createBundle(api, 'E2E 過去下線方案')
			const past = Math.floor(Date.now() / 1000) - 3600 // 一小時前

			const resp = await api.pcPostForm<{ schedule_notice?: string | null }>(
				`bundle_products/${bundleId}`,
				{
					type: 'simple',
					bundle_schedule_offline: String(past),
				},
			)

			// 回應應包含立即下線的提示訊息（Q3=B）
			expect(
				resp.data?.schedule_notice,
				'過去時間應回傳 schedule_notice',
			).toBeTruthy()

			// 方案應立即轉為草稿
			const record = await getBundle(api, bundleId)
			expect(record?.status).toBe('draft')
		} finally {
			await dispose()
		}
	})
})
