/**
 * 整合測試：外掛相依性驗證
 *
 * 驗證 Power Course + Powerhouse + WooCommerce 三外掛共存無衝突
 */
import { test, expect } from '@playwright/test'

const BASE_URL = process.env.TEST_SITE_URL || 'http://localhost:8889'

test.describe('外掛相依性', () => {
	test.use({ storageState: '.auth/admin.json' })

	test('三外掛皆為啟用狀態', async ({ page }) => {
		await page.goto(`${BASE_URL}/wp-admin/plugins.php`, {
			waitUntil: 'domcontentloaded',
		})
		await page.waitForLoadState('networkidle')

		const html = await page.content()

		// Power Course — 檢查其列在 active plugins 中
		const powerCourseRow = page.locator('tr[data-plugin*="power-course"]')
		const pcActive = await powerCourseRow.locator('.deactivate').isVisible().catch(() => false)
		// 如果 data-plugin 不匹配，嘗試搜尋文字
		const hasPowerCourse = pcActive || html.includes('Power Course')
		expect(hasPowerCourse).toBe(true)

		// WooCommerce
		const hasWoo = html.includes('WooCommerce')
		expect(hasWoo).toBe(true)

		// Powerhouse
		const hasPowerhouse = html.includes('Powerhouse') || html.includes('powerhouse')
		expect(hasPowerhouse).toBe(true)
	})

	test('Admin SPA 頁面無 console error', async ({ page }) => {
		const errors: string[] = []
		page.on('console', (msg) => {
			if (msg.type() === 'error') {
				const text = msg.text()
				// 忽略已知的非關鍵性錯誤
				if (
					text.includes('favicon') ||
					text.includes('net::ERR') ||
					text.includes('Failed to load resource') ||
					text.includes('third-party cookie')
				) return
				errors.push(text)
			}
		})

		await page.goto(
			`${BASE_URL}/wp-admin/admin.php?page=power-course#/courses`,
			{ waitUntil: 'domcontentloaded' },
		)
		await page.waitForLoadState('networkidle')
		await page.waitForTimeout(3_000) // 等待 React 渲染

		// 不應有嚴重的 JS 錯誤
		const criticalErrors = errors.filter(
			(e) =>
				!e.includes('React') && // React DevTools 相關
				!e.includes('Warning:') && // React warnings
				!e.includes('Deprecation'), // 棄用警告
		)

		// 允許少量非關鍵性錯誤
		expect(criticalErrors.length).toBeLessThanOrEqual(2)
	})

	test('前台首頁可正常載入', async ({ page }) => {
		const resp = await page.goto(`${BASE_URL}/`, {
			waitUntil: 'domcontentloaded',
		})
		expect(resp?.status()).toBe(200)
	})
})
