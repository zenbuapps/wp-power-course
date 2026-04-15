/**
 * WooCommerce 結帳流程 Helper
 *
 * 模擬使用者從加入購物車到完成結帳的流程。
 * 使用 BACS（銀行轉帳）作為付款方式。
 */
import { type Page, expect } from '@playwright/test'

interface CheckoutData {
	firstName?: string
	lastName?: string
	address?: string
	city?: string
	postcode?: string
	phone?: string
	email?: string
}

const DEFAULT_CHECKOUT_DATA: CheckoutData = {
	firstName: '測試',
	lastName: '用戶',
	address: '台北市中正區忠孝東路一段1號',
	city: '台北市',
	postcode: '100',
	phone: '0912345678',
	email: 'test@example.com',
}

/**
 * 將商品加入購物車（透過 URL）
 *
 * @param page - Playwright Page
 * @param productId - WooCommerce 商品 ID
 */
export async function addToCart(page: Page, productId: number): Promise<void> {
	await page.goto(`/?add-to-cart=${productId}`, {
		waitUntil: 'domcontentloaded',
	})
	// WooCommerce 會自動加入並重導向
	await page.waitForLoadState('networkidle')
}

/**
 * 前往購物車頁面
 */
export async function goToCart(page: Page): Promise<void> {
	await page.goto('/cart/', { waitUntil: 'domcontentloaded' })
	await page.waitForLoadState('networkidle')
}

/**
 * 前往結帳頁面
 */
export async function goToCheckout(page: Page): Promise<void> {
	await page.goto('/checkout/', { waitUntil: 'domcontentloaded' })
	await page.waitForLoadState('networkidle')
}

/**
 * 完整結帳流程（BACS 銀行轉帳）
 *
 * @param page - Playwright Page
 * @param productId - 商品 ID
 * @param data - 結帳資料（可選，會與預設值合併）
 * @returns 訂單編號
 */
export async function completeCheckout(
	page: Page,
	productId: number,
	data?: Partial<CheckoutData>,
): Promise<string> {
	const checkoutData = { ...DEFAULT_CHECKOUT_DATA, ...data }

	// 1. 加入購物車
	await addToCart(page, productId)

	// 2. 前往結帳
	await goToCheckout(page)

	// 3. 等待結帳表單渲染（Block 或 Classic）
	await Promise.race([
		page.locator('.wc-block-checkout').waitFor({ state: 'visible', timeout: 20_000 }),
		page.locator('#customer_details').waitFor({ state: 'visible', timeout: 20_000 }),
	]).catch(() => {})

	const isBlockCheckout = await page
		.locator('.wc-block-checkout')
		.isVisible()
		.catch(() => false)

	if (isBlockCheckout) {
		await fillBlockCheckout(page, checkoutData)
	} else {
		await fillClassicCheckout(page, checkoutData)
	}

	// 4. 取得訂單編號（從 URL 提取，兼容 WC 各版本）
	await page.waitForURL(/order-received/, { timeout: 30_000 })
	const urlMatch = page.url().match(/order-received\/(\d+)/)
	if (urlMatch) {
		return urlMatch[1]
	}
	// fallback: 嘗試 DOM selector
	const orderNumber = await page
		.locator('.woocommerce-order-overview__order strong')
		.textContent({ timeout: 5_000 })
		.catch(() => '')
	return orderNumber?.trim() || ''
}

/**
 * 填寫 Block Checkout 表單
 */
async function fillBlockCheckout(
	page: Page,
	data: CheckoutData,
): Promise<void> {
	// 填寫 email
	const emailInput = page.locator('#email')
	if (await emailInput.isVisible()) {
		await emailInput.fill(data.email || '')
	}

	// 填寫姓名
	const firstNameInput = page.locator('#billing-first_name')
	if (await firstNameInput.isVisible()) {
		await firstNameInput.fill(data.firstName || '')
	}

	const lastNameInput = page.locator('#billing-last_name')
	if (await lastNameInput.isVisible()) {
		await lastNameInput.fill(data.lastName || '')
	}

	// 地址
	const addressInput = page.locator('#billing-address_1')
	if (await addressInput.isVisible()) {
		await addressInput.fill(data.address || '')
	}

	const cityInput = page.locator('#billing-city')
	if (await cityInput.isVisible()) {
		await cityInput.fill(data.city || '')
	}

	const postcodeInput = page.locator('#billing-postcode')
	if (await postcodeInput.isVisible()) {
		await postcodeInput.fill(data.postcode || '')
	}

	const phoneInput = page.locator('#billing-phone')
	if (await phoneInput.isVisible()) {
		await phoneInput.fill(data.phone || '')
	}

	// 選擇 BACS 付款方式
	const bacsRadio = page.locator(
		'input[name="radio-control-wc-payment-method-options"][value="bacs"]',
	)
	if (await bacsRadio.isVisible()) {
		await bacsRadio.check()
	}

	// 送出訂單
	await page.click(
		'.wc-block-components-checkout-place-order-button, button:has-text("下訂單")',
	)
}

/**
 * 填寫 Classic Checkout 表單
 */
async function fillClassicCheckout(
	page: Page,
	data: CheckoutData,
): Promise<void> {
	// 國家（WC 必填，否則 validation 失敗）
	const countrySelect = page.locator('#billing_country')
	if (await countrySelect.isVisible().catch(() => false)) {
		await countrySelect.selectOption('TW')
		await page.waitForTimeout(500) // 等待 WC AJAX 更新州/省欄位
	}

	await page.fill('#billing_first_name', data.firstName || '')
	await page.fill('#billing_last_name', data.lastName || '')
	await page.fill('#billing_address_1', data.address || '')
	await page.fill('#billing_city', data.city || '')
	await page.fill('#billing_postcode', data.postcode || '')
	await page.fill('#billing_phone', data.phone || '')
	await page.fill('#billing_email', data.email || '')

	// 選擇 BACS
	const bacsRadio = page.locator('#payment_method_bacs')
	if (await bacsRadio.isVisible()) {
		await bacsRadio.check()
	}

	// 等待 WC update_order_review AJAX 完成
	await page.waitForTimeout(1000)

	// 送出訂單
	await page.click('#place_order')
}

/**
 * 透過 WP Admin 將訂單狀態改為已完成
 *
 * @param page - Playwright Page（需要 admin 登入狀態）
 * @param orderId - 訂單 ID
 */
export async function completeOrderViaAdmin(
	page: Page,
	orderId: string | number,
): Promise<void> {
	await page.goto(`/wp-admin/post.php?post=${orderId}&action=edit`, {
		waitUntil: 'domcontentloaded',
	})

	// WooCommerce HPOS 或 legacy
	const statusSelect = page.locator(
		'#order_status, select[name="order_status"]',
	)
	if (await statusSelect.isVisible()) {
		await statusSelect.selectOption('wc-completed')

		// 儲存
		const updateBtn = page.locator(
			'.save_order, button.save_order, input[name="save"]',
		)
		await updateBtn.click()
		await page.waitForLoadState('networkidle')
	}
}

/**
 * 清空購物車
 */
export async function emptyCart(page: Page): Promise<void> {
	await page.goto('/cart/', { waitUntil: 'domcontentloaded' })

	// 嘗試移除所有商品
	const removeButtons = page.locator('.remove, a.wc-block-cart-item__remove')
	const count = await removeButtons.count()
	for (let i = 0; i < count; i++) {
		await removeButtons.first().click()
		await page.waitForTimeout(1000)
	}
}
