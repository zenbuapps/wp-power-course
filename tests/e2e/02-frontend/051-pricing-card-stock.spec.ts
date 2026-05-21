/**
 * 測試目標：銷售方案卡片庫存顯示 (Issue #225)
 *
 * 對應原始碼：
 *   - inc/templates/components/card/pricing.php（主要修改檔案）
 *   - inc/templates/components/stock/index.php（共用庫存模板，售完文字調整）
 *   - inc/classes/Shortcodes/General.php::pc_courses_callback()（短代碼進入點）
 *
 * 對應規格：specs/features/frontend/銷售方案卡片庫存顯示.feature
 *
 * 場景覆蓋（5 個 scenarios）：
 *   1. 充足庫存：stock_quantity=50、low_stock_amount=5 → 綠色 badge，內含數字 50
 *   2. 低庫存：stock_quantity=3、low_stock_amount=5 → 紅色 badge，內含數字 3
 *   3. 售完：stock_quantity=0 + stock_status=outofstock → 灰色 badge 顯示「已售完」，卡片 disabled
 *   4. 未啟用庫存管理（manage_stock=no）→ 卡片完全不渲染庫存 badge
 *   5. show_rest_stock=no → 即使啟用庫存管理也不渲染庫存 badge
 */

import { test, expect, type Page } from '@playwright/test'
import {
	ApiClient,
	setupApiFromBrowser,
} from '../helpers/api-client.js'

const SHORTCODE_PAGE_SLUG = 'e2e-pricing-card-stock'

interface ProductFixture {
	id: number
	name: string
}

/**
 * 建立帶有 [pc_courses include="..."] 短代碼的測試頁面
 */
async function ensureShortcodePage(
	api: ApiClient,
	productIds: number[],
): Promise<string> {
	const shortcode = `[pc_courses include="${productIds.join(',')}" limit="20" orderby="post__in"]`

	// 嘗試找既有頁面
	const search = await api.wpGet<Array<{ id: number; slug: string; link: string }>>(
		'pages',
		{ slug: SHORTCODE_PAGE_SLUG, status: 'publish,draft' },
	)
	const pages = Array.isArray(search.data) ? search.data : []
	const existing = pages.find((p) => p.slug === SHORTCODE_PAGE_SLUG)

	if (existing) {
		// 更新頁面內容（確保短代碼與目前商品列表同步）
		await api.wpPost(`pages/${existing.id}`, {
			content: shortcode,
			status: 'publish',
		})
		return existing.link
	}

	// 不存在則建立
	const resp = await api.wpPost<{ id: number; link: string }>('pages', {
		title: 'E2E Pricing Card Stock Test',
		slug: SHORTCODE_PAGE_SLUG,
		content: shortcode,
		status: 'publish',
	})
	const created = resp.data as { id: number; link: string }
	if (!created?.id) {
		throw new Error(
			`E2E 測試頁面建立失敗: ${JSON.stringify(resp.data)}`,
		)
	}
	return created.link
}

/**
 * 設定商品的庫存欄位（透過 WC REST API）
 */
async function setProductStock(
	api: ApiClient,
	productId: number,
	options: {
		manage_stock: boolean
		stock_quantity?: number | null
		stock_status?: 'instock' | 'outofstock'
		low_stock_amount?: number | string | null
		show_rest_stock?: 'yes' | 'no'
	},
): Promise<void> {
	const body: Record<string, unknown> = {
		manage_stock: options.manage_stock,
	}
	if (options.stock_quantity !== undefined) {
		body.stock_quantity = options.stock_quantity
	}
	if (options.stock_status !== undefined) {
		body.stock_status = options.stock_status
	}
	if (options.low_stock_amount !== undefined) {
		body.low_stock_amount = options.low_stock_amount
	}
	if (options.show_rest_stock !== undefined) {
		body.meta_data = [
			{ key: 'show_rest_stock', value: options.show_rest_stock },
		]
	}
	await api.wcPost(`products/${productId}`, body)
}

/**
 * 取得某商品卡片的根節點 locator
 *
 * 透過卡片內的商品名稱定位（同一頁有 4 張卡片，每張名稱不同）
 */
function cardLocator(page: Page, productName: string) {
	return page.locator('.pc-course-card').filter({ hasText: productName })
}

test.describe('Issue #225 - 銷售方案卡片庫存顯示', () => {
	test.describe.configure({ mode: 'serial', timeout: 240_000 })

	let api: ApiClient
	let dispose: () => Promise<void>
	let shortcodePageUrl: string

	// 4 個測試商品 fixture
	const fixtures: Record<
		'sufficient' | 'low' | 'outOfStock' | 'unmanaged',
		ProductFixture
	> = {
		sufficient: { id: 0, name: '' },
		low: { id: 0, name: '' },
		outOfStock: { id: 0, name: '' },
		unmanaged: { id: 0, name: '' },
	}

	const createdCourseIds: number[] = []

	test.beforeAll(async ({ browser }) => {
		const setup = await setupApiFromBrowser(browser)
		api = setup.api
		dispose = setup.dispose

		// 建立 4 個課程商品（皆 publish + 設定價格）
		const ts = Date.now()
		const buildName = (suffix: string) => `E2E-225 庫存${suffix} ${ts}`

		const sufficientId = await api.createCourse(buildName('充足'))
		const lowId = await api.createCourse(buildName('低'))
		const outOfStockId = await api.createCourse(buildName('售完'))
		const unmanagedId = await api.createCourse(buildName('未管理'))
		createdCourseIds.push(sufficientId, lowId, outOfStockId, unmanagedId)

		fixtures.sufficient = { id: sufficientId, name: buildName('充足') }
		fixtures.low = { id: lowId, name: buildName('低') }
		fixtures.outOfStock = { id: outOfStockId, name: buildName('售完') }
		fixtures.unmanaged = { id: unmanagedId, name: buildName('未管理') }

		// 統一設定為已發佈、有價格
		for (const id of createdCourseIds) {
			await api.updateCourse(id, {
				regular_price: '500',
				status: 'publish',
			})
		}

		// 設定庫存
		// 1. 充足：50 個、低庫存閾值 5
		await setProductStock(api, sufficientId, {
			manage_stock: true,
			stock_quantity: 50,
			stock_status: 'instock',
			low_stock_amount: '5',
			show_rest_stock: 'yes',
		})

		// 2. 低庫存：3 個、低庫存閾值 5
		await setProductStock(api, lowId, {
			manage_stock: true,
			stock_quantity: 3,
			stock_status: 'instock',
			low_stock_amount: '5',
			show_rest_stock: 'yes',
		})

		// 3. 售完：0 個 + outofstock
		await setProductStock(api, outOfStockId, {
			manage_stock: true,
			stock_quantity: 0,
			stock_status: 'outofstock',
			low_stock_amount: '5',
			show_rest_stock: 'yes',
		})

		// 4. 未啟用庫存管理
		await setProductStock(api, unmanagedId, {
			manage_stock: false,
			stock_status: 'instock',
			show_rest_stock: 'yes',
		})

		// 建立顯示這 4 張卡片的短代碼頁面
		shortcodePageUrl = await ensureShortcodePage(api, createdCourseIds)
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

	test('test_充足庫存顯示綠色 badge 與庫存數量', async ({ page }) => {
		await page.goto(shortcodePageUrl)
		const card = cardLocator(page, fixtures.sufficient.name)
		await expect(card).toBeVisible()

		// 庫存 badge 應出現在卡片內，class 含 bg-green-100，且包含數字 50
		const stockBadge = card.locator('.bg-green-100')
		await expect(stockBadge).toBeVisible()
		await expect(stockBadge).toContainText('50')

		// 卡片不應被標記為售完
		await expect(card).not.toHaveClass(/pc-course-card--sold-out/)
	})

	test('test_低庫存顯示紅色 badge 與庫存數量', async ({ page }) => {
		await page.goto(shortcodePageUrl)
		const card = cardLocator(page, fixtures.low.name)
		await expect(card).toBeVisible()

		const stockBadge = card.locator('.bg-red-100')
		await expect(stockBadge).toBeVisible()
		await expect(stockBadge).toContainText('3')

		await expect(card).not.toHaveClass(/pc-course-card--sold-out/)
	})

	test('test_售完顯示灰色已售完 badge 並停用卡片連結', async ({ page }) => {
		await page.goto(shortcodePageUrl)
		const card = cardLocator(page, fixtures.outOfStock.name)
		await expect(card).toBeVisible()

		// 灰色 badge + 「已售完」文字
		const stockBadge = card.locator('.bg-gray-100')
		await expect(stockBadge).toBeVisible()
		await expect(stockBadge).toContainText('已售完')

		// 卡片整體被加上 sold-out 修飾 class
		await expect(card).toHaveClass(/pc-course-card--sold-out/)

		// 卡片內所有 <a> 連結都有 aria-disabled="true"
		const links = card.locator('a')
		const linkCount = await links.count()
		expect(linkCount).toBeGreaterThan(0)
		for (let i = 0; i < linkCount; i++) {
			const link = links.nth(i)
			await expect(link).toHaveAttribute('aria-disabled', 'true')
		}
	})

	test('test_未啟用庫存管理時不渲染庫存 badge', async ({ page }) => {
		await page.goto(shortcodePageUrl)
		const card = cardLocator(page, fixtures.unmanaged.name)
		await expect(card).toBeVisible()

		// 卡片內不應出現任何 stock badge color class
		await expect(card.locator('.bg-green-100')).toHaveCount(0)
		await expect(card.locator('.bg-red-100')).toHaveCount(0)
		await expect(card.locator('.bg-gray-100')).toHaveCount(0)
	})

	test('test_show_rest_stock_為_no_時不渲染庫存 badge', async ({ page }) => {
		// 動態調整充足庫存商品的 show_rest_stock meta
		await setProductStock(api, fixtures.sufficient.id, {
			manage_stock: true,
			stock_quantity: 50,
			stock_status: 'instock',
			low_stock_amount: '5',
			show_rest_stock: 'no',
		})

		try {
			await page.goto(shortcodePageUrl)
			const card = cardLocator(page, fixtures.sufficient.name)
			await expect(card).toBeVisible()

			// 應完全不渲染任何庫存 badge
			await expect(card.locator('.bg-green-100')).toHaveCount(0)
			await expect(card.locator('.bg-red-100')).toHaveCount(0)
			await expect(card.locator('.bg-gray-100')).toHaveCount(0)
		} finally {
			// 還原以免影響其他測試
			await setProductStock(api, fixtures.sufficient.id, {
				manage_stock: true,
				stock_quantity: 50,
				stock_status: 'instock',
				low_stock_amount: '5',
				show_rest_stock: 'yes',
			})
		}
	})
})
