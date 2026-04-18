/**
 * Issue #203：課程編輯頁選填欄位空值儲存 — UI E2E 測試（Phase F）
 *
 * 覆蓋 Issue #203 原始驗收場景：
 *   使用者清空 sale_price 與 sale_date_range → 儲存 → invalidate → 欄位仍為空
 *
 * 此 spec 屬於 TDD Red Phase：執行時應全部失敗，由 Phase C/D 前端實作驅動為綠燈。
 */

import { test, expect } from '@playwright/test'
import {
	navigateToAdmin,
	waitForFormLoaded,
	clickTab,
	waitForMessage,
} from '../helpers/admin-page'
import { setupApiFromBrowser, ApiClient } from '../helpers/api-client'

test.describe('Issue #203 - 課程編輯頁清空選填欄位', () => {
	test.describe.configure({ mode: 'serial', timeout: 120_000 })
	test.use({ storageState: '.auth/admin.json' })

	const createdCourseIds: number[] = []
	let api: ApiClient
	let dispose: () => Promise<void>

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

	async function createFilledCourse(label: string): Promise<number> {
		const id = await api.createCourse(`E2E-203-UI ${label}`)
		createdCourseIds.push(id)
		await api.updateCourse(id, {
			regular_price: '1200',
			sale_price: '888',
			date_on_sale_from: '1735689600',
			date_on_sale_to: '1767225599',
			purchase_note: '感謝購買',
			sku: `PHP-UI-${id}`,
			limit_type: 'fixed',
			limit_value: '30',
			limit_unit: 'day',
			course_schedule: '1735689600',
		})
		return id
	}

	test('Issue #203 原始驗收：清空 sale_price 與 sale_date_range 並儲存', async ({ page }) => {
		const consoleErrors: string[] = []
		const consoleWarnings: string[] = []
		page.on('console', (msg) => {
			if (msg.type() === 'error') consoleErrors.push(msg.text())
			if (msg.type() === 'warning') consoleWarnings.push(msg.text())
		})
		page.on('pageerror', (err) => {
			consoleErrors.push(`pageerror: ${err.message}`)
		})

		const courseId = await createFilledCourse('headline-case')

		await navigateToAdmin(page, `/courses/edit/${courseId}`)
		await waitForFormLoaded(page)
		// 等待 React SPA 內所有 spinner 消失後再操作
		await page.waitForFunction(
			() => document.querySelectorAll('.ant-spin-spinning').length === 0,
			{ timeout: 15_000 },
		)
		await clickTab(page, '課程訂價')

		// 清空 sale_price InputNumber
		const salePriceInput = page
			.locator('.ant-form-item')
			.filter({ hasText: /特價|Sale price/i })
			.locator('input.ant-input-number-input')
			.first()
		// tab 切換後 panel 可能延遲渲染，給較長 timeout
		await expect(salePriceInput).toBeVisible({ timeout: 15_000 })
		await salePriceInput.click()
		await salePriceInput.press('ControlOrMeta+A')
		await salePriceInput.press('Delete')

		// 清空 sale_date_range RangePicker：點 clear 按鈕
		const rangePicker = page
			.locator('.ant-form-item')
			.filter({ hasText: /特價排程|Sale schedule|Sale date/i })
			.locator('.ant-picker-range')
			.first()
		await rangePicker.hover()
		const clearBtn = rangePicker.locator('.ant-picker-clear')
		if (await clearBtn.isVisible({ timeout: 2_000 }).catch(() => false)) {
			await clearBtn.click({ force: true })
		}

		// 儲存（Ant Design 會把中文字間自動加空格，需支援「儲 存」）
		// Refine + antd 的 notification 可能走 .ant-notification 或 .ant-message
		// 先攔截 POST /courses/{id} 的 response 確認儲存完成
		const savePromise = page.waitForResponse(
			(resp) =>
				resp.url().includes(`/power-course/courses/${courseId}`) &&
				resp.request().method() === 'POST',
			{ timeout: 30_000 },
		)
		await page
			.getByRole('button', { name: /^儲\s*存$|^Save$/i })
			.first()
			.click()
		const saveResp = await savePromise
		expect(saveResp.status()).toBeLessThan(400)

		// 等 Refine invalidate 完成，refetch 後的新資料載入頁面
		await page.waitForTimeout(2_000)
		await page.waitForFunction(
			() => document.querySelectorAll('.ant-spin-spinning').length === 0,
			{ timeout: 15_000 },
		)

		// 驗證：DB 中 sale_price 與 date_on_sale_* 應為空
		const product = await api.wcGet<{
			sale_price: string
			date_on_sale_from: string | null
			date_on_sale_to: string | null
		}>(`products/${courseId}`)
		const body = product.data as {
			sale_price: string
			date_on_sale_from: string | null
			date_on_sale_to: string | null
		}
		expect(body.sale_price).toBe('')
		expect(body.date_on_sale_from).toBeNull()
		expect(body.date_on_sale_to).toBeNull()

		// 驗證：invalidate 後 UI 欄位為空（placeholder 狀態）
		// sale_price InputNumber 應為空
		const reloadedSalePrice = page
			.locator('.ant-form-item')
			.filter({ hasText: /特價|Sale price/i })
			.locator('input.ant-input-number-input')
			.first()
		await expect(reloadedSalePrice).toHaveValue('', { timeout: 10_000 })

		// RangePicker 的 2 個 input 都應為空（不顯示 1970-01-01）
		const reloadedRangeInputs = page
			.locator('.ant-form-item')
			.filter({ hasText: /特價排程|Sale schedule|Sale date/i })
			.locator('.ant-picker-range input')
		const countRange = await reloadedRangeInputs.count()
		for (let i = 0; i < countRange; i++) {
			await expect(reloadedRangeInputs.nth(i)).toHaveValue('')
		}

		// 最後驗證：無 React warning、無 1970 錯誤日期
		const pageText = await page.textContent('body')
		expect(pageText ?? '').not.toContain('1970-01-01')
		expect(consoleErrors.join('\n')).toBe('')
		// 過濾掉其他 warning（只關心 React warning 與 dayjs warning）
		const relevantWarnings = consoleWarnings.filter(
			(w) =>
				w.toLowerCase().includes('react') ||
				w.toLowerCase().includes('dayjs') ||
				w.toLowerCase().includes('controlled'),
		)
		expect(relevantWarnings.join('\n')).toBe('')
	})

	test('UI 防禦：開啟 DB 為空課程的編輯頁不噴 warning 不顯示 1970', async ({ page }) => {
		const consoleErrors: string[] = []
		const consoleWarnings: string[] = []
		page.on('console', (msg) => {
			if (msg.type() === 'error') consoleErrors.push(msg.text())
			if (msg.type() === 'warning') consoleWarnings.push(msg.text())
		})
		page.on('pageerror', (err) => {
			consoleErrors.push(`pageerror: ${err.message}`)
		})

		// 建立一個 DB 中 sale 欄位完全空的課程
		const id = await api.createCourse('E2E-203-UI defense-empty')
		createdCourseIds.push(id)

		await navigateToAdmin(page, `/courses/edit/${id}`)
		await waitForFormLoaded(page)
		await page.waitForFunction(
			() => document.querySelectorAll('.ant-spin-spinning').length === 0,
			{ timeout: 15_000 },
		)
		await clickTab(page, '課程訂價')

		const pageText = await page.textContent('body')
		expect(pageText ?? '').not.toContain('1970-01-01')
		expect(pageText ?? '').not.toContain('1970/01/01')
		expect(consoleErrors.join('\n')).toBe('')
		const relevantWarnings = consoleWarnings.filter(
			(w) =>
				w.toLowerCase().includes('react') ||
				w.toLowerCase().includes('dayjs') ||
				w.toLowerCase().includes('controlled'),
		)
		expect(relevantWarnings.join('\n')).toBe('')
	})

	test('Bundle Edit smoke：parseRangePickerValue 修改不影響 Bundle 頁', async ({ page }) => {
		// 回歸檢查：共享 utility 變更不得讓 Bundle Edit 壞掉
		// 找一個既有課程並進銷售方案 tab
		const id = await api.createCourse('E2E-203-UI bundle-smoke')
		createdCourseIds.push(id)
		await api.updateCourse(id, {
			regular_price: '1000',
		})

		await navigateToAdmin(page, `/courses/edit/${id}`)
		await waitForFormLoaded(page)
		await page.waitForFunction(
			() => document.querySelectorAll('.ant-spin-spinning').length === 0,
			{ timeout: 15_000 },
		)
		await clickTab(page, '銷售方案')

		// Bundle Edit 頁面載入不應爆炸
		const pageText = await page.textContent('body')
		expect(pageText ?? '').not.toContain('1970-01-01')
	})
})
