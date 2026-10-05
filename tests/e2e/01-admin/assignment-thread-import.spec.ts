/**
 * 作業批改頁：討論串與匯入批改結果 CSV（Issue #131 第二波 W3-R）
 *
 * 對應規格：specs/ui/作業批改頁.md（詳情區「討論串（第二波）」、工具列「匯入 CSV」）
 * 對應 feature：specs/features/assignment/{查詢作業討論串,發送作業討論訊息,匯入批改結果CSV}.feature
 * 對應計劃：specs/plan/issue-131-assignment-grading/wave2-implementation-plan.md §11 W3、DEC-W2-17 / 18
 * 對應 API：specs/api/api.yml（tag Assignments）：
 *   - 管理端 GET / POST `/assignment-submissions/{id}/messages`
 *   - 學員端 GET / POST `/my/assignment-submissions/{id}/messages`
 *   - 管理端 POST `/assignment-submissions/import`（multipart，field 名 file）
 *
 * 第 0～5 條與 Green 棒次的對應（W3-G0 → W3-G1 → W3-G2 各自只需讓自己的條目變綠）：
 *   0. 預設狀態篩選為「待批改」、預設匯出只含待批改列        → W3-G0
 *   1. 「討論」徽章 1 → 開詳情看到學員訊息 → 關抽屜後歸 0       → W3-G1
 *   2. 抽屜內回覆按 Enter → 「老師」訊息、學員端收得到、「已讀」 → W3-G1
 *   3. XSS：學員訊息以純文字顯示                               → W3-G1
 *   4. 匯入兩列 CSV（pass + ok）→ 結果摘要、錯誤列號、列表與統計 → W3-G2
 *   5. 匯出批改用 CSV 原檔直接匯回 → 失敗 0、略過 0            → W3-G2
 *
 * 資料準備（S-RED-E2E 硬性規則 1：一律走 REST，不走 UI）：
 * - 課程 + 章節 + 6 份作業：管理員 REST 建立
 * - 學員 P（有 5 筆待批改繳交）、學員 G（有 1 筆已批改為 fail 的繳交）：Powerhouse V2 API 建立
 *   （本站沒有 /wp/v2/users），以 loginAs() 登入後取得 REST nonce，再用學員自己的 nonce 繳交、發訊息
 * - 老師回覆與批改一律用管理員 nonce 的 REST
 *
 * 為什麼每一條測試各用自己的繳交（不同作業）而不共用：
 * 學員讀 / 管理員讀討論串會把對方訊息標為已讀、匯入會改狀態，這些副作用不可逆；若共用同一筆，
 * 後面的測試看到的是前面測試的殘留。每一條只動自己的那筆，並在開頭用 REST 把它重設成確定狀態，
 * 所以重試（本機 retries: 1）也不會讀到上一次嘗試留下的髒資料。
 *
 * 不使用 test.describe.serial()：serial 中一支紅，其餘全部 did not run，紅燈原因被遮住
 * （第一波 016 與 assignment-grading.spec.ts 的實測教訓）。
 *
 * 共用資料與「worker 重啟」：Playwright 在任何一支 test 失敗後都會丟掉 worker、重開新 worker 並重跑 beforeAll。
 * 紅燈階段 6 支必紅，若每次都重建「課程 + 兩次學員登入 + 繳交」，本機站一次 beforeAll 就要數分鐘。
 * 所以固定資料只在「這一輪 run 的第一個 worker」建一次，寫進 os.tmpdir() 下以 Playwright runner PID
 * （process.ppid，同一輪的所有 worker 共用）命名的 JSON，之後的 worker 直接還原
 * （學員 session 用 storageState + 快取的 nonce，不重新登入）。做法同 03-integration/017。
 * 清理：afterEach 在每支 test「最終結果出爐」時登記，登記數達 EXPECTED_TEST_COUNT 的那個 worker 的
 * afterAll 才刪課程與學員並移除快取檔；用 --grep 只跑一部分時不會清（殘留的 E2E 課程由下一輪
 * global-setup 清掉；課程名稱必須以 E2E 開頭）。
 *
 * 斷言 DOM 狀態，不等 toast（第一波 waitForMessage 兩套提示管道、Skeleton 競態的教訓）。
 * 不斷言「直連受保護目錄的檔案會被擋」（本機是 Nginx，R-04）：本檔沒有上傳附件。
 *
 * 預期結果（Green 完成前，本檔 6 支全紅，且原因必須是「功能不存在」）：
 * - 第 0 條：預設選中的是「全部」而不是「待批改」
 * - 第 1～3 條：詳情抽屜沒有討論串區塊（.pc-assignment-thread）
 * - 第 4、5 條：工具列沒有「匯入批改 CSV」按鈕
 *
 * 介面契約（本檔偏離計劃 DEC-W2-17 / 18 的「沒寫死的部分」，由 test-creator 決定，Green 必須照做）：
 * - 討論串容器 `.pc-assignment-thread`，放在詳情抽屜 `.pc-assignment-detail` 內
 * - 每則訊息 `.pc-assignment-thread-message`；訊息內：
 *   * 寄件者標籤是一個「全文恰為『學員』或『老師』」的元素（不與時間、本文合併在同一個文字節點）
 *   * 本文以純文字呈現（不可用 innerHTML / dangerouslySetInnerHTML）
 *   * 含時間（任何含 HH:mm 的格式，例如 `2026-10-02 14:03` 或 `14:03`）
 *   * 己方（管理員端 = 老師）訊息且 read_at 不為空時，含全文恰為『已讀』的元素；否則不含
 * - 回覆輸入列：討論串容器內唯一的單行 Input（role=textbox），Enter 送出，前端 trim 後為空不送
 * - 匯入：工具列按鈕「匯入批改 CSV」（msgid `Import grading CSV`）；隱藏的 `input[type=file]` 其 accept 含 `csv`
 * - 匯入結果：`.pc-assignment-import-result`（頁內 Alert），文字含「共 N 筆」「更新 X」「略過 Y」「失敗 Z」，
 *   每則錯誤含「第 N 列」與錯誤訊息
 */
import fs from 'fs'
import os from 'os'
import path from 'path'
import { test, expect } from '@playwright/test'
import type {
	APIRequestContext,
	APIResponse,
	Browser,
	BrowserContext,
	Download,
	Locator,
	Page,
} from '@playwright/test'

import { navigateToAdmin } from '../helpers/admin-page.js'
import { ApiClient, getNonceFromPage } from '../helpers/api-client.js'
import { loginAs } from '../helpers/frontend-setup.js'

const BASE_URL = process.env.TEST_SITE_URL || 'http://localhost:8889'
const NAV_TIMEOUT = Number(process.env.E2E_ADMIN_NAV_TIMEOUT || 90_000)

// 每支 test 都要導覽 wp-admin（本機 2～60 秒）再開抽屜 / 匯入，90 秒的 config 預設不夠
test.setTimeout(180_000)

/** 本檔 test 數：afterEach 登記數達此值才清理固定資料（新增 / 刪除 test 時要同步修改） */
const EXPECTED_TEST_COUNT = 6

/** 固定資料快取檔：以 runner PID 命名，同一輪 run 的所有 worker 共用（見檔頭「worker 重啟」） */
const FIXTURE_FILE = path.join(
	os.tmpdir(),
	`pc-w3-assignment-thread-import-fixture-${process.ppid}.json`,
)

// ── 測試資料 ────────────────────────────────────────
// 課程名稱必須以 E2E 開頭，global-setup 才清得掉殘留
const COURSE_TITLE = 'E2E 作業討論串匯入批改課程'

// 6 份作業的標題彼此不是子字串（列表以「Email + 作業標題」定位同一位學員的不同列）
const ASSIGNMENT = {
	pending: 'E2E討論匯入-預設待批改',
	graded: 'E2E討論匯入-已批改',
	badge: 'E2E討論匯入-徽章',
	reply: 'E2E討論匯入-回覆',
	xss: 'E2E討論匯入-XSS',
	importPass: 'E2E討論匯入-匯入通過',
} as const

type AssignmentKey = keyof typeof ASSIGNMENT
const ASSIGNMENT_KEYS = Object.keys(ASSIGNMENT) as AssignmentKey[]

// 學員 P：5 筆待批改（pending / badge / reply / xss / importPass）
const STUDENT_P = {
	username: 'e2e_thread_p_131',
	email: 'e2e_thread_p_131@test.local',
	password: 'e2eThreadP131!Pass',
	name: 'E2E討論學員P',
}
// 學員 G：1 筆已批改（graded，fail），整輪不再被任何測試改動
const STUDENT_G = {
	username: 'e2e_thread_g_131',
	email: 'e2e_thread_g_131@test.local',
	password: 'e2eThreadG131!Pass',
	name: 'E2E討論學員G',
}

const GRADED_FEEDBACK = 'E2E 預先批改：未通過（固定資料）'
const XSS_BODY = '<img src=x onerror="window.__pcXss=1">'
const IMPORT_FEEDBACK = 'E2E 匯入評語：寫得很好'

// ── 匯入 CSV 的表頭（與 feature 一致；依表頭名稱定位，不依位置） ──
const HEADER_ID = 'submission_id'
const HEADER_STATUS = '狀態(可改 pending/pass/fail)'
const HEADER_FEEDBACK = '講師回饋(可改)'

/** UTF-8 BOM 位元組（EF BB BF）：Excel 另存的 CSV 會以此開頭 */
const UTF8_BOM = Buffer.from([0xef, 0xbb, 0xbf])

// ── 型別 ────────────────────────────────────────────

type StorageStateData = Awaited<ReturnType<BrowserContext['storageState']>>

interface ThreadMessage {
	id: number
	sender_type: string
	body: string
	read_at: string | null
	created_at: string
	mine: boolean
}

interface AdminSession {
	context: BrowserContext
	api: ApiClient
	request: APIRequestContext
	nonce: string
}

interface StudentSession {
	context: BrowserContext
	request: APIRequestContext
	nonce: string
}

/** 學員 session 的可序列化快取：cookie（storageState）+ 當時取得的 REST nonce */
interface StudentCache {
	storageState: StorageStateData
	nonce: string
}

/** 固定資料（只建一次，寫進快取檔；所有 test 共用，會改動的部分由各 test 自己重設） */
interface FixtureData {
	adminNonce: string
	courseId: number
	studentPId: number
	studentGId: number
	assignmentIds: Record<AssignmentKey, number>
	/** 每份作業對應的繳交 ID（pending / badge / reply / xss / importPass 屬於 P，graded 屬於 G） */
	submissionIds: Record<AssignmentKey, number>
	studentP: StudentCache
	/** 已「最終出爐」的 test 標題（見 afterEach） */
	finished: string[]
}

// ── Helper：固定資料快取檔 ────────────────────────────

function readFixture(): FixtureData | undefined {
	try {
		return JSON.parse(fs.readFileSync(FIXTURE_FILE, 'utf-8')) as FixtureData
	} catch {
		return undefined
	}
}

function writeFixture(data: FixtureData): void {
	fs.writeFileSync(FIXTURE_FILE, JSON.stringify(data))
}

function removeFixture(): void {
	try {
		fs.rmSync(FIXTURE_FILE, { force: true })
	} catch {
		/* ignore */
	}
}

// ── Helper：登入 / 連線 ──────────────────────────────

/**
 * 建立管理員 context：同時提供 ApiClient（JSON 資料準備）與裸的 request + nonce（讀 CSV 位元組）
 *
 * 做法同 helpers/api-client.ts 的 setupApiFromBrowser()（storageState 登入 + 等 wpApiSettings + 取 nonce），
 * 只是多保留裸的 request 與 nonce。帶入 knownNonce（同一輪 run 的快取）時不導覽，省下一次 wp-admin 冷載入。
 * 不修改共用 helper。
 */
async function openAdmin(browser: Browser, knownNonce?: string): Promise<AdminSession> {
	const context = await browser.newContext({
		storageState: '.auth/admin.json',
		ignoreHTTPSErrors: true,
	})
	context.setDefaultTimeout(NAV_TIMEOUT)
	context.setDefaultNavigationTimeout(NAV_TIMEOUT)

	let nonce = knownNonce ?? ''
	if (!nonce) {
		const page = await context.newPage()
		await page.goto(`${BASE_URL}/wp-admin/`, {
			waitUntil: 'domcontentloaded',
			timeout: NAV_TIMEOUT,
		})
		await page.waitForFunction(() => !!(window as any).wpApiSettings?.nonce, {
			timeout: NAV_TIMEOUT,
		})
		nonce = await getNonceFromPage(page, { required: true })
		await page.close()
	}

	return { context, api: new ApiClient(context.request, nonce), request: context.request, nonce }
}

/**
 * 以學員身分登入並取得 REST nonce
 *
 * loginAs() 已處理 confirm_admin_email 攔截頁、waitUntil: 'commit'、60s 逾時；但它只等到「已被導離登入頁」，
 * 落地頁（/my-account/）尚未載完，此時 wpApiSettings 還不存在，所以再導向首頁並等 wpApiSettings 出現。
 */
async function loginStudent(
	browser: Browser,
	account: { username: string; password: string },
): Promise<StudentSession & { storageState: StorageStateData }> {
	// baseURL 必須明確指定：loginAs() 用相對路徑 goto，而手動 browser.newContext() 不會繼承 config 的 use.baseURL
	const context = await browser.newContext({ baseURL: BASE_URL, ignoreHTTPSErrors: true })
	context.setDefaultTimeout(NAV_TIMEOUT)
	context.setDefaultNavigationTimeout(NAV_TIMEOUT)

	const page = await context.newPage()
	await loginAs(page, account.username, account.password)
	await page.goto('/', { waitUntil: 'domcontentloaded', timeout: NAV_TIMEOUT })
	await page.waitForFunction(() => !!(window as any).wpApiSettings?.nonce, {
		timeout: NAV_TIMEOUT,
	})
	const nonce = await getNonceFromPage(page, { required: true })
	await page.close()

	return { context, request: context.request, nonce, storageState: await context.storageState() }
}

/** 由快取還原學員 session：沿用 cookie 與 nonce，不重新登入 */
async function restoreStudent(browser: Browser, cache: StudentCache): Promise<StudentSession> {
	const context = await browser.newContext({
		storageState: cache.storageState,
		ignoreHTTPSErrors: true,
	})
	return { context, request: context.request, nonce: cache.nonce }
}

// ── Helper：HTTP 呼叫 ────────────────────────────────

/** 讀回應本文並盡量 parse 成 JSON（PHP debug 模式可能在 JSON 前輸出 notices，從第一個 { 或 [ 開始取） */
async function readJson(resp: APIResponse): Promise<{ text: string; data: unknown }> {
	const text = await resp.text()
	const start = text.search(/[[{]/)
	try {
		return { text, data: JSON.parse(start > 0 ? text.slice(start) : text) }
	} catch {
		return { text, data: undefined }
	}
}

/** 唯一字串：重試時訊息本文不會與上一次嘗試留下的撞在一起（strict mode 會因重複而炸） */
function uniq(): string {
	return `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`
}

/** 學員身分繳交作業，回傳 submission id（失敗直接拋錯：這是資料準備，不是受測行為） */
async function submitAsStudent(
	session: StudentSession,
	assignmentId: number,
	textBody: string,
): Promise<number> {
	const resp = await session.request.post(
		`${BASE_URL}/wp-json/power-course/my/assignments/${assignmentId}/submit`,
		{
			headers: { 'X-WP-Nonce': session.nonce, 'Content-Type': 'application/json' },
			data: { text_body: textBody },
			timeout: 60_000,
		},
	)
	const { text, data } = await readJson(resp)
	const id = (data as { id?: number } | undefined)?.id
	if (resp.status() >= 400 || !id) {
		throw new Error(`學員繳交作業 ${assignmentId} 失敗（${resp.status()}）: ${text.slice(0, 500)}`)
	}
	return id
}

/** 學員在自己的繳交討論串發送訊息（資料準備） */
async function postStudentMessage(
	session: StudentSession,
	submissionId: number,
	body: string,
): Promise<void> {
	const resp = await session.request.post(
		`${BASE_URL}/wp-json/power-course/my/assignment-submissions/${submissionId}/messages`,
		{
			headers: { 'X-WP-Nonce': session.nonce, 'Content-Type': 'application/json' },
			data: { body },
			timeout: 60_000,
		},
	)
	if (resp.status() >= 400) {
		throw new Error(`學員發送討論訊息失敗（${resp.status()}）: ${(await resp.text()).slice(0, 500)}`)
	}
}

/** 學員讀討論串（GET my/assignment-submissions/{id}/messages）；讀取會把老師的未讀訊息標為已讀 */
async function getMyThread(
	session: StudentSession,
	submissionId: number,
): Promise<{ status: number; messages: ThreadMessage[] }> {
	const resp = await session.request.get(
		`${BASE_URL}/wp-json/power-course/my/assignment-submissions/${submissionId}/messages`,
		{ headers: { 'X-WP-Nonce': session.nonce }, timeout: 60_000 },
	)
	const { data } = await readJson(resp)
	return { status: resp.status(), messages: Array.isArray(data) ? (data as ThreadMessage[]) : [] }
}

/** 呼叫「匯出批改用 CSV」REST（管理員身分），回傳原始位元組（對照組：不套 status 篩選時會含已批改的列） */
async function exportGradingCsvViaRest(
	admin: AdminSession,
	params: Record<string, string>,
): Promise<{ status: number; text: string }> {
	const url = new URL(`${BASE_URL}/wp-json/power-course/assignment-submissions/export`)
	Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v))

	const resp = await admin.request.get(url.toString(), {
		headers: { 'X-WP-Nonce': admin.nonce },
		timeout: 60_000,
	})
	const body = await resp.body().catch(() => Buffer.alloc(0))
	return { status: resp.status(), text: body.toString('utf-8').replace(/^﻿/, '') }
}

// ── Helper：CSV 組裝 / 解析（測試側自備，與 production 的 CsvParser 無關） ──────

/** CSV 欄位跳脫：含逗號、雙引號、CR / LF 才加引號 */
function csvCell(value: string): string {
	return /[",\r\n]/.test(value) ? `"${value.replace(/"/g, '""')}"` : value
}

/** 組 CSV 位元組：每列以 CRLF 結尾，可選 UTF-8 BOM（Excel 另存的樣子） */
function buildCsv(rows: string[][], options: { bom: boolean }): Buffer {
	const text = rows.map((row) => row.map(csvCell).join(',')).join('\r\n') + '\r\n'
	const body = Buffer.from(text, 'utf-8')
	return options.bom ? Buffer.concat([UTF8_BOM, body]) : body
}

/** 簡易 RFC 4180 解析：處理 BOM、引號內逗號 / 換行、"" 跳脫；用來數匯出檔的資料列數 */
function parseCsv(raw: string): string[][] {
	const src = raw.charCodeAt(0) === 0xfeff ? raw.slice(1) : raw
	const rows: string[][] = []
	let row: string[] = []
	let cell = ''
	let inQuotes = false

	for (let i = 0; i < src.length; i++) {
		const ch = src[i]
		if (inQuotes) {
			if (ch === '"') {
				if (src[i + 1] === '"') {
					cell += '"'
					i++
				} else {
					inQuotes = false
				}
			} else {
				cell += ch
			}
		} else if (ch === '"') {
			inQuotes = true
		} else if (ch === ',') {
			row.push(cell)
			cell = ''
		} else if (ch === '\n') {
			row.push(cell)
			rows.push(row)
			row = []
			cell = ''
		} else if (ch !== '\r') {
			cell += ch
		}
	}
	if (cell !== '' || row.length > 0) {
		row.push(cell)
		rows.push(row)
	}
	return rows
}

/** 讀下載檔：回傳原始位元組（餵回匯入時要「原封不動」）與去 BOM 的文字 */
async function readDownload(download: Download): Promise<{ bytes: Buffer; text: string }> {
	const filePath = await download.path()
	if (!filePath) {
		throw new Error('download.path() 回傳 null：下載沒有落地成檔案')
	}
	const bytes = fs.readFileSync(filePath)
	return { bytes, text: bytes.toString('utf-8').replace(/^﻿/, '') }
}

// ── Helper：UI ───────────────────────────────────────

/** 切換到指定課程分頁（Tabs 選項文字含「{課程標題} ({count})」） */
async function selectCourseTab(page: Page, courseTitle: string): Promise<void> {
	const tab = page.locator('.ant-tabs-tab').filter({ hasText: courseTitle })
	await expect(tab, `課程分頁應包含「${courseTitle}」（含繳交數）`).toBeVisible({
		timeout: 30_000,
	})
	await tab.click()
	await expect(tab, `應已切換到「${courseTitle}」分頁`).toHaveClass(/ant-tabs-tab-active/, {
		timeout: 15_000,
	})
}

/** 進入作業批改頁並切到本檔課程的分頁（狀態篩選維持頁面預設值，不動它） */
async function openGradingPage(page: Page): Promise<void> {
	await navigateToAdmin(page, '/assignments')
	await page.waitForSelector('.ant-tabs', { timeout: 30_000 })
	await selectCourseTab(page, COURSE_TITLE)
}

/** 以「學員 Email + 作業標題」定位列表中的一列（同一位學員在不同作業各有一列） */
function submissionRow(page: Page, email: string, assignmentTitle: string): Locator {
	return page
		.locator('.pc-assignment-submission-table tr.ant-table-row')
		.filter({ hasText: email })
		.filter({ hasText: assignmentTitle })
}

/** 開啟指定列的詳情抽屜 */
async function openDetail(page: Page, row: Locator): Promise<void> {
	await row.getByRole('button', { name: '查看詳情' }).click()
	await expect(page.locator('.pc-assignment-detail'), '應開啟詳情抽屜（.pc-assignment-detail）').toBeVisible({
		timeout: 30_000,
	})
}

/** 抽屜內的討論串容器 */
function threadOf(page: Page): Locator {
	return page.locator('.pc-assignment-detail .pc-assignment-thread')
}

/** 討論串中本文含指定文字的那則訊息 */
function messageOf(thread: Locator, text: string): Locator {
	return thread.locator('.pc-assignment-thread-message').filter({ hasText: text })
}

/**
 * 等「統計卡 Skeleton」消失
 *
 * StatsCards 在抓取期間刻意渲染 Skeleton；列表重新整理與統計重新整理同時發生，
 * Skeleton 歸零代表這一輪資料都落地了。這不是 sleep，是等一個確定的 UI 狀態。
 */
async function waitForSkeletonGone(page: Page): Promise<void> {
	await expect(page.locator('.ant-skeleton'), '統計卡 Skeleton 應消失（資料載入完畢）').toHaveCount(0, {
		timeout: 30_000,
	})
}

/** 斷言「討論」徽章的數字（antd Badge 把數字放在 .ant-badge-count 的 title） */
async function expectDiscussBadge(row: Locator, count: number): Promise<void> {
	await expect(
		row.locator('.pc-assignment-discuss-badge .ant-badge-count'),
		`「討論」徽章數字應為 ${count}`,
	).toHaveAttribute('title', String(count), { timeout: 30_000 })
}

/** 讀指定標題的統計卡數值（`.ant-statistic` 內含 title 與 content；以精確文字比對，避免「通過」命中「未通過」） */
async function getStatValue(page: Page, title: string): Promise<string> {
	const card = page.locator('.ant-statistic').filter({ has: page.getByText(title, { exact: true }) })
	await expect(card, `統計卡「${title}」應存在`).toBeVisible({ timeout: 15_000 })
	return (await card.locator('.ant-statistic-content').innerText()).trim()
}

/** 在預設篩選下按「匯出批改用 CSV」並取得下載檔 */
async function exportGradingCsvViaUi(page: Page): Promise<{ bytes: Buffer; text: string; fileName: string }> {
	const exportBtn = page.getByRole('button', { name: '匯出批改用 CSV' })
	await expect(exportBtn, '工具列應有「匯出批改用 CSV」').toBeVisible({ timeout: 15_000 })
	const downloadPromise = page.waitForEvent('download', { timeout: 30_000 })
	await exportBtn.click()
	const download = await downloadPromise
	return { ...(await readDownload(download)), fileName: download.suggestedFilename() }
}

// ── 共用狀態 ────────────────────────────────────────
let admin: AdminSession
let fx: FixtureData
let studentP: StudentSession

/**
 * 建立固定資料（一輪 run 只在第一個 worker 執行一次）：
 * 課程 + 章節 + 6 份作業 + 學員 P、G（有觀看權、各自 loginAs() 後繳交）+ G 的批改 + XSS 那筆的學員訊息
 */
async function createFixture(browser: Browser, adminSession: AdminSession): Promise<FixtureData> {
	const api = adminSession.api

	const created = await api.createCourseWithChapters(
		COURSE_TITLE,
		'0',
		[{ name: 'E2E 討論串匯入章節', slug: 'e2e-thread-import-chapter' }],
		'e2e-assignment-thread-import-admin-course',
	)
	const chapterId = created.chapterIds[0]

	// 一個章節可有多份作業（feature 建立作業）；作業之間用標題區分
	const assignmentIds = {} as Record<AssignmentKey, number>
	for (const key of ASSIGNMENT_KEYS) {
		const resp = await api.pcPost<{ id: number }>('assignments', {
			chapter_id: chapterId,
			title: ASSIGNMENT[key],
			allow_text: 'yes',
			allow_file: 'yes',
			max_files: 5,
		})
		const id = (resp.data as { id?: number })?.id
		if (resp.status >= 400 || !id) {
			throw new Error(`建立測試作業「${ASSIGNMENT[key]}」失敗: ${JSON.stringify(resp.data)}`)
		}
		assignmentIds[key] = id
	}

	// 兩位學員 + 課程觀看權（第 5 參數帶顯示名稱，列表的學員姓名欄才有中文）
	const studentPId = await api.ensureUserViaPowerhouse(
		STUDENT_P.username,
		STUDENT_P.email,
		STUDENT_P.password,
		['subscriber'],
		STUDENT_P.name,
	)
	const studentGId = await api.ensureUserViaPowerhouse(
		STUDENT_G.username,
		STUDENT_G.email,
		STUDENT_G.password,
		['subscriber'],
		STUDENT_G.name,
	)
	await api.grantCourseAccess(studentPId, created.courseId)
	await api.grantCourseAccess(studentGId, created.courseId)

	// 學員登入（loginAs）取得 nonce，各自繳交
	const loginP = await loginStudent(browser, STUDENT_P)
	const loginG = await loginStudent(browser, STUDENT_G)
	try {
		const submissionIds = {} as Record<AssignmentKey, number>
		for (const key of ASSIGNMENT_KEYS) {
			const session = key === 'graded' ? loginG : loginP
			submissionIds[key] = await submitAsStudent(session, assignmentIds[key], `E2E ${ASSIGNMENT[key]} 的繳交內容`)
		}

		// G 的繳交：管理員 REST 批改為 fail（第 0 條要它「不在預設待批改列表與預設匯出內」）
		const gradeG = await api.pcPatch(`assignment-submissions/${submissionIds.graded}/grade`, {
			status: 'fail',
			feedback: GRADED_FEEDBACK,
		})
		if (gradeG.status >= 400) {
			throw new Error(`學員 G 預先批改失敗（${gradeG.status}）: ${JSON.stringify(gradeG.data)}`)
		}

		// XSS 那筆的學員訊息：固定資料只送一次（重試時不重送，否則同一段文字會出現兩次，strict mode 會炸）
		await postStudentMessage(loginP, submissionIds.xss, XSS_BODY)

		return {
			adminNonce: adminSession.nonce,
			courseId: created.courseId,
			studentPId,
			studentGId,
			assignmentIds,
			submissionIds,
			studentP: { storageState: loginP.storageState, nonce: loginP.nonce },
			finished: [],
		}
	} finally {
		await loginP.context.close()
		await loginG.context.close()
	}
}

test.describe('作業批改頁：討論串與匯入批改結果 CSV（Issue #131 第二波 W3）', () => {
	test.beforeAll(async ({ browser }) => {
		// 第一個 worker 要建固定資料（admin 導覽 + 約 30 次 REST + 2 次可能各吃滿 NAV_TIMEOUT 的登入），
		// 本機站負載高時數分鐘起跳；之後的 worker 只還原。hook 的 timeout 要在 hook 內設。
		test.setTimeout(NAV_TIMEOUT * 6 + 300_000)

		// 同一輪 run 已有固定資料 → 還原；快取失效（課程不見了 / nonce 過期）→ 重建
		let cached = readFixture()
		let session: AdminSession | undefined
		if (cached) {
			session = await openAdmin(browser, cached.adminNonce)
			const probe = await session.api
				.pcGet(`courses/${cached.courseId}`)
				.catch(() => ({ status: 0 }))
			if (probe.status !== 200) {
				await session.context.close()
				session = undefined
				cached = undefined
				removeFixture()
			}
		}
		if (!cached || !session) {
			session = await openAdmin(browser)
			cached = await createFixture(browser, session)
			writeFixture(cached)
		}

		admin = session
		fx = cached
		studentP = await restoreStudent(browser, fx.studentP)
	})

	// 登記「最終結果出爐」的 test：通過、略過，或已用完重試次數（本機 retries: 1）。
	// 失敗但還會重試的嘗試不算，否則清理會在重試之前就把資料刪掉。
	test.afterEach(async ({}, testInfo) => {
		const isFinal =
			testInfo.status === 'passed' ||
			testInfo.status === 'skipped' ||
			testInfo.retry >= testInfo.project.retries
		if (!isFinal) return

		const state = readFixture()
		if (state && !state.finished.includes(testInfo.title)) {
			state.finished.push(testInfo.title)
			writeFixture(state)
		}
	})

	test.afterAll(async () => {
		test.setTimeout(NAV_TIMEOUT + 120_000)

		// 只有「所有 test 都已出爐」的那個 worker 才清資料；其他 worker 重啟時要沿用同一份
		const state = readFixture()
		if (admin && fx && state && state.finished.length >= EXPECTED_TEST_COUNT) {
			for (const studentId of [fx.studentPId, fx.studentGId]) {
				try {
					await admin.api.removeCourseAccess(studentId, fx.courseId)
				} catch {
					/* ignore */
				}
			}
			try {
				await admin.api.deleteCourses([fx.courseId])
			} catch {
				/* ignore */
			}
			// 本檔沒有上傳附件（D9 / D16 的「附件實體檔不刪」不適用）
			for (const studentId of [fx.studentPId, fx.studentGId]) {
				try {
					await admin.api.deleteUserViaPowerhouse(studentId)
				} catch {
					/* ignore */
				}
			}
			removeFixture()
		}

		if (studentP) await studentP.context.close()
		if (admin) await admin.context.close()
	})

	// ─────────────────────────────────────────────
	// 0. 預設狀態篩選：待批改（W3-G0）
	// ─────────────────────────────────────────────

	test('預設狀態篩選為「待批改」，列表與「匯出批改用 CSV」都只含待批改的繳交 @happy', async ({
		page,
	}) => {
		// 對照組先做：不套 status 篩選的匯出「確實」含已批改學員 G，下面「預設匯出不含 G」才不是空洞的
		await test.step('對照組：不篩選狀態的匯出同時含學員 P 與已批改的學員 G', async () => {
			const res = await exportGradingCsvViaRest(admin, { course_id: String(fx.courseId) })
			expect(res.status, '對照組匯出應為 200').toBe(200)
			expect(res.text, '不篩選時應含學員 P 的 Email').toContain(STUDENT_P.email)
			expect(res.text, '不篩選時應含已批改學員 G 的 Email（對照組：否則「預設匯出不含 G」是空洞的）').toContain(
				STUDENT_G.email,
			)
		})

		await openGradingPage(page)

		await test.step('狀態篩選預設選中「待批改」', async () => {
			const filter = page.locator('.pc-assignment-status-filter')
			await expect(filter, '應有狀態篩選區塊').toBeVisible({ timeout: 30_000 })
			const checked = filter.locator('.ant-radio-button-wrapper-checked')
			await expect(checked, '狀態篩選應恰有一個選中項').toHaveCount(1, { timeout: 15_000 })
			await expect(checked, '進入批改頁時，預設選中的狀態篩選應為「待批改」').toHaveText('待批改', {
				timeout: 15_000,
			})
		})

		await test.step('列表含待批改的繳交、不含已批改的那筆', async () => {
			const table = page.locator('.pc-assignment-submission-table')
			await expect(
				submissionRow(page, STUDENT_P.email, ASSIGNMENT.pending),
				'學員 P 待批改的繳交應在預設列表內',
			).toBeVisible({ timeout: 30_000 })
			await expect(
				table.locator('tr.ant-table-row').filter({ hasText: STUDENT_G.email }),
				'已批改（fail）學員 G 的繳交不應出現在預設的「待批改」列表',
			).toHaveCount(0)
			await expect(
				table.locator('tr.ant-table-row').filter({ hasNotText: '待批改' }),
				'預設列表的每一列狀態都應是「待批改」',
			).toHaveCount(0)
		})

		await test.step('預設狀態下「匯出批改用 CSV」只含待批改學員', async () => {
			const exported = await exportGradingCsvViaUi(page)
			expect(exported.fileName).toMatch(/^submissions-grades-\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2}\.csv$/)
			expect(exported.text, '匯出檔應含待批改學員 P 的 Email').toContain(STUDENT_P.email)
			expect(exported.text, '匯出檔應含學員 P 待批改那筆的 submission_id').toContain(
				String(fx.submissionIds.pending),
			)
			expect(
				exported.text,
				'預設（待批改）狀態下匯出的 CSV 不應含已批改學員 G 的 Email',
			).not.toContain(STUDENT_G.email)
		})
	})

	// ─────────────────────────────────────────────
	// 1. 「討論」徽章與抽屜內的討論串（W3-G1）
	// ─────────────────────────────────────────────

	test('列表「討論」徽章顯示 1，開詳情看到學員訊息，關閉抽屜後徽章歸 0 @happy', async ({ page }) => {
		const studentMsg = `E2E 學員提問（徽章）${uniq()}`

		await test.step('前置：管理員先讀一次把舊訊息標為已讀，學員再發一則新訊息（未讀恰為 1）', async () => {
			const reset = await admin.api.pcGet<ThreadMessage[]>(
				`assignment-submissions/${fx.submissionIds.badge}/messages`,
			)
			expect(reset.status, `管理員讀討論串應為 200: ${JSON.stringify(reset.data)}`).toBe(200)
			await postStudentMessage(studentP, fx.submissionIds.badge, studentMsg)
		})

		await openGradingPage(page)
		const row = submissionRow(page, STUDENT_P.email, ASSIGNMENT.badge)
		await expect(row, '學員 P 的繳交列應在預設列表內').toBeVisible({ timeout: 30_000 })

		await test.step('列表「討論」徽章顯示 1', async () => {
			await expectDiscussBadge(row, 1)
		})

		await openDetail(page, row)

		await test.step('討論串顯示學員訊息（標示「學員」、含時間）', async () => {
			const thread = threadOf(page)
			await expect(thread, '詳情抽屜應有討論串區塊（.pc-assignment-thread）').toBeVisible({
				timeout: 30_000,
			})
			const item = messageOf(thread, studentMsg)
			await expect(item, '討論串應顯示學員剛發的訊息').toBeVisible({ timeout: 30_000 })
			await expect(item.getByText('學員', { exact: true }), '學員訊息應標示「學員」').toBeVisible()
			await expect(item.getByText('老師', { exact: true }), '學員訊息不應標示「老師」').toHaveCount(0)
			await expect(item, '訊息應顯示時間（含 HH:mm）').toContainText(/\d{2}:\d{2}/)
			// 學員發的訊息不是管理員「己方」的訊息，不顯示「已讀」
			await expect(item.getByText('已讀', { exact: true }), '己方才顯示「已讀」，學員訊息不應有').toHaveCount(0)
		})

		await test.step('關閉抽屜後徽章歸 0（開抽屜即讀取並標已讀，列表重新取得）', async () => {
			await page.locator('.ant-drawer-close').click()
			await expect(page.locator('.pc-assignment-detail'), '抽屜應已關閉').toBeHidden({ timeout: 15_000 })
			await waitForSkeletonGone(page)
			await expectDiscussBadge(row, 0)
		})
	})

	// ─────────────────────────────────────────────
	// 2. 抽屜內回覆（W3-G1）
	// ─────────────────────────────────────────────

	test('抽屜內輸入回覆按 Enter 出現「老師」訊息，學員端收得到，學員讀過後顯示「已讀」 @happy', async ({
		page,
	}) => {
		const stamp = uniq()
		const studentMsg = `E2E 學員提問（回覆）${stamp}`
		const replyText = `E2E 老師回覆：請參考 1-1 的範例 ${stamp}`

		await test.step('前置：學員先發一則訊息（讓討論串裡有學員的一方）', async () => {
			await postStudentMessage(studentP, fx.submissionIds.reply, studentMsg)
		})

		await openGradingPage(page)
		const row = submissionRow(page, STUDENT_P.email, ASSIGNMENT.reply)
		await expect(row, '學員 P 的繳交列應在預設列表內').toBeVisible({ timeout: 30_000 })
		await openDetail(page, row)

		const thread = threadOf(page)
		await expect(thread, '詳情抽屜應有討論串區塊（.pc-assignment-thread）').toBeVisible({
			timeout: 30_000,
		})
		await expect(messageOf(thread, studentMsg), '討論串應先載入學員的訊息').toBeVisible({
			timeout: 30_000,
		})

		await test.step('前端 trim 後為空不送：只有空白的訊息按 Enter 不會建立訊息', async () => {
			const input = thread.getByRole('textbox')
			await expect(input, '討論串應有回覆輸入列（單行 Input）').toBeVisible()
			await input.fill('   ')
			await input.press('Enter')
			// 是否真的沒送出，等下面「學員端 REST 讀回的每則訊息本文 trim 後都不為空」才驗得到
		})

		await test.step('輸入回覆按 Enter，新訊息出現在討論串（標示「老師」、尚未已讀）', async () => {
			const input = thread.getByRole('textbox')
			const postResponse = page.waitForResponse(
				(resp) =>
					resp.url().includes(`/assignment-submissions/${fx.submissionIds.reply}/messages`) &&
					resp.request().method() === 'POST' &&
					resp.status() < 400,
				{ timeout: 60_000 },
			)
			await input.fill(replyText)
			await input.press('Enter')
			await postResponse

			const item = messageOf(thread, replyText)
			await expect(item, 'Enter 送出後，討論串應就地出現老師剛送的訊息').toBeVisible({ timeout: 30_000 })
			await expect(item.getByText('老師', { exact: true }), '管理員送出的訊息應標示「老師」').toBeVisible()
			await expect(item.getByText('學員', { exact: true }), '老師訊息不應標示「學員」').toHaveCount(0)
			await expect(item, '訊息應顯示時間（含 HH:mm）').toContainText(/\d{2}:\d{2}/)
			await expect(
				item.getByText('已讀', { exact: true }),
				'學員尚未讀取，老師訊息（read_at 為空）不應顯示「已讀」',
			).toHaveCount(0)
		})

		await test.step('以學員 REST 讀取：收到 mine = false 的老師訊息；空白訊息沒有被送出', async () => {
			const res = await getMyThread(studentP, fx.submissionIds.reply)
			expect(res.status, '學員讀自己的討論串應為 200').toBe(200)

			const teacherMsg = res.messages.find((m) => m.body === replyText)
			expect(teacherMsg, `學員端應讀到老師的訊息，實際: ${JSON.stringify(res.messages.map((m) => m.body))}`).toBeDefined()
			expect(teacherMsg?.sender_type, '管理員送出的訊息 sender_type 應為 teacher').toBe('teacher')
			expect(teacherMsg?.mine, '對學員而言老師的訊息 mine 應為 false').toBe(false)
			expect(
				res.messages.every((m) => m.body.trim() !== ''),
				`只有空白的訊息不應被建立，實際: ${JSON.stringify(res.messages.map((m) => m.body))}`,
			).toBe(true)
		})

		// 上一步學員的 GET 已把老師訊息標為已讀；另開一頁（全新載入）重新開抽屜才讀得到最新狀態
		await test.step('學員讀過後，管理員重新開抽屜：老師訊息顯示「已讀」，學員訊息不顯示', async () => {
			const page2 = await page.context().newPage()
			await openGradingPage(page2)
			const row2 = submissionRow(page2, STUDENT_P.email, ASSIGNMENT.reply)
			await expect(row2, '重新載入後學員 P 的繳交列應在預設列表內').toBeVisible({ timeout: 30_000 })
			await openDetail(page2, row2)

			const thread2 = threadOf(page2)
			const teacherItem = messageOf(thread2, replyText)
			await expect(teacherItem, '重新載入後應讀到老師的訊息').toBeVisible({ timeout: 30_000 })
			await expect(
				teacherItem.getByText('已讀', { exact: true }),
				'學員已讀取，己方（老師）訊息 read_at 不為空，應顯示「已讀」',
			).toBeVisible({ timeout: 15_000 })
			await expect(
				messageOf(thread2, studentMsg).getByText('已讀', { exact: true }),
				'學員的訊息不是管理員己方的訊息，不應顯示「已讀」',
			).toHaveCount(0)
			await page2.close()
		})
	})

	// ─────────────────────────────────────────────
	// 3. XSS（W3-G1）
	// ─────────────────────────────────────────────

	test('學員訊息含 HTML 時以純文字顯示，不產生 img 也不執行 onerror @security', async ({ page }) => {
		await test.step('前置：學員送出的 HTML 經 API 原樣存取（不是被伺服器清掉才「看起來安全」）', async () => {
			const res = await getMyThread(studentP, fx.submissionIds.xss)
			expect(res.status, '學員讀自己的討論串應為 200').toBe(200)
			expect(
				res.messages.some((m) => m.body === XSS_BODY),
				`API 應原樣回傳 HTML 字串，實際: ${JSON.stringify(res.messages.map((m) => m.body))}`,
			).toBe(true)
		})

		await openGradingPage(page)
		const row = submissionRow(page, STUDENT_P.email, ASSIGNMENT.xss)
		await expect(row, '學員 P 的繳交列應在預設列表內').toBeVisible({ timeout: 30_000 })
		await openDetail(page, row)

		const thread = threadOf(page)
		await expect(thread, '詳情抽屜應有討論串區塊（.pc-assignment-thread）').toBeVisible({
			timeout: 30_000,
		})
		const item = messageOf(thread, XSS_BODY)

		await test.step('HTML 字串以文字顯示', async () => {
			await expect(item, '討論串應把 <img ...> 當文字顯示出來').toBeVisible({ timeout: 30_000 })
			await expect(item).toContainText(XSS_BODY)
		})

		await test.step('討論串內沒有 img 元素', async () => {
			await expect(thread.locator('img'), '討論串容器內不應出現任何 img（代表 HTML 被當成標籤解析了）').toHaveCount(0)
		})

		// 對照組：同一段手法若走 innerHTML，onerror 在這個瀏覽器「確實會」執行；
		// 等對照組的 onerror 觸發後，才有資格說「討論串那一段沒執行」不是因為還沒輪到
		await test.step('對照組：以 innerHTML 注入同類 HTML，onerror 會執行', async () => {
			await page.evaluate(() => {
				const probe = document.createElement('div')
				probe.innerHTML = '<img src=x onerror="window.__pcXssControl=1">'
				document.body.appendChild(probe)
			})
			await expect
				.poll(() => page.evaluate(() => (window as unknown as { __pcXssControl?: number }).__pcXssControl), {
					timeout: 15_000,
					message: '對照組的 onerror 應在這個瀏覽器執行；若沒有，下面「沒執行」就不具鑑別力',
				})
				.toBe(1)
		})

		await test.step('討論串的 onerror 沒有執行（window.__pcXss 為 undefined）', async () => {
			const fired = await page.evaluate(() => (window as unknown as { __pcXss?: number }).__pcXss)
			expect(fired, '學員訊息中的 onerror 不應被執行').toBeUndefined()
		})
	})

	// ─────────────────────────────────────────────
	// 4. 匯入批改結果 CSV（W3-G2）
	// ─────────────────────────────────────────────

	test('匯入兩列 CSV（pass 與 ok）：結果顯示共 2 筆、更新 1、略過 1、失敗 0 與第 3 列錯誤，通過的那筆離開待批改列表並更新統計 @happy', async ({
		page,
	}) => {
		const importPassId = fx.submissionIds.importPass
		const skippedId = fx.submissionIds.pending

		await test.step('前置：把「匯入通過」那筆重設為待批改（重試時不依賴上一次嘗試）', async () => {
			const reset = await admin.api.pcPatch(`assignment-submissions/${importPassId}/grade`, {
				status: 'pending',
				feedback: null,
			})
			expect(reset.status, `重設批改狀態回應非 200: ${JSON.stringify(reset.data)}`).toBe(200)
		})

		await openGradingPage(page)
		const rowImport = submissionRow(page, STUDENT_P.email, ASSIGNMENT.importPass)
		await expect(rowImport, '匯入前，該筆應在預設的「待批改」列表內').toBeVisible({ timeout: 30_000 })
		await waitForSkeletonGone(page)
		const passBefore = Number(await getStatValue(page, '通過'))
		const pendingBefore = Number(await getStatValue(page, '待批改'))

		const csv = buildCsv(
			[
				[HEADER_ID, HEADER_STATUS, HEADER_FEEDBACK],
				[String(importPassId), 'pass', IMPORT_FEEDBACK],
				[String(skippedId), 'ok', '狀態寫錯的一列'],
			],
			{ bom: true },
		)
		// 防守：確認檔案真的是 BOM + CRLF（測試資料本身不是它宣稱的樣子會造成偽陽性 / 偽陰性）
		expect(csv.subarray(0, 3).equals(UTF8_BOM), '測試用 CSV 應以 UTF-8 BOM 開頭').toBe(true)
		expect(csv.toString('utf-8').includes('\r\n'), '測試用 CSV 應為 CRLF 換行').toBe(true)

		await test.step('工具列有「匯入批改 CSV」，選檔上傳', async () => {
			await expect(
				page.getByRole('button', { name: '匯入批改 CSV' }),
				'工具列應有「匯入批改 CSV」按鈕（匯出按鈕旁）',
			).toBeVisible({ timeout: 15_000 })

			const fileInput = page.locator('input[type="file"][accept*="csv"]')
			await expect(fileInput, '應有接受 CSV 的檔案欄位').toHaveCount(1)

			const importResponse = page.waitForResponse(
				(resp) =>
					resp.url().includes('/assignment-submissions/import') && resp.request().method() === 'POST',
				{ timeout: 60_000 },
			)
			await fileInput.setInputFiles({
				name: 'grades-import-w3.csv',
				mimeType: 'text/csv',
				buffer: csv,
			})
			const resp = await importResponse
			expect(resp.status(), '匯入 API 應回 200').toBe(200)
		})

		await test.step('頁內結果顯示共 2 筆、更新 1、略過 1、失敗 0 與「第 3 列」錯誤', async () => {
			const result = page.locator('.pc-assignment-import-result')
			await expect(result, '匯入後應在頁內顯示結果（.pc-assignment-import-result）').toBeVisible({
				timeout: 30_000,
			})
			await expect(result).toContainText(/共\s*2\s*筆/)
			await expect(result).toContainText(/更新\s*1(?!\d)/)
			await expect(result).toContainText(/略過\s*1(?!\d)/)
			await expect(result).toContainText(/失敗\s*0(?!\d)/)
			// 錯誤訊息文字依站台語系，只斷言列號與狀態原值（CSV 第 1 列是表頭，ok 那列是第 3 列）
			await expect(result, '錯誤列應標出「第 3 列」').toContainText(/第\s*3\s*列/)
			await expect(result, '錯誤訊息應帶出無法辨識的狀態原值 ok').toContainText('ok')
		})

		await test.step('通過的那筆離開預設的「待批改」列表', async () => {
			await expect(rowImport, '匯入為通過後，該筆不應再出現在「待批改」列表').toHaveCount(0, {
				timeout: 30_000,
			})
		})

		await test.step('切到「通過」篩選，該筆顯示「通過」', async () => {
			await page.locator('.pc-assignment-status-filter').getByText('通過', { exact: true }).click()
			await expect(rowImport, '「通過」篩選下應看到剛匯入為通過的那筆').toBeVisible({ timeout: 30_000 })
			await expect(rowImport.getByText('通過', { exact: true }), '該筆狀態應顯示「通過」').toBeVisible()
		})

		await test.step('統計卡更新：通過 +1、待批改 -1', async () => {
			await waitForSkeletonGone(page)
			await expect
				.poll(async () => getStatValue(page, '通過'), { timeout: 30_000 })
				.toBe(String(passBefore + 1))
			await expect
				.poll(async () => getStatValue(page, '待批改'), { timeout: 30_000 })
				.toBe(String(pendingBefore - 1))
		})
	})

	// ─────────────────────────────────────────────
	// 5. 匯出再匯入的往返（W3-G2）
	// ─────────────────────────────────────────────

	test('在預設篩選下匯出批改用 CSV，原檔直接匯回：失敗 0、略過 0 @happy', async ({ page }) => {
		await openGradingPage(page)
		await expect(
			submissionRow(page, STUDENT_P.email, ASSIGNMENT.pending),
			'學員 P 待批改的繳交應在預設列表內',
		).toBeVisible({ timeout: 30_000 })

		const exported = await exportGradingCsvViaUi(page)

		// 防守：匯出檔真的有內容（匯入 N 列才有意義），且資料列數就是後面要對的 N
		const rows = parseCsv(exported.text)
		const dataRows = rows.length - 1
		expect(rows[0]?.[0], '匯出檔第一欄表頭應為 submission_id').toBe('submission_id')
		// 學員 P 至少有 pending / badge / reply / xss 四筆整輪都維持待批改
		expect(dataRows, `匯出檔資料列數應 >= 4，實際: ${dataRows}`).toBeGreaterThanOrEqual(4)

		await test.step('把下載檔原封不動餵給匯入', async () => {
			await expect(
				page.getByRole('button', { name: '匯入批改 CSV' }),
				'工具列應有「匯入批改 CSV」按鈕（匯出按鈕旁）',
			).toBeVisible({ timeout: 15_000 })

			const fileInput = page.locator('input[type="file"][accept*="csv"]')
			await expect(fileInput, '應有接受 CSV 的檔案欄位').toHaveCount(1)

			const importResponse = page.waitForResponse(
				(resp) =>
					resp.url().includes('/assignment-submissions/import') && resp.request().method() === 'POST',
				{ timeout: 90_000 },
			)
			await fileInput.setInputFiles({
				name: exported.fileName,
				mimeType: 'text/csv',
				buffer: exported.bytes,
			})
			const resp = await importResponse
			expect(resp.status(), '匯入 API 應回 200').toBe(200)
		})

		await test.step('頁內結果：全部列都更新、失敗 0、略過 0、沒有錯誤列', async () => {
			const result = page.locator('.pc-assignment-import-result')
			await expect(result, '匯入後應在頁內顯示結果（.pc-assignment-import-result）').toBeVisible({
				timeout: 60_000,
			})
			await expect(result).toContainText(new RegExp(`共\\s*${dataRows}\\s*筆`))
			await expect(result).toContainText(new RegExp(`更新\\s*${dataRows}(?!\\d)`))
			await expect(result).toContainText(/略過\s*0(?!\d)/)
			await expect(result).toContainText(/失敗\s*0(?!\d)/)
			await expect(result, '往返匯入不應有任何錯誤列').not.toContainText(/第\s*\d+\s*列/)
		})
	})
})
