/**
 * 學員管理頁測試
 *
 * 驗證學員管理頁面正確渲染
 */

import { test, expect } from '@playwright/test'
import { navigateToAdmin } from '../helpers/admin-page'

test.describe('學員管理', () => {
	test.use({ storageState: '.auth/admin.json' })

	test('頁面正確渲染', async ({ page }) => {
		await navigateToAdmin(page, '/students')
		await page.waitForSelector('.ant-table-wrapper', {
			timeout: 15_000,
		})
		await expect(
			page.locator('.ant-table-wrapper').first(),
		).toBeVisible()
	})

	test('表格標頭存在', async ({ page }) => {
		await navigateToAdmin(page, '/students')
		await page.waitForSelector('.ant-table-thead', { timeout: 15_000 })
		await expect(page.locator('.ant-table-thead')).toBeVisible()
	})

	test('頁面無 console 錯誤', async ({ page }) => {
		const errors: string[] = []
		page.on('console', (msg) => {
			if (msg.type() === 'error') errors.push(msg.text())
		})
		await navigateToAdmin(page, '/students')
		await page.waitForSelector('.ant-table-wrapper', {
			timeout: 15_000,
		})
		const critical = errors.filter(
			(e) => !e.includes('favicon') && !e.includes('404'),
		)
		expect(critical).toHaveLength(0)
	})
})
