/**
 * 講師管理頁測試
 *
 * 驗證講師管理頁面正確渲染
 */

import { test, expect } from '@playwright/test'
import { navigateToAdmin, waitForTableLoaded } from '../helpers/admin-page'

test.describe('講師管理', () => {
	test.use({ storageState: '.auth/admin.json' })

	test('頁面正確渲染', async ({ page }) => {
		await navigateToAdmin(page, '/teachers')
		await waitForTableLoaded(page)
		await expect(page.locator('.ant-table')).toBeVisible()
	})

	test('新增講師按鈕存在', async ({ page }) => {
		await navigateToAdmin(page, '/teachers')
		await waitForTableLoaded(page)
		const addBtn = page.locator('.anticon-plus').first()
		await expect(addBtn).toBeVisible()
	})

	test('頁面無 console 錯誤', async ({ page }) => {
		const errors: string[] = []
		page.on('console', (msg) => {
			if (msg.type() === 'error') errors.push(msg.text())
		})
		await navigateToAdmin(page, '/teachers')
		await waitForTableLoaded(page)
		const critical = errors.filter(
			(e) => !e.includes('favicon') && !e.includes('404'),
		)
		expect(critical).toHaveLength(0)
	})
})
