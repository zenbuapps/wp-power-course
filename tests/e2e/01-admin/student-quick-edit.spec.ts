/**
 * 學員快速編輯 E2E smoke spec
 *
 * 對應 specs/student-quick-edit/student-quick-edit.feature
 * 涵蓋：
 * 1. 全域學員頁點名字開 Drawer
 * 2. 課程學員 Tab 開出相同 Drawer
 * 3. 修改顯示名稱儲存成功
 * 4. 密碼不一致擋下
 * 5. 列表顯示電話欄位
 */

import { test, expect } from '@playwright/test'

import { navigateToAdmin, waitForTableLoaded, waitForMessage, clickTab } from '../helpers/admin-page'
import { ApiClient, setupApiFromBrowser } from '../helpers/api-client'

test.describe('學員快速編輯', () => {
	test.use({ storageState: '.auth/admin.json' })

	let api: ApiClient
	let dispose: () => Promise<void>
	let studentId: number
	let courseId: number

	test.beforeAll(async ({ browser }) => {
		const setup = await setupApiFromBrowser(browser)
		api = setup.api
		dispose = setup.dispose

		// 建立測試學員
		studentId = await api.ensureUser(
			'e2e-student-quickedit',
			'e2e-student-quickedit@test.local',
			'Test1234!',
			['subscriber'],
		)

		// 植入帳單電話與顯示名稱（透過 Power Course 學員更新端點）
		await api.pcPostForm(`users/${studentId}`, {
			billing_phone: '0912345678',
			display_name: 'E2E 學員',
		})

		// 建立測試課程並授權學員，使其出現在課程學員 Tab
		courseId = await api.createCourse('E2E 快速編輯測試課程')
		await api.grantCourseAccess(studentId, courseId)
	})

	test.afterAll(async () => {
		await dispose()
	})

	// ── Smoke 01：全域學員頁點名字開 Drawer ─────────────────────────────────
	// 對應 Rule: 透過點擊學員開啟共用的編輯 Drawer
	// Scenario: 從全域學員管理點擊學員開啟 Drawer
	test('全域學員頁點名字應開啟 StudentEditDrawer', async ({ page }) => {
		await navigateToAdmin(page, '/students')
		await waitForTableLoaded(page)

		// 找到該學員的名字儲存格（UserName 元件渲染為 <p>，包含 #id）
		const studentCell = page.locator('.ant-table-row').filter({
			hasText: `#${studentId}`,
		})
		await expect(studentCell).toBeVisible({ timeout: 15_000 })

		// 點擊名字欄位（p 標籤可點擊，包含顯示名稱或 id）
		await studentCell.locator('p').first().click()

		// 驗證 Drawer 開啟（Ant Design Drawer）
		await expect(page.locator('.ant-drawer')).toBeVisible({ timeout: 10_000 })

		// 驗證 Drawer 內含該學員的 Email 或 ID
		const drawerContent = page.locator('.ant-drawer-body')
		await expect(
			drawerContent.getByText(new RegExp(`#${studentId}|e2e-student-quickedit@test.local`)),
		).toBeVisible({ timeout: 10_000 })
	})

	// ── Smoke 02：課程學員 Tab 開出相同 Drawer ─────────────────────────────
	// 對應 Rule: 透過點擊學員開啟共用的編輯 Drawer
	// Scenario: 從課程編輯頁學員 Tab 點擊學員開啟相同的 Drawer
	test('課程學員 Tab 點名字應開啟相同 StudentEditDrawer', async ({ page }) => {
		await navigateToAdmin(page, `/courses/edit/${courseId}`)

		// 等待課程編輯頁載入後點擊學員 Tab
		await expect(page.locator('[role="tab"]').first()).toBeVisible({ timeout: 15_000 })
		await clickTab(page, '課程學員')
		await waitForTableLoaded(page)

		// 找到學員名字儲存格
		const studentCell = page.locator('.ant-table-row').filter({
			hasText: `#${studentId}`,
		})
		await expect(studentCell).toBeVisible({ timeout: 15_000 })

		await studentCell.locator('p').first().click()

		// 驗證 Drawer 開啟
		await expect(page.locator('.ant-drawer')).toBeVisible({ timeout: 10_000 })

		// Drawer 標題應為「編輯學員」
		await expect(
			page.locator('.ant-drawer-title').getByText(/編輯學員|Edit student/),
		).toBeVisible({ timeout: 10_000 })
	})

	// ── Smoke 03：修改顯示名稱儲存成功 ────────────────────────────────────
	// 對應 Rule: 修改基本資料並儲存
	// Scenario: 管理員修改顯示名稱成功
	test('在 Drawer 修改顯示名稱後儲存應顯示修改成功', async ({ page }) => {
		await navigateToAdmin(page, '/students')
		await waitForTableLoaded(page)

		// 開啟 Drawer
		const studentCell = page.locator('.ant-table-row').filter({
			hasText: `#${studentId}`,
		})
		await expect(studentCell).toBeVisible({ timeout: 15_000 })
		await studentCell.locator('p').first().click()

		const drawer = page.locator('.ant-drawer')
		await expect(drawer).toBeVisible({ timeout: 10_000 })

		// 找到「顯示名稱」欄位（Form item label 下方的 input）
		const displayNameInput = drawer.locator('.ant-form-item').filter({
			hasText: /顯示名稱|Display name/,
		}).locator('input')
		await expect(displayNameInput).toBeVisible({ timeout: 10_000 })

		// 清空並填入新顯示名稱
		await displayNameInput.click({ clickCount: 3 })
		await displayNameInput.fill('E2E 學員更新')

		// 點擊儲存按鈕
		await drawer.getByRole('button', { name: /儲存|Save/ }).click()

		// 驗證成功訊息「修改成功」
		await expect(
			page.locator('.ant-message-success, .ant-message').filter({ hasText: /修改成功|Modified successfully/ }),
		).toBeVisible({ timeout: 10_000 })
	})

	// ── Smoke 04：密碼不一致擋下 ──────────────────────────────────────────
	// 對應 Rule: 設定新密碼
	// Scenario: 兩次密碼輸入不一致時阻擋儲存
	test('新密碼與確認密碼不一致時應顯示驗證錯誤且不送出', async ({ page }) => {
		await navigateToAdmin(page, '/students')
		await waitForTableLoaded(page)

		// 開啟 Drawer
		const studentCell = page.locator('.ant-table-row').filter({
			hasText: `#${studentId}`,
		})
		await expect(studentCell).toBeVisible({ timeout: 15_000 })
		await studentCell.locator('p').first().click()

		const drawer = page.locator('.ant-drawer')
		await expect(drawer).toBeVisible({ timeout: 10_000 })

		// 填入不同的新密碼與確認密碼
		const newPasswordInput = drawer.locator('.ant-form-item').filter({
			hasText: /新密碼|New password/,
		}).locator('input').first()
		const confirmPasswordInput = drawer.locator('.ant-form-item').filter({
			hasText: /確認新密碼|Confirm new password/,
		}).locator('input').first()

		await expect(newPasswordInput).toBeVisible({ timeout: 10_000 })
		await newPasswordInput.fill('NewPass123!')
		await confirmPasswordInput.fill('DifferentPass456!')

		// 點擊儲存
		await drawer.getByRole('button', { name: /儲存|Save/ }).click()

		// 應顯示密碼不一致錯誤
		await expect(
			drawer.getByText(/兩次輸入的密碼不一致|Passwords do not match/),
		).toBeVisible({ timeout: 10_000 })

		// 不應顯示成功訊息
		await expect(
			page.locator('.ant-message-success').filter({ hasText: /修改成功/ }),
		).not.toBeVisible({ timeout: 3_000 })
	})

	// ── Smoke 05：列表顯示電話欄位 ────────────────────────────────────────
	// 對應 Rule: 學員列表顯示電話欄位
	// Scenario: 全域學員列表顯示電話欄位
	test('全域學員列表應顯示電話欄位與帳單電話值', async ({ page }) => {
		await navigateToAdmin(page, '/students')
		await waitForTableLoaded(page)

		// 驗證「電話」欄位表頭存在
		await expect(page.getByText('電話')).toBeVisible({ timeout: 10_000 })

		// 驗證該學員的電話號碼顯示於列表
		const studentRow = page.locator('.ant-table-row').filter({
			hasText: `#${studentId}`,
		})
		await expect(studentRow).toBeVisible({ timeout: 15_000 })
		await expect(studentRow.getByText('0912345678')).toBeVisible({ timeout: 10_000 })
	})
})
