/**
 * Admin SPA 導航 Helper
 *
 * Power Course 後台使用 React SPA + HashRouter，
 * 頁面 URL 格式: /wp-admin/admin.php?page=power-course#/path
 */
import { type Page, expect } from '@playwright/test'

/** Admin SPA 基礎 URL */
const ADMIN_PAGE = '/wp-admin/admin.php?page=power-course'

/** SPA 載入最長等待時間 */
const SPA_LOAD_TIMEOUT = 15_000

/**
 * 導航到 Admin SPA 的指定路由
 *
 * @param page - Playwright Page 實例
 * @param hash - Hash 路由路徑，例如 '/courses' 或 '/courses/edit/123'
 */
export async function navigateToAdmin(page: Page, hash: string): Promise<void> {
	const url = `${ADMIN_PAGE}#${hash}`
	await page.goto(url, { waitUntil: 'domcontentloaded', timeout: SPA_LOAD_TIMEOUT })

	// 檢查是否被重導到登入頁（auth session 無效）
	const currentUrl = page.url()
	if (currentUrl.includes('wp-login.php')) {
		throw new Error(
			`Admin navigation redirected to login page: ${currentUrl}. Session may have expired.`,
		)
	}

	// 等待 React SPA 根節點掛載
	await page.waitForSelector('#power_course', {
		state: 'attached',
		timeout: SPA_LOAD_TIMEOUT,
	})

	// 等待 Ant Design Spin 消失（SPA 資料載入完畢）
	await page.waitForFunction(
		() => {
			const spinners = document.querySelectorAll('.ant-spin-spinning')
			return spinners.length === 0
		},
		{ timeout: SPA_LOAD_TIMEOUT },
	)
}

/**
 * 等待 Ant Design Table 載入完成
 */
export async function waitForTableLoaded(page: Page): Promise<void> {
	// 等待 Ant Design 表格出現
	await page.waitForSelector('.ant-table', { timeout: SPA_LOAD_TIMEOUT })

	// 等待 loading 結束
	await page.waitForFunction(
		() => {
			const loading = document.querySelector('.ant-table-loading')
			return !loading
		},
		{ timeout: SPA_LOAD_TIMEOUT },
	)
}

/**
 * 等待 Ant Design ProTable 載入完成
 */
export async function waitForProTableLoaded(page: Page): Promise<void> {
	await page.waitForSelector('.ant-table-wrapper', { timeout: SPA_LOAD_TIMEOUT })
	await waitForTableLoaded(page)
}

/**
 * 等待 Ant Design Form 載入完成
 *
 * 不依賴 skeleton 全部消失（部分 lazy-loaded 區塊的 skeleton 可能持續存在），
 * 改為等待 form + tabs 都渲染完成即視為載入完畢。
 */
export async function waitForFormLoaded(page: Page): Promise<void> {
	await page.waitForSelector('.ant-form', { timeout: SPA_LOAD_TIMEOUT })
	await page.waitForSelector('.ant-tabs-tab', { timeout: SPA_LOAD_TIMEOUT })
}

/**
 * 等待 Ant Design 訊息提示出現
 *
 * @param page - Playwright Page 實例
 * @param type - 訊息類型 'success' | 'error' | 'warning' | 'info'
 */
export async function waitForMessage(
	page: Page,
	type: 'success' | 'error' | 'warning' | 'info' = 'success',
): Promise<void> {
	await page.waitForSelector(`.ant-message-${type}`, { timeout: 10_000 })
}

/**
 * 等待 Ant Design Notification 出現
 */
export async function waitForNotification(page: Page): Promise<void> {
	await page.waitForSelector('.ant-notification', { timeout: 10_000 })
}

/**
 * 點擊 Ant Design Tabs 的指定 Tab
 *
 * 策略：先嘗試直接點擊 tab，若因 overflow 導致 aria-selected 未改變，
 * 則透過 overflow dropdown（ellipsis 按鈕）點擊。最終 fallback 使用
 * dispatchEvent 直接觸發 React 事件。
 *
 * @param page - Playwright Page
 * @param tabName - Tab 顯示文字
 */
export async function clickTab(page: Page, tabName: string): Promise<void> {
	const tab = page.getByRole('tab', { name: tabName })

	// 策略 1：直接點擊 role="tab" 元素
	await tab.click()

	// 檢查是否成功切換
	const isSelected = await tab
		.getAttribute('aria-selected', { timeout: 1_500 })
		.then((v) => v === 'true')
		.catch(() => false)
	if (isSelected) return

	// 策略 2：透過 Ant Design overflow dropdown 點擊
	const moreBtn = page.locator('.ant-tabs-nav-more')
	if (await moreBtn.isVisible({ timeout: 1_000 }).catch(() => false)) {
		await moreBtn.click()
		const dropdownItem = page.locator('.ant-tabs-dropdown-menu-item').filter({ hasText: tabName })
		if (await dropdownItem.isVisible({ timeout: 2_000 }).catch(() => false)) {
			await dropdownItem.click()
			await expect(tab).toHaveAttribute('aria-selected', 'true', { timeout: 5_000 })
			return
		}
	}

	// 策略 3：dispatchEvent 直接觸發 click（繞過 overflow clip）
	await page.evaluate((name: string) => {
		const btns = document.querySelectorAll<HTMLElement>('.ant-tabs-tab-btn')
		for (const btn of btns) {
			if (btn.textContent?.trim() === name) {
				btn.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }))
				return
			}
		}
	}, tabName)

	await expect(tab).toHaveAttribute('aria-selected', 'true', { timeout: 5_000 })
}

/**
 * 在 Ant Design Modal 中確認
 */
export async function confirmModal(page: Page): Promise<void> {
	await page.click('.ant-modal-footer .ant-btn-primary')
	await page.waitForSelector('.ant-modal', {
		state: 'hidden',
		timeout: 10_000,
	})
}

/**
 * 在 Ant Design Popconfirm 中確認
 */
export async function confirmPopconfirm(page: Page): Promise<void> {
	await page.click('.ant-popconfirm .ant-btn-primary')
}

/**
 * 收集頁面上的 console errors
 */
export function collectConsoleErrors(page: Page): string[] {
	const errors: string[] = []
	page.on('console', (msg) => {
		if (msg.type() === 'error') {
			errors.push(msg.text())
		}
	})
	return errors
}
