/**
 * 測試目標：作業附件的真實 HTTP 契約（Issue #131 第一波 P3）
 * 對應原始碼（P3 尚未實作，本檔為紅燈）：
 *   - inc/classes/Resources/Assignment/Core/MyApi.php::post_my_assignment_attachments_callback
 *     （POST /power-course/my/assignment-attachments）
 *   - inc/classes/Resources/Assignment/Core/Api.php::get_assignment_attachments_with_id_download_callback
 *     （GET /power-course/assignment-attachments/{id}/download）
 * 對應規格：specs/features/assignment/上傳作業附件.feature、下載作業附件.feature
 *
 * 為什麼要用 E2E 而非只靠 PHPUnit（見計劃 §6.2、§12 風險登記表 R-04 / R-07 / R-13 / R-14）：
 * - R-13：`is_uploaded_file()` 在 PHPUnit CLI 下永遠不成立（沒有真實的 multipart 上傳），
 *   只有真實 HTTP multipart request 才能驗證上傳流程的這一段。
 * - R-07：`nocache_headers()` 在 PHPUnit process 下第一行就 return（`headers_sent()` 已為 true），
 *   PHPUnit 完全看不到真實回應標頭；只有真實 HTTP 回應才能驗 Cache-Control / Content-Disposition /
 *   X-Content-Type-Options。
 * - R-04：受保護目錄在本機 Nginx 環境對 `.htaccess` 無效，但本檔**不**斷言「直連檔案會被擋」
 *   （那需要另外設定 Nginx deny 規則），只斷言 API 回應本身不洩漏 url / stored_path。
 *
 * 前置條件：
 * - 管理員以 REST 建立課程 + 章節 + 作業（P2 端點已可用）
 * - 兩位學員（Powerhouse V2 API 建立，本站 /wp/v2/users 被 mu-plugin 移除，
 *   見 tests/e2e/helpers/api-client.ts 的 ensureUserViaPowerhouse()）
 * - 其中一位（Student A）取得課程觀看權；另一位（Student B）沒有，用來測附件 IDOR
 *
 * 預期結果（Green 完成前，本檔全數失敗，且失敗原因必須是「功能不存在」— 路由 404 rest_no_route，
 * 不是登入 / nonce / 憑證問題）：
 * 1. 學員 multipart 上傳附件成功，回應含 attachment_id / file_name / mime_type / file_size，
 *    不含 url / stored_path
 * 2. 學員下載自己的附件：200；Cache-Control 含 no-cache；Content-Disposition 為 attachment
 *    且含原檔名（RFC 5987 `filename*=UTF-8''...` 編碼，見 P3-R1 介面契約）；
 *    X-Content-Type-Options: nosniff；內容與上傳檔一致
 * 3. 另一位學員下載他人附件：403
 * 4. 訪客（未登入）上傳 / 下載：401
 *
 * CSV 契約（匯出單一作業繳交CSV / 匯出批改用CSV）留給 P4-R2 擴充，見檔尾空 describe 骨架。
 */
import { test, expect } from '@playwright/test'
import type { APIRequestContext, APIResponse, Browser, BrowserContext } from '@playwright/test'
import { ApiClient, setupApiFromBrowser } from '../helpers/api-client.js'

const BASE_URL = process.env.TEST_SITE_URL || 'http://localhost:8889'

test.setTimeout(120_000)

// ── 測試用檔案內容 ──────────────────────────────────
//
// 用真實、可被 finfo 正確辨識為 image/png 的最小合法 PNG（1x1 透明像素），
// 避免 Green 端若真的做 MIME 內容交叉驗證（DEC-16 / R-14）時，因為測試資料
// 本身不是「真的」PNG 而產生偽陽性紅燈。
const PNG_1PX_BASE64 =
	'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='
const UPLOAD_FILE_BUFFER = Buffer.from(PNG_1PX_BASE64, 'base64')
const UPLOAD_FILE_NAME = '作業截圖-P3R2.png'
const UPLOAD_FILE_MIME = 'image/png'

// ── 測試用學員（Powerhouse V2 API 建立，避開本站移除的 /wp/v2/users） ──
const STUDENT_OWNER = {
	username: 'e2e_assign_owner_131',
	email: 'e2e_assign_owner_131@test.local',
	password: 'e2eAssignOwner131!Pass',
}
const STUDENT_OTHER = {
	username: 'e2e_assign_other_131',
	email: 'e2e_assign_other_131@test.local',
	password: 'e2eAssignOther131!Pass',
}

// 本機站冷啟動時導覽很慢（memory: reference_local_frontend_verify_playbook /
// project_e2e_helper_traps）。可用 E2E_ADMIN_NAV_TIMEOUT 覆寫，預設 90s。
const NAV_TIMEOUT = Number(process.env.E2E_ADMIN_NAV_TIMEOUT || 90_000)

// ── Helper：以學員身分登入並取得 REST nonce ──────────────
//
// 不直接用 helpers/frontend-setup.ts 的 loginAs()：它內部 waitForURL 寫死
// 30s timeout，在本機站冷啟動時實測會逾時（page.goto / waitForURL 皆曾逾時），
// 而規則禁止修改既有 helper 行為。這裡改寫等效流程（cookie + nonce 取得方式，
// 派發規格明確允許），只是把逾時放寬到可設定的 NAV_TIMEOUT。
// nonce 另外透過 admin-ajax.php?action=rest-nonce 取得（WP 核心內建 hook，
// 任何已登入使用者皆可用），比等待完整 wp-admin SPA render 快很多。
async function loginStudentAndGetNonce(
	browser: Browser,
	username: string,
	password: string,
): Promise<{ context: BrowserContext; request: APIRequestContext; nonce: string }> {
	const context = await browser.newContext({ ignoreHTTPSErrors: true })
	context.setDefaultTimeout(NAV_TIMEOUT)
	context.setDefaultNavigationTimeout(NAV_TIMEOUT)
	const page = await context.newPage()

	await page.context().clearCookies()
	await page.goto(`${BASE_URL}/wp-login.php`, {
		waitUntil: 'domcontentloaded',
		timeout: NAV_TIMEOUT,
	})
	await page.locator('#user_login').fill(username)
	await page.locator('#user_pass').fill(password)
	await page.locator('#wp-submit').click({ noWaitAfter: true, timeout: NAV_TIMEOUT })
	// 等待離開登入頁面（訂閱者登入後導向首頁或 profile.php）
	await page.waitForURL((url) => !url.pathname.includes('wp-login'), {
		timeout: NAV_TIMEOUT,
	})

	// 防禦：極少數情況登入後被導去「確認管理員電子郵件」攔截頁
	// （memory: reference_local_frontend_verify_playbook，一般學員通常不會遇到）
	if (page.url().includes('confirm_admin_email')) {
		await page
			.locator('.admin-email__actions-secondary a')
			.click({ timeout: 5_000 })
			.catch(() => {
				/* 沒有這個連結就略過，交給後面的 nonce 檢查判斷是否真的失敗 */
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

/** Helper：建立未登入（訪客）用的 request context */
async function createGuestContext(
	browser: Browser,
): Promise<{ context: BrowserContext; request: APIRequestContext }> {
	const context = await browser.newContext({ ignoreHTTPSErrors: true })
	return { context, request: context.request }
}

/** Helper：以 multipart/form-data 呼叫「上傳作業附件」 */
async function uploadAssignmentAttachment(
	requestCtx: APIRequestContext,
	nonce: string,
	params: { filename: string; buffer: Buffer; mimeType: string },
): Promise<{ status: number; data: unknown }> {
	const headers: Record<string, string> = {}
	if (nonce) headers['X-WP-Nonce'] = nonce

	const resp = await requestCtx.post(
		`${BASE_URL}/wp-json/power-course/my/assignment-attachments`,
		{
			headers,
			multipart: {
				file: {
					name: params.filename,
					mimeType: params.mimeType,
					buffer: params.buffer,
				},
			},
			timeout: 60_000,
		},
	)

	let data: unknown
	try {
		data = await resp.json()
	} catch {
		// PHP debug 模式可能在 JSON 前輸出 notices，嘗試擷取 JSON 部分
		const text = await resp.text()
		const match = text.match(/[\[{][\s\S]*/)
		data = match ? JSON.parse(match[0]) : text
	}

	return { status: resp.status(), data }
}

/** Helper：呼叫「下載作業附件」，回傳原始 APIResponse（需要讀 headers / binary body） */
async function downloadAssignmentAttachment(
	requestCtx: APIRequestContext,
	nonce: string,
	attachmentId: number | string,
): Promise<APIResponse> {
	const headers: Record<string, string> = {}
	if (nonce) headers['X-WP-Nonce'] = nonce

	return requestCtx.get(
		`${BASE_URL}/wp-json/power-course/assignment-attachments/${attachmentId}/download`,
		{ headers, timeout: 60_000 },
	)
}

// ── 共用狀態 ────────────────────────────────────────
let adminApi: ApiClient
let disposeAdmin: () => Promise<void>

let courseId: number
let assignmentId: number
let ownerId: number
let otherId: number

let ownerCtx: BrowserContext
let ownerRequest: APIRequestContext
let ownerNonce: string

let otherCtx: BrowserContext
let otherRequest: APIRequestContext
let otherNonce: string

let guestCtx: BrowserContext
let guestRequest: APIRequestContext

/** 上傳測試建立的附件 ID，供後續下載測試串接（見測試 1 → 2 → 3 的依賴關係） */
let uploadedAttachmentId: number | undefined

test.describe.serial('作業附件 HTTP 契約（Issue #131 P3-R2）', () => {
	test.beforeAll(async ({ browser }) => {
		// 課程/章節/作業建立 + 2 位學員建立 + 2 次可能各吃滿 NAV_TIMEOUT 的登入，
		// 序列執行加總可能超過整合測試預設的 120s，這裡明確放寬。
		test.setTimeout(NAV_TIMEOUT * 3 + 60_000)

		// 1. 管理員 REST：建課程 + 章節 + 作業（P2 端點已可用）
		const setup = await setupApiFromBrowser(browser)
		adminApi = setup.api
		disposeAdmin = setup.dispose

		const created = await adminApi.createCourseWithChapters(
			'E2E 作業附件測試課程',
			'0',
			[{ name: 'E2E 作業附件章節', slug: 'e2e-assignment-attachment-chapter' }],
			'e2e-assignment-attachment-course',
		)
		courseId = created.courseId
		const chapterId = created.chapterIds[0]

		const assignResp = await adminApi.pcPost<{ id: number }>('assignments', {
			chapter_id: chapterId,
			title: '練習：上傳作業附件（E2E P3-R2）',
			allow_text: 'yes',
			allow_file: 'yes',
			max_files: 5,
		})
		if (assignResp.status >= 400 || !(assignResp.data as { id?: number })?.id) {
			throw new Error(`建立測試作業失敗: ${JSON.stringify(assignResp.data)}`)
		}
		assignmentId = (assignResp.data as { id: number }).id

		// 2. 建立兩位學員；只給 owner 課程觀看權（other 用來測 IDOR）
		ownerId = await adminApi.ensureUserViaPowerhouse(
			STUDENT_OWNER.username,
			STUDENT_OWNER.email,
			STUDENT_OWNER.password,
		)
		otherId = await adminApi.ensureUserViaPowerhouse(
			STUDENT_OTHER.username,
			STUDENT_OTHER.email,
			STUDENT_OTHER.password,
		)
		await adminApi.grantCourseAccess(ownerId, courseId)

		// 3. 各身分登入取得 request context + nonce
		const owner = await loginStudentAndGetNonce(browser, STUDENT_OWNER.username, STUDENT_OWNER.password)
		ownerCtx = owner.context
		ownerRequest = owner.request
		ownerNonce = owner.nonce

		const other = await loginStudentAndGetNonce(browser, STUDENT_OTHER.username, STUDENT_OTHER.password)
		otherCtx = other.context
		otherRequest = other.request
		otherNonce = other.nonce

		const guest = await createGuestContext(browser)
		guestCtx = guest.context
		guestRequest = guest.request
	})

	test.afterAll(async () => {
		test.setTimeout(120_000)

		try {
			await adminApi.removeCourseAccess(ownerId, courseId)
		} catch {
			/* ignore */
		}
		try {
			await adminApi.deleteCourses([courseId])
		} catch {
			/* ignore */
		}
		// 附件實體檔不刪（D9 / D16，見計劃 §13 限制條件）
		try {
			await adminApi.deleteUserViaPowerhouse(ownerId)
		} catch {
			/* ignore */
		}
		try {
			await adminApi.deleteUserViaPowerhouse(otherId)
		} catch {
			/* ignore */
		}

		if (ownerCtx) await ownerCtx.close()
		if (otherCtx) await otherCtx.close()
		if (guestCtx) await guestCtx.close()
		if (disposeAdmin) await disposeAdmin()
	})

	// ─────────────────────────────────────────────
	// 1. 上傳附件（真實 multipart）
	// ─────────────────────────────────────────────

	test('學員 multipart 上傳附件成功，回應含必要欄位且不含 url / stored_path @happy @smoke', async () => {
		const { status, data } = await uploadAssignmentAttachment(ownerRequest, ownerNonce, {
			filename: UPLOAD_FILE_NAME,
			buffer: UPLOAD_FILE_BUFFER,
			mimeType: UPLOAD_FILE_MIME,
		})

		expect(status, `上傳回應非 200，body: ${JSON.stringify(data)}`).toBe(200)

		const body = data as Record<string, unknown>
		expect(body).toHaveProperty('attachment_id')
		expect(typeof body['attachment_id']).toBe('number')
		expect(body).toHaveProperty('file_name', UPLOAD_FILE_NAME)
		expect(body).toHaveProperty('mime_type', UPLOAD_FILE_MIME)
		expect(body).toHaveProperty('file_size', UPLOAD_FILE_BUFFER.length)

		// 回應不應洩漏儲存路徑或公開 URL（受保護目錄，Q4 使用者決策）
		expect(body).not.toHaveProperty('url')
		expect(body).not.toHaveProperty('stored_path')

		uploadedAttachmentId = body['attachment_id'] as number
	})

	// ─────────────────────────────────────────────
	// 2. 下載附件（真實標頭 / 串流內容）
	// ─────────────────────────────────────────────

	test('本人下載自己的附件：200，禁止快取標頭、Content-Disposition、nosniff 與內容皆正確 @happy', async () => {
		const resp = await downloadAssignmentAttachment(
			ownerRequest,
			ownerNonce,
			uploadedAttachmentId ?? -1,
		)
		const bodyBuffer = await resp.body().catch(() => Buffer.alloc(0))

		expect(
			resp.status(),
			`下載回應非 200，body 前 500 字: ${bodyBuffer.subarray(0, 500).toString('utf-8')}`,
		).toBe(200)

		const headers = resp.headers()
		const cacheControl = String(headers['cache-control'] || headers['Cache-Control'] || '')
		expect(cacheControl).toContain('no-cache')

		const contentDisposition = String(
			headers['content-disposition'] || headers['Content-Disposition'] || '',
		)
		expect(contentDisposition).toContain('attachment')
		// P3-R1 介面契約：filename*=UTF-8''{rawurlencode(original_name)}（RFC 5987，中文檔名不可裸放引號內）
		expect(contentDisposition).toContain(`filename*=UTF-8''${encodeURIComponent(UPLOAD_FILE_NAME)}`)

		const nosniff = String(
			headers['x-content-type-options'] || headers['X-Content-Type-Options'] || '',
		)
		expect(nosniff).toBe('nosniff')

		const contentType = String(headers['content-type'] || headers['Content-Type'] || '')
		expect(contentType).toContain(UPLOAD_FILE_MIME)

		// 內容必須與上傳檔逐位元組一致（真實串流，PHPUnit 驗不到）
		expect(bodyBuffer.equals(UPLOAD_FILE_BUFFER)).toBe(true)
	})

	// ─────────────────────────────────────────────
	// 3. IDOR：另一位學員下載他人附件
	// ─────────────────────────────────────────────

	test('另一位學員下載他人附件應回應 403（防 IDOR） @security', async () => {
		const resp = await downloadAssignmentAttachment(
			otherRequest,
			otherNonce,
			uploadedAttachmentId ?? -1,
		)

		expect(resp.status()).toBe(403)
	})

	// ─────────────────────────────────────────────
	// 4. 訪客（未登入）
	// ─────────────────────────────────────────────

	test('訪客（未登入）上傳與下載作業附件皆應回應 401 @error', async () => {
		const uploadResp = await uploadAssignmentAttachment(guestRequest, '', {
			filename: UPLOAD_FILE_NAME,
			buffer: UPLOAD_FILE_BUFFER,
			mimeType: UPLOAD_FILE_MIME,
		})
		expect(uploadResp.status, `訪客上傳回應: ${JSON.stringify(uploadResp.data)}`).toBe(401)

		const downloadResp = await downloadAssignmentAttachment(
			guestRequest,
			'',
			uploadedAttachmentId ?? -1,
		)
		expect(downloadResp.status()).toBe(401)
	})

	// ─────────────────────────────────────────────
	// CSV 契約（P4-R2 擴充）
	// ─────────────────────────────────────────────
	//
	// 匯出單一作業繳交 CSV（GET /assignments/{id}/submissions/export）與
	// 匯出批改用 CSV（GET /assignment-submissions/export）尚未實作（P4），
	// 此處只保留結構骨架供 P4-R2 補上：BOM 開頭、CRLF、Content-Disposition
	// 檔名規則（見計劃 §9 P4、§12 R-21）。
	test.describe('CSV 匯出契約（P4-R2 擴充）', () => {
		// 保留給 P4-R2：courseId / assignmentId 已在 beforeAll 建立，CSV 測試可直接沿用，
		// 此區塊故意留空，不在本階段（附件上傳/下載）產生任何測試案例。
	})
})
