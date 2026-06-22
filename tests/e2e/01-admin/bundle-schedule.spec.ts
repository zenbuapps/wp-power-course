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
	test.use({ storageState: '.auth/admin.json' })

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
		// 列表預設 status 含 publish + draft；include 以單一 id 過濾
		const resp = await api.pcGet<TBundleRecord[]>('bundle_products', {
			include: String(id),
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
