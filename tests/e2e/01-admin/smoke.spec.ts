/**
 * Smoke Test — 驗證 E2E 基礎設施是否正常運作
 *
 * 確認：
 * 1. WordPress 站點可訪問
 * 2. Admin 登入成功（storageState）
 * 3. Power Course 外掛頁面可進入
 */
import { test, expect } from '@playwright/test'
import { URLS } from '../fixtures/test-data'

test.describe('E2E Infrastructure Smoke Test', () => {
	test('WordPress 首頁可訪問', async ({ page }) => {
		await page.goto('/')
		await expect(page).toHaveTitle(/power.?course/i)
	})

	test('WordPress 登入頁可訪問', async ({ page }) => {
		await page.goto('/wp-login.php')
		await expect(page.locator('#loginform')).toBeVisible()
	})
})

test.describe('Admin Smoke Test', () => {
	test.use({ storageState: '.auth/admin.json' })

	test('Admin Dashboard 可進入', async ({ page }) => {
		await page.goto('/wp-admin/')
		// wp-admin body 有 .wp-admin class 即表示成功進入後台
		await expect(page.locator('body.wp-admin')).toBeAttached()
	})

	test('Power Course 管理頁面可進入', async ({ page }) => {
		await page.goto(URLS.adminCourses)
		// 等待 React SPA 載入
		await page.waitForSelector('#power_course', { timeout: 15000 })
		await expect(page.locator('#power_course')).toBeVisible()
	})
})
