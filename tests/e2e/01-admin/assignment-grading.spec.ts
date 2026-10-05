/**
 * 作業批改頁測試（Issue #131 第一波 P7）
 *
 * 對應規格：specs/ui/作業批改頁.md
 * 對應 feature：specs/features/assignment/{查詢有繳交紀錄的課程,查詢作業批改統計,
 *   查詢作業繳交列表,批改作業,查詢作業學員背景資料,下載作業附件,
 *   匯出單一作業繳交CSV,匯出批改用CSV}.feature
 * 對應計劃：specs/plan/issue-131-assignment-grading/implementation-plan.md §9 P7
 * 對應 API：specs/api/api.yml（tag Assignments，管理端 `/assignment-submissions*`、
 *   `/assignments/{id}/submissions/export`）
 *
 * 資料準備（S-RED-E2E 硬性規則 1：一律走 REST，不走 UI）：
 * - 課程 + 章節 + 作業：管理員 REST 建立
 * - 3 位學員（Powerhouse V2 API，本站 /wp/v2/users 被 mu-plugin 移除，見
 *   tests/e2e/helpers/api-client.ts 的 ensureUserViaPowerhouse()）
 * - 學員以自己的 nonce 呼叫 `POST my/assignments/{id}/submit` 繳交（不可用管理員身分代繳）：
 *   - Student A：文字 + 1 張附件，維持 pending（用於列表欄位 / 詳情 / 背景資料 / 篩選「待批改」）
 *   - Student B：純文字，繳交後管理員 REST 呼叫 `PATCH assignment-submissions/{id}/grade`
 *     判定為 fail（用於篩選「未通過」與統計「平均批改時間」有值）
 *   - Student C：純文字，維持 pending，只在「批改通過並寫評語」測試中被實際批改（避免與其他
 *     讀取型測試互相干擾統計數字）
 *
 * 測試執行順序刻意讓「批改」測試排在所有讀取型斷言（分頁 / 統計 / 篩選 / 列表 / 詳情）之後
 * ——playwright.config.ts 為 `fullyParallel: false` + `workers: 1`，同檔測試依宣告順序循序執行，
 * 讀取型測試才能穩定看到批改前的統計基準值。
 *
 * 本檔**不**使用 test.describe.serial()：作業批改頁整個功能目前完全不存在，若用 serial，
 * 第一個 test 紅燈後其餘會被標記為 did not run，看不到「統計卡」「篩選」「匯出」各自的
 * 真實紅燈原因（P4-R2 已踩過這個坑）。改用「共用唯讀 fixture（beforeAll 建立、多個測試共讀）
 * + 批改動作只動 Student C（獨立於其他測試）」的方式讓每個 test 都能各自產出真實紅燈。
 *
 * 預期結果（Green 完成前，本檔全數失敗，且失敗原因必須是「功能不存在」——
 * 找不到「作業批改」選單 / 頁面元素 / 對應 REST 路由，不是登入或資料準備失敗）：
 * 全部 7 個 test 皆紅。
 */
import { test, expect } from '@playwright/test'
import type {
	APIRequestContext,
	Browser,
	BrowserContext,
	Page,
} from '@playwright/test'

import { navigateToAdmin, waitForMessage } from '../helpers/admin-page'
import { setupApiFromBrowser, type ApiClient } from '../helpers/api-client'

const BASE_URL = process.env.TEST_SITE_URL || 'http://localhost:8889'
const NAV_TIMEOUT = Number(process.env.E2E_ADMIN_NAV_TIMEOUT || 90_000)

test.setTimeout(120_000)

// ── 測試用資料 ──────────────────────────────────
const COURSE_TITLE = 'E2E 作業批改課程'
const ASSIGNMENT_TITLE = 'E2E批改作業'

const STUDENT_A = {
	username: 'e2e_grade_a_131',
	email: 'e2e_grade_a_131@test.local',
	password: 'e2eGradeA131!Pass',
	name: 'E2E學員A',
}
const STUDENT_B = {
	username: 'e2e_grade_b_131',
	email: 'e2e_grade_b_131@test.local',
	password: 'e2eGradeB131!Pass',
	name: 'E2E學員B',
}
const STUDENT_C = {
	username: 'e2e_grade_c_131',
	email: 'e2e_grade_c_131@test.local',
	password: 'e2eGradeC131!Pass',
	name: 'E2E學員C',
}

// 1x1 透明 PNG（比照 016 spec 慣例，finfo 可正確辨識為 image/png）
const PNG_1PX_BASE64 =
	'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='
const ATTACHMENT_BUFFER = Buffer.from(PNG_1PX_BASE64, 'base64')
const ATTACHMENT_NAME = 'E2E作業附件.png'
const ATTACHMENT_MIME = 'image/png'

// ── Helper：學員登入取得 nonce（比照 016 spec，未改共用 loginAs()，
//    因其 waitForURL 逾時寫死 30s，本機冷啟動會炸；此處等效流程可設定逾時） ──
async function loginStudentAndGetNonce(
	browser: Browser,
	username: string,
	password: string,
): Promise<{
	context: BrowserContext
	request: APIRequestContext
	nonce: string
}> {
	const context = await browser.newContext({ ignoreHTTPSErrors: true })
	context.setDefaultTimeout(NAV_TIMEOUT)
	context.setDefaultNavigationTimeout(NAV_TIMEOUT)
	const page = await context.newPage()

	await page.goto(`${BASE_URL}/wp-login.php`, {
		waitUntil: 'domcontentloaded',
		timeout: NAV_TIMEOUT,
	})
	await page.locator('#user_login').fill(username)
	await page.locator('#user_pass').fill(password)
	await page.locator('#wp-submit').click({ noWaitAfter: true, timeout: NAV_TIMEOUT })
	await page.waitForURL((url) => !url.pathname.includes('wp-login'), {
		timeout: NAV_TIMEOUT,
	})

	if (page.url().includes('confirm_admin_email')) {
		await page
			.locator('.admin-email__actions-secondary a')
			.click({ timeout: 5_000 })
			.catch(() => {
				/* 沒有這個連結就略過 */
			})
	}

	const resp = await context.request.get(
		`${BASE_URL}/wp-admin/admin-ajax.php?action=rest-nonce`,
		{ timeout: NAV_TIMEOUT },
	)
	const nonce = (await resp.text()).trim()
	await page.close()

	if (!nonce || nonce === '0' || nonce === '-1') {
		throw new Error(`無法取得學員 "${username}" 的 REST nonce，回應: ${nonce}`)
	}

	return { context, request: context.request, nonce }
}

/** Helper：以學員身分上傳作業附件 */
async function uploadAttachmentAsStudent(
	requestCtx: APIRequestContext,
	nonce: string,
): Promise<number> {
	const resp = await requestCtx.post(
		`${BASE_URL}/wp-json/power-course/my/assignment-attachments`,
		{
			headers: { 'X-WP-Nonce': nonce },
			multipart: {
				file: {
					name: ATTACHMENT_NAME,
					mimeType: ATTACHMENT_MIME,
					buffer: ATTACHMENT_BUFFER,
				},
			},
			timeout: 60_000,
		},
	)
	const data = (await resp.json()) as { attachment_id?: number }
	if (resp.status() >= 400 || !data.attachment_id) {
		throw new Error(`學員上傳附件失敗: ${JSON.stringify(data)}`)
	}
	return data.attachment_id
}

/** Helper：以學員身分繳交作業 */
async function submitAssignmentAsStudent(
	requestCtx: APIRequestContext,
	nonce: string,
	assignmentId: number,
	body: { text_body?: string; attachment_ids?: number[] },
): Promise<{ status: number; data: unknown }> {
	const resp = await requestCtx.post(
		`${BASE_URL}/wp-json/power-course/my/assignments/${assignmentId}/submit`,
		{
			headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
			data: body,
			timeout: 60_000,
		},
	)
	return { status: resp.status(), data: await resp.json() }
}

/** Helper：切換到指定課程分頁（Tabs 選項文字含「{課程標題} ({count})」） */
async function selectCourseTab(page: Page, courseTitle: string): Promise<void> {
	const tab = page.locator('.ant-tabs-tab').filter({ hasText: courseTitle })
	await expect(
		tab,
		`課程分頁應包含「${courseTitle}」（含繳交數，例如 "${courseTitle} (3)"）`,
	).toBeVisible({ timeout: 15_000 })
	await tab.click()
}

/** Helper：讀取指定標題的統計卡數值（`.ant-statistic` 內含 title 與 content） */
async function getStatValue(page: Page, title: string): Promise<string> {
	// hasText 是子字串比對，查「通過」會同時命中「未通過」「通過率」三張卡（strict mode violation），
	// 且這在實作端無解（含「未通過」者必然含「通過」）。改用精確文字節點比對。
	const card = page
		.locator('.ant-statistic')
		.filter({ has: page.getByText(title, { exact: true }) })
	await expect(card, `統計卡「${title}」應存在`).toBeVisible({
		timeout: 15_000,
	})
	return (await card.locator('.ant-statistic-content').innerText()).trim()
}

// ── 共用狀態 ────────────────────────────────────────
let adminApi: ApiClient
let disposeAdmin: () => Promise<void>

let courseId: number
let assignmentId: number
let studentAId: number
let studentBId: number
let studentCId: number

let ctxA: BrowserContext
let requestA: APIRequestContext
let nonceA: string
let submissionAId: number
let attachmentId: number

let ctxB: BrowserContext
let requestB: APIRequestContext
let submissionBId: number

let ctxC: BrowserContext
let requestC: APIRequestContext
let submissionCId: number

test.describe('作業批改頁（Issue #131 P7）', () => {
	test.use({ storageState: '.auth/admin.json' })

	test.beforeAll(async ({ browser }) => {
		test.setTimeout(NAV_TIMEOUT * 3 + 60_000)

		const setup = await setupApiFromBrowser(browser)
		adminApi = setup.api
		disposeAdmin = setup.dispose

		// 1. 課程 + 章節 + 作業
		const created = await adminApi.createCourseWithChapters(
			COURSE_TITLE,
			'0',
			[{ name: 'E2E批改章節', slug: 'e2e-grading-chapter' }],
			'e2e-assignment-grading-course',
		)
		courseId = created.courseId
		const chapterId = created.chapterIds[0]

		const assignResp = await adminApi.pcPost<{ id: number }>('assignments', {
			chapter_id: chapterId,
			title: ASSIGNMENT_TITLE,
			allow_text: 'yes',
			allow_file: 'yes',
			max_files: 5,
		})
		if (assignResp.status >= 400 || !(assignResp.data as { id?: number })?.id) {
			throw new Error(`建立測試作業失敗: ${JSON.stringify(assignResp.data)}`)
		}
		assignmentId = (assignResp.data as { id: number }).id

		// 2. 三位學員 + 授權觀看課程
		// 第 5 參數帶顯示名稱：後台一律以 get_formatted_name() 呈現學員，
		// 不指定時 display_name 會等於 user_login，畫面只看得到帳號、驗不到姓名欄位。
		studentAId = await adminApi.ensureUserViaPowerhouse(
			STUDENT_A.username,
			STUDENT_A.email,
			STUDENT_A.password,
			['subscriber'],
			STUDENT_A.name,
		)
		studentBId = await adminApi.ensureUserViaPowerhouse(
			STUDENT_B.username,
			STUDENT_B.email,
			STUDENT_B.password,
			['subscriber'],
			STUDENT_B.name,
		)
		studentCId = await adminApi.ensureUserViaPowerhouse(
			STUDENT_C.username,
			STUDENT_C.email,
			STUDENT_C.password,
			['subscriber'],
			STUDENT_C.name,
		)
		await adminApi.grantCourseAccess(studentAId, courseId)
		await adminApi.grantCourseAccess(studentBId, courseId)
		await adminApi.grantCourseAccess(studentCId, courseId)

		// 3. 各學員登入取得 nonce
		const a = await loginStudentAndGetNonce(browser, STUDENT_A.username, STUDENT_A.password)
		ctxA = a.context
		requestA = a.request
		nonceA = a.nonce

		const b = await loginStudentAndGetNonce(browser, STUDENT_B.username, STUDENT_B.password)
		ctxB = b.context
		requestB = b.request

		const c = await loginStudentAndGetNonce(browser, STUDENT_C.username, STUDENT_C.password)
		ctxC = c.context
		requestC = c.request

		// 4. Student A：文字 + 1 張附件，維持 pending
		attachmentId = await uploadAttachmentAsStudent(requestA, nonceA)
		const submitA = await submitAssignmentAsStudent(requestA, nonceA, assignmentId, {
			text_body: 'Student A 的繳交內容',
			attachment_ids: [attachmentId],
		})
		if (submitA.status >= 400) {
			throw new Error(`Student A 繳交失敗: ${JSON.stringify(submitA.data)}`)
		}
		submissionAId = (submitA.data as { id: number }).id

		// 5. Student B：純文字繳交，管理員 REST 判定 fail
		const submitB = await submitAssignmentAsStudent(requestB, b.nonce, assignmentId, {
			text_body: 'Student B 的繳交內容',
		})
		if (submitB.status >= 400) {
			throw new Error(`Student B 繳交失敗: ${JSON.stringify(submitB.data)}`)
		}
		submissionBId = (submitB.data as { id: number }).id
		const gradeB = await adminApi.pcPatch(`assignment-submissions/${submissionBId}/grade`, {
			status: 'fail',
			feedback: 'E2E 預先批改：未通過（測試固定資料）',
		})
		if (gradeB.status >= 400) {
			throw new Error(`Student B 預先批改失敗: ${JSON.stringify(gradeB.data)}`)
		}

		// 6. Student C：純文字繳交，維持 pending，留給「批改」測試操作
		const submitC = await submitAssignmentAsStudent(requestC, c.nonce, assignmentId, {
			text_body: 'Student C 的繳交內容',
		})
		if (submitC.status >= 400) {
			throw new Error(`Student C 繳交失敗: ${JSON.stringify(submitC.data)}`)
		}
		submissionCId = (submitC.data as { id: number }).id
	})

	test.afterAll(async () => {
		test.setTimeout(120_000)
		try {
			await adminApi.deleteCourses([courseId])
		} catch {
			/* ignore */
		}
		// 附件實體檔不刪（D9/D16，計劃 §13 限制條件）
		for (const id of [studentAId, studentBId, studentCId]) {
			try {
				await adminApi.deleteUserViaPowerhouse(id)
			} catch {
				/* ignore */
			}
		}
		if (ctxA) await ctxA.close()
		if (ctxB) await ctxB.close()
		if (ctxC) await ctxC.close()
		if (disposeAdmin) await disposeAdmin()
	})

	// ─────────────────────────────────────────────
	// 1. 課程分頁顯示有繳交紀錄的課程與繳交數
	// ─────────────────────────────────────────────

	test('課程分頁顯示有繳交紀錄的課程與繳交數 @happy @smoke', async ({ page }) => {
		await navigateToAdmin(page, '/assignments')
		await page.waitForSelector('.ant-tabs', { timeout: 15_000 })

		await expect(
			page.getByRole('tab', { name: '全部' }),
			'應有「全部」分頁',
		).toBeVisible({ timeout: 10_000 })

		// 目前只有 1 門課程有繳交紀錄（3 筆：A、B、C）
		await selectCourseTab(page, COURSE_TITLE)
		const activeTab = page.locator('.ant-tabs-tab-active')
		await expect(activeTab.getByText('(3)')).toBeVisible({ timeout: 10_000 })
	})

	// ─────────────────────────────────────────────
	// 2. 統計卡依課程分頁範圍顯示正確數字
	// ─────────────────────────────────────────────

	test('統計卡依課程分頁範圍顯示正確數字（全部/待批改/通過/未通過/通過率/平均批改時間） @happy', async ({
		page,
	}) => {
		await navigateToAdmin(page, '/assignments')
		await page.waitForSelector('.ant-tabs', { timeout: 15_000 })
		await selectCourseTab(page, COURSE_TITLE)
		await page.waitForSelector('.ant-statistic', { timeout: 15_000 })

		// 此時尚未執行「批改通過」測試：A pending、B fail、C pending
		// → total=3, pending=2, pass=0, fail=1, pass_rate=round(0/(0+1)*100)=0
		expect(await getStatValue(page, '全部')).toBe('3')
		expect(await getStatValue(page, '待批改')).toBe('2')
		expect(await getStatValue(page, '通過')).toBe('0')
		expect(await getStatValue(page, '未通過')).toBe('1')
		expect(await getStatValue(page, '通過率')).toContain('0')

		// 平均批改時間：Student B 已被批改（graded_at 不為 null），故不應是「無資料」佔位符「-」
		const avgGradingTime = await getStatValue(page, '平均批改時間')
		expect(
			avgGradingTime,
			`平均批改時間不應為佔位符「-」，實際: "${avgGradingTime}"`,
		).not.toBe('-')
		expect(avgGradingTime).toMatch(/^\d+\s*秒$/)
	})

	// ─────────────────────────────────────────────
	// 3. 狀態篩選
	// ─────────────────────────────────────────────

	test('狀態篩選：切換全部/待批改/通過/未通過更新列表 @happy', async ({ page }) => {
		await navigateToAdmin(page, '/assignments')
		await page.waitForSelector('.ant-tabs', { timeout: 15_000 })
		await selectCourseTab(page, COURSE_TITLE)

		const filter = page.locator('.pc-assignment-status-filter')
		await expect(filter, '應有狀態篩選區塊（.pc-assignment-status-filter）').toBeVisible({
			timeout: 15_000,
		})

		const table = page.locator('.pc-assignment-submission-table')
		await expect(table).toBeVisible({ timeout: 15_000 })

		await filter.getByText('待批改', { exact: true }).click()
		await expect(table.locator('.ant-table-tbody tr')).toHaveCount(2, {
			timeout: 10_000,
		})

		await filter.getByText('未通過', { exact: true }).click()
		await expect(table.locator('.ant-table-tbody tr')).toHaveCount(1, {
			timeout: 10_000,
		})
		await expect(table.getByText(STUDENT_B.email)).toBeVisible()

		await filter.getByText('通過', { exact: true }).click()
		// 先等資料列歸零再驗空狀態：切換篩選時 React Query 會沿用前一個篩選的資料，
		// 空狀態元素要等 refetch 落地才出現。直接斷言 .ant-empty 會跟 refetch 賽跑，
		// 站台慢時就紅。用 tr.ant-table-row（真資料列）而非 tbody tr——後者會把
		// antd 的 .ant-table-placeholder 空列也算進去，永遠不會是 0。
		await expect(
			table.locator('.ant-table-tbody tr.ant-table-row'),
			'篩選「通過」時不應有任何資料列',
		).toHaveCount(0, { timeout: 30_000 })
		await expect(
			table.getByText('No data').or(table.locator('.ant-empty')),
			'無資料時應顯示空狀態',
		).toBeVisible({ timeout: 10_000 })

		await filter.getByText('全部', { exact: true }).click()
		await expect(table.locator('.ant-table-tbody tr')).toHaveCount(3, {
			timeout: 10_000,
		})
	})

	// ─────────────────────────────────────────────
	// 4. 列表欄位
	// ─────────────────────────────────────────────

	test('列表顯示學員姓名/Email、課程、作業、繳交時間、狀態、附件圖示、討論徽章 @happy', async ({
		page,
	}) => {
		await navigateToAdmin(page, '/assignments')
		await page.waitForSelector('.ant-tabs', { timeout: 15_000 })
		await selectCourseTab(page, COURSE_TITLE)

		const table = page.locator('.pc-assignment-submission-table')
		await expect(table).toBeVisible({ timeout: 15_000 })
		// 狀態篩選預設為「待批改」（第二波 W3-G0），不含已判定未通過的 Student B；
		// 下方排序斷言需要 A、B、C 三列，先切到「全部」並等三列資料到齊
		await page
			.locator('.pc-assignment-status-filter')
			.getByText('全部', { exact: true })
			.click()
		// 切換期間表格沿用上一個查詢的資料（Refine 預設 keepPreviousData），「全部課程 + 待批改」
		// 加上前幾輪殘留的繳交可能剛好也是 3 列；先等只有「全部」才看得到的 Student B 出現
		await expect(
			table.locator('tr.ant-table-row').filter({ hasText: STUDENT_B.email }),
		).toBeVisible({ timeout: 30_000 })
		await expect(table.locator('tr.ant-table-row')).toHaveCount(3, {
			timeout: 30_000,
		})
		await expect(table.getByText('討論')).toBeVisible()

		const rowA = table.locator('tr').filter({ hasText: STUDENT_A.email })
		await expect(rowA, 'Student A 的繳交列應存在').toBeVisible({
			timeout: 10_000,
		})
		await expect(rowA.getByText(STUDENT_A.name)).toBeVisible()
		await expect(rowA.getByText(COURSE_TITLE)).toBeVisible()
		await expect(rowA.getByText(ASSIGNMENT_TITLE)).toBeVisible()
		await expect(rowA.getByText(/\d{4}-\d{2}-\d{2}/)).toBeVisible()
		await expect(rowA.getByText('待批改', { exact: true })).toBeVisible()

		// 附件大類圖示：本測試附件為 image/png → kind=image → Tooltip/title="圖片"
		const kindIcon = rowA.locator('.pc-attachment-kind-icon')
		await expect(kindIcon, '應顯示附件大類圖示').toBeVisible({
			timeout: 10_000,
		})
		await expect(kindIcon).toHaveAttribute('title', '圖片')

		// 討論未讀徽章：本波尚無訊息功能，徽章應存在但顯示 0
		const discussBadge = rowA.locator('.pc-assignment-discuss-badge')
		await expect(discussBadge, '應顯示討論未讀徽章').toBeVisible({
			timeout: 10_000,
		})

		// 列表依繳交時間由新到舊（UI 規格「關鍵元素」）：
		// beforeAll 依序繳交 A → B → C，故新到舊應為 C → B → A
		const rowTexts = await table.locator('.ant-table-tbody tr').allTextContents()
		const idxA = rowTexts.findIndex((t) => t.includes(STUDENT_A.email))
		const idxB = rowTexts.findIndex((t) => t.includes(STUDENT_B.email))
		const idxC = rowTexts.findIndex((t) => t.includes(STUDENT_C.email))
		expect(
			idxC,
			`列表應依繳交時間由新到舊排序（C 最後繳交應排最前），實際列順序: ${JSON.stringify(rowTexts)}`,
		).toBeGreaterThanOrEqual(0)
		expect(idxC, '最後繳交的 Student C 應排在 Student B 之前').toBeLessThan(idxB)
		expect(idxB, 'Student B 應排在最先繳交的 Student A 之前').toBeLessThan(idxA)
	})

	// ─────────────────────────────────────────────
	// 5. 詳情：學員內容、附件、學生背景資料（含學習進度）
	// ─────────────────────────────────────────────

	test('開啟詳情顯示學員文字內容、附件與學生背景資料（含學習進度） @happy', async ({
		page,
	}) => {
		await navigateToAdmin(page, '/assignments')
		await page.waitForSelector('.ant-tabs', { timeout: 15_000 })
		await selectCourseTab(page, COURSE_TITLE)

		const table = page.locator('.pc-assignment-submission-table')
		await expect(table).toBeVisible({ timeout: 15_000 })
		const rowA = table.locator('tr').filter({ hasText: STUDENT_A.email })
		await expect(rowA).toBeVisible({ timeout: 10_000 })

		const detailBtn = rowA.getByRole('button', { name: '查看詳情' })
		await expect(detailBtn, '每一列應有「查看詳情」按鈕').toBeVisible({
			timeout: 10_000,
		})
		await detailBtn.click()

		const detail = page.locator('.pc-assignment-detail')
		await expect(detail, '應開啟詳情區塊（.pc-assignment-detail）').toBeVisible({
			timeout: 10_000,
		})

		// 學員文字內容與附件
		await expect(detail.getByText('Student A 的繳交內容')).toBeVisible()
		const attachmentLink = detail.getByText(ATTACHMENT_NAME)
		await expect(attachmentLink, '應顯示附件檔名（可點擊下載）').toBeVisible()

		const downloadPromise = page.waitForEvent('download', {
			timeout: 15_000,
		})
		await attachmentLink.click()
		const download = await downloadPromise
		expect(download.suggestedFilename()).toBe(ATTACHMENT_NAME)

		// 最後批改時間區塊：Student A 尚未被批改，應顯示「尚未批改」佔位字樣
		// （介面契約：.pc-assignment-graded-at，批改後改顯示實際時間，見「批改通過」測試）
		await expect(
			detail.locator('.pc-assignment-graded-at').getByText('尚未批改'),
			'尚未批改的繳交，詳情應顯示「尚未批改」佔位字樣',
		).toBeVisible({ timeout: 10_000 })

		// 學生背景資料：基本資料 + 本課程作業統計 + 學習進度
		const overview = detail.locator('.pc-assignment-student-overview')
		await expect(overview, '應顯示學生背景資料區塊').toBeVisible({
			timeout: 10_000,
		})
		await expect(overview.getByText(STUDENT_A.name)).toBeVisible()
		await expect(overview.getByText(STUDENT_A.email)).toBeVisible()

		// Student A 在此課程僅 1 筆繳交，且尚未被批改 → submitted=1, pending=1, passed=0, failed=0
		await expect(overview.getByText('已繳交：1', { exact: true })).toBeVisible()
		await expect(overview.getByText('待批改：1', { exact: true })).toBeVisible()
		await expect(overview.getByText('已通過：0', { exact: true })).toBeVisible()
		await expect(overview.getByText('未通過：0', { exact: true })).toBeVisible()

		// 學習進度：課程僅 1 章節，Student A 尚未被批改通過 → 0/1（0%）
		await expect(
			overview.getByText('已完成 0 / 1 章節（0%）', { exact: true }),
		).toBeVisible()
	})

	// ─────────────────────────────────────────────
	// 6. 批改通過並寫評語 → 列表狀態與統計即時更新
	// ─────────────────────────────────────────────

	test('批改通過並寫評語後，列表狀態與統計即時更新 @happy', async ({ page }) => {
		await navigateToAdmin(page, '/assignments')
		await page.waitForSelector('.ant-tabs', { timeout: 30_000 })
		await selectCourseTab(page, COURSE_TITLE)
		// StatsCards 在 isFetching 期間刻意渲染 Skeleton（避免顯示上一個課程分頁的
		// 舊數字），所以 .ant-statistic 要等統計查詢落地才會出現，不是頁面一到就有
		await page.waitForSelector('.ant-statistic', { timeout: 45_000 })

		const passBefore = await getStatValue(page, '通過')
		const pendingBefore = await getStatValue(page, '待批改')

		const table = page.locator('.pc-assignment-submission-table')
		await expect(table).toBeVisible({ timeout: 15_000 })
		const rowC = table.locator('tr').filter({ hasText: STUDENT_C.email })
		await expect(rowC, 'Student C 的繳交列應存在').toBeVisible({
			timeout: 10_000,
		})

		await rowC.getByRole('button', { name: '查看詳情' }).click()
		const detail = page.locator('.pc-assignment-detail')
		await expect(detail).toBeVisible({ timeout: 10_000 })

		// 批改操作應提供「通過 / 未通過 / 待批改（改回待批改）」三個選項（UI 規格「關鍵元素」）
		const gradeRadio = detail.locator('.pc-assignment-grade-radio')
		await expect(gradeRadio, '應有批改操作單選群組').toBeVisible({
			timeout: 10_000,
		})
		await expect(
			gradeRadio.getByText('通過', { exact: true }),
			'批改操作應有「通過」選項',
		).toBeVisible()
		await expect(
			gradeRadio.getByText('未通過', { exact: true }),
			'批改操作應有「未通過」選項',
		).toBeVisible()
		await expect(
			gradeRadio.getByText('待批改', { exact: true }),
			'批改操作應有「待批改」選項（可改回待批改）',
		).toBeVisible()

		const feedbackText = 'E2E 測試評語：完成度良好'
		await detail.locator('#feedback').fill(feedbackText)
		await gradeRadio.getByText('通過', { exact: true }).click()

		const gradeResponse = page.waitForResponse(
			(resp) =>
				resp.url().includes(`/wp-json/power-course/assignment-submissions/${submissionCId}/grade`) &&
				resp.request().method() === 'PATCH' &&
				resp.status() < 400,
			{ timeout: 15_000 },
		)
		await detail.getByRole('button', { name: '儲存批改結果' }).click()
		await gradeResponse
		await waitForMessage(page, 'success')

		// 詳情應顯示最後批改時間區塊（介面契約：.pc-assignment-graded-at，
		// 未批改時顯示「尚未批改」佔位字樣，批改後應改顯示實際時間）
		const gradedAt = detail.locator('.pc-assignment-graded-at')
		await expect(
			gradedAt,
			'詳情應有「最後批改時間」區塊（.pc-assignment-graded-at）',
		).toBeVisible({ timeout: 10_000 })
		// 給 30 秒而非預設 5 秒：PATCH 已 await 過，這裡等的是列表 refetch 落地後
		// 詳情重新取用最新那一筆。同檔其他等待都是 30～60 秒，只有這裡沿用預設，
		// 在負載高的機器上會單獨紅（實測 41.7 分鐘那一輪就是卡在這裡）。
		await expect(gradedAt.getByText('尚未批改')).toHaveCount(0, {
			timeout: 30_000,
		})
		await expect(
			gradedAt.getByText(/\d{4}-\d{2}-\d{2}/),
			'批改後「最後批改時間」應顯示實際時間',
		).toBeVisible({ timeout: 10_000 })

		// 列表狀態即時更新：狀態篩選預設為「待批改」（第二波 W3-G0），批改通過的 Student C
		// 會先離開目前列表；關閉詳情抽屜（遮罩會擋住篩選）後切到「全部」，應顯示「通過」
		await expect(rowC, '批改通過後 Student C 應離開「待批改」列表').toHaveCount(0, {
			timeout: 30_000,
		})
		await page.locator('.ant-drawer-close').click()
		await expect(detail).toBeHidden({ timeout: 15_000 })
		await page
			.locator('.pc-assignment-status-filter')
			.getByText('全部', { exact: true })
			.click()
		await expect(rowC.getByText('通過', { exact: true })).toBeVisible({
			timeout: 30_000,
		})

		// 統計即時更新：通過 +1、待批改 -1
		await expect
			.poll(async () => getStatValue(page, '通過'), { timeout: 15_000 })
			.toBe(String(Number(passBefore) + 1))
		await expect
			.poll(async () => getStatValue(page, '待批改'), { timeout: 15_000 })
			.toBe(String(Number(pendingBefore) - 1))
	})

	// ─────────────────────────────────────────────
	// 7. 匯出單一作業 CSV 與匯出批改用 CSV
	// ─────────────────────────────────────────────

	test('匯出單一作業CSV與匯出批改用CSV觸發下載且檔名符合規則 @happy', async ({ page }) => {
		await navigateToAdmin(page, '/assignments')
		await page.waitForSelector('.ant-tabs', { timeout: 15_000 })
		await selectCourseTab(page, COURSE_TITLE)

		// 匯出批改用 CSV：以目前課程分頁 + 狀態篩選範圍匯出（檔名 submissions-grades-{UTC 時間戳}.csv）
		const gradingExportBtn = page.getByRole('button', { name: '匯出批改用 CSV' })
		await expect(gradingExportBtn).toBeVisible({ timeout: 10_000 })
		const gradingDownloadPromise = page.waitForEvent('download', {
			timeout: 20_000,
		})
		await gradingExportBtn.click()
		const gradingDownload = await gradingDownloadPromise
		expect(gradingDownload.suggestedFilename()).toMatch(
			/^submissions-grades-\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2}\.csv$/,
		)

		// 匯出單一作業 CSV：需先在 Modal 中選擇要匯出的作業
		// （檔名 assignment-{標題非文字字元換底線，取前40字}-{UTC 時間戳}.csv）
		const singleExportBtn = page.getByRole('button', { name: '匯出單一作業 CSV' })
		await expect(singleExportBtn).toBeVisible({ timeout: 10_000 })
		await singleExportBtn.click()

		const exportModal = page.locator('.ant-modal')
		await expect(exportModal.getByText('選擇要匯出的作業')).toBeVisible({
			timeout: 10_000,
		})
		await exportModal.locator('.ant-select').click()
		await page
			.locator('.ant-select-dropdown .ant-select-item-option')
			.filter({ hasText: ASSIGNMENT_TITLE })
			.click()

		const singleDownloadPromise = page.waitForEvent('download', {
			timeout: 20_000,
		})
		await exportModal.getByRole('button', { name: '確認匯出' }).click()
		const singleDownload = await singleDownloadPromise
		expect(singleDownload.suggestedFilename()).toMatch(
			/^assignment-.+-\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2}\.csv$/,
		)
	})
})
