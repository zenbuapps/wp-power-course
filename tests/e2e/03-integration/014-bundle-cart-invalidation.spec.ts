/**
 * 測試目標：購物車內的銷售方案失效後不得結帳（Issue #261）
 * 對應原始碼：inc/classes/FrontEnd/Purchasable.php
 *
 * ⚠️ 守備範圍（前台實測確認，見 Purchasable 的說明）：
 * WooCommerce 自己會在 WC_Cart_Session::get_cart_from_session() 依 is_purchasable()
 * 移除 draft 商品，所以「方案轉草稿」不需要本外掛出手。
 *
 * 本外掛真正守住的是 **is_purchasable() 仍為 true、但依本外掛規則不該販售** 的情況：
 * post_status 是 publish、但自動上線時間尚未到點（Issue #260 會產生的狀態）。
 *
 * 因此本測試刻意用 WC REST 直接寫 meta 來製造該狀態——若改用 power-course 的
 * bundle_products 端點，會觸發 Issue #260 的修復把方案轉成草稿，就變成在測 WC 的行為。
 */

import { test, expect } from '@playwright/test'
import { setupApiFromBrowser } from '../helpers/api-client'

type TStoreCart = {
	items_count?: number
	items?: Array<{ id: number }>
	errors?: Array<{ code?: string; message?: string }>
}

test.describe('購物車內銷售方案失效', () => {
	test.use({ storageState: '.auth/admin.json', actionTimeout: 45_000, navigationTimeout: 90_000 })
	test.describe.configure({ timeout: 240_000, mode: 'serial' })

	let courseId: number
	let bundleId: number

	test.beforeAll(async ({ browser }) => {
		test.setTimeout(240_000)

		const { api, dispose } = await setupApiFromBrowser(browser)
		try {
			courseId = await api.createCourse('E2E 購物車失效測試課程')
			const resp = await api.pcPostForm('bundle_products', {
				name: 'E2E 購物車失效方案',
				type: 'simple',
				bundle_type: 'single_course',
				status: 'publish',
				regular_price: '399',
				link_course_ids: [courseId],
			})
			bundleId = Number((resp.data as { data?: { id?: string } })?.data?.id)
			expect(bundleId, 'bundle 應建立成功').toBeGreaterThan(0)
		} finally {
			await dispose()
		}
	})

	test.afterAll(async ({ browser }) => {
		test.setTimeout(120_000)

		const { api, dispose } = await setupApiFromBrowser(browser)
		try {
			if (courseId) {
				await api.deleteCourses([courseId])
			}
		} finally {
			await dispose()
		}
	})

	/** 以瀏覽器 session 讀 Store API 購物車（帶 cookie） */
	async function readCart(page: import('@playwright/test').Page) {
		return await page.evaluate(async () => {
			const r = await fetch('/wp-json/wc/store/v1/cart', {
				credentials: 'include',
			})
			return (await r.json()) as unknown
		}) as TStoreCart
	}

	test('尚未到上線時間的方案應被移出購物車，且無法結帳', async ({
		page,
		browser,
	}) => {
		// 1. 方案仍可販售 → 加入購物車
		await page.goto(`/?add-to-cart=${bundleId}`, {
			waitUntil: 'domcontentloaded',
		})

		// 斷言「這個方案在不在購物車」而非總數：購物車 session 會跨測試/跨 run 保留，
		// 用總數會被其他殘留品項影響而變得脆弱。
		const before = await readCart(page)
		expect(
			before.items?.map((i) => i.id),
			'前置條件：方案應已在購物車內',
		).toContain(bundleId)

		// 2. 事後才設定未來的上線時間：post_status 維持 publish，
		//    WC 的 is_purchasable() 仍為 true，所以 WC 自己不會移除。
		const future = Math.floor(Date.now() / 1000) + 86400
		const { api, dispose } = await setupApiFromBrowser(browser)
		try {
			await api.wcPost(`products/${bundleId}`, {
				meta_data: [{ key: 'bundle_schedule_online', value: String(future) }],
			})
			const check = await api.wcGet<{ status: string; purchasable: boolean }>(
				`products/${bundleId}`,
			)
			const data = check.data as { status: string; purchasable: boolean }
			expect(data.status, '方案應維持發佈中').toBe('publish')
			expect(
				data.purchasable,
				'前置條件：WC 仍認為可購買（所以 WC 自己不會移除）',
			).toBe(true)
		} finally {
			await dispose()
		}

		// 3. 重新載入購物車 → 應由本外掛移除
		await page.goto(`/cart/?e2e=${Date.now()}`, {
			waitUntil: 'domcontentloaded',
		})

		const after = await readCart(page)
		expect(
			after.items?.map((i) => i.id),
			'尚未到上線時間的方案應被移出購物車（Issue #260 + #261）',
		).not.toContain(bundleId)
	})

	test('仍在販售的方案不應被誤移出購物車', async ({ page, browser }) => {
		// 清掉上一個 case 留下的排程，讓方案回到可販售
		const { api, dispose } = await setupApiFromBrowser(browser)
		try {
			await api.wcPost(`products/${bundleId}`, {
				meta_data: [{ key: 'bundle_schedule_online', value: '0' }],
			})
		} finally {
			await dispose()
		}

		await page.goto(`/?add-to-cart=${bundleId}`, {
			waitUntil: 'domcontentloaded',
		})
		await page.goto(`/cart/?e2e=${Date.now()}`, {
			waitUntil: 'domcontentloaded',
		})

		const cart = await readCart(page)
		expect(
			cart.items?.map((i) => i.id),
			'仍在販售的方案不應被移除',
		).toContain(bundleId)
	})
})
