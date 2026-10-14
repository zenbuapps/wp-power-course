/**
 * 教室作業卡片的討論串（Issue #131 第二波 W4）
 *
 * 對應規格：specs/ui/教室作業區塊.md（討論串（第二波））
 * 對應 feature：specs/features/assignment/{查詢作業討論串,發送作業討論訊息}.feature
 * 對應計劃：specs/plan/issue-131-assignment-grading/wave2-implementation-plan.md §11 W4、DEC-W2-04 / 19
 * 對應 API：specs/api/api.yml（tag Assignments）：
 *   - 學員端 GET / POST `/my/assignment-submissions/{id}/messages`（GET 先標老師訊息已讀再回傳）
 *   - 管理端 GET / POST `/assignment-submissions/{id}/messages`
 *   - 學員端 POST `/my/assignments/{id}/submit`、管理端 PATCH `/assignment-submissions/{id}/grade`
 * 對應原始碼（W4 Green 階段建立，Red 階段尚不存在）：
 *   - inc/templates/components/assignment/card.php（討論串區塊、`<template>`）
 *   - inc/assets/src/events/assignments/{thread.ts,index.ts,submit.ts}
 *
 * 資料準備（S-RED-E2E 硬性規則 1：一律走 REST，不走 UI）：
 * - 課程 + 章節 + 5 份作業、老師訊息、批改：管理員 REST
 * - 學員：Powerhouse V2 API（ensureUserViaPowerhouse，本站沒有 /wp/v2/users），以 loginAs() 登入後取得 REST nonce，
 *   用學員自己的 nonce 繳交、發訊息；之後每支 test 把同一組 cookie 還原到 `page`（不重複登入，省下一次一次的 /my-account/ 冷載入）
 * - 唯一走 UI 的資料動作是 test 4 的「頁內首次繳交」，因為受測對象正是 submit.ts 繳交成功後的畫面變化
 *
 * 不使用 test.describe.serial()：serial 中一支紅，其餘全部 did not run，紅燈原因被遮住
 * （第一波 016 與 assignment-grading.spec.ts 的實測教訓）。需要順序的流程寫在同一個 test 內用 test.step() 分段；
 * 共用資料只在 beforeAll 準備，每支 test 只依賴 beforeAll 的資料，各自重設會被改動的部分
 * （訊息本文用 uniq() 做唯一字串、批改狀態開頭重設、test 4 每次嘗試建立自己的新作業），所以重試也不會讀到上一次嘗試的殘留。
 *
 * 共用資料與「worker 重啟」：Playwright 在任何一支 test 失敗後都會丟掉 worker、重開新 worker 並重跑 beforeAll。
 * 紅燈階段 6 支必紅，若每次都重建「課程 + 登入 + 繳交」，本機站一次 beforeAll 就要數分鐘。
 * 所以固定資料只在「這一輪 run 的第一個 worker」建一次，寫進 os.tmpdir() 下以 Playwright runner PID
 * （process.ppid，同一輪的所有 worker 共用）命名的 JSON，之後的 worker 直接還原（學員 session 用 storageState + 快取的 nonce）。
 * 做法同 01-admin/assignment-thread-import.spec.ts。清理：afterEach 在每支 test「最終結果出爐」時登記，
 * 登記數達 EXPECTED_TEST_COUNT 的那個 worker 的 afterAll 才刪課程與學員並移除快取檔；用 --grep / file:line 只跑一部分時不會清
 * （殘留的 E2E 課程由下一輪 global-setup 清掉；課程名稱必須以 E2E 開頭）。附件實體檔不刪（D9 / D16）：本檔沒有上傳附件。
 *
 * 斷言 DOM 狀態，不等 toast。不斷言「直連受保護目錄的檔案會被擋」（本機是 Nginx，R-04）：本檔沒有附件。
 *
 * 介面契約（DEC-W2-19 沒寫死的部分，由 test-creator 決定，Green 必須照做）：
 * - 卡片 `.pc-assignment-card[data-assignment-id]` 永遠輸出 `data-submission-id`：無繳交為空字串，有繳交為繳交 ID
 *   （DEC-W2-19；管理員預覽卡片仍輸出該屬性，但不輸出討論串區塊）
 * - 討論串區塊根 `.pc-assignment-card__thread`：卡片內；無繳交時整塊 hidden；管理員預覽（唯讀）完全不輸出；
 *   狀態為通過（繳交表單隱藏）時照常顯示
 * - 切換鈕 `.pc-assignment-card__thread-toggle`：文字含「與老師討論」；點擊展開，展開時才 GET
 *   `/my/assignment-submissions/{id}/messages`（展開 = 讀取 = 標老師訊息已讀）；頁面載入時不得預先請求或渲染訊息
 * - 每則訊息 `.pc-assignment-card__thread-message`（由 PHP `<template>` clone，以 textContent 填值），訊息內：
 *   * 寄件者標籤是一個「全文恰為『我』或『老師』」的元素（不與時間、本文合併在同一個文字節點）：mine = true 為「我」，否則「老師」
 *   * 本文以純文字呈現（不可用 innerHTML）
 *   * 時間為 created_at 去掉秒（站台時區字串，不做瀏覽器時區換算），即 `YYYY-MM-DD HH:mm`
 *   * 己方（學員）訊息且 read_at 不為空時，含全文恰為『已讀』的元素；否則不含；老師的訊息不顯示『已讀』
 * - 輸入列 `.pc-assignment-card__thread-input`（可 .fill() 的單行 / 多行輸入）、送出鈕為文字「送出」的 button；
 *   點「送出」POST `/my/assignment-submissions/{id}/messages`，成功後就地出現新訊息
 * - 頁內首次繳交成功後（submit.ts），不重新整理：寫入 `data-submission-id` 並顯示討論串區塊
 *
 * 範圍限制（不在本檔覆蓋）：
 * - 線性觀看鎖定下討論串仍可用（D29，計劃列為選做）：學員到不了鎖定章節自己的教室頁
 *   （single-pc_chapter.php 對學員一律 wp_safe_redirect，見 021 檔頭裁決 2），這個狀態在前台 E2E 不可達，
 *   D29 由 API 層（查詢作業討論串.feature「不檢查線性觀看」）守住
 * - 管理員端討論串、匯入 CSV（W3：01-admin/assignment-thread-import.spec.ts）
 *
 * 預期結果（Green 完成前，本檔 6 支全紅，且原因必須是「功能不存在」——找不到 .pc-assignment-card__thread-toggle、
 * 卡片沒有 data-submission-id 屬性等，不是登入或資料準備失敗）。
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
	Locator,
	Page,
} from '@playwright/test'

import { ApiClient, getNonceFromPage } from '../helpers/api-client.js'
import { loginAs } from '../helpers/frontend-setup.js'
import { WP_ADMIN } from '../fixtures/test-data.js'

const BASE_URL = process.env.TEST_SITE_URL || 'http://localhost:8889'
const NAV_TIMEOUT = Number(process.env.E2E_ADMIN_NAV_TIMEOUT || 90_000)

// 每支 test 都要冷載入教室頁（本機 2～60 秒）並呼叫數次 REST，90 秒的 config 預設不夠
test.setTimeout(180_000)

/** 本檔 test 數：afterEach 登記數達此值才清理固定資料（新增 / 刪除 test 時要同步修改） */
const EXPECTED_TEST_COUNT = 6

/** 固定資料快取檔：以 runner PID 命名，同一輪 run 的所有 worker 共用（見檔頭「worker 重啟」） */
const FIXTURE_FILE = path.join(
	os.tmpdir(),
	`pc-w4-classroom-assignment-thread-fixture-${process.ppid}.json`,
)

// ── 測試資料 ────────────────────────────────────────
// 課程名稱必須以 E2E 開頭，global-setup 才清得掉殘留
const COURSE = {
	name: 'E2E 教室作業討論串課程',
	slug: 'e2e-classroom-assignment-thread-course',
	chapter: { name: 'E2E教室討論串章節', slug: 'e2e-classroom-thread-chapter' },
}

const STUDENT = {
	username: 'e2e_thread_stu_131',
	email: 'e2e_thread_stu_131@test.local',
	password: 'e2eThreadStu131!Pass',
	name: 'E2E討論串學員',
}

// 5 份作業：前 4 份學員在 beforeAll 以 REST 繳交，none 保持未繳交
const ASSIGNMENT = {
	read: 'E2E討論串-老師訊息已讀',
	send: 'E2E討論串-學員送出',
	pass: 'E2E討論串-通過後',
	xss: 'E2E討論串-XSS',
	none: 'E2E討論串-未繳交',
} as const

type AssignmentKey = keyof typeof ASSIGNMENT
const ASSIGNMENT_KEYS = Object.keys(ASSIGNMENT) as AssignmentKey[]
type SubmittedKey = Exclude<AssignmentKey, 'none'>
const SUBMITTED_KEYS = ASSIGNMENT_KEYS.filter((key): key is SubmittedKey => key !== 'none')

const XSS_BODY = '<img src=x onerror="window.__pcXss=1">'

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
	courseSlug: string
	chapterId: number
	chapterSlug: string
	studentId: number
	assignmentIds: Record<AssignmentKey, number>
	/** 學員已繳交的作業對應的繳交 ID（none 沒有） */
	submissionIds: Record<SubmittedKey, number>
	student: StudentCache
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
 * 建立管理員 context 與 ApiClient（JSON 資料準備）
 *
 * 做法同 helpers/api-client.ts 的 setupApiFromBrowser()（storageState 登入 + 等 wpApiSettings + 取 nonce）。
 * 帶入 knownNonce（同一輪 run 的快取）時不導覽，省下一次 wp-admin 冷載入。不修改共用 helper。
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

	return { context, api: new ApiClient(context.request, nonce), nonce }
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

/** 由快取還原學員的 REST session：沿用 cookie 與 nonce，不重新登入 */
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

/** 學員在自己繳交的討論串發送訊息（資料準備） */
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

/**
 * 管理員讀討論串（GET assignment-submissions/{id}/messages）
 *
 * 管理端讀取只會把「學員發的」未讀訊息標為已讀（DEC-W2-04），不會動老師自己發的訊息的 read_at，
 * 所以拿它來觀察「學員有沒有讀到老師的訊息」不會自己污染結果。
 */
async function adminGetThread(submissionId: number): Promise<ThreadMessage[]> {
	const resp = await admin.api.pcGet<ThreadMessage[]>(
		`assignment-submissions/${submissionId}/messages`,
	)
	expect(resp.status, `管理員讀討論串應為 200: ${JSON.stringify(resp.data)}`).toBe(200)
	return Array.isArray(resp.data) ? resp.data : []
}

/** 管理員以老師身分發訊息（資料準備） */
async function adminPostMessage(submissionId: number, body: string): Promise<ThreadMessage> {
	const resp = await admin.api.pcPost<ThreadMessage>(
		`assignment-submissions/${submissionId}/messages`,
		{ body },
	)
	expect(resp.status, `管理員發送老師訊息應為 200: ${JSON.stringify(resp.data)}`).toBe(200)
	return resp.data
}

function findMessage(messages: ThreadMessage[], body: string): ThreadMessage | undefined {
	return messages.find((m) => m.body === body)
}

// ── Helper：UI ───────────────────────────────────────

/** 把 page 切成學員身分：沿用 beforeAll 以 loginAs() 登入取得的 cookie（fixture 的 page 預設帶管理員 storageState） */
async function useStudentSession(page: Page): Promise<void> {
	await page.context().clearCookies()
	await page.context().addCookies(fx.student.storageState.cookies)
}

/** 把 page 切成管理員身分（教室預覽模式）：照 021 的做法，loginAs() 會先清 cookie 再登入 */
async function useAdminSession(page: Page): Promise<void> {
	await loginAs(page, WP_ADMIN.username, WP_ADMIN.password)
}

/**
 * 斷言卡片的 data-submission-id（DEC-W2-19：永遠輸出，無繳交為空字串，有繳交為繳交 ID）
 *
 * 不直接用 toHaveAttribute(name, '')：屬性「不存在」時它的失敗訊息是
 * 「Received: serializes to the same string」，看不出是欄位沒輸出。改用 poll 讀屬性值，
 * 屬性不存在時印出 Expected: "" / Received: null。
 */
async function expectSubmissionId(card: Locator, expected: string, message: string): Promise<void> {
	await expect
		.poll(() => card.getAttribute('data-submission-id'), { timeout: 15_000, message })
		.toBe(expected)
}

async function gotoClassroom(page: Page): Promise<void> {
	await page.goto(`/classroom/${fx.courseSlug}/${fx.chapterSlug}/`)
	await page.waitForLoadState('networkidle')
}

/** 目前章節自己的作業卡片（.pc-assignment-list 內） */
function chapterCard(page: Page, assignmentId: number): Locator {
	return page.locator(
		`#pc-classroom-assignments .pc-assignment-list .pc-assignment-card[data-assignment-id="${assignmentId}"]`,
	)
}

/** 開教室頁並等指定作業的卡片出現（作業區塊本身是第一波功能，已存在） */
async function gotoCard(page: Page, assignmentId: number): Promise<Locator> {
	await gotoClassroom(page)
	const card = chapterCard(page, assignmentId)
	await expect(card, `教室頁應顯示作業 ${assignmentId} 的卡片（第一波功能）`).toBeVisible({
		timeout: 60_000,
	})
	return card
}

function threadOf(card: Locator): Locator {
	return card.locator('.pc-assignment-card__thread')
}

function toggleOf(card: Locator): Locator {
	return card.locator('.pc-assignment-card__thread-toggle')
}

/** 討論串中本文含指定文字的那則訊息 */
function messageOf(card: Locator, text: string): Locator {
	return threadOf(card).locator('.pc-assignment-card__thread-message').filter({ hasText: text })
}

function inputOf(card: Locator): Locator {
	return threadOf(card).locator('.pc-assignment-card__thread-input')
}

function sendButtonOf(card: Locator): Locator {
	return threadOf(card).getByRole('button', { name: '送出', exact: true })
}

/** 學員端討論串 REST 的網址片段（GET / POST 共用） */
function threadUrlPart(submissionId: number): string {
	return `/my/assignment-submissions/${submissionId}/messages`
}

/** 點「與老師討論」展開，並等展開時的 GET 回 200（展開才載入） */
async function expandThread(page: Page, card: Locator, submissionId: number): Promise<void> {
	const toggle = toggleOf(card)
	await expect(toggle, '卡片應有「與老師討論」切換鈕（.pc-assignment-card__thread-toggle）').toBeVisible({
		timeout: 30_000,
	})
	await Promise.all([
		page.waitForResponse(
			(resp) =>
				resp.url().includes(threadUrlPart(submissionId)) &&
				resp.request().method() === 'GET' &&
				resp.status() === 200,
			{ timeout: 60_000 },
		),
		toggle.click(),
	])
}

/** 在已展開的討論串輸入並按「送出」，回傳 POST 的回應內容 */
async function sendMessage(
	page: Page,
	card: Locator,
	submissionId: number,
	text: string,
): Promise<ThreadMessage> {
	const input = inputOf(card)
	await expect(input, '展開後應有回覆輸入列（.pc-assignment-card__thread-input）').toBeVisible({
		timeout: 30_000,
	})
	const [resp] = await Promise.all([
		page.waitForResponse(
			(r) => r.url().includes(threadUrlPart(submissionId)) && r.request().method() === 'POST',
			{ timeout: 60_000 },
		),
		(async () => {
			await input.fill(text)
			await sendButtonOf(card).click()
		})(),
	])
	expect(resp.status(), '學員送出訊息 API 應回 200').toBe(200)
	return (await resp.json()) as ThreadMessage
}

// ── 共用狀態 ────────────────────────────────────────
let admin: AdminSession
let fx: FixtureData
let studentSession: StudentSession

/**
 * 建立固定資料（一輪 run 只在第一個 worker 執行一次）：
 * 課程 + 章節 + 5 份作業 + 學員（有觀看權、loginAs() 後繳交其中 4 份）+ XSS 那筆的學員訊息
 */
async function createFixture(browser: Browser, adminSession: AdminSession): Promise<FixtureData> {
	const api = adminSession.api

	const created = await api.createCourseWithChapters(
		COURSE.name,
		'0',
		[COURSE.chapter],
		COURSE.slug,
	)
	const chapterId = created.chapterIds[0]
	await api.setCourseFree(created.courseId)

	// 一個章節可有多份作業；作業之間用 ID 區分（卡片以 data-assignment-id 定位）
	const assignmentIds = {} as Record<AssignmentKey, number>
	for (const key of ASSIGNMENT_KEYS) {
		const resp = await api.pcPost<{ id: number }>('assignments', {
			chapter_id: chapterId,
			title: ASSIGNMENT[key],
			allow_text: 'yes',
			allow_file: 'no',
			due_at: 0,
		})
		const id = (resp.data as { id?: number })?.id
		if (resp.status >= 400 || !id) {
			throw new Error(`建立測試作業「${ASSIGNMENT[key]}」失敗: ${JSON.stringify(resp.data)}`)
		}
		assignmentIds[key] = id
	}

	// 學員 + 課程觀看權
	const studentId = await api.ensureUserViaPowerhouse(
		STUDENT.username,
		STUDENT.email,
		STUDENT.password,
		['subscriber'],
		STUDENT.name,
	)
	await api.grantCourseAccess(studentId, created.courseId)

	// 學員登入（loginAs）取得 nonce，繳交 4 份作業；none 刻意不繳交
	const login = await loginStudent(browser, STUDENT)
	try {
		const submissionIds = {} as Record<SubmittedKey, number>
		for (const key of SUBMITTED_KEYS) {
			submissionIds[key] = await submitAsStudent(
				login,
				assignmentIds[key],
				`E2E ${ASSIGNMENT[key]} 的繳交內容`,
			)
		}

		// XSS 那筆的學員訊息：固定資料只送一次（重試時不重送，否則同一段文字會出現兩次，strict mode 會炸）
		await postStudentMessage(login, submissionIds.xss, XSS_BODY)

		return {
			adminNonce: adminSession.nonce,
			courseId: created.courseId,
			courseSlug: created.courseSlug,
			chapterId,
			// 用 WP 實際指派的 slug，不是常數（同名時 WP 會加 -2 尾碼）
			chapterSlug: created.chapterSlugs[0],
			studentId,
			assignmentIds,
			submissionIds,
			student: { storageState: login.storageState, nonce: login.nonce },
			finished: [],
		}
	} finally {
		await login.context.close()
	}
}

test.describe('教室作業討論串（Issue #131 第二波 W4）', () => {
	test.beforeAll(async ({ browser }) => {
		// 第一個 worker 要建固定資料（約 20 次 REST + 1 次可能吃滿 NAV_TIMEOUT 的登入），
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
		studentSession = await restoreStudent(browser, fx.student)
	})

	// 登記「最終結果出爐」的 test：通過、略過，或已用完重試次數。
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
			try {
				await admin.api.removeCourseAccess(fx.studentId, fx.courseId)
			} catch {
				/* ignore */
			}
			try {
				await admin.api.deleteCourses([fx.courseId])
			} catch {
				/* ignore */
			}
			try {
				await admin.api.deleteUserViaPowerhouse(fx.studentId)
			} catch {
				/* ignore */
			}
			removeFixture()
		}

		if (studentSession) await studentSession.context.close()
		if (admin) await admin.context.close()
	})

	// ─────────────────────────────────────────────
	// 1. 有繳交才有討論串；管理員預覽沒有
	// ─────────────────────────────────────────────

	test('有繳交的卡片有「與老師討論」，沒繳交的沒有，管理員預覽模式沒有討論串 @happy @security', async ({
		page,
	}) => {
		await test.step('學員：有繳交的卡片顯示「與老師討論」與繳交 ID', async () => {
			await useStudentSession(page)
			const card = await gotoCard(page, fx.assignmentIds.read)

			await expect(
				toggleOf(card),
				'有繳交的卡片應有「與老師討論」切換鈕（.pc-assignment-card__thread-toggle）',
			).toBeVisible({ timeout: 30_000 })
			await expect(toggleOf(card)).toContainText('與老師討論')
			await expect(threadOf(card), '有繳交的卡片，討論串區塊應顯示').toBeVisible()
			await expectSubmissionId(
				card,
				String(fx.submissionIds.read),
				'卡片應輸出 data-submission-id = 該學員這份作業的繳交 ID（DEC-W2-19）',
			)
		})

		await test.step('學員：沒繳交的卡片沒有討論串，data-submission-id 為空字串', async () => {
			const noneCard = chapterCard(page, fx.assignmentIds.none)
			await expect(noneCard, '未繳交作業的卡片仍應顯示（對照組：頁面本身沒壞）').toBeVisible()
			await expectSubmissionId(
				noneCard,
				'',
				'未繳交的卡片仍輸出 data-submission-id，值為空字串（DEC-W2-19）',
			)
			await expect(toggleOf(noneCard), '未繳交的卡片不應有「與老師討論」切換鈕').toBeHidden()
			await expect(threadOf(noneCard), '未繳交的卡片，討論串區塊整塊應隱藏').toBeHidden()
			await expect(noneCard.getByText('與老師討論')).toBeHidden()
		})

		await test.step('管理員預覽：卡片唯讀（對照），且沒有討論串', async () => {
			await useAdminSession(page)
			const card = await gotoCard(page, fx.assignmentIds.read)

			await expect(
				card.locator('.pc-assignment-card__text-input'),
				'管理員預覽模式的卡片應為唯讀（對照組：確認看的是預覽卡片）',
			).toBeDisabled()
			await expect(toggleOf(card), '管理員預覽模式不應有「與老師討論」').toBeHidden()
			await expect(card.getByText('與老師討論')).toBeHidden()
			await expect(threadOf(card), '管理員預覽模式不應輸出討論串區塊').toHaveCount(0)
		})
	})

	// ─────────────────────────────────────────────
	// 2. 老師訊息：展開才載入、展開即標已讀
	// ─────────────────────────────────────────────

	test('老師訊息在展開前不標已讀，學員展開後看到「老師」訊息並標為已讀 @happy', async ({ page }) => {
		const submissionId = fx.submissionIds.read
		const teacherMsg = `E2E 老師回覆：請參考 1-1 的範例 ${uniq()}`
		let createdAt = ''

		await test.step('前置：管理員 REST 發一則老師訊息，read_at 為 null', async () => {
			const posted = await adminPostMessage(submissionId, teacherMsg)
			createdAt = posted.created_at
			expect(
				createdAt,
				`API 的 created_at 應為 YYYY-MM-DD HH:mm:ss（站台時區字串），實際: ${createdAt}`,
			).toMatch(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/)

			const found = findMessage(await adminGetThread(submissionId), teacherMsg)
			expect(found, '管理員讀討論串應含剛發的老師訊息').toBeDefined()
			expect(found?.sender_type, '管理員發的訊息 sender_type 應為 teacher').toBe('teacher')
			expect(found?.read_at, '學員還沒讀之前，老師訊息的 read_at 應為 null').toBeNull()
		})

		await useStudentSession(page)
		const card = await gotoCard(page, fx.assignmentIds.read)

		await test.step('只載入頁面（沒展開）：沒有預先渲染訊息，老師訊息仍未讀', async () => {
			await expect(toggleOf(card), '卡片應有「與老師討論」切換鈕').toBeVisible({ timeout: 30_000 })
			await expect(
				threadOf(card).locator('.pc-assignment-card__thread-message'),
				'展開前不應預先渲染任何訊息（展開才載入，DEC-W2-19）',
			).toHaveCount(0)

			const found = findMessage(await adminGetThread(submissionId), teacherMsg)
			expect(
				found?.read_at,
				'只載入頁面不應把老師訊息標為已讀（讀取 = 展開，不是載入頁面）',
			).toBeNull()
		})

		await test.step('展開：看到標示「老師」的訊息，含站台時區的時間（去掉秒）', async () => {
			await expandThread(page, card, submissionId)

			const item = messageOf(card, teacherMsg)
			await expect(item, '展開後應就地載入並顯示老師的訊息').toBeVisible({ timeout: 30_000 })
			await expect(item.getByText('老師', { exact: true }), '老師的訊息應標示「老師」').toBeVisible()
			// 否定斷言一律用 toBeHidden()，不用 toHaveCount(0)：訊息節點由 <template> clone，
			// 實作上很可能保留一個帶 hidden 屬性的「已讀」元素，toHaveCount 連隱藏的也算
			// （以最小 Green DOM 實測：count 會是 1）。toBeHidden 對「不存在」與「存在但隱藏」都成立。
			await expect(item.getByText('我', { exact: true }), '老師的訊息不應標示「我」').toBeHidden()
			await expect(
				item.getByText('已讀', { exact: true }),
				'「已讀」只標在自己（學員）發的訊息上，老師的訊息不顯示',
			).toBeHidden()
			await expect(item, '時間應為 created_at 去掉秒（不做瀏覽器時區換算）').toContainText(
				createdAt.slice(0, 16),
			)
			await expect(item, '時間不應顯示到秒').not.toContainText(createdAt)
		})

		await test.step('展開 = 標已讀：管理員 REST 讀回，老師訊息 read_at 不為空', async () => {
			// 觀察點用管理員端：它不會動老師自己發的訊息，所以 read_at 有值只可能是學員展開時的 GET 造成的。
			// 若改用學員端 REST 讀（任務原寫法），那次讀取本身就會標已讀，斷言會變空洞（DEC-W2-04）。
			const found = findMessage(await adminGetThread(submissionId), teacherMsg)
			expect(found, '管理員讀討論串應仍含該老師訊息').toBeDefined()
			expect(found?.read_at, '學員展開後，老師訊息的 read_at 不應為空（展開 = 標已讀）').not.toBeNull()
		})

		await test.step('學員 REST 讀一次：老師訊息對學員 mine = false 且已讀', async () => {
			const res = await getMyThread(studentSession, submissionId)
			expect(res.status, '學員讀自己的討論串應為 200').toBe(200)
			const found = findMessage(res.messages, teacherMsg)
			expect(found, `學員端應讀到老師的訊息，實際: ${JSON.stringify(res.messages.map((m) => m.body))}`).toBeDefined()
			expect(found?.mine, '對學員而言老師的訊息 mine 應為 false').toBe(false)
			expect(found?.read_at, '老師訊息的 read_at 不應為空').not.toBeNull()
		})
	})

	// ─────────────────────────────────────────────
	// 3. 學員送出、老師讀過後顯示「已讀」
	// ─────────────────────────────────────────────

	test('學員送出訊息出現「我」的訊息，管理員讀過後重新整理並展開顯示「已讀」 @happy', async ({
		page,
	}) => {
		const submissionId = fx.submissionIds.send
		const myMsg = `E2E 學員提問：變數要怎麼宣告 ${uniq()}`

		await useStudentSession(page)
		const card = await gotoCard(page, fx.assignmentIds.send)
		await expandThread(page, card, submissionId)

		await test.step('輸入並按「送出」：就地出現標示「我」的訊息，老師還沒讀所以沒有「已讀」', async () => {
			const sent = await sendMessage(page, card, submissionId, myMsg)
			expect(sent.sender_type, '學員送出的訊息 sender_type 應為 student').toBe('student')
			expect(sent.mine, '回應中學員自己的訊息 mine 應為 true').toBe(true)

			const item = messageOf(card, myMsg)
			await expect(item, '送出後，討論串應就地出現自己剛送的訊息').toBeVisible({ timeout: 30_000 })
			await expect(item.getByText('我', { exact: true }), '自己的訊息應標示「我」').toBeVisible()
			await expect(item.getByText('老師', { exact: true }), '自己的訊息不應標示「老師」').toBeHidden()
			await expect(item, '訊息應顯示時間（含 HH:mm）').toContainText(/\d{2}:\d{2}/)
			await expect(
				item.getByText('已讀', { exact: true }),
				'老師還沒讀，自己的訊息不應顯示「已讀」（對照組：下面老師讀過後才會出現）',
			).toBeHidden()
		})

		await test.step('管理員 REST 讀取（把學員的訊息標為已讀）', async () => {
			const found = findMessage(await adminGetThread(submissionId), myMsg)
			expect(found, '管理員應讀到學員剛送的訊息').toBeDefined()
			expect(found?.sender_type, '訊息 sender_type 應為 student').toBe('student')
			expect(found?.read_at, '管理員讀取後，學員的訊息 read_at 不應為空').not.toBeNull()
		})

		await test.step('學員重新整理並展開：自己的訊息顯示「已讀」', async () => {
			await page.reload()
			await page.waitForLoadState('networkidle')

			const reloadedCard = chapterCard(page, fx.assignmentIds.send)
			await expect(reloadedCard, '重新整理後應顯示作業卡片').toBeVisible({ timeout: 60_000 })
			await expandThread(page, reloadedCard, submissionId)

			const item = messageOf(reloadedCard, myMsg)
			await expect(item, '重新整理並展開後應讀到自己的訊息').toBeVisible({ timeout: 30_000 })
			await expect(item.getByText('我', { exact: true }), '自己的訊息應標示「我」').toBeVisible()
			await expect(
				item.getByText('已讀', { exact: true }),
				'管理員已讀取，自己的訊息 read_at 不為空，應顯示「已讀」',
			).toBeVisible({ timeout: 15_000 })
		})
	})

	// ─────────────────────────────────────────────
	// 4. 頁內首次繳交成功後立刻有討論串
	// ─────────────────────────────────────────────

	test('頁內首次繳交成功後，不重新整理就出現「與老師討論」且可送出訊息 @happy', async ({ page }) => {
		const title = `E2E討論串-首次繳交-${uniq()}`
		const myMsg = `E2E 學員第一則訊息 ${uniq()}`
		let assignmentId = 0

		await test.step('前置：管理員 REST 為本次嘗試建立一份新作業（學員尚未繳交，重試不會撞到上次的繳交）', async () => {
			const resp = await admin.api.pcPost<{ id: number }>('assignments', {
				chapter_id: fx.chapterId,
				title,
				allow_text: 'yes',
				allow_file: 'no',
				due_at: 0,
				menu_order: 100,
			})
			const id = (resp.data as { id?: number })?.id
			expect(resp.status, `建立測試作業失敗: ${JSON.stringify(resp.data)}`).toBeLessThan(400)
			expect(id, '建立作業應回傳 id').toBeTruthy()
			assignmentId = id as number
		})

		await useStudentSession(page)
		const card = await gotoCard(page, assignmentId)

		await test.step('繳交前：沒有討論串，data-submission-id 為空字串', async () => {
			await expectSubmissionId(
				card,
				'',
				'尚未繳交的卡片仍輸出 data-submission-id，值為空字串（DEC-W2-19）',
			)
			await expect(toggleOf(card), '尚未繳交時不應有「與老師討論」').toBeHidden()
		})

		let submissionId = 0
		await test.step('頁內填寫文字並繳交成功（徽章變待批改）', async () => {
			await card.locator('.pc-assignment-card__text-input').fill('E2E 首次繳交的內容')
			const [resp] = await Promise.all([
				page.waitForResponse(
					(r) =>
						r.url().includes(`/wp-json/power-course/my/assignments/${assignmentId}/submit`) &&
						r.request().method() === 'POST',
					{ timeout: 60_000 },
				),
				card.locator('.pc-assignment-card__submit-btn').click(),
			])
			expect(resp.status(), '首次繳交應成功').toBeLessThan(400)
			const body = (await resp.json()) as { id?: number }
			expect(body.id, '繳交成功回應應含繳交紀錄 id').toBeTruthy()
			submissionId = body.id as number

			await expect(card.getByText('待批改', { exact: true }), '繳交後徽章應變為待批改').toBeVisible({
				timeout: 30_000,
			})
		})

		await test.step('不重新整理：「與老師討論」出現，data-submission-id 已寫入', async () => {
			await expect(
				toggleOf(card),
				'頁內首次繳交成功後，不重新整理就應出現「與老師討論」',
			).toBeVisible({ timeout: 30_000 })
			await expectSubmissionId(
				card,
				String(submissionId),
				'頁內首次繳交成功後，submit.ts 應把繳交 ID 寫入 data-submission-id（DEC-W2-19）',
			)
		})

		await test.step('展開並送出第一則訊息：出現標示「我」的訊息，管理員讀得到', async () => {
			await expandThread(page, card, submissionId)
			await sendMessage(page, card, submissionId, myMsg)

			const item = messageOf(card, myMsg)
			await expect(item, '送出後應就地出現訊息').toBeVisible({ timeout: 30_000 })
			await expect(item.getByText('我', { exact: true })).toBeVisible()

			const found = findMessage(await adminGetThread(submissionId), myMsg)
			expect(found, '訊息應寫入頁內首次繳交所建立的那筆繳交的討論串').toBeDefined()
			expect(found?.sender_type).toBe('student')
		})
	})

	// ─────────────────────────────────────────────
	// 5. 通過後繳交表單隱藏、討論串仍在
	// ─────────────────────────────────────────────

	test('狀態為通過後重新載入，繳交表單隱藏但討論串仍在 @happy @edge', async ({ page }) => {
		const submissionId = fx.submissionIds.pass
		const teacherMsg = `E2E 老師回覆：作業通過了 ${uniq()}`

		await test.step('前置：重設為待批改（重試時不依賴上一次嘗試），管理員發一則老師訊息', async () => {
			const reset = await admin.api.pcPatch(`assignment-submissions/${submissionId}/grade`, {
				status: 'pending',
				feedback: null,
			})
			expect(reset.status, `重設批改狀態回應非 200: ${JSON.stringify(reset.data)}`).toBe(200)
			await adminPostMessage(submissionId, teacherMsg)
		})

		await useStudentSession(page)
		const card = await gotoCard(page, fx.assignmentIds.pass)

		await test.step('通過前（對照）：繳交表單與討論串都在', async () => {
			await expect(
				card.locator('.pc-assignment-card__submit-btn'),
				'待批改時繳交表單應顯示（對照組）',
			).toBeVisible({ timeout: 30_000 })
			await expect(toggleOf(card), '待批改時應有「與老師討論」').toBeVisible({ timeout: 30_000 })
		})

		await test.step('管理員 REST 批改為通過', async () => {
			const grade = await admin.api.pcPatch(`assignment-submissions/${submissionId}/grade`, {
				status: 'pass',
				feedback: 'E2E 測試評語：通過',
			})
			expect(grade.status, `批改 API 失敗: ${JSON.stringify(grade.data)}`).toBeLessThan(400)
		})

		await test.step('重新載入：徽章通過、繳交表單隱藏，討論串仍可展開並看到訊息', async () => {
			await page.reload()
			await page.waitForLoadState('networkidle')

			const passedCard = chapterCard(page, fx.assignmentIds.pass)
			await expect(passedCard).toBeVisible({ timeout: 60_000 })
			await expect(
				passedCard.getByText('通過', { exact: true }),
				'批改通過後徽章應顯示通過',
			).toBeVisible({ timeout: 30_000 })
			await expect(
				passedCard.locator('.pc-assignment-card__form'),
				'狀態為通過時，繳交表單應整個隱藏',
			).toHaveCount(0)
			await expect(passedCard.locator('.pc-assignment-card__submit-btn')).toHaveCount(0)

			await expect(
				toggleOf(passedCard),
				'狀態為通過時，討論串仍應顯示（照常顯示「與老師討論」）',
			).toBeVisible({ timeout: 30_000 })
			await expandThread(page, passedCard, submissionId)

			const item = messageOf(passedCard, teacherMsg)
			await expect(item, '通過後展開仍應讀到老師的訊息').toBeVisible({ timeout: 30_000 })
			await expect(item.getByText('老師', { exact: true })).toBeVisible()
			await expect(
				inputOf(passedCard),
				'通過後討論串仍可繼續討論（回覆輸入列仍在）',
			).toBeVisible()
		})
	})

	// ─────────────────────────────────────────────
	// 6. XSS
	// ─────────────────────────────────────────────

	test('學員訊息含 HTML 時以純文字顯示，不產生 img 也不執行 onerror @security', async ({ page }) => {
		const submissionId = fx.submissionIds.xss

		await test.step('前置：學員送出的 HTML 經 API 原樣存取（不是被伺服器清掉才「看起來安全」）', async () => {
			const res = await getMyThread(studentSession, submissionId)
			expect(res.status, '學員讀自己的討論串應為 200').toBe(200)
			expect(
				res.messages.some((m) => m.body === XSS_BODY),
				`API 應原樣回傳 HTML 字串，實際: ${JSON.stringify(res.messages.map((m) => m.body))}`,
			).toBe(true)
		})

		await useStudentSession(page)
		const card = await gotoCard(page, fx.assignmentIds.xss)
		await expandThread(page, card, submissionId)

		const item = messageOf(card, XSS_BODY)

		await test.step('HTML 字串以文字顯示', async () => {
			await expect(item, '討論串應把 <img ...> 當文字顯示出來').toBeVisible({ timeout: 30_000 })
			await expect(item).toContainText(XSS_BODY)
		})

		await test.step('討論串內沒有 img 元素', async () => {
			await expect(
				threadOf(card).locator('img'),
				'討論串內不應出現任何 img（代表 HTML 被當成標籤解析了）',
			).toHaveCount(0)
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
				.poll(
					() =>
						page.evaluate(
							() => (window as unknown as { __pcXssControl?: number }).__pcXssControl,
						),
					{
						timeout: 15_000,
						message: '對照組的 onerror 應在這個瀏覽器執行；若沒有，下面「沒執行」就不具鑑別力',
					},
				)
				.toBe(1)
		})

		await test.step('討論串的 onerror 沒有執行（window.__pcXss 為 undefined）', async () => {
			const fired = await page.evaluate(
				() => (window as unknown as { __pcXss?: number }).__pcXss,
			)
			expect(fired, '學員訊息中的 onerror 不應被執行').toBeUndefined()
		})
	})
})
