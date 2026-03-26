/**
 * Bunny 媒體庫頁測試（Mock）
 *
 * 驗證 Bunny CDN 媒體庫頁面在無 API Key 情況下正確渲染
 */

import { test, expect } from '@playwright/test'
import { navigateToAdmin } from '../helpers/admin-page'

test.describe('Bunny 媒體庫', () => {
	test.use({ storageState: '.auth/admin.json' })

	test('頁面載入不崩潰', async ({ page }) => {
		const errors: string[] = []
		page.on('pageerror', (err) => errors.push(err.message))

		await navigateToAdmin(page, '/bunny-media-library')
		// 等待頁面渲染（可能顯示空狀態或設定提示）
		await page.waitForSelector('#power_course', { timeout: 15_000 })
		await expect(page.locator('#power_course')).toBeVisible()

		// 不應有未捕獲的 JS 錯誤
		expect(errors).toHaveLength(0)
	})
})
