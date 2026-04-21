/**
 * 講師 Edit 頁 E2E smoke
 *
 * 對應 refactor-teacher-management.plan.md 階段 4-3 smoke 驗收。
 * 涵蓋：
 * 1. 從列表點 Edit 按鈕 → 進 /teachers/edit/:id
 * 2. 4 個 Tab（Basic / Orders / Learning / Meta）都能切換
 * 3. is_teacher 守衛：非講師的 user_id 直接開 Edit 頁會被導回列表
 */

import { test, expect } from '@playwright/test'

import { navigateToAdmin, waitForTableLoaded } from '../helpers/admin-page'
import { ApiClient, setupApiFromBrowser } from '../helpers/api-client'

test.describe('講師 Edit 頁', () => {
	test.use({ storageState: '.auth/admin.json' })

	let api: ApiClient
	let dispose: () => Promise<void>
	let teacherId: number
	let nonTeacherId: number

	test.beforeAll(async ({ browser }) => {
		const setup = await setupApiFromBrowser(browser)
		api = setup.api
		dispose = setup.dispose

		// 建立一個 teacher 用戶
		teacherId = await api.ensureUser(
			'e2e-teacher-edit',
			'e2e-teacher-edit@test.local',
			'Test1234!',
			['author'],
		)
		// 設為講師（直接打 add-teachers 端點；註：URL 不含 /v2，由 Power Course namespace 決定）
		await api
			.pcPostForm('users/add-teachers', {
				user_ids: [teacherId],
			})
			.catch(() => {
				// 若已為講師則忽略
			})

		// 建立一個「非講師」用戶，用於驗證 is_teacher 守衛
		nonTeacherId = await api.ensureUser(
			'e2e-non-teacher',
			'e2e-non-teacher@test.local',
			'Test1234!',
			['subscriber'],
		)
	})

	test.afterAll(async () => {
		await dispose()
	})

	// ── 冒煙：列表 Edit 按鈕 → Edit 頁 ────────────────────────────────
	test('從講師列表點 Edit 按鈕可進入 Edit 頁', async ({ page }) => {
		await navigateToAdmin(page, '/teachers')
		await waitForTableLoaded(page)

		// Ant Design Table 列 Edit 按鈕（我們 useColumns 定義為 type="link" + EditOutlined）
		const editButtons = page.locator(
			'.ant-table-row .anticon-edit, .ant-table-row button:has-text("編輯")',
		)
		await expect(editButtons.first()).toBeVisible({ timeout: 10_000 })
		await editButtons.first().click()

		// URL hash 應含 /teachers/edit/
		await page.waitForURL((u) => u.hash.includes('/teachers/edit/'), {
			timeout: 10_000,
		})
		expect(page.url()).toContain('/teachers/edit/')
	})

	// ── 4 個 Tab 都可切換 ──────────────────────────────────────────
	test('Edit 頁四個 Tab 可切換', async ({ page }) => {
		await navigateToAdmin(page, `/teachers/edit/${teacherId}`)

		// 等待 Statistic「講師資料」Heading 出現代表頁面 render 完畢
		await expect(page.getByText(/講師資料|Instructor information/).first()).toBeVisible(
			{ timeout: 15_000 },
		)

		// 4 Tab key 用 Ant Tabs 產生的 role="tab"
		const tabs = page.locator('[role="tab"]')
		await expect(tabs).toHaveCount(4, { timeout: 10_000 })

		// 依序點擊每個 Tab
		for (const label of [
			/基本資料|Basic/i,
			/訂單紀錄|Order records/i,
			/學習紀錄|Learning records/i,
			/Meta|其他欄位/i,
		]) {
			const tab = page.locator('[role="tab"]', { hasText: label })
			await tab.click()
			await expect(tab).toHaveAttribute('aria-selected', 'true')
		}
	})

	// ── is_teacher 守衛：非講師 id 會被導回列表 ───────────────────────
	test('進入非講師 id 的 Edit 頁會被導回 /teachers', async ({ page }) => {
		await navigateToAdmin(page, `/teachers/edit/${nonTeacherId}`)

		// 守衛邏輯 useEffect 載入後觸發：notification.error + list('teachers')
		await page.waitForURL((u) => !u.hash.includes('/edit/'), {
			timeout: 10_000,
		})
		expect(page.url().includes('/teachers/edit/')).toBe(false)
	})
})
