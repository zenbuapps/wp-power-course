/**
 * 課程列表頁測試
 *
 * 驗證 Admin SPA 的課程列表頁面正確渲染
 */

import { test, expect } from '@playwright/test'
import {
	navigateToAdmin,
	waitForProTableLoaded,
} from '../helpers/admin-page'

test.describe('課程列表頁', () => {
	test.use({ storageState: '.auth/admin.json' })

	test('ProTable 正確渲染', async ({ page }) => {
		await navigateToAdmin(page, '/courses')
		await waitForProTableLoaded(page)
		await expect(page.locator('.ant-table-wrapper')).toBeVisible()
	})

	test('新增課程按鈕存在', async ({ page }) => {
		await navigateToAdmin(page, '/courses')
		await waitForProTableLoaded(page)
		const addBtn = page.locator('.anticon-plus').first()
		await expect(addBtn).toBeVisible()
	})

	test('表格標頭存在', async ({ page }) => {
		await navigateToAdmin(page, '/courses')
		await waitForProTableLoaded(page)
		await expect(page.locator('.ant-table-thead')).toBeVisible()
	})

	test('頁面無 console 錯誤', async ({ page }) => {
		const errors: string[] = []
		page.on('console', (msg) => {
			if (msg.type() === 'error') errors.push(msg.text())
		})
		await navigateToAdmin(page, '/courses')
		await waitForProTableLoaded(page)
		const critical = errors.filter(
			(e) => !e.includes('favicon') && !e.includes('404'),
		)
		expect(critical).toHaveLength(0)
	})
})
