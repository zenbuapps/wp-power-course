/**
 * 章節作業管理測試（Issue #131 第一波 P7）
 *
 * 對應規格：specs/ui/章節作業管理.md、specs/features/assignment/{建立作業,更新作業,排序作業,刪除作業}.feature
 * 對應計劃：specs/plan/issue-131-assignment-grading/implementation-plan.md §9 P7
 *
 * 涵蓋範圍（UI 規格「關鍵元素」逐項斷言）：
 * 1. 章節編輯區新增作業（標題 / 允許檔案 / 上限 / 截止日），並確認表單其餘關鍵欄位存在
 *    （作業說明、允許文字繳交、文字必填、檔案必填、所屬章節）
 * 2. 作業列表顯示題型摘要（文字 / 檔案 / 必填 / 附件上限）與截止時間
 * 3. 編輯作業標題
 * 4. 排序作業（以 REST 呼叫 `POST assignments/sort` 改序後，重新載入驗證畫面順序；
 *    不測試拖拉手勢本身 — 拖拉互動屬 UI 函式庫細節，畫面順序才是使用者看得到的行為）
 * 5. 刪除作業時確認框提示「學員繳交紀錄會保留」
 *
 * 資料準備策略：
 * - 課程 / 章節 / 作業一律以 REST 建立（S-RED-E2E 硬性規則 1）
 * - 每個測試使用獨立章節（beforeAll 一次建立 5 個章節），彼此不共用狀態，
 *   故本檔**不**使用 test.describe.serial() —— 章節作業管理整個功能目前完全不存在，
 *   若用 serial 讓後面的 test 依賴前一個 test 的 UI 操作結果，
 *   第一個 test 紅燈後其餘會被 Playwright 標記為 did not run，
 *   看不到「刪除確認框文案」「排序」等其餘功能各自的真實紅燈原因（P4-R2 已踩過這個坑）。
 *   獨立章節 + 各自 REST 灌資料，能讓每個 test 都跑出自己的真實失敗原因。
 *
 * 預期結果（Green 完成前，本檔全數失敗，且失敗原因必須是「功能不存在」——
 * 找不到「作業管理」區塊 / 「新增作業」按鈕 / 作業列表項目等，不是登入或資料準備失敗）：
 * 全部 5 個 test 皆紅。
 */
import { test, expect } from '@playwright/test'

import {
	navigateToAdmin,
	waitForFormLoaded,
	clickTab,
	confirmModal,
	confirmPopconfirm,
} from '../helpers/admin-page'
import { setupApiFromBrowser, type ApiClient } from '../helpers/api-client'

test.setTimeout(60_000)

test.describe('章節作業管理（Issue #131 P7）', () => {
	// 本機站台實測：一次「導航 → 等表單 → 切 Tab → 點章節 → 等作業列表載入完成」
	// 的冷啟動就要 50–60 秒，排序那支還要做兩輪（改序後重新載入再驗一次）。
	// 預設 90 秒會讓它剛好卡在邊緣：第一次紅、retry 綠，看起來像 flaky，
	// 實際上是時間不夠而不是功能不穩。拉高上限不改動任何斷言。
	test.beforeEach(() => {
		test.setTimeout(180_000)
	})

	test.use({ storageState: '.auth/admin.json' })

	let adminApi: ApiClient
	let disposeAdmin: () => Promise<void>

	let courseId: number
	// 7 個獨立章節，各自對應一個測試情境，彼此互不干擾
	// （改章節情境需要「來源」「目的」兩個獨立章節，故額外多 2 個）
	let chapterCreateId: number
	let chapterSummaryId: number
	let chapterEditId: number
	let chapterSortId: number
	let chapterDeleteId: number
	let chapterReassignFromId: number
	let chapterReassignToId: number

	test.beforeAll(async ({ browser }) => {
		// beforeAll/afterAll 有獨立於 test.setTimeout(60_000) 的預設 30s hook timeout
		// （S-RED-E2E 硬性規則 2：hook 的 timeout 要在 hook 內另外設定）；
		// 本 hook 依序建立 1 門課程 + 7 個章節 + 排序 API，7 章節版本實測會逼近甚至超過 30s。
		test.setTimeout(90_000)

		const setup = await setupApiFromBrowser(browser)
		adminApi = setup.api
		disposeAdmin = setup.dispose

		const created = await adminApi.createCourseWithChapters(
			'E2E 章節作業管理課程',
			'0',
			[
				{ name: 'E2E作業章節-新增', slug: 'e2e-assign-chapter-create' },
				{ name: 'E2E作業章節-摘要', slug: 'e2e-assign-chapter-summary' },
				{ name: 'E2E作業章節-編輯', slug: 'e2e-assign-chapter-edit' },
				{ name: 'E2E作業章節-排序', slug: 'e2e-assign-chapter-sort' },
				{ name: 'E2E作業章節-刪除', slug: 'e2e-assign-chapter-delete' },
				{ name: 'E2E作業章節-改章節-來源', slug: 'e2e-assign-chapter-reassign-from' },
				{ name: 'E2E作業章節-改章節-目的', slug: 'e2e-assign-chapter-reassign-to' },
			],
			'e2e-assignment-chapter-manage-course',
		)
		courseId = created.courseId
		;[
			chapterCreateId,
			chapterSummaryId,
			chapterEditId,
			chapterSortId,
			chapterDeleteId,
			chapterReassignFromId,
			chapterReassignToId,
		] = created.chapterIds
	})

	test.afterAll(async () => {
		if (courseId && adminApi) {
			await adminApi.deleteCourses([courseId])
		}
		if (disposeAdmin) {
			await disposeAdmin()
		}
	})

	/**
	 * 導航到課程編輯頁的章節管理 Tab，點擊指定章節名稱以選取該章節，
	 * 回傳「作業管理」區塊的 locator（介面契約：wrapper class `.pc-chapter-assignments`，
	 * 比照既有 `.pc-sortable-chapters` 的區塊 class 命名慣例）。
	 */
	async function openChapterAssignments(
		page: import('@playwright/test').Page,
		chapterName: string,
	) {
		await navigateToAdmin(page, `/courses/edit/${courseId}`)
		await waitForFormLoaded(page)
		await clickTab(page, '章節管理')

		// ChapterName 元件以 <p> 顯示章節名稱，點擊即選取該章節（見
		// js/src/components/course/ChapterName/index.tsx），右側會渲染 ChapterEdit。
		// 章節樹是切到 Tab 後才打 REST 取得，站台忙碌時可能晚於 actionTimeout 才渲染，
		// 先等它可見再點，避免「找不到章節」的環境性假紅。
		const chapterNode = page.getByText(chapterName, { exact: true })
		await expect(
			chapterNode,
			`章節管理 Tab 應列出章節「${chapterName}」`,
		).toBeVisible({ timeout: 60_000 })
		await chapterNode.click()

		const section = page.locator('.pc-chapter-assignments')
		await expect(
			section,
			'章節編輯區應顯示「作業管理」區塊（.pc-chapter-assignments），但目前找不到——功能尚未實作',
		).toBeVisible({ timeout: 15_000 })
		await waitForAssignmentListLoaded(section)
		return section
	}

	/**
	 * 等作業列表結束載入
	 *
	 * AssignmentList 在 isLoading 期間渲染 <Skeleton>，容器 .pc-chapter-assignments
	 * 一掛載就可見，因此「容器可見」不等於「列表已有資料」。站台忙碌時取作業的 REST
	 * 可能慢於單一斷言的 timeout，直接斷言標題會與 fetch 賽跑而假紅。
	 *
	 * @param section 作業管理區塊 locator
	 */
	async function waitForAssignmentListLoaded(
		section: import('@playwright/test').Locator,
	) {
		await expect(
			section.locator('.ant-skeleton'),
			'作業列表載入過久（Skeleton 未消失）',
		).toHaveCount(0, { timeout: 60_000 })
	}

	// ─────────────────────────────────────────────
	// 1. 新增作業（標題、允許檔案、上限、截止日）+ 表單關鍵欄位存在
	// ─────────────────────────────────────────────

	test('新增作業（標題、允許檔案、上限、截止日），表單關鍵欄位齊全 @happy @smoke', async ({
		page,
	}) => {
		const section = await openChapterAssignments(page, 'E2E作業章節-新增')

		const addBtn = section.getByRole('button', { name: '新增作業' })
		await expect(
			addBtn,
			'「作業管理」區塊內應有「新增作業」按鈕',
		).toBeVisible({ timeout: 10_000 })
		await addBtn.click()

		const modal = page.locator('.ant-modal')
		await expect(modal, '點擊「新增作業」應開啟表單 Modal').toBeVisible({
			timeout: 10_000,
		})
		await expect(modal.getByText('新增作業', { exact: true })).toBeVisible()

		// UI 規格「新增 / 編輯作業表單」關鍵欄位逐項存在斷言
		// （即使本測試流程只實際填寫標題/允許檔案/上限/截止日，其餘欄位仍需確認渲染）
		await expect(modal.getByText('作業標題', { exact: true })).toBeVisible()
		await expect(modal.getByText('作業說明', { exact: true })).toBeVisible()
		await expect(
			modal.getByText('允許文字繳交', { exact: true }),
		).toBeVisible()
		await expect(modal.getByText('文字必填', { exact: true })).toBeVisible()
		await expect(
			modal.getByText('允許檔案繳交', { exact: true }),
		).toBeVisible()
		await expect(modal.getByText('檔案必填', { exact: true })).toBeVisible()
		await expect(
			modal.getByText('附件數量上限', { exact: true }),
		).toBeVisible()
		await expect(modal.getByText('截止時間', { exact: true })).toBeVisible()
		await expect(modal.getByText('所屬章節', { exact: true })).toBeVisible()

		// 填寫：標題、允許檔案繳交、附件數量上限、截止日
		await modal.locator('#title').fill('E2E作業-新增')
		await modal.locator('#allow_file').click() // Switch

		const maxFilesInput = modal.locator('#max_files')
		await maxFilesInput.fill('')
		await maxFilesInput.fill('3')

		// 截止時間：DatePicker 輸入一個未來日期（站台時區顯示，見 UI 規格）
		await modal.locator('#due_at').fill('2027-06-15')
		await page.keyboard.press('Enter')

		const createResponse = page.waitForResponse(
			(resp) =>
				resp.url().includes('/wp-json/power-course/assignments') &&
				resp.request().method() === 'POST' &&
				resp.status() < 400,
			{ timeout: 15_000 },
		)
		await confirmModal(page)
		await createResponse

		await expect(modal, '送出成功後 Modal 應關閉').toBeHidden({
			timeout: 10_000,
		})
		// POST 已 await 過，這裡等的是列表 refetch 回來——站台忙碌時比建立本身還久
		await expect(
			section.getByText('E2E作業-新增', { exact: true }),
			'新增成功後作業列表應顯示新作業標題',
		).toBeVisible({ timeout: 30_000 })
	})

	// ─────────────────────────────────────────────
	// 2. 作業列表顯示題型摘要與截止時間
	// ─────────────────────────────────────────────

	test('作業列表顯示題型摘要（文字/檔案/必填/附件上限）與截止時間 @happy', async ({
		page,
	}) => {
		const dueAt = Math.floor(
			new Date('2027-08-20T12:00:00+08:00').getTime() / 1000,
		)
		const created = await adminApi.pcPost<{ id: number }>('assignments', {
			chapter_id: chapterSummaryId,
			title: 'E2E作業-摘要',
			allow_text: 'yes',
			required_text: 'yes',
			allow_file: 'yes',
			required_file: 'no',
			max_files: 5,
			due_at: dueAt,
		})
		expect(
			created.status,
			`REST 建立測試作業失敗: ${JSON.stringify(created.data)}`,
		).toBeLessThan(400)

		const section = await openChapterAssignments(page, 'E2E作業章節-摘要')
		const row = section.filter({ hasText: 'E2E作業-摘要' })
		await expect(row, '作業列表應顯示該作業').toBeVisible({
			timeout: 10_000,
		})

		// 題型摘要：文字必填（required_text=yes）、檔案非必填（required_file=no）、附件上限
		await expect(row.getByText('文字（必填）', { exact: true })).toBeVisible()
		await expect(row.getByText('檔案', { exact: true })).toBeVisible()
		await expect(row.getByText('上限 5 份', { exact: true })).toBeVisible()

		// 截止時間：以站台時區顯示，斷言日期子字串避免時分秒格式差異造成偽陽性
		await expect(row.getByText(/2027-08-20/)).toBeVisible()
	})

	// ─────────────────────────────────────────────
	// 3. 編輯作業標題
	// ─────────────────────────────────────────────

	test('編輯作業標題 @happy', async ({ page }) => {
		const created = await adminApi.pcPost<{ id: number }>('assignments', {
			chapter_id: chapterEditId,
			title: 'E2E作業-編輯前',
			allow_text: 'yes',
		})
		expect(
			created.status,
			`REST 建立測試作業失敗: ${JSON.stringify(created.data)}`,
		).toBeLessThan(400)
		const assignmentId = (created.data as { id: number }).id

		const section = await openChapterAssignments(page, 'E2E作業章節-編輯')
		const row = section.filter({ hasText: 'E2E作業-編輯前' })
		await expect(row).toBeVisible({ timeout: 10_000 })

		const editBtn = row.getByRole('button', { name: '編輯作業' })
		await expect(
			editBtn,
			'作業列表每一列應有「編輯作業」按鈕（Tooltip title）',
		).toBeVisible({ timeout: 10_000 })
		await editBtn.click()

		const modal = page.locator('.ant-modal')
		await expect(modal.getByText('編輯作業', { exact: true })).toBeVisible({
			timeout: 10_000,
		})
		await expect(modal.locator('#title')).toHaveValue('E2E作業-編輯前')

		await modal.locator('#title').fill('')
		await modal.locator('#title').fill('E2E作業-編輯後')

		const updateResponse = page.waitForResponse(
			(resp) =>
				resp
					.url()
					.includes(`/wp-json/power-course/assignments/${assignmentId}`) &&
				['PUT', 'POST', 'PATCH'].includes(resp.request().method()) &&
				resp.status() < 400,
			{ timeout: 15_000 },
		)
		await confirmModal(page)
		await updateResponse

		await expect(
			section.getByText('E2E作業-編輯後', { exact: true }),
			'更新成功後列表應顯示新標題',
		).toBeVisible({ timeout: 10_000 })
		await expect(
			section.getByText('E2E作業-編輯前', { exact: true }),
		).toHaveCount(0)
	})

	// ─────────────────────────────────────────────
	// 4. 排序作業（以 API 改序後驗證畫面順序）
	// ─────────────────────────────────────────────

	test('排序作業：以 API 改序後畫面順序隨之更新 @happy', async ({ page }) => {
		const a = await adminApi.pcPost<{ id: number }>('assignments', {
			chapter_id: chapterSortId,
			title: 'E2E作業-排序A',
			allow_text: 'yes',
			menu_order: 0,
		})
		const b = await adminApi.pcPost<{ id: number }>('assignments', {
			chapter_id: chapterSortId,
			title: 'E2E作業-排序B',
			allow_text: 'yes',
			menu_order: 1,
		})
		expect(a.status).toBeLessThan(400)
		expect(b.status).toBeLessThan(400)
		const idA = (a.data as { id: number }).id
		const idB = (b.data as { id: number }).id

		// 初始順序應為 A → B（menu_order 由建立時指定）
		const section = await openChapterAssignments(page, 'E2E作業章節-排序')
		await expect(section.getByText('E2E作業-排序A')).toBeVisible({
			timeout: 10_000,
		})

		const initialTitles = await section
			.locator('.pc-assignment-list-item')
			.allTextContents()
		expect(
			initialTitles.findIndex((t) => t.includes('排序A')) <
				initialTitles.findIndex((t) => t.includes('排序B')),
			`初始順序應為 A 在前、B 在後，實際: ${JSON.stringify(initialTitles)}`,
		).toBe(true)

		// 呼叫排序 API，將 B 排到 A 之前
		const sortResp = await adminApi.pcPost('assignments/sort', {
			chapter_id: chapterSortId,
			ids: [idB, idA],
		})
		expect(
			sortResp.status,
			`排序 API 失敗: ${JSON.stringify(sortResp.data)}`,
		).toBeLessThan(400)

		// 重新載入頁面驗證畫面順序已反映新的 menu_order
		await page.reload({ waitUntil: 'domcontentloaded' })
		await waitForFormLoaded(page)
		await clickTab(page, '章節管理')
		await page.getByText('E2E作業章節-排序', { exact: true }).click()

		const reorderedSection = page.locator('.pc-chapter-assignments')
		await expect(reorderedSection).toBeVisible({ timeout: 15_000 })
		await waitForAssignmentListLoaded(reorderedSection)
		await expect(reorderedSection.getByText('E2E作業-排序B')).toBeVisible({
			timeout: 10_000,
		})
		const reorderedTitles = await reorderedSection
			.locator('.pc-assignment-list-item')
			.allTextContents()
		expect(
			reorderedTitles.findIndex((t) => t.includes('排序B')) <
				reorderedTitles.findIndex((t) => t.includes('排序A')),
			`改序後應為 B 在前、A 在後，實際: ${JSON.stringify(reorderedTitles)}`,
		).toBe(true)
	})

	// ─────────────────────────────────────────────
	// 5. 刪除作業時確認框提示「學員繳交紀錄會保留」
	// ─────────────────────────────────────────────

	test('刪除作業時確認框提示「學員繳交紀錄會保留」 @happy @security', async ({
		page,
	}) => {
		const created = await adminApi.pcPost<{ id: number }>('assignments', {
			chapter_id: chapterDeleteId,
			title: 'E2E作業-刪除',
			allow_text: 'yes',
		})
		expect(created.status).toBeLessThan(400)
		const assignmentId = (created.data as { id: number }).id

		const section = await openChapterAssignments(page, 'E2E作業章節-刪除')
		const row = section.filter({ hasText: 'E2E作業-刪除' })
		await expect(row).toBeVisible({ timeout: 10_000 })

		const deleteBtn = row.getByRole('button', { name: '刪除作業' })
		await expect(
			deleteBtn,
			'作業列表每一列應有「刪除作業」按鈕（Tooltip title）',
		).toBeVisible({ timeout: 10_000 })
		await deleteBtn.click()

		const popconfirm = page.locator('.ant-popconfirm')
		await expect(popconfirm, '刪除應先彈出確認框').toBeVisible({
			timeout: 10_000,
		})
		await expect(
			popconfirm.getByText('學員繳交紀錄會保留'),
			'確認框文案必須提示「學員繳交紀錄會保留」',
		).toBeVisible()

		const deleteResponse = page.waitForResponse(
			(resp) =>
				resp
					.url()
					.includes(`/wp-json/power-course/assignments/${assignmentId}`) &&
				resp.request().method() === 'DELETE' &&
				resp.status() < 400,
			{ timeout: 15_000 },
		)
		await confirmPopconfirm(page)
		await deleteResponse

		await expect(
			section.getByText('E2E作業-刪除', { exact: true }),
			'刪除成功後列表不應再顯示該作業',
		).toHaveCount(0, { timeout: 10_000 })
	})

	// ─────────────────────────────────────────────
	// 6. 編輯作業時可將作業改掛到其他章節（所屬章節，UI 規格關鍵元素）
	// ─────────────────────────────────────────────

	test('編輯作業時可將作業改掛到其他章節（所屬章節） @happy', async ({
		page,
	}) => {
		const created = await adminApi.pcPost<{ id: number }>('assignments', {
			chapter_id: chapterReassignFromId,
			title: 'E2E作業-改章節',
			allow_text: 'yes',
		})
		expect(
			created.status,
			`REST 建立測試作業失敗: ${JSON.stringify(created.data)}`,
		).toBeLessThan(400)
		const assignmentId = (created.data as { id: number }).id

		const section = await openChapterAssignments(page, 'E2E作業章節-改章節-來源')
		const row = section.filter({ hasText: 'E2E作業-改章節' })
		await expect(row).toBeVisible({ timeout: 10_000 })

		const editBtn = row.getByRole('button', { name: '編輯作業' })
		await expect(editBtn).toBeVisible({ timeout: 10_000 })
		await editBtn.click()

		const modal = page.locator('.ant-modal')
		await expect(modal.getByText('編輯作業', { exact: true })).toBeVisible({
			timeout: 10_000,
		})

		// 所屬章節：AntD 對字串 label 會把原文字面值原封不動寫進 <label title="…">，
		// 用 title 屬性精確定位欄位（見 antd FormItemLabel.js），再切換到目的章節
		const chapterSelectItem = modal.locator('.ant-form-item').filter({
			has: page.locator('label[title="所屬章節"]'),
		})
		await expect(
			chapterSelectItem,
			'編輯作業表單應有「所屬章節」欄位，且可切換到其他章節',
		).toBeVisible({ timeout: 10_000 })
		await chapterSelectItem.locator('.ant-select-selector').click()

		const dropdown = page.locator(
			'.ant-select-dropdown:not(.ant-select-dropdown-hidden)',
		)
		await expect(dropdown).toBeVisible({ timeout: 10_000 })
		await dropdown
			.getByText('E2E作業章節-改章節-目的', { exact: true })
			.click()

		const updateResponse = page.waitForResponse(
			(resp) =>
				resp
					.url()
					.includes(`/wp-json/power-course/assignments/${assignmentId}`) &&
				['PUT', 'POST', 'PATCH'].includes(resp.request().method()) &&
				resp.status() < 400,
			{ timeout: 15_000 },
		)
		await confirmModal(page)
		await updateResponse

		// 來源章節不再顯示該作業
		await expect(
			section.getByText('E2E作業-改章節', { exact: true }),
			'改掛到其他章節後，來源章節不應再顯示該作業',
		).toHaveCount(0, { timeout: 10_000 })

		// 目的章節顯示該作業
		const destSection = await openChapterAssignments(
			page,
			'E2E作業章節-改章節-目的',
		)
		await expect(
			destSection.getByText('E2E作業-改章節', { exact: true }),
			'改掛後，目的章節應顯示該作業',
		).toBeVisible({ timeout: 10_000 })

		// 交叉驗證：REST 回應的 chapter_id 應等於目的章節 ID
		const verify = await adminApi.pcGet<{ chapter_id: number }>(
			`assignments/${assignmentId}`,
		)
		expect(verify.status).toBeLessThan(400)
		expect(
			(verify.data as { chapter_id: number }).chapter_id,
			'REST 回應的 chapter_id 應更新為目的章節 ID',
		).toBe(chapterReassignToId)
	})
})
