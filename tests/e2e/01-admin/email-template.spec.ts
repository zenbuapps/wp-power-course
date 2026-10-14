/**
 * Email 模板管理測試
 *
 * 驗證 Email 模板列表頁面的 Tab 切換與渲染
 */

import { test, expect, type Page, type Locator } from '@playwright/test'
import {
	navigateToAdmin,
	waitForTableLoaded,
	waitForMessage,
	clickTab,
} from '../helpers/admin-page'
import { setupApiFromBrowser, type ApiClient } from '../helpers/api-client'

test.describe('Email 模板管理', () => {
	test.use({ storageState: '.auth/admin.json' })

	test('Email 模板管理 Tab 正確載入', async ({ page }) => {
		await navigateToAdmin(page, '/emails')
		await waitForTableLoaded(page)
		await expect(page.locator('.ant-tabs')).toBeVisible()
		await expect(page.locator('.ant-table')).toBeVisible()
	})

	test('排程作業 Tab 切換', async ({ page }) => {
		await navigateToAdmin(page, '/emails')
		await waitForTableLoaded(page)
		await clickTab(page, '排程作業')
		await expect(page.locator('.ant-tabs-tabpane-active')).toBeVisible()
	})

	test('新增 Email 模板按鈕存在', async ({ page }) => {
		await navigateToAdmin(page, '/emails')
		await waitForTableLoaded(page)
		const addBtn = page.locator('.anticon-plus').first()
		await expect(addBtn).toBeVisible()
	})
})

/**
 * Email 模板 — 作業 Trigger 選項（Issue #131 P7-R，DEC-11）
 *
 * 對應計劃：specs/plan/issue-131-assignment-grading/implementation-plan.md
 *   §5.2（components/emails/SendCondition/{enum.ts,Condition.tsx}）、§9 P7
 * 對應 UC-0 DEC-11 決定（主窗口貼入原文）：
 *   「Email 模板 UI 補兩個 trigger 選項與作業變數提示；選這兩個 trigger 時
 *    隱藏章節選擇、觸發條件、延遲寄送」
 *
 * 介面契約（Green 必須照做，見 P5-R 回報）：
 * - `TriggerAt` 新增 `ASSIGNMENT_GRADED = 'assignment_graded'`、
 *   `ASSIGNMENT_DUE_SOON = 'assignment_due_soon'`（js/src/components/emails/SendCondition/enum.ts）
 * - `Condition.tsx` 觸發時機 Select 的 options 新增兩個選項，msgid 沿用後端
 *   `AtHelper::LABELS`（P5-G 已寫入 manual.json）：
 *     'When assignment graded' → 「作業批改時」
 *     'Before assignment due'  → 「作業截止前」
 * - 選擇上述任一 trigger 時，隱藏「選擇章節」（label msgid 'Select chapter'）、
 *   「觸發條件」（'Trigger condition'）、「延遲寄送」整組欄位
 *   （現有程式碼此區塊的 wrapper label 是 'After trigger condition is met' →
 *    「觸發條件達成後」，此為 test-creator 依現有欄位命名對應 DEC-11 的「延遲寄送」，
 *    非另外新增欄位）
 * - 「選擇課程」（'Select course'）欄位不受影響，維持顯示
 *   （P5-R 契約：模板 course_ids 非空時只寄該課程，空 = 全部，對作業 trigger 同樣生效）
 *
 * 資料準備（S-RED-E2E 硬性規則 1）：Email 模板以 REST 建立
 * （`adminApi.createEmailTemplate()`，見 api-client.ts 既有 helper），
 * 不透過 UI 點擊「新增 Email」按鈕。
 *
 * 本檔兩個 test 各自獨立建立自己的 Email 模板（不共用狀態、不使用 serial），
 * 使兩個情境（下拉選項＋持久化 / 欄位隱藏規則）各自能顯示真實紅燈原因。
 *
 * 預期結果（Green 完成前，本區塊 2 個 test 皆紅，失敗原因必須是「功能不存在」——
 * 觸發時機下拉選單找不到「作業批改時」/「作業截止前」選項，
 * 而不是登入、nonce、或 Email 模板建立失敗）。
 */
test.describe('Email 模板 — 作業 Trigger 選項（Issue #131 DEC-11，P7）', () => {
	test.use({ storageState: '.auth/admin.json' })
	test.setTimeout(60_000)

	let adminApi: ApiClient
	let disposeAdmin: () => Promise<void>
	const createdEmailIds: number[] = []

	test.beforeAll(async ({ browser }) => {
		const setup = await setupApiFromBrowser(browser)
		adminApi = setup.api
		disposeAdmin = setup.dispose
	})

	test.afterAll(async () => {
		for (const id of createdEmailIds) {
			try {
				await adminApi.deleteEmailTemplate(id)
			} catch {
				/* ignore */
			}
		}
		if (disposeAdmin) {
			await disposeAdmin()
		}
	})

	/**
	 * 「觸發時機」Form.Item 的 locator（AntD 對字串 label 會把原文字面值
	 * 原封不動地寫進 <label title="…">，故用 title 屬性精確比對，
	 * 不受 colon 移除 / 其他欄位 label 前綴重疊影響，見 antd FormItemLabel.js）
	 */
	function triggerFormItem(page: Page): Locator {
		return page.locator('.ant-form-item').filter({
			has: page.locator('label[title="觸發時機"]'),
		})
	}

	/** 開啟「觸發時機」下拉選單，回傳目前顯示中的 dropdown locator */
	async function openTriggerDropdown(page: Page): Promise<Locator> {
		const triggerItem = triggerFormItem(page)
		await expect(
			triggerItem,
			'應有「觸發時機」欄位（Trigger timing Form.Item，label title="觸發時機"）',
		).toBeVisible({ timeout: 15_000 })
		await triggerItem.locator('.ant-select-selector').click()

		const dropdown = page.locator(
			'.ant-select-dropdown:not(.ant-select-dropdown-hidden)',
		)
		await expect(dropdown, '點擊「觸發時機」下拉應開啟選項清單').toBeVisible({
			timeout: 10_000,
		})
		return dropdown
	}

	/**
	 * 等待 easy-email 編輯器 lazy chunk 完成首次渲染。
	 *
	 * js/src/pages/admin/Emails/Edit/EmailEditor/index.tsx 的 CustomEmailEditor
	 * 在 Suspense fallback 顯示純文字「Loading...」；首次渲染完成的同時，
	 * render-prop 會把預設空白內容同步寫回表單的 short_description 欄位
	 * ——儲存全新（空白）Email 模板時，若在此之前點擊儲存，會被既有的
	 * 「Email 內容不合法」防線擋下（與 DEC-11 無關的既有防護，不可誤判為紅燈原因）。
	 */
	async function waitForEmailEditorReady(page: Page): Promise<void> {
		await page
			.getByText('Loading...', { exact: true })
			.waitFor({ state: 'hidden', timeout: 30_000 })
	}

	test('觸發時機下拉應包含「作業批改時」與「作業截止前」兩個作業選項，選擇後儲存並重新載入仍保留 @happy @smoke', async ({
		page,
	}) => {
		const emailId = await adminApi.createEmailTemplate()
		createdEmailIds.push(emailId)

		await navigateToAdmin(page, `/emails/edit/${emailId}`)
		await page.waitForSelector('.ant-form', { timeout: 15_000 })
		await waitForEmailEditorReady(page)

		const dropdown = await openTriggerDropdown(page)
		await expect(
			dropdown.getByText('作業批改時', { exact: true }),
			'觸發時機下拉應包含「作業批改時」選項（msgid: When assignment graded）',
		).toBeVisible({ timeout: 10_000 })
		await expect(
			dropdown.getByText('作業截止前', { exact: true }),
			'觸發時機下拉應包含「作業截止前」選項（msgid: Before assignment due）',
		).toBeVisible({ timeout: 10_000 })

		await dropdown.getByText('作業批改時', { exact: true }).click()
		await expect(
			triggerFormItem(page).locator('.ant-select-selection-item'),
			'選擇後，觸發時機欄位應顯示「作業批改時」',
		).toHaveText('作業批改時')

		// DEC-11 的持久化契約是 POST power-email/emails/{id}，直接斷言該回應：
		// 比起只等 UI 提示，這同時證明伺服器真的收下了 trigger_at
		const savePromise = page.waitForResponse(
			(resp) =>
				resp.url().includes(`/power-email/emails/${emailId}`) &&
				'POST' === resp.request().method(),
			{ timeout: 30_000 },
		)
		await page.getByRole('button', { name: '儲存信件' }).click()
		const saveResp = await savePromise
		expect(
			saveResp.status(),
			'儲存信件的 POST 應成功',
		).toBeLessThan(400)
		await waitForMessage(page, 'success')

		// 重新載入頁面（比照既有 linear-viewing-setting.spec.ts 的做法，
		// 以 navigateToAdmin 重新導航而非 page.reload()，確保 SPA hash route 正確重新掛載）
		await navigateToAdmin(page, `/emails/edit/${emailId}`)
		await page.waitForSelector('.ant-form', { timeout: 15_000 })

		await expect(
			triggerFormItem(page).locator('.ant-select-selection-item'),
			'重新載入頁面後，觸發時機應仍顯示「作業批改時」（存檔已持久化）',
		).toHaveText('作業批改時', { timeout: 15_000 })
	})

	test('選擇「作業截止前」trigger 時，依 DEC-11 隱藏章節選擇、觸發條件、延遲寄送欄位（選擇課程不受影響） @happy', async ({
		page,
	}) => {
		const emailId = await adminApi.createEmailTemplate()
		createdEmailIds.push(emailId)

		await navigateToAdmin(page, `/emails/edit/${emailId}`)
		await page.waitForSelector('.ant-form', { timeout: 15_000 })

		const dropdown = await openTriggerDropdown(page)
		await expect(
			dropdown.getByText('作業截止前', { exact: true }),
			'觸發時機下拉應包含「作業截止前」選項（msgid: Before assignment due）',
		).toBeVisible({ timeout: 10_000 })
		await dropdown.getByText('作業截止前', { exact: true }).click()
		await expect(
			triggerFormItem(page).locator('.ant-select-selection-item'),
		).toHaveText('作業截止前')

		await expect(
			page.locator('label[title="選擇章節"]'),
			'選擇「作業截止前」trigger 後不應顯示「選擇章節」欄位（DEC-11）',
		).toHaveCount(0)
		await expect(
			page.locator('label[title="觸發條件"]'),
			'選擇「作業截止前」trigger 後不應顯示「觸發條件」欄位（DEC-11）',
		).toHaveCount(0)
		await expect(
			page.locator('label[title="觸發條件達成後"]'),
			'選擇「作業截止前」trigger 後不應顯示「觸發條件達成後」（延遲寄送）欄位（DEC-11）',
		).toHaveCount(0)

		await expect(
			page.locator('label[title="選擇課程"]'),
			'「選擇課程」欄位不受 DEC-11 影響，選擇作業 trigger 後仍應顯示',
		).toBeVisible({ timeout: 10_000 })
	})
})
