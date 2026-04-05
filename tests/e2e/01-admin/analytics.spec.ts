/**
 * 營收分析頁測試
 *
 * 驗證營收分析頁面的篩選器與圖表區域渲染
 */

import { test, expect } from '@playwright/test'
import { navigateToAdmin } from '../helpers/admin-page'

test.describe('營收分析', () => {
	test.use({ storageState: '.auth/admin.json' })

	test('頁面正確渲染', async ({ page }) => {
		await navigateToAdmin(page, '/analytics')
		// 等待載入完成（可能有 Skeleton 過渡）
		await page.waitForSelector(
			'.ant-picker, .ant-select, .ant-skeleton',
			{ timeout: 15_000 },
		)
		await expect(page.locator('#power_course')).toBeVisible()
	})

	test('日期選擇器存在', async ({ page }) => {
		await navigateToAdmin(page, '/analytics')
		await page.waitForSelector('.ant-picker', { timeout: 15_000 })
		await expect(page.locator('.ant-picker').first()).toBeVisible()
	})

	test('頁面無 console 錯誤', async ({ page }) => {
		const errors: string[] = []
		page.on('console', (msg) => {
			if (msg.type() === 'error') errors.push(msg.text())
		})
		await navigateToAdmin(page, '/analytics')
		await page.waitForSelector(
			'.ant-picker, .ant-select, .ant-skeleton',
			{ timeout: 15_000 },
		)
		const critical = errors.filter(
			(e) => !e.includes('favicon') && !e.includes('404'),
		)
		expect(critical).toHaveLength(0)
	})
})
