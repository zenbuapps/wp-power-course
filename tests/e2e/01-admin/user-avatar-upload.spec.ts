/**
 * 用戶頭像上傳元件 E2E 測試（TDD 紅燈階段）
 *
 * 驗證用戶編輯 Drawer 中的頭像上傳已從
 * UserAvatarUpload（Upload picture-circle + ImgCrop）重構為 MediaLibraryModal 選圖器。
 *
 * 測試目前預期失敗（紅燈），待實作完成後才會通過（綠燈）。
 *
 * 測試策略：
 * 1. UI 結構測試：驗證 MediaLibraryModal 選圖器存在，舊元件不存在
 * 2. 不實際操作 WordPress Media Library 內部（原生 WP 介面，操作複雜）
 */

import { test, expect } from '@playwright/test'
import {
	navigateToAdmin,
	waitForTableLoaded,
} from '../helpers/admin-page'
import { ApiClient, setupApiFromBrowser } from '../helpers/api-client'

test.describe('用戶頭像上傳元件', () => {
	test.use({ storageState: '.auth/admin.json' })

	let api: ApiClient
	let dispose: () => Promise<void>

	/** 測試用講師 ID，afterAll 統一清除 */
	let testTeacherId: number

	test.beforeAll(async ({ browser }) => {
		const setup = await setupApiFromBrowser(browser)
		api = setup.api
		dispose = setup.dispose

		// 建立測試用講師（需要先成為 teacher 才能出現在講師列表）
		testTeacherId = await api.ensureUser(
			'e2e-teacher-avatar',
			'e2e-teacher-avatar@test.local',
			'Test1234!',
			['subscriber'],
		)

		// 設定為講師角色（透過 Power Course API）
		await api.pcPostForm('teachers', {
			user_id: testTeacherId,
		}).catch(() => {
			// 若 API 不存在，忽略錯誤（後續測試會視情況 skip）
		})
	})

	test.afterAll(async () => {
		await dispose()
	})

	// ── 冒煙測試 ──────────────────────────────────

	/**
	 * 開啟講師管理頁並點擊編輯，驗證 Drawer 中出現新的 MediaLibraryModal 選圖器
	 */
	test('用戶編輯 Drawer 頭像區域應使用 MediaLibraryModal 選圖器 @smoke', async ({ page }) => {
		await navigateToAdmin(page, '/teachers')
		await waitForTableLoaded(page)

		// 點擊表格中第一位講師的編輯按鈕（或新增按鈕）
		// 優先嘗試點擊已有講師的編輯，若無則點擊新增
		const editBtn = page.locator('.ant-table-tbody tr').first().locator('.anticon-edit, .anticon-user, button').first()
		const addBtn = page.locator('.anticon-plus').first()

		let drawerOpened = false

		// 嘗試點擊現有講師的編輯
		if (await editBtn.isVisible({ timeout: 3_000 }).catch(() => false)) {
			await editBtn.click()
			drawerOpened = true
		} else if (await addBtn.isVisible({ timeout: 3_000 }).catch(() => false)) {
			// 若無現有講師，點擊新增按鈕
			await addBtn.click()
			drawerOpened = true
		}

		if (!drawerOpened) {
			test.skip()
			return
		}

		// 等待 Drawer 出現
		await page.waitForSelector('.ant-drawer-content', { timeout: 10_000 })

		// 預期：新的 MediaLibraryModal 選圖器存在
		// 重構後應顯示 MediaLibraryModal 的觸發按鈕或預覽圖
		const mediaLibraryTrigger = page.locator(
			'.ant-drawer-content [data-testid="media-library-trigger"], ' +
			'.ant-drawer-content .media-library-modal-trigger, ' +
			'.ant-drawer-content .user-avatar-selector',
		)
		await expect(mediaLibraryTrigger).toBeVisible({ timeout: 10_000 })
	})

	// ── UI 結構驗證（舊元件不應存在）─────────────

	test('用戶編輯 Drawer 不應存在 picture-circle 上傳按鈕', async ({ page }) => {
		await navigateToAdmin(page, '/teachers')
		await waitForTableLoaded(page)

		const addBtn = page.locator('.anticon-plus').first()
		if (!await addBtn.isVisible({ timeout: 3_000 }).catch(() => false)) {
			test.skip()
			return
		}

		await addBtn.click()
		await page.waitForSelector('.ant-drawer-content', { timeout: 10_000 })

		// 重構後，舊的 picture-circle 上傳按鈕不應存在於 Drawer 中
		const pictureCircle = page.locator('.ant-drawer-content .ant-upload-select-picture-circle, .ant-drawer-content .ant-upload-list-picture-circle')
		await expect(pictureCircle).not.toBeAttached()
	})

	test('用戶編輯 Drawer 不應存在 antd-img-crop 裁剪 Modal', async ({ page }) => {
		await navigateToAdmin(page, '/teachers')
		await waitForTableLoaded(page)

		const addBtn = page.locator('.anticon-plus').first()
		if (!await addBtn.isVisible({ timeout: 3_000 }).catch(() => false)) {
			test.skip()
			return
		}

		await addBtn.click()
		await page.waitForSelector('.ant-drawer-content', { timeout: 10_000 })

		// 重構後，ImgCrop 相關元件不應出現在 Drawer 頭像區域
		// ImgCrop 的 Dragger/Upload 觸發器在 Drawer 內不應存在
		const imgCropUpload = page.locator('.ant-drawer-content [class*="img-crop"]')
		await expect(imgCropUpload).not.toBeAttached()
	})

	// ── 學員管理頁用戶編輯測試 ────────────────────

	/**
	 * 學員管理頁的用戶資料應顯示頭像
	 * 驗證新的 MediaLibraryModal 儲存後，avatar URL 正確回顯
	 */
	test('學員管理頁點擊編輯後 Drawer 頭像區應顯示 MediaLibraryModal 選圖器', async ({ page }) => {
		await navigateToAdmin(page, '/students')
		await waitForTableLoaded(page)

		// 確認有學員資料可以點擊
		const tableRows = page.locator('.ant-table-tbody tr')
		const rowCount = await tableRows.count()

		if (rowCount === 0) {
			test.skip()
			return
		}

		// 點擊第一列的某個可點擊元素（例如用戶名稱或編輯按鈕）
		const firstRow = tableRows.first()
		const editOrNameCell = firstRow.locator('td').first()

		await editOrNameCell.click({ timeout: 5_000 }).catch(() => {
			// 若點擊失敗，跳過此測試
		})

		// 嘗試等待 Drawer 出現
		const drawerVisible = await page
			.waitForSelector('.ant-drawer-content', { timeout: 5_000 })
			.then(() => true)
			.catch(() => false)

		if (!drawerVisible) {
			test.skip()
			return
		}

		// 預期：MediaLibraryModal 選圖器存在於 Drawer 中
		const mediaLibraryTrigger = page.locator(
			'.ant-drawer-content [data-testid="media-library-trigger"], ' +
			'.ant-drawer-content .media-library-modal-trigger, ' +
			'.ant-drawer-content .user-avatar-selector',
		)
		await expect(mediaLibraryTrigger).toBeVisible({ timeout: 10_000 })
	})

	// ── 邊緣案例 ──────────────────────────────────

	test('用戶無頭像時，選圖器應顯示佔位符或新增按鈕', async ({ page }) => {
		await navigateToAdmin(page, '/teachers')
		await waitForTableLoaded(page)

		// 點擊新增講師按鈕（新用戶必定無頭像）
		const addBtn = page.locator('.anticon-plus').first()
		if (!await addBtn.isVisible({ timeout: 3_000 }).catch(() => false)) {
			test.skip()
			return
		}

		await addBtn.click()
		await page.waitForSelector('.ant-drawer-content', { timeout: 10_000 })

		// 預期：無頭像時應顯示新增提示或佔位符
		// 新的 MediaLibraryModal 選圖器在無圖片時應顯示可點擊的觸發器
		const emptyAvatarTrigger = page.locator(
			'.ant-drawer-content [data-testid="media-library-trigger"], ' +
			'.ant-drawer-content .user-avatar-placeholder, ' +
			'.ant-drawer-content .user-avatar-add',
		)
		await expect(emptyAvatarTrigger).toBeVisible({ timeout: 10_000 })
	})
})
