/**
 * Issue #226 — 課程學員 TAB「顯示全部」Switch 移到表頭
 *
 * 規格：specs/features/student/課程學員列表批次切換顯示全部課程.feature
 *
 * 驗收標準（與規格對應）：
 * - AC-1 表頭出現 Show all + Switch
 * - AC-2 預設 OFF，row 只看本課程
 * - AC-3 切 ON 全部 row 展開
 * - AC-4 切 OFF 全部 row 收回
 * - AC-5 row 內無 switch（DOM 中應只剩 1 個 switch，在 th 內）
 * - AC-6 重新整理後 switch 重置 OFF
 * - AC-7 /admin/students 全局學員管理頁 th 不顯示 switch
 */

import { test, expect } from '@playwright/test'

import { navigateToAdmin, clickTab, waitForTableLoaded } from '../helpers/admin-page'
import { ApiClient, setupApiFromBrowser } from '../helpers/api-client'

test.describe('Issue #226 — 課程學員 Show all switch', () => {
	test.use({ storageState: '.auth/admin.json' })
	test.setTimeout(90_000)

	let api: ApiClient
	let dispose: () => Promise<void>

	let courseAId: number
	let courseBId: number
	let userAliceId: number
	let userBobId: number

	test.beforeAll(async ({ browser }) => {
		const setup = await setupApiFromBrowser(browser)
		api = setup.api
		dispose = setup.dispose

		// 建立兩堂課程
		courseAId = await api.createCourse('Issue226 課程 A — PHP 基礎課')
		courseBId = await api.createCourse('Issue226 課程 B — React 進階')

		// 建立兩位學員
		userAliceId = await api.ensureUser(
			'issue226_alice',
			'issue226_alice@test.local',
			'test1234!',
		)
		userBobId = await api.ensureUser(
			'issue226_bob',
			'issue226_bob@test.local',
			'test1234!',
		)

		// Alice 同時授權兩堂課；Bob 只授權課程 A
		await api.grantCourseAccess(userAliceId, courseAId, 0)
		await api.grantCourseAccess(userAliceId, courseBId, 0)
		await api.grantCourseAccess(userBobId, courseAId, 0)
	})

	test.afterAll(async () => {
		try {
			for (const uid of [userAliceId, userBobId]) {
				for (const cid of [courseAId, courseBId]) {
					try {
						await api.removeCourseAccess(uid, cid)
					} catch {
						// 已移除或不存在則略過
					}
				}
			}
			await api.deleteCourses([courseAId, courseBId])
		} catch {
			// 清理失敗不影響測試結果
		} finally {
			await dispose()
		}
	})

	/**
	 * 走到課程學員 TAB
	 */
	async function gotoCourseStudentsTab(
		page: import('@playwright/test').Page,
		courseId: number,
	) {
		await navigateToAdmin(page, `/courses/edit/${courseId}`)
		await page.waitForSelector('.ant-tabs-tab', { timeout: 15_000 })
		await clickTab(page, '學員管理')
		await waitForTableLoaded(page)
	}

	test('AC-1 表頭顯示 Show all 文字與 Switch，預設 OFF', async ({ page }) => {
		await gotoCourseStudentsTab(page, courseAId)

		// 找到表頭的 Granted courses cell
		const headerCell = page
			.locator('.ant-table-thead th', { hasText: 'Granted courses' })
			.first()
		await expect(headerCell).toBeVisible()

		// 表頭內必須有 Show all 文字 + switch
		await expect(headerCell.getByText('Show all')).toBeVisible()
		const headerSwitch = headerCell.locator('[role="switch"]')
		await expect(headerSwitch).toHaveCount(1)
		await expect(headerSwitch).toHaveAttribute('aria-checked', 'false')
	})

	test('AC-5 row 內不再有獨立 switch，整張表只有 1 個 switch（在 th 內）', async ({
		page,
	}) => {
		await gotoCourseStudentsTab(page, courseAId)

		const tbodySwitches = page.locator(
			'.ant-table-tbody [role="switch"]',
		)
		await expect(tbodySwitches).toHaveCount(0)

		const theadSwitches = page.locator(
			'.ant-table-thead [role="switch"]',
		)
		await expect(theadSwitches).toHaveCount(1)
	})

	test('AC-3 切 ON → Alice row 同時顯示課程 A 與課程 B', async ({ page }) => {
		await gotoCourseStudentsTab(page, courseAId)

		const aliceRow = page
			.locator('.ant-table-tbody tr', { hasText: 'issue226_alice' })
			.first()
		await expect(aliceRow).toBeVisible()

		// 預設 OFF：Alice 只看到課程 A
		await expect(aliceRow.getByText('PHP 基礎課')).toBeVisible()
		await expect(aliceRow.getByText('React 進階')).toHaveCount(0)

		// 切到 ON
		const headerSwitch = page
			.locator('.ant-table-thead th', { hasText: 'Granted courses' })
			.locator('[role="switch"]')
		await headerSwitch.click()
		await expect(headerSwitch).toHaveAttribute('aria-checked', 'true')

		// 切換後：Alice 同時顯示兩堂課
		await expect(aliceRow.getByText('PHP 基礎課')).toBeVisible()
		await expect(aliceRow.getByText('React 進階')).toBeVisible()
	})

	test('AC-4 切 ON 再切 OFF → 回到只看本課程', async ({ page }) => {
		await gotoCourseStudentsTab(page, courseAId)

		const headerSwitch = page
			.locator('.ant-table-thead th', { hasText: 'Granted courses' })
			.locator('[role="switch"]')

		// ON
		await headerSwitch.click()
		await expect(headerSwitch).toHaveAttribute('aria-checked', 'true')

		// OFF
		await headerSwitch.click()
		await expect(headerSwitch).toHaveAttribute('aria-checked', 'false')

		const aliceRow = page
			.locator('.ant-table-tbody tr', { hasText: 'issue226_alice' })
			.first()
		await expect(aliceRow.getByText('PHP 基礎課')).toBeVisible()
		await expect(aliceRow.getByText('React 進階')).toHaveCount(0)
	})

	test('AC-6 重新整理頁面後 switch 重置為 OFF', async ({ page }) => {
		await gotoCourseStudentsTab(page, courseAId)

		const headerSwitch = page
			.locator('.ant-table-thead th', { hasText: 'Granted courses' })
			.locator('[role="switch"]')

		// 切到 ON
		await headerSwitch.click()
		await expect(headerSwitch).toHaveAttribute('aria-checked', 'true')

		// 重新整理 + 重新點 TAB（HashRouter）
		await page.reload({ waitUntil: 'domcontentloaded' })
		await page.waitForSelector('.ant-tabs-tab', { timeout: 15_000 })
		await clickTab(page, '學員管理')
		await waitForTableLoaded(page)

		const headerSwitchAfter = page
			.locator('.ant-table-thead th', { hasText: 'Granted courses' })
			.locator('[role="switch"]')
		await expect(headerSwitchAfter).toHaveAttribute('aria-checked', 'false')
	})

	test('AC-7 /admin/students 全局頁的 Granted courses 表頭不顯示 switch', async ({
		page,
	}) => {
		await navigateToAdmin(page, '/students')
		await page.waitForSelector('.ant-table-wrapper', { timeout: 15_000 })
		await waitForTableLoaded(page)

		const grantedHeader = page.locator('.ant-table-thead th', {
			hasText: 'Granted courses',
		})
		// 表頭應該存在（欄位仍要顯示）
		await expect(grantedHeader.first()).toBeVisible()

		// 表頭內不應該有任何 switch
		const headerSwitches = grantedHeader.locator('[role="switch"]')
		await expect(headerSwitches).toHaveCount(0)
	})
})
