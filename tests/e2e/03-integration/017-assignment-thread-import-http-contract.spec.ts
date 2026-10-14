/**
 * 測試目標：作業討論串與「匯入批改結果 CSV」的真實 HTTP 契約（Issue #131 第二波 W2-R2）
 * 對應原始碼：
 *   - inc/classes/Resources/Assignment/Core/Api.php::post_assignment_submissions_import_callback
 *     （POST /power-course/assignment-submissions/import；W2 尚未實作，本檔相關測試為紅燈）
 *   - inc/classes/Resources/Assignment/Core/MyApi.php::get_my_assignment_submissions_with_id_messages_callback
 *     （GET /power-course/my/assignment-submissions/{id}/messages；W1 已實作，本檔相關測試為守門）
 * 對應規格：specs/features/assignment/匯入批改結果CSV.feature、查詢作業討論串.feature
 *
 * 為什麼要用 E2E 而非只靠 PHPUnit（見計劃 wave2-implementation-plan.md §11 W2 Red-2、§13 第 5 點、
 * 風險 R-W2-02 / 04 / 05 / 08）：
 * - R-W2-08：`is_uploaded_file()` 在 PHPUnit CLI 下永遠不成立，匯入 callback 「讀暫存檔、解析、
 *   actor = 目前使用者」這一段只有真實 multipart 上傳才走得到。
 * - R-W2-04：Excel 實際產出的 CSV 位元組（BOM、CRLF、引號跳脫、評語內換行）要用「站台真的匯出的檔案」
 *   原封不動餵回去才踏實，PHPUnit 的 `Csv::write_grading_csv()` 串流不經過 HTTP 與站台語系。
 * - R-W2-05（R-21）：匯出表頭依站台實際語系翻譯，往返在這裡才是真語系。
 * - R-W2-02：討論串 GET 有「標已讀」副作用，必須確認真實 HTTP stack 沒有剝除禁止快取標頭。
 *
 * 不使用 test.describe.serial()：第一波 016 的教訓是序列中一支紅，其餘全部 did not run，紅燈原因被遮住。
 * 需要順序的流程寫在同一個 test 內用 test.step() 分段；會改動資料的測試一開頭先把自己要的前置狀態
 * 用管理員 REST 重設（批改為指定狀態），所以不依賴其他測試的副作用，重試也不會讀到上一次嘗試留下的髒資料。
 *
 * 共用資料與「worker 重啟」：Playwright 在任何一個 test 失敗後都會丟掉 worker、重開新 worker 並重跑 beforeAll。
 * 紅燈階段 6 支必紅 ×（首次 + 重試）= 12 次重啟，若每次都重建「課程 + 學員登入 + 繳交 + 討論訊息」，
 * 本機站實測一次 beforeAll 就要好幾分鐘，整輪會超過一小時。所以固定資料只在「這一輪 run 的第一個 worker」
 * 建一次，寫進 os.tmpdir() 下以 Playwright runner PID（process.ppid，同一輪的所有 worker 共用）命名的 JSON，
 * 之後的 worker 直接還原（學員 session 用 storageState + 快取的 nonce，不用重新登入）。
 * 清理：afterEach 在每個 test「最終結果出爐」時登記，登記數達 EXPECTED_TEST_COUNT 的那個 worker 的 afterAll
 * 才刪課程與學員並移除快取檔；用 --grep 只跑一部分時不會清（殘留的 E2E 課程由下一輪 global-setup 清掉）。
 *
 * 預期結果（Green 完成前）：
 * - 紅燈 6 支（匯入路由尚不存在 → 404 rest_no_route，不是登入 / nonce / 憑證 / license gate 問題）：
 *   1. 管理員真實 multipart 匯入
 *   2. 匯出 → 原封匯入往返
 *   3. 無法解析的 CSV 仍回 200（row 0）
 *   4a. 學員呼叫匯入 → 403
 *   4b. 訪客呼叫匯入 → 401
 *   4c. 管理員不帶檔 → 400
 * - 守門 3 支（W1 已實作，本階段本來就綠；理由「W1 已實作；017 驗真實 HTTP 層」）：
 *   5.  學員 A 讀自己的討論串 → 200 + Cache-Control 含 no-cache
 *       （注意：WP 核心對已登入 REST 請求本來就送 no-cache，class-wp-rest-server.php L487–501，
 *        本條只證明 stack 未剝除標頭，不證明 callback 有呼叫 nocache_headers()；
 *        後者由 PHPUnit 的 token_get_all() 靜態檢查守住）
 *   6a. 學員 A 讀 B 的討論串 → 403
 *   6b. 訪客讀討論串 → 401
 *
 * 不斷言：直連受保護目錄的附件檔會被擋（本機是 Nginx，R-04）；匯入 / 匯出的訊息字面值依站台語系，
 * 只斷言 status + code（摘要中的列號、計數與 submission_id 等與語系無關的欄位照常斷言）。
 */
import fs from 'fs'
import os from 'os'
import path from 'path'
import { test, expect } from '@playwright/test'
import type { APIRequestContext, APIResponse, Browser, BrowserContext } from '@playwright/test'
import { ApiClient, getNonceFromPage } from '../helpers/api-client.js'
import { loginAs } from '../helpers/frontend-setup.js'

const BASE_URL = process.env.TEST_SITE_URL || 'http://localhost:8889'

// 本機站冷啟動時導覽很慢（memory: reference_local_frontend_verify_playbook / project_e2e_helper_traps）。
// 可用 E2E_ADMIN_NAV_TIMEOUT 覆寫，預設 90s。
const NAV_TIMEOUT = Number(process.env.E2E_ADMIN_NAV_TIMEOUT || 90_000)

/** UTF-8 BOM 位元組（EF BB BF）：匯出檔以此開頭，Excel 開啟中文才不會亂碼 */
const UTF8_BOM = Buffer.from([0xef, 0xbb, 0xbf])

/** 本檔 test 數：afterEach 登記數達此值才清理固定資料（新增 / 刪除 test 時要同步修改） */
const EXPECTED_TEST_COUNT = 9

/** 固定資料快取檔：以 runner PID 命名，同一輪 run 的所有 worker 共用（見檔頭「worker 重啟」） */
const FIXTURE_FILE = path.join(os.tmpdir(), `pc-017-assignment-w2-fixture-${process.ppid}.json`)

// ── 測試用學員（Powerhouse V2 API 建立，避開本站移除的 /wp/v2/users） ──
// 帶中文顯示名稱：匯出 CSV 的「學員姓名」欄會出現 UTF-8 多位元組字元，往返才有意義。
const STUDENT_A = {
	username: 'e2e_assign_w2_a_131',
	email: 'e2e_assign_w2_a_131@test.local',
	password: 'e2eAssignW2A131!Pass',
	name: 'E2E 匯入學員甲',
}
const STUDENT_B = {
	username: 'e2e_assign_w2_b_131',
	email: 'e2e_assign_w2_b_131@test.local',
	password: 'e2eAssignW2B131!Pass',
	name: 'E2E 匯入學員乙',
}

// ── 匯入 CSV 的表頭（與 feature 一致；依表頭名稱定位，不依位置） ──
const HEADER_ID = 'submission_id'
const HEADER_STATUS = '狀態(可改 pending/pass/fail)'
const HEADER_FEEDBACK = '講師回饋(可改)'

// ── 討論串訊息 ──
const MSG_A_QUESTION = 'E2E 學員甲請問：變數要怎麼宣告？'
const MSG_TEACHER_REPLY = 'E2E 老師回覆：請參考 1-1 的範例。'
const MSG_B_PRIVATE = 'E2E 學員乙的私密提問：只有乙與老師看得到。'

// ── 往返測試用評語：含逗號、雙引號、換行（LF / CRLF），逐字保留才算往返成功 ──
const FEEDBACK_ROUNDTRIP_A = '做得好, 但請留意 "命名" 規則\n第二行：換行也要原封不動'
const FEEDBACK_ROUNDTRIP_B = '未通過, 請補上 "型別說明"\r\n第二行：CRLF 換行'

// ── 型別 ──

type StorageStateData = Awaited<ReturnType<BrowserContext['storageState']>>

interface ImportSummary {
	total: number
	updated: number
	skipped: number
	failed: number
	errors: { row: number; message: string }[]
}

interface SubmissionItem {
	id: number
	status: string
	feedback: string | null
	graded_by: number | null
	graded_at: string | null
}

interface ThreadMessage {
	id: number
	sender_type: string
	body: string
	read_at: string | null
	created_at: string
	mine: boolean
}

interface UploadFile {
	name: string
	mimeType: string
	buffer: Buffer
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
	studentAId: number
	studentBId: number
	/** 唯一一份作業；A、B 各有一筆繳交 */
	assignmentId: number
	subA: number
	subB: number
	studentA: StudentCache
	studentB: StudentCache
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
 * 建立管理員 context：同時提供 ApiClient（JSON 資料準備）與裸的 request + nonce（multipart / 讀 CSV 位元組）
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
 * 落地頁（/my-account/）尚未載完，此時 wpApiSettings 還不存在，所以再導向首頁並等 wpApiSettings 出現
 * （2026-10-02 實測：訂閱者在 / 與 /my-account/ 載完後都有 wpApiSettings.nonce）。
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

/** 建立未登入（訪客）用的 request context：沒有 cookie、沒有 nonce */
async function createGuestContext(
	browser: Browser,
): Promise<{ context: BrowserContext; request: APIRequestContext }> {
	const context = await browser.newContext({ ignoreHTTPSErrors: true })
	return { context, request: context.request }
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

/** 取 REST 錯誤回應的 code（非物件時回空字串） */
function errorCode(data: unknown): string {
	if (data !== null && typeof data === 'object') {
		const code = (data as { code?: unknown }).code
		return typeof code === 'string' ? code : String(code ?? '')
	}
	return ''
}

/** 以 multipart/form-data 呼叫「匯入批改結果 CSV」；file 為 null 時送出不含 file 欄位的 multipart */
async function importGradesCsv(
	requestCtx: APIRequestContext,
	nonce: string,
	file: UploadFile | null,
): Promise<{ status: number; text: string; data: unknown }> {
	const headers: Record<string, string> = {}
	if (nonce) headers['X-WP-Nonce'] = nonce

	const resp = await requestCtx.post(`${BASE_URL}/wp-json/power-course/assignment-submissions/import`, {
		headers,
		// 不帶檔時仍送 multipart（有別的欄位但沒有 file）：要驗的是「檔案缺漏」而非「整個請求沒有 body」
		multipart: file ? { file } : { note: 'no file attached' },
		timeout: 60_000,
	})
	const { text, data } = await readJson(resp)
	return { status: resp.status(), text, data }
}

/** 呼叫「匯出批改用 CSV」，回傳原始位元組（不 decode，往返才是「原封不動」） */
async function exportGradingCsv(
	requestCtx: APIRequestContext,
	nonce: string,
	params: Record<string, string>,
): Promise<{ status: number; body: Buffer }> {
	const url = new URL(`${BASE_URL}/wp-json/power-course/assignment-submissions/export`)
	Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v))

	const resp = await requestCtx.get(url.toString(), {
		headers: { 'X-WP-Nonce': nonce },
		timeout: 60_000,
	})
	const body = await resp.body().catch(() => Buffer.alloc(0))
	return { status: resp.status(), body }
}

/** 學員讀討論串（GET my/assignment-submissions/{id}/messages），回傳原始 APIResponse 以便讀標頭 */
async function getMyThread(
	requestCtx: APIRequestContext,
	nonce: string,
	submissionId: number,
): Promise<APIResponse> {
	const headers: Record<string, string> = {}
	if (nonce) headers['X-WP-Nonce'] = nonce

	return requestCtx.get(
		`${BASE_URL}/wp-json/power-course/my/assignment-submissions/${submissionId}/messages`,
		{ headers, timeout: 60_000 },
	)
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

/**
 * 管理員 REST 批改（PATCH assignment-submissions/{id}/grade），回傳批改後的 graded_by
 *
 * 這是各 test 重設自己前置狀態的手段：不論上一次嘗試或上一個 test 留下什麼，批改成指定狀態後狀態就確定了。
 * 重設為 pending 時 graded_by 會是目前管理員（第一波行為：任何 status 都寫入批改者），所以「匯入後
 * graded_by 等於目前管理員」仍然有鑑別力——寫錯 actor（0 或別人）一定對不上。
 */
async function gradeAsAdmin(
	submissionId: number,
	status: 'pending' | 'pass' | 'fail',
	feedback: string | null,
): Promise<number> {
	const resp = await admin.api.pcPatch<{ graded_by?: number }>(
		`assignment-submissions/${submissionId}/grade`,
		{ status, feedback },
	)
	expect(resp.status, `重設批改狀態（${status}）回應非 200: ${JSON.stringify(resp.data)}`).toBe(200)

	const gradedBy = Number((resp.data as { graded_by?: number }).graded_by)
	expect(gradedBy, `批改回應應含目前管理員的 graded_by: ${JSON.stringify(resp.data)}`).toBeGreaterThan(0)
	return gradedBy
}

/** 管理員 REST 讀取作業的繳交列表，回傳 id → 繳交（GET assignment-submissions?assignment_id=） */
async function listSubmissions(
	api: ApiClient,
	assignmentId: number,
): Promise<Map<number, SubmissionItem>> {
	const resp = await api.pcGet<{ items?: SubmissionItem[] }>('assignment-submissions', {
		assignment_id: String(assignmentId),
		limit: '200',
	})
	expect(resp.status, `列表回應非 200: ${JSON.stringify(resp.data)}`).toBe(200)

	const items = (resp.data as { items?: SubmissionItem[] }).items
	expect(Array.isArray(items), `列表回應應含 items 陣列: ${JSON.stringify(resp.data)}`).toBe(true)

	return new Map((items as SubmissionItem[]).map((item) => [Number(item.id), item]))
}

/** 從列表 Map 取指定繳交；不存在時當場失敗並標出是哪個階段 */
function requireItem(
	list: Map<number, SubmissionItem>,
	submissionId: number,
	stage: string,
): SubmissionItem {
	const item = list.get(submissionId)
	expect(item, `${stage}：列表應含繳交 ${submissionId}`).toBeDefined()
	return item as SubmissionItem
}

// ── Helper：CSV 組裝 / 解析（測試側自備，與 production 的 CsvParser 無關） ──────

/** CSV 欄位跳脫：含逗號、雙引號、CR / LF 才加引號 */
function csvCell(value: string): string {
	return /[",\r\n]/.test(value) ? `"${value.replace(/"/g, '""')}"` : value
}

/** 組 CSV 位元組：每列以 CRLF 結尾，可選 UTF-8 BOM */
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

// ── 共用狀態 ────────────────────────────────────────
let admin: AdminSession
let fx: FixtureData
let studentA: StudentSession
let studentB: StudentSession
let guestContext: BrowserContext
let guestRequest: APIRequestContext

/**
 * 建立固定資料（一輪 run 只在第一個 worker 執行一次）：
 * 課程 + 章節 + 一份作業 + 學員 A、B（有觀看權、各自 loginAs() 後繳交一次）+ 作業討論串的三則訊息
 */
async function createFixture(browser: Browser, adminSession: AdminSession): Promise<FixtureData> {
	const api = adminSession.api

	// 課程 + 章節（名稱以 E2E 開頭，global-setup 才清得掉殘留）
	const created = await api.createCourseWithChapters(
		'E2E 作業討論串與匯入契約課程',
		'0',
		[{ name: 'E2E 討論串與匯入章節', slug: 'e2e-assign-w2-chapter' }],
		'e2e-assignment-thread-import-course',
	)

	// 作業標題含逗號：匯出 CSV「作業」欄會被加引號，往返才吃得到這條路徑
	const assignResp = await api.pcPost<{ id: number }>('assignments', {
		chapter_id: created.chapterIds[0],
		title: '練習：討論串與匯入, 含逗號',
		allow_text: 'yes',
		allow_file: 'yes',
		max_files: 5,
	})
	const assignmentId = (assignResp.data as { id?: number })?.id
	if (assignResp.status >= 400 || !assignmentId) {
		throw new Error(`建立測試作業失敗: ${JSON.stringify(assignResp.data)}`)
	}

	// 兩位學員 + 課程觀看權（第 5 參數帶顯示名稱，匯出的「學員姓名」欄才有中文）
	const studentAId = await api.ensureUserViaPowerhouse(
		STUDENT_A.username,
		STUDENT_A.email,
		STUDENT_A.password,
		['subscriber'],
		STUDENT_A.name,
	)
	const studentBId = await api.ensureUserViaPowerhouse(
		STUDENT_B.username,
		STUDENT_B.email,
		STUDENT_B.password,
		['subscriber'],
		STUDENT_B.name,
	)
	await api.grantCourseAccess(studentAId, created.courseId)
	await api.grantCourseAccess(studentBId, created.courseId)

	// 學員登入（loginAs）取得 nonce，各自繳交一次
	const loginA = await loginStudent(browser, STUDENT_A)
	const loginB = await loginStudent(browser, STUDENT_B)
	try {
		const subA = await submitAsStudent(loginA, assignmentId, 'E2E 學員甲的繳交')
		const subB = await submitAsStudent(loginB, assignmentId, 'E2E 學員乙的繳交')

		// 討論串：A 提問、老師回覆（管理員端 POST）、B 另有一則私密訊息（讓「讀他人討論串」的斷言不空洞）
		await postStudentMessage(loginA, subA, MSG_A_QUESTION)
		const teacherReply = await api.pcPost(`assignment-submissions/${subA}/messages`, {
			body: MSG_TEACHER_REPLY,
		})
		if (teacherReply.status >= 400) {
			throw new Error(`老師回覆失敗（${teacherReply.status}）: ${JSON.stringify(teacherReply.data)}`)
		}
		await postStudentMessage(loginB, subB, MSG_B_PRIVATE)

		return {
			adminNonce: adminSession.nonce,
			courseId: created.courseId,
			studentAId,
			studentBId,
			assignmentId,
			subA,
			subB,
			studentA: { storageState: loginA.storageState, nonce: loginA.nonce },
			studentB: { storageState: loginB.storageState, nonce: loginB.nonce },
			finished: [],
		}
	} finally {
		await loginA.context.close()
		await loginB.context.close()
	}
}

test.describe('作業討論串與匯入批改結果 HTTP 契約（Issue #131 第二波 W2-R2）', () => {
	test.beforeAll(async ({ browser }) => {
		// 第一個 worker 要建固定資料（admin 導覽 + 約 20 次 REST + 2 次可能各吃滿 NAV_TIMEOUT 的登入），
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
		studentA = await restoreStudent(browser, fx.studentA)
		studentB = await restoreStudent(browser, fx.studentB)
		const guest = await createGuestContext(browser)
		guestContext = guest.context
		guestRequest = guest.request
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
			for (const studentId of [fx.studentAId, fx.studentBId]) {
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
			// 附件實體檔不刪（D9 / D16）；本檔沒有上傳附件
			for (const studentId of [fx.studentAId, fx.studentBId]) {
				try {
					await admin.api.deleteUserViaPowerhouse(studentId)
				} catch {
					/* ignore */
				}
			}
			removeFixture()
		}

		if (studentA) await studentA.context.close()
		if (studentB) await studentB.context.close()
		if (guestContext) await guestContext.close()
		if (admin) await admin.context.close()
	})

	// ─────────────────────────────────────────────
	// 匯入批改結果 CSV（W2 尚未實作，以下 6 支為紅燈）
	// ─────────────────────────────────────────────

	test('管理員真實 multipart 匯入：200 與摘要正確，A 變通過（批改者＝目前管理員）、B 狀態 ok 被略過維持待批改 @happy', async () => {
		const { adminId, beforeB } = await test.step('前置：把 A、B 重設為待批改（feedback 空）並確認', async () => {
			const adminId = await gradeAsAdmin(fx.subA, 'pending', null)
			await gradeAsAdmin(fx.subB, 'pending', null)

			const list = await listSubmissions(admin.api, fx.assignmentId)
			for (const [label, id] of [
				['A', fx.subA],
				['B', fx.subB],
			] as const) {
				const item = requireItem(list, id, `匯入前（${label}）`)
				expect(item.status, `前置：${label} 應為 pending`).toBe('pending')
				expect(item.feedback ?? null, `前置：${label} 的評語應為空`).toBeNull()
			}
			return { adminId, beforeB: requireItem(list, fx.subB, '匯入前（B）') }
		})

		const csv = buildCsv(
			[
				[HEADER_ID, HEADER_STATUS, HEADER_FEEDBACK],
				[String(fx.subA), 'pass', '寫得很好'],
				[String(fx.subB), 'ok', '狀態寫錯的一列'],
			],
			{ bom: true },
		)
		// 防守：確認檔案真的是 BOM + CRLF（測試資料本身不是它宣稱的樣子會造成偽陽性 / 偽陰性）
		expect(csv.subarray(0, 3).equals(UTF8_BOM), '測試用 CSV 應以 UTF-8 BOM 開頭').toBe(true)
		expect(csv.toString('utf-8').includes('\r\n'), '測試用 CSV 應為 CRLF 換行').toBe(true)

		await test.step('以管理員身分 multipart 上傳（field 名 file）', async () => {
			const res = await importGradesCsv(admin.request, admin.nonce, {
				name: 'grades-import-017.csv',
				mimeType: 'text/csv',
				buffer: csv,
			})
			expect(res.status, `匯入回應非 200，body 前 500 字: ${res.text.slice(0, 500)}`).toBe(200)

			const data = res.data as ImportSummary
			expect(Object.keys(data).sort(), '回應鍵集合應恰為 AssignmentImportSummary 的 5 個欄位').toEqual([
				'errors',
				'failed',
				'skipped',
				'total',
				'updated',
			])
			expect({
				total: data.total,
				updated: data.updated,
				skipped: data.skipped,
				failed: data.failed,
			}).toEqual({ total: 2, updated: 1, skipped: 1, failed: 0 })

			// 列號含表頭：表頭 = 第 1 列、A = 第 2 列、B = 第 3 列
			expect(data.errors, '應只有 B 那一列的錯誤').toHaveLength(1)
			expect(data.errors[0].row).toBe(3)
			// 訊息依站台語系，不斷言字面值；只確認它點名了無法辨識的狀態原值
			expect(typeof data.errors[0].message).toBe('string')
			expect(data.errors[0].message).toContain('ok')
		})

		await test.step('匯入後：A 為通過且批改者是目前管理員（API 層確實讀了暫存檔、以目前使用者為 actor）、B 完全不變', async () => {
			const list = await listSubmissions(admin.api, fx.assignmentId)

			const a = requireItem(list, fx.subA, '匯入後（A）')
			expect(a.status).toBe('pass')
			expect(a.feedback).toBe('寫得很好')
			expect(a.graded_by, '批改者應為目前管理員').toBe(adminId)
			expect(a.graded_at, '批改時間應已寫入').not.toBeNull()

			expect(requireItem(list, fx.subB, '匯入後（B）'), '狀態無法辨識的列不可被套用').toEqual(beforeB)
		})
	})

	test('匯出批改用 CSV 後原封不動匯入（站台實際語系）：failed 0、skipped 0、updated＝匯出列數，資料逐字不變 @happy', async () => {
		const baseline = await test.step('前置：A 批改為通過、B 為未通過，評語含逗號 / 雙引號 / 換行', async () => {
			await gradeAsAdmin(fx.subA, 'pass', FEEDBACK_ROUNDTRIP_A)
			await gradeAsAdmin(fx.subB, 'fail', FEEDBACK_ROUNDTRIP_B)

			const list = await listSubmissions(admin.api, fx.assignmentId)
			const a = requireItem(list, fx.subA, '匯出前（A）')
			const b = requireItem(list, fx.subB, '匯出前（B）')
			expect(a.status).toBe('pass')
			expect(b.status).toBe('fail')

			// 以「實際存下來的值」為基準，不假設第一波批改端點如何處理評語；
			// 但評語必須真的含 CSV 特殊字元，否則往返測試就是空洞的（綠燈不代表有碰到）
			for (const item of [a, b]) {
				expect(item.feedback ?? '', '評語應含逗號').toContain(',')
				expect(item.feedback ?? '', '評語應含雙引號').toContain('"')
				expect(item.feedback ?? '', '評語應含換行').toContain('\n')
			}
			return { a, b }
		})

		const exported = await test.step('GET 批改用 CSV（以本 spec 的 course_id / assignment_id 篩選，不重批其他 spec 的資料）', async () => {
			const res = await exportGradingCsv(admin.request, admin.nonce, {
				course_id: String(fx.courseId),
				assignment_id: String(fx.assignmentId),
			})
			expect(res.status, `匯出回應非 200，body 前 300 bytes: ${res.body.subarray(0, 300).toString('utf-8')}`).toBe(200)
			expect(res.body.subarray(0, 3).equals(UTF8_BOM), '匯出檔應以 UTF-8 BOM 開頭').toBe(true)

			const rows = parseCsv(res.body.toString('utf-8'))
			expect(rows[0]?.[0], '表頭第一欄應為 submission_id').toBe(HEADER_ID)
			expect(rows[0], '匯出表頭應為 8 欄').toHaveLength(8)

			const dataRows = rows.slice(1).filter((row) => row.some((cell) => cell !== ''))
			expect(dataRows.length, '匯出列數應 ≥ 2').toBeGreaterThanOrEqual(2)
			// 安全閥：匯出內容必須只含本 spec 作業的兩筆，否則原封匯入會重批到其他 spec 的資料
			expect(
				dataRows.map((row) => Number(row[0])).sort((x, y) => x - y),
				'篩選後的匯出檔應只含本 spec 作業的 A、B 兩筆',
			).toEqual([fx.subA, fx.subB].sort((x, y) => x - y))

			return { body: res.body, dataRowCount: dataRows.length }
		})

		await test.step('不做任何修改，把匯出檔以 multipart 原封匯入', async () => {
			const res = await importGradesCsv(admin.request, admin.nonce, {
				name: 'submissions-grades-roundtrip.csv',
				mimeType: 'text/csv',
				buffer: exported.body,
			})
			expect(res.status, `匯入回應非 200，body 前 500 字: ${res.text.slice(0, 500)}`).toBe(200)

			const data = res.data as ImportSummary
			expect(data.errors, `往返不應有任何錯誤列: ${JSON.stringify(data.errors)}`).toEqual([])
			expect({
				total: data.total,
				updated: data.updated,
				skipped: data.skipped,
				failed: data.failed,
			}).toEqual({
				total: exported.dataRowCount,
				updated: exported.dataRowCount,
				skipped: 0,
				failed: 0,
			})
		})

		await test.step('匯入後狀態與評語逐字不變（含逗號 / 雙引號 / 換行）', async () => {
			const list = await listSubmissions(admin.api, fx.assignmentId)
			const a = requireItem(list, fx.subA, '往返後（A）')
			const b = requireItem(list, fx.subB, '往返後（B）')
			expect(a.status).toBe('pass')
			expect(a.feedback).toBe(baseline.a.feedback)
			expect(b.status).toBe('fail')
			expect(b.feedback).toBe(baseline.b.feedback)
		})
	})

	test('管理員上傳無法解析的 CSV（未閉合引號）：200、四個計數皆 0、錯誤列 row 0，A、B 狀態不變 @error', async () => {
		const before = await test.step('前置：A、B 重設為待批改並記下快照', async () => {
			await gradeAsAdmin(fx.subA, 'pending', null)
			await gradeAsAdmin(fx.subB, 'pending', null)

			const list = await listSubmissions(admin.api, fx.assignmentId)
			const a = requireItem(list, fx.subA, '匯入前（A）')
			const b = requireItem(list, fx.subB, '匯入前（B）')
			expect(a.status, '前置：A 應為 pending').toBe('pending')
			expect(b.status, '前置：B 應為 pending').toBe('pending')
			return { a, b }
		})

		// 評語欄開了引號卻從未閉合：整份檔案無法解析（不可寬鬆解析成「有些列可以套用」）
		const broken = Buffer.from(
			'﻿' +
				[
					[HEADER_ID, HEADER_STATUS, HEADER_FEEDBACK].join(','),
					`${fx.subA},pass,"這則評語的引號沒有閉合`,
					`${fx.subB},fail,另一列也不該被套用`,
				].join('\r\n') +
				'\r\n',
			'utf-8',
		)

		await test.step('上傳後仍是 200，摘要為 row 0 的解析失敗', async () => {
			const res = await importGradesCsv(admin.request, admin.nonce, {
				name: 'broken-quote.csv',
				mimeType: 'text/csv',
				buffer: broken,
			})
			expect(res.status, `解析失敗在 API 層仍應回 200，body 前 500 字: ${res.text.slice(0, 500)}`).toBe(200)

			const data = res.data as ImportSummary
			expect({
				total: data.total,
				updated: data.updated,
				skipped: data.skipped,
				failed: data.failed,
			}).toEqual({ total: 0, updated: 0, skipped: 0, failed: 0 })
			expect(data.errors, '應只有一筆 row 0 的解析錯誤').toHaveLength(1)
			expect(data.errors[0].row).toBe(0)
			expect(typeof data.errors[0].message).toBe('string')
			expect(data.errors[0].message.length).toBeGreaterThan(0)
		})

		await test.step('A、B 的狀態、評語、批改者、批改時間完全不變', async () => {
			const list = await listSubmissions(admin.api, fx.assignmentId)
			expect(requireItem(list, fx.subA, '匯入後（A）')).toEqual(before.a)
			expect(requireItem(list, fx.subB, '匯入後（B）')).toEqual(before.b)
		})
	})

	test('學員（非管理員）呼叫匯入：403 rest_forbidden，且其繳交未被改動 @security', async () => {
		const before = await test.step('前置：A 重設為待批改並記下快照', async () => {
			await gradeAsAdmin(fx.subA, 'pending', null)
			const item = requireItem(await listSubmissions(admin.api, fx.assignmentId), fx.subA, '學員匯入前')
			expect(item.status, '前置：學員 A 的繳交應為 pending').toBe('pending')
			return item
		})

		// 帶「看起來合法」的檔案：403 必須是權限先擋，而不是檔案缺漏的 400
		const csv = buildCsv(
			[
				[HEADER_ID, HEADER_STATUS, HEADER_FEEDBACK],
				[String(fx.subA), 'pass', '自己幫自己通過'],
			],
			{ bom: true },
		)
		const res = await importGradesCsv(studentA.request, studentA.nonce, {
			name: 'student-self-grade.csv',
			mimeType: 'text/csv',
			buffer: csv,
		})
		expect(res.status, `學員呼叫匯入應為 403，body: ${res.text.slice(0, 300)}`).toBe(403)
		expect(errorCode(res.data)).toBe('rest_forbidden')

		const after = requireItem(await listSubmissions(admin.api, fx.assignmentId), fx.subA, '學員匯入後')
		expect(after, '被 403 擋下後，繳交不可有任何變動').toEqual(before)
	})

	test('訪客（未登入）呼叫匯入：401 rest_forbidden @security', async () => {
		const csv = buildCsv(
			[
				[HEADER_ID, HEADER_STATUS, HEADER_FEEDBACK],
				[String(fx.subA), 'pass', '訪客不該能匯入'],
			],
			{ bom: true },
		)
		const res = await importGradesCsv(guestRequest, '', {
			name: 'guest-import.csv',
			mimeType: 'text/csv',
			buffer: csv,
		})
		expect(res.status, `訪客呼叫匯入應為 401，body: ${res.text.slice(0, 300)}`).toBe(401)
		expect(errorCode(res.data)).toBe('rest_forbidden')
	})

	test('管理員不帶檔案（multipart 沒有 file 欄位）呼叫匯入：400 rest_invalid_param @error', async () => {
		const res = await importGradesCsv(admin.request, admin.nonce, null)
		expect(res.status, `不帶檔案應為 400，body: ${res.text.slice(0, 300)}`).toBe(400)
		expect(errorCode(res.data)).toBe('rest_invalid_param')
	})

	// ─────────────────────────────────────────────
	// 作業討論串 GET（W1 已實作；以下 3 支本階段本來就綠，為真實 HTTP 層守門）
	// ─────────────────────────────────────────────

	test('學員 A 讀自己的討論串：200、真實回應 Cache-Control 含 no-cache、內容正確且不含發送者 id / email @happy @smoke', async () => {
		const resp = await getMyThread(studentA.request, studentA.nonce, fx.subA)
		const { text, data } = await readJson(resp)
		expect(resp.status(), `讀討論串應為 200，body 前 300 字: ${text.slice(0, 300)}`).toBe(200)

		// WP 核心對已登入 REST 請求本來就送 no-cache（class-wp-rest-server.php L487–501），
		// 本條只證明 stack（Nginx / 快取外掛 / Cloudflare tunnel）沒有把標頭剝掉，
		// 不證明 callback 呼叫了 nocache_headers()（那由 PHPUnit 靜態檢查守）。
		const cacheControl = String(resp.headers()['cache-control'] || '')
		expect(cacheControl, `Cache-Control 實際值: ${cacheControl}`).toContain('no-cache')

		expect(Array.isArray(data), `回應應為訊息陣列: ${text.slice(0, 300)}`).toBe(true)
		const messages = data as ThreadMessage[]

		const own = messages.find((m) => m.body === MSG_A_QUESTION)
		expect(own, '應含學員 A 自己發的訊息').toBeDefined()
		expect(own?.sender_type).toBe('student')
		expect(own?.mine, '自己發的訊息 mine 應為 true').toBe(true)

		const teacher = messages.find((m) => m.body === MSG_TEACHER_REPLY)
		expect(teacher, '應含老師的回覆').toBeDefined()
		expect(teacher?.sender_type).toBe('teacher')
		expect(teacher?.mine, '老師發的訊息對學員 mine 應為 false').toBe(false)

		// 只回 id / sender_type / body / read_at / created_at / mine；不回發送者 id 與 email
		for (const message of messages) {
			expect(Object.keys(message).sort(), '訊息鍵集合應恰為 6 個欄位（不含 sender_id / sender_email）').toEqual([
				'body',
				'created_at',
				'id',
				'mine',
				'read_at',
				'sender_type',
			])
		}
		// 不可混入 B 的私密訊息
		expect(text).not.toContain(MSG_B_PRIVATE)
	})

	test('學員 A 讀 B 的討論串：403 rest_forbidden，且回應不含 B 的訊息內容 @security', async () => {
		const resp = await getMyThread(studentA.request, studentA.nonce, fx.subB)
		const { text, data } = await readJson(resp)

		expect(resp.status(), `讀他人討論串應為 403，body 前 300 字: ${text.slice(0, 300)}`).toBe(403)
		expect(errorCode(data)).toBe('rest_forbidden')
		// 固定資料已讓 B 的討論串真的有訊息，這條斷言才不是空洞的
		expect(text, '403 回應不可洩漏他人訊息內容').not.toContain(MSG_B_PRIVATE)
	})

	test('訪客（未登入）讀討論串：401 rest_not_logged_in，且回應不含訊息內容 @security', async () => {
		const resp = await getMyThread(guestRequest, '', fx.subA)
		const { text, data } = await readJson(resp)

		expect(resp.status(), `訪客讀討論串應為 401，body 前 300 字: ${text.slice(0, 300)}`).toBe(401)
		expect(errorCode(data)).toBe('rest_not_logged_in')
		expect(text, '401 回應不可洩漏訊息內容').not.toContain(MSG_A_QUESTION)
	})
})
