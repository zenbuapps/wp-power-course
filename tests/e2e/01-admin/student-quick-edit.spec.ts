/**
 * 學員快速編輯 E2E smoke spec
 *
 * 對應 specs/student-quick-edit/student-quick-edit.feature
 * UI 為 antd-toolkit SimpleModal（容器 class `.student-edit-modal`，寬 1280）。
 * 內容對齊 power-shop User Edit 雙欄卡片：
 *   - 左欄：消費數據（6 Statistic）+ 用戶資料 Tabs（基本資料 / 自動填入 / 其他欄位）
 *   - 右欄：聯絡註記 + 購物車 + 最近訂單
 * 「顯示名稱」「密碼」欄位位於「基本資料 / Basic」Tab 內（預設 active）。
 * 預設為唯讀檢視，需先點「編輯用戶 / Edit user」進入編輯模式才能改欄位。
 * 涵蓋：
 * 1. 全域學員頁點名字開 Modal
 * 2. 課程學員 Tab 開出相同 Modal
 * 3. 進入編輯模式修改顯示名稱儲存成功
 * 4. 密碼不一致擋下
 * 5. 列表顯示電話欄位
 * 6. 雙欄卡片結構（消費數據 / 聯絡註記 / 購物車 / 最近訂單）標題可見
 */

import { test, expect } from '@playwright/test'

import { navigateToAdmin, waitForTableLoaded, clickTab } from '../helpers/admin-page'
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

	// ── Smoke 01：全域學員頁點名字開 Modal ──────────────────────────────────
	// 對應 Rule: 透過點擊學員開啟共用的編輯 Modal
	// Scenario: 從全域學員管理點擊學員開啟 Modal
	test('全域學員頁點名字應開啟 StudentEditModal', async ({ page }) => {
		await navigateToAdmin(page, '/students')
		await waitForTableLoaded(page)

		// 找到該學員的名字儲存格（UserName 元件渲染為 <p>，包含 #id）
		const studentCell = page.locator('.ant-table-row').filter({
			hasText: `#${studentId}`,
		})
		await expect(studentCell).toBeVisible({ timeout: 15_000 })

		// 點擊名字欄位（p 標籤可點擊，包含顯示名稱或 id）
		await studentCell.locator('p').first().click()

		// 驗證 Modal 開啟（SimpleModal 容器；opacity 0 時 Playwright 視為 hidden）
		const modal = page.locator('.student-edit-modal')
		await expect(modal).toBeVisible({ timeout: 10_000 })

		// 驗證 Modal 內含該學員的 Email 或 ID
		await expect(
			modal.getByText(new RegExp(`#${studentId}|e2e-student-quickedit@test.local`)),
		).toBeVisible({ timeout: 10_000 })
	})

	// ── Smoke 02：課程學員 Tab 開出相同 Modal ─────────────────────────────
	// 對應 Rule: 透過點擊學員開啟共用的編輯 Modal
	// Scenario: 從課程編輯頁學員 Tab 點擊學員開啟相同的 Modal
	test('課程學員 Tab 點名字應開啟相同 StudentEditModal', async ({ page }) => {
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

		// 驗證 Modal 開啟
		const modal = page.locator('.student-edit-modal')
		await expect(modal).toBeVisible({ timeout: 10_000 })

		// Modal 標題應為「編輯學員」
		await expect(
			modal.getByText(/編輯學員|Edit student/),
		).toBeVisible({ timeout: 10_000 })
	})

	// ── Smoke 03：進入編輯模式修改顯示名稱儲存成功 ────────────────────────
	// 對應 Rule: 修改基本資料並儲存
	// Scenario: 管理員修改顯示名稱成功
	// 註：顯示名稱欄位位於「基本資料 / Basic」Tab（預設 active）
	test('在 Modal 進入編輯模式修改顯示名稱後儲存應顯示修改成功', async ({ page }) => {
		await navigateToAdmin(page, '/students')
		await waitForTableLoaded(page)

		// 開啟 Modal
		const studentCell = page.locator('.ant-table-row').filter({
			hasText: `#${studentId}`,
		})
		await expect(studentCell).toBeVisible({ timeout: 15_000 })
		await studentCell.locator('p').first().click()

		const modal = page.locator('.student-edit-modal')
		await expect(modal).toBeVisible({ timeout: 10_000 })

		// 預設為唯讀檢視，先點「編輯用戶」進入編輯模式
		await modal.getByRole('button', { name: /編輯用戶|Edit user/ }).click()

		// 「基本資料 / Basic」Tab 預設 active，「顯示名稱」列下的 input 應可見
		const displayNameRow = modal.locator('tr').filter({
			hasText: /顯示名稱|Display name/,
		})
		const displayNameInput = displayNameRow.locator('input')
		await expect(displayNameInput).toBeVisible({ timeout: 10_000 })

		// 清空並填入新顯示名稱
		await displayNameInput.click({ clickCount: 3 })
		await displayNameInput.fill('E2E 學員更新')

		// 點擊儲存按鈕（編輯模式 footer）
		await modal.getByRole('button', { name: /儲存|Save/ }).click()

		// 驗證成功訊息「修改成功」
		await expect(
			page.locator('.ant-message-success, .ant-message').filter({ hasText: /修改成功|Modified successfully/ }),
		).toBeVisible({ timeout: 10_000 })
	})

	// ── Smoke 04：密碼不一致擋下 ──────────────────────────────────────────
	// 對應 Rule: 設定新密碼
	// Scenario: 兩次密碼輸入不一致時阻擋儲存
	// 註：密碼欄位位於「基本資料 / Basic」Tab（預設 active），需點「直接修改密碼」展開
	test('新密碼與確認密碼不一致時應顯示驗證錯誤且不送出', async ({ page }) => {
		await navigateToAdmin(page, '/students')
		await waitForTableLoaded(page)

		// 開啟 Modal
		const studentCell = page.locator('.ant-table-row').filter({
			hasText: `#${studentId}`,
		})
		await expect(studentCell).toBeVisible({ timeout: 15_000 })
		await studentCell.locator('p').first().click()

		const modal = page.locator('.student-edit-modal')
		await expect(modal).toBeVisible({ timeout: 10_000 })

		// 進入編輯模式
		await modal.getByRole('button', { name: /編輯用戶|Edit user/ }).click()

		// 點「直接修改密碼」露出新密碼 / 確認密碼欄位
		await modal.getByRole('button', { name: /直接修改密碼|Change password directly/ }).click()

		// 填入不同的新密碼與確認密碼
		// 「新密碼」列：排除「確認新密碼 / Confirm new password」列避免誤匹配
		const newPasswordInput = modal.locator('tr').filter({
			hasText: /新密碼|New password/,
		}).filter({
			hasNotText: /確認|Confirm/,
		}).locator('input').first()
		const confirmPasswordInput = modal.locator('tr').filter({
			hasText: /確認新密碼|Confirm new password/,
		}).locator('input').first()

		await expect(newPasswordInput).toBeVisible({ timeout: 10_000 })
		await newPasswordInput.fill('NewPass123!')
		await confirmPasswordInput.fill('DifferentPass456!')

		// 點擊儲存
		await modal.getByRole('button', { name: /儲存|Save/ }).click()

		// 應顯示密碼不一致錯誤
		await expect(
			modal.getByText(/兩次輸入的密碼不一致|Passwords do not match/),
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

	// ── Smoke 06：雙欄卡片結構標題可見 ────────────────────────────────────
	// 對應「一模一樣」對齊 power-shop User Edit 卡片：左欄消費數據 + 右欄聯絡註記 / 購物車 / 最近訂單
	// Scenario: 開 Modal 後左右兩欄各區塊標題可見
	test('開啟 Modal 後應顯示消費數據與聯絡註記 / 購物車 / 最近訂單標題', async ({ page }) => {
		await navigateToAdmin(page, '/students')
		await waitForTableLoaded(page)

		// 開啟 Modal
		const studentCell = page.locator('.ant-table-row').filter({
			hasText: `#${studentId}`,
		})
		await expect(studentCell).toBeVisible({ timeout: 15_000 })
		await studentCell.locator('p').first().click()

		const modal = page.locator('.student-edit-modal')
		await expect(modal).toBeVisible({ timeout: 10_000 })

		// 左欄：消費數據
		await expect(
			modal.getByText(/消費數據|Consumption data/),
		).toBeVisible({ timeout: 10_000 })

		// 右欄：聯絡註記 / 購物車 / 最近訂單
		await expect(
			modal.getByText(/聯絡註記|Contact notes/),
		).toBeVisible({ timeout: 10_000 })
		await expect(
			modal.getByText(/購物車|Cart/).first(),
		).toBeVisible({ timeout: 10_000 })
		await expect(
			modal.getByText(/最近訂單|Recent orders/),
		).toBeVisible({ timeout: 10_000 })
	})
})
