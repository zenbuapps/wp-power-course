/**
 * 匯入批改 CSV 不受共用 axios 的 30 秒逾時截斷（Issue #131 第二波 W3-TO）
 *
 * Bug 成因：
 * - antd-toolkit 的 EnvProvider 建立的 axios 實例 `timeout: 3e4`（30 秒），
 *   dataProvider `custom()` 又只合併 headers、不吃 per-request config，useCustomMutation 無法延長單次逾時
 * - 「匯入批改 CSV」上限 5000 列，本機 500 列實測 6.4 秒 → 5000 列約 64 秒；後端 `wc_set_time_limit(0)` 會全部套用
 * → 前端 30 秒就顯示錯誤，後端其實已（或仍在）套用並排了批改通知信。老師以為失敗而重匯，學員收到第二封通知。
 *
 * 模擬「後端處理超過 30 秒」：page.route 讓真實請求照常送到後端（route.fetch），
 * 拿到真實回應後延遲 40 秒才交給頁面（route.fulfill）。後端真的套用了，畫面只是晚一點才拿到回應。
 *
 * 對照斷言（少了它們，「畫面顯示結果」可能只代表攔截沒生效）：
 * - 攔截器確實攔到匯入請求，且真實後端回 200、摘要為更新 1
 * - 從選檔到按鈕結束 loading 的時間至少是延遲的 40 秒（請求確實被拖過 30 秒）
 *
 * 資料準備一律走 REST：課程 + 章節 + 作業（管理員）、學員（Powerhouse V2 API）+ 授權，
 * 學員以 loginAs() 登入後用自己的 nonce 繳交一份作業（pending）。
 * 匯入用 CSV 由匯出端點取得原檔，只把該筆的狀態欄改成 pass（表頭沿用匯出格式與站台語系）。
 *
 * 預期結果（修正前）：約 30 秒時 axios 逾時 → 畫面跳出錯誤訊息、結果容器 `.pc-assignment-import-result` 不出現；
 * 後端其實已把該筆改為 pass。
 */
import { test, expect } from '@playwright/test'
import type { APIRequestContext, Browser, Page } from '@playwright/test'

import { navigateToAdmin } from '../helpers/admin-page.js'
import { setupApiFromBrowser, type ApiClient } from '../helpers/api-client.js'
import { loginAs } from '../helpers/frontend-setup.js'

const BASE_URL = process.env.TEST_SITE_URL || 'http://localhost:8889'
const NAV_TIMEOUT = Number(process.env.E2E_ADMIN_NAV_TIMEOUT || 90_000)

/** 攔截器拿到真實回應後，延遲多久才交給頁面（必須大於 antd-toolkit axios 實例的 30 秒） */
const RESPONSE_DELAY_MS = 40_000

/** route.fetch 等真實後端回應的上限（本機只有 2 個 PHP 工作程序，請求可能排隊） */
const BACKEND_FETCH_TIMEOUT = 120_000

// 後台冷載入 2～60 秒 + 真實匯入請求 + 40 秒延遲 + 斷言等待，上限給足
test.setTimeout(480_000)

// ── 測試資料 ────────────────────────────────────────
// 課程名稱必須以 E2E 開頭，global-setup 才清得掉殘留
const COURSE_TITLE = 'E2E 匯入逾時課程'
const ASSIGNMENT_TITLE = 'E2E匯入逾時作業'

const STUDENT = {
	username: 'e2e_import_timeout_131',
	email: 'e2e_import_timeout_131@test.local',
	password: 'e2eImportTimeout131!Pass',
	name: 'E2E匯入逾時學員',
}

/** 頁面上記錄 antd message 的 window key（message 預設 3 秒消失，事後查 DOM 會漏掉） */
const MESSAGE_LOG_KEY = '__pcImportTimeoutMessages'

// ── 型別 ────────────────────────────────────────────

interface ImportSummary {
	total: number
	updated: number
	skipped: number
	failed: number
	errors: { row: number; message: string }[]
}

interface SubmissionListItem {
	id: number
	status: string
}

/** 攔截器的觀測紀錄 */
interface RouteObservation {
	/** 攔到匯入 POST 的次數 */
	hits: number
	/** 真實後端的狀態碼 */
	backendStatus?: number
	/** 真實後端的回應本文 */
	backendBody?: string
	/** route.fetch 失敗原因（後端沒回應） */
	fetchError?: string
	/** route.fulfill 失敗原因（例如頁面已因逾時取消請求） */
	fulfillError?: string
}

// ── Helper：CSV（測試側自備，與 production 的 CsvParser 無關） ──

/** 簡易 RFC 4180 解析：處理 BOM、引號內逗號 / 換行、"" 跳脫 */
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

/** CSV 欄位跳脫：含逗號、雙引號、CR / LF 才加引號 */
function csvCell(value: string): string {
	return /[",\r\n]/.test(value) ? `"${value.replace(/"/g, '""')}"` : value
}

/** 組 CSV 位元組（UTF-8 BOM + CRLF，同 Excel 另存與匯出檔的樣子） */
function buildCsv(rows: string[][]): Buffer {
	const text = rows.map((r) => r.map(csvCell).join(',')).join('\r\n') + '\r\n'
	return Buffer.concat([Buffer.from([0xef, 0xbb, 0xbf]), Buffer.from(text, 'utf-8')])
}

// ── Helper：學員登入取得 nonce ──
// loginAs() 會等登入頁載完才填表、以 waitUntil: 'commit' 判斷已被導離登入頁（不等最重的 /my-account/），
// 之後用 admin-ajax 的 rest-nonce 取 REST nonce，不必再載任何前台頁
async function loginStudentForNonce(
	browser: Browser,
): Promise<{ close: () => Promise<void>; request: APIRequestContext; nonce: string }> {
	// baseURL 必須明確指定：loginAs() 用相對路徑 goto，手動 newContext() 不繼承 config 的 use.baseURL
	const context = await browser.newContext({ baseURL: BASE_URL, ignoreHTTPSErrors: true })
	context.setDefaultTimeout(NAV_TIMEOUT)
	context.setDefaultNavigationTimeout(NAV_TIMEOUT)

	const page = await context.newPage()
	await loginAs(page, STUDENT.username, STUDENT.password)
	await page.close()

	const resp = await context.request.get(`${BASE_URL}/wp-admin/admin-ajax.php?action=rest-nonce`, {
		timeout: NAV_TIMEOUT,
	})
	const nonce = (await resp.text()).trim()
	if (!nonce || nonce === '0' || nonce === '-1') {
		await context.close()
		throw new Error(`無法取得學員 "${STUDENT.username}" 的 REST nonce，回應: ${nonce}`)
	}

	return { close: () => context.close(), request: context.request, nonce }
}

// ── Helper：UI ───────────────────────────────────────

/** 切換到指定課程分頁（Tabs 選項文字含「{課程標題} ({count})」） */
async function selectCourseTab(page: Page, courseTitle: string): Promise<void> {
	const tab = page.locator('.ant-tabs-tab').filter({ hasText: courseTitle })
	await expect(tab, `課程分頁應包含「${courseTitle}」`).toBeVisible({ timeout: 60_000 })
	await tab.click()
	await expect(tab, `應已切換到「${courseTitle}」分頁`).toHaveClass(/ant-tabs-tab-active/, {
		timeout: 30_000,
	})
}

/**
 * 在頁面上記錄之後出現的每一則 antd message（類型 + 文字）
 *
 * antd message 預設 3 秒就消失；錯誤訊息若在斷言之前就消失，事後查 DOM 會得到假的「沒有錯誤」。
 * SPA 全程不重新載入，掛在 window 上的紀錄會一直留著。
 */
async function startMessageLog(page: Page): Promise<void> {
	await page.evaluate((key) => {
		const log: string[] = []
		;(window as unknown as Record<string, unknown>)[key] = log
		const types = ['error', 'warning', 'success', 'info', 'loading']
		const record = () => {
			document.querySelectorAll('.ant-message-notice').forEach((notice) => {
				const type =
					types.find((t) => notice.querySelector(`.ant-message-${t}`)) ?? 'unknown'
				const entry = `${type}: ${(notice.textContent || '').trim()}`
				if (!log.includes(entry)) {
					log.push(entry)
				}
			})
		}
		new MutationObserver(record).observe(document.body, { childList: true, subtree: true })
	}, MESSAGE_LOG_KEY)
}

/** 讀回目前為止記錄到的 antd message */
async function readMessageLog(page: Page): Promise<string[]> {
	return page.evaluate(
		(key) => ((window as unknown as Record<string, unknown>)[key] as string[] | undefined) ?? [],
		MESSAGE_LOG_KEY,
	)
}

// ── 共用狀態 ────────────────────────────────────────
let adminApi: ApiClient
let disposeAdmin: () => Promise<void>

let courseId: number
let studentId: number
let submissionId: number

test.describe('匯入批改 CSV 不受 30 秒逾時截斷（Issue #131 W3-TO）', () => {
	test.use({ storageState: '.auth/admin.json' })

	test.beforeAll(async ({ browser }) => {
		test.setTimeout(NAV_TIMEOUT * 3 + 180_000)

		const setup = await setupApiFromBrowser(browser)
		adminApi = setup.api
		disposeAdmin = setup.dispose

		// 1. 課程 + 章節 + 作業
		const created = await adminApi.createCourseWithChapters(
			COURSE_TITLE,
			'0',
			[{ name: 'E2E匯入逾時章節', slug: 'e2e-import-timeout-chapter' }],
			'e2e-assignment-import-timeout-course',
		)
		courseId = created.courseId

		const assignResp = await adminApi.pcPost<{ id: number }>('assignments', {
			chapter_id: created.chapterIds[0],
			title: ASSIGNMENT_TITLE,
			allow_text: 'yes',
			allow_file: 'yes',
			max_files: 5,
		})
		const assignmentId = (assignResp.data as { id?: number })?.id
		if (assignResp.status >= 400 || !assignmentId) {
			throw new Error(`建立測試作業失敗: ${JSON.stringify(assignResp.data)}`)
		}

		// 2. 學員 + 課程觀看權（第 5 參數帶顯示名稱）
		studentId = await adminApi.ensureUserViaPowerhouse(
			STUDENT.username,
			STUDENT.email,
			STUDENT.password,
			['subscriber'],
			STUDENT.name,
		)
		await adminApi.grantCourseAccess(studentId, courseId)

		// 3. 學員以自己的 nonce 繳交（維持 pending，留給匯入）
		const student = await loginStudentForNonce(browser)
		try {
			const submitResp = await student.request.post(
				`${BASE_URL}/wp-json/power-course/my/assignments/${assignmentId}/submit`,
				{
					headers: { 'X-WP-Nonce': student.nonce, 'Content-Type': 'application/json' },
					data: { text_body: '匯入逾時測試的繳交內容' },
					timeout: 60_000,
				},
			)
			const submitText = await submitResp.text()
			const submitted = JSON.parse(submitText.slice(Math.max(0, submitText.indexOf('{')))) as {
				id?: number
			}
			if (submitResp.status() >= 400 || !submitted.id) {
				throw new Error(`學員繳交失敗（${submitResp.status()}）: ${submitText.slice(0, 500)}`)
			}
			submissionId = submitted.id
		} finally {
			await student.close()
		}
	})

	test.afterAll(async () => {
		test.setTimeout(120_000)
		if (adminApi && courseId) {
			try {
				await adminApi.deleteCourses([courseId])
			} catch {
				/* ignore */
			}
		}
		if (adminApi && studentId) {
			try {
				await adminApi.deleteUserViaPowerhouse(studentId)
			} catch {
				/* ignore */
			}
		}
		if (disposeAdmin) await disposeAdmin()
	})

	test('後端處理超過 30 秒時，畫面等到真正的匯入結果：顯示更新 1、失敗 0，不跳錯誤，該筆離開待批改列表 @regression', async ({
		page,
	}) => {
		// ── 1. 匯入用 CSV：取匯出原檔（表頭依站台語系），只把該筆的狀態欄改成 pass
		const csv = await test.step('由匯出端點取得 CSV，把該筆狀態改為 pass', async () => {
			// ApiClient 只回 JSON，CSV 位元組改用頁面 context 的裸 request（同一組管理員 cookie）+ rest-nonce
			const nonceResp = await page.request.get(`${BASE_URL}/wp-admin/admin-ajax.php?action=rest-nonce`, {
				timeout: NAV_TIMEOUT,
			})
			const adminNonce = (await nonceResp.text()).trim()
			expect(adminNonce, `應取得管理員 REST nonce，實際: ${adminNonce}`).toMatch(/^[0-9a-f]{6,}$/)

			const resp = await page.request.get(
				`${BASE_URL}/wp-json/power-course/assignment-submissions/export?course_id=${courseId}`,
				{ headers: { 'X-WP-Nonce': adminNonce }, timeout: 60_000 },
			)
			expect(resp.status(), '匯出端點應回 200').toBe(200)
			const rows = parseCsv((await resp.body()).toString('utf-8'))
			const header = rows[0] ?? []
			const idCol = header.indexOf('submission_id')
			const statusCol = header.findIndex((h) => h.includes('pending/pass/fail'))
			expect(idCol, `匯出表頭應有 submission_id，實際: ${header.join(' | ')}`).toBeGreaterThanOrEqual(0)
			expect(statusCol, `匯出表頭應有可改的狀態欄（含 pending/pass/fail），實際: ${header.join(' | ')}`).toBeGreaterThanOrEqual(0)

			const dataRow = rows.slice(1).find((r) => r[idCol] === String(submissionId))
			expect(dataRow, `匯出檔應含 fixture 繳交 ${submissionId}`).toBeDefined()
			const edited = [...(dataRow as string[])]
			edited[statusCol] = 'pass'
			return buildCsv([header, edited])
		})

		// ── 2. 攔截匯入請求：照常送到真實後端，拿到回應後延遲 40 秒才交給頁面
		const observation: RouteObservation = { hits: 0 }
		let markRouteDone: () => void = () => undefined
		const routeDone = new Promise<void>((resolve) => {
			markRouteDone = resolve
		})
		await page.route('**/assignment-submissions/import**', async (route) => {
			if (route.request().method() !== 'POST') {
				await route.continue()
				return
			}
			observation.hits++
			try {
				const response = await route.fetch({ timeout: BACKEND_FETCH_TIMEOUT })
				observation.backendStatus = response.status()
				observation.backendBody = await response.text()
				await new Promise((resolve) => setTimeout(resolve, RESPONSE_DELAY_MS))
				try {
					await route.fulfill({ response })
				} catch (error) {
					observation.fulfillError = String(error)
				}
			} catch (error) {
				observation.fetchError = String(error)
				await route.abort().catch(() => undefined)
			} finally {
				markRouteDone()
			}
		})

		// ── 3. 進批改頁 → 選 fixture 課程（預設篩選「待批改」）
		await navigateToAdmin(page, '/assignments')
		await page.waitForSelector('.ant-tabs', { timeout: 60_000 })
		await selectCourseTab(page, COURSE_TITLE)

		const row = page
			.locator('.pc-assignment-submission-table tr.ant-table-row')
			.filter({ hasText: STUDENT.email })
		await expect(row, '匯入前，該筆應在預設的「待批改」列表內').toBeVisible({ timeout: 60_000 })

		await startMessageLog(page)

		// ── 4. 「匯入批改 CSV」選檔上傳
		const importButton = page.locator('.ant-btn').filter({ hasText: '匯入批改 CSV' })
		await expect(importButton, '工具列應有「匯入批改 CSV」按鈕').toBeVisible({ timeout: 30_000 })
		const fileInput = page.locator('input[type="file"][accept*="csv"]')
		await expect(fileInput, '應有接受 CSV 的檔案欄位').toHaveCount(1)

		await fileInput.setInputFiles({ name: 'grades-import-timeout.csv', mimeType: 'text/csv', buffer: csv })
		const uploadedAt = Date.now()
		await expect(importButton, '上傳中按鈕應為 loading（避免重複送出）').toHaveClass(/ant-btn-loading/, {
			timeout: 15_000,
		})

		// 與下面的對照步驟並行等「前端結束這次請求」（按鈕離開 loading），記下當下時間：
		// 修正前它在 30 秒就結束，若等對照步驟做完才量，量到的會是攔截器的 40 秒而不是前端放棄的時間
		const loadingEnded = expect(importButton)
			.not.toHaveClass(/ant-btn-loading/, { timeout: BACKEND_FETCH_TIMEOUT + RESPONSE_DELAY_MS + 60_000 })
			.then(
				() => Date.now(),
				(error: unknown) => (error instanceof Error ? error : new Error(String(error))),
			)

		// ── 5. 對照：攔截器確實攔到請求、真實後端已套用
		await test.step('對照：真實後端回 200、摘要為更新 1，且該筆在後端已是 pass', async () => {
			await routeDone
			console.log(
				`[W3-TO] 攔截紀錄：hits=${observation.hits}；後端狀態碼=${observation.backendStatus}；` +
					`後端回應=${observation.backendBody}；fetchError=${observation.fetchError ?? '無'}；` +
					`fulfillError=${observation.fulfillError ?? '無'}`,
			)
			expect(observation.hits, '攔截器應恰好攔到一次匯入 POST').toBe(1)
			expect(observation.fetchError, '真實後端應有回應').toBeUndefined()
			expect(observation.backendStatus, `真實後端應回 200（${observation.backendBody}）`).toBe(200)
			const summary = JSON.parse(observation.backendBody as string) as ImportSummary
			expect(summary.updated, `真實後端摘要應為更新 1（${observation.backendBody}）`).toBe(1)
			expect(summary.failed, `真實後端摘要應為失敗 0（${observation.backendBody}）`).toBe(0)

			const list = await adminApi.pcGet<{ items: SubmissionListItem[] }>('assignment-submissions', {
				course_id: String(courseId),
				status: 'all',
				limit: '50',
			})
			const record = (list.data as { items?: SubmissionListItem[] }).items?.find((item) => item.id === submissionId)
			console.log(`[W3-TO] 後端該筆（${submissionId}）狀態：${record?.status}`)
			expect(record?.status, '後端該筆應已被匯入為 pass').toBe('pass')
		})

		// ── 6. 畫面：匯入請求在前端結束（按鈕離開 loading）之後，前端給了什麼結果
		const endedAt = await loadingEnded
		if (endedAt instanceof Error) {
			throw new Error(`匯入請求結束後按鈕應離開 loading：${endedAt.message}`)
		}
		const settledSeconds = (endedAt - uploadedAt) / 1000
		const messages = await readMessageLog(page)
		console.log(`[W3-TO] 選檔到按鈕結束 loading：${settledSeconds.toFixed(1)} 秒；畫面 message：${JSON.stringify(messages)}`)

		expect
			.soft(
				messages.filter((m) => m.startsWith('error:') || m.includes('匯入失敗')),
				`畫面不應跳出任何錯誤訊息（實際記錄到：${JSON.stringify(messages)}）`,
			)
			.toEqual([])
		expect
			.soft(
				settledSeconds,
				`對照：前端應等到延遲 ${RESPONSE_DELAY_MS / 1000} 秒後的真實回應才結束 loading（實際 ${settledSeconds.toFixed(1)} 秒）`,
			)
			.toBeGreaterThanOrEqual(RESPONSE_DELAY_MS / 1000)

		const result = page.locator('.pc-assignment-import-result')
		await expect(result, '匯入後應在頁內顯示結果（.pc-assignment-import-result）').toBeVisible({
			timeout: 90_000,
		})
		await expect(result, '結果應為更新 1').toContainText(/更新\s*1(?!\d)/)
		await expect(result, '結果應為失敗 0').toContainText(/失敗\s*0(?!\d)/)

		await expect(row, '匯入為通過後，該筆不應再出現在「待批改」列表').toHaveCount(0, {
			timeout: 60_000,
		})
	})
})
