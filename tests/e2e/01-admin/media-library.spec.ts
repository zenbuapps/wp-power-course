/**
 * WP 媒體庫頁測試
 *
 * 驗證媒體庫頁面正確渲染
 */

import { test, expect } from '@playwright/test'
import { navigateToAdmin } from '../helpers/admin-page'

test.describe('WP 媒體庫', () => {
	test.use({ storageState: '.auth/admin.json' })

	test('頁面正確渲染', async ({ page }) => {
		await navigateToAdmin(page, '/media-library')
		// MediaLibrary 可能使用 iframe 或 antd-toolkit 的元件
		await page.waitForSelector(
			'iframe, .media-frame, .media-modal, #power_course .ant-upload',
			{ timeout: 15_000 },
		)
		await expect(page.locator('#power_course')).toBeVisible()
	})

	test('頁面無 JS 崩潰', async ({ page }) => {
		const errors: string[] = []
		page.on('pageerror', (err) => errors.push(err.message))
		await navigateToAdmin(page, '/media-library')
		await page.waitForSelector('#power_course', { timeout: 15_000 })
		expect(errors).toHaveLength(0)
	})
})
