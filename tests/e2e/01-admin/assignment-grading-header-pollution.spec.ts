/**
 * 作業批改不受共用 axios header 污染（Issue #131 第二波 W3-AX）
 *
 * Bug 成因：
 * - antd-toolkit 的 dataProvider `custom()` 會把呼叫端傳入的 headers 寫進共用 axios 實例的
 *   `defaults.headers`（node_modules/antd-toolkit/dist/useWoocommerce-*.js），不只影響那一次請求，
 *   之後同一個 SPA 生命週期內的每個請求都會沿用
 * - 後台多處 useCustomMutation 帶 `'Content-Type': 'multipart/form-data;'`；之後的物件 payload
 *   會被 axios 1.x 依 header 轉成 FormData 送出
 * - PHP 只替 POST 解析 multipart；WordPress REST 對 PATCH / PUT / DELETE 的 multipart 本文讀不到參數
 * → 管理員先做過任一次 multipart 操作、沒有重新載入頁面就去批改，`PATCH grade` 收不到 status
 *
 * 前置操作的選擇：課程編輯 → 「學員」分頁 → 勾選 fixture 學員 → 「更新觀看時間限制」（日期留空 = 無限制）
 * - 這顆按鈕走 useCustomMutation（→ dataProvider.custom()）並帶 multipart header，一定會寫進 defaults
 * - 課程列表的 ToggleVisibility 走 useUpdate（→ dataProvider.update()），header 只套用在那一次請求、
 *   不寫進 defaults，拿它當前置操作永遠紅不起來，所以不用
 * - fixture 學員先以 REST 授權一年期限，前置操作改成無限制：學員始終有觀看權，副作用只落在本檔自建、
 *   afterAll 會刪除的課程與學員上。不能一開始就給無限期——值沒變時後端 update_metadata 回 false，
 *   update-students 會回 400（既有行為，與本 bug 無關）
 *
 * 對照斷言：前置操作那支 POST 的 Content-Type 必須含 multipart/form-data，且全程沒有重新載入頁面
 * （頁面上掛一個 window 標記，最後確認還在）。少了這兩個對照，「PATCH 是 JSON」可能只代表
 * 污染根本沒發生，不代表修正有效。
 *
 * 資料準備一律走 REST：課程 + 章節 + 作業（管理員）、學員（Powerhouse V2 API）+ 授權，
 * 學員以自己的 nonce 繳交一份作業（pending）。
 *
 * 預期結果（修正前）：紅在 PATCH 的 Content-Type（multipart）、狀態碼（400）與批改結果（status 缺漏）。
 */
import { test, expect } from '@playwright/test'
import type {
	APIRequestContext,
	Browser,
	BrowserContext,
	Page,
} from '@playwright/test'

import {
	clickTab,
	navigateToAdmin,
	waitForTableLoaded,
} from '../helpers/admin-page'
import { setupApiFromBrowser, type ApiClient } from '../helpers/api-client'
import { loginAs } from '../helpers/frontend-setup'

const BASE_URL = process.env.TEST_SITE_URL || 'http://localhost:8889'
const NAV_TIMEOUT = Number(process.env.E2E_ADMIN_NAV_TIMEOUT || 90_000)

// 本機站只有 2 個 PHP 工作程序，頁面並行請求排隊，單一請求可達 20～30 秒；
// 本測試要走兩個後台頁面（課程編輯 + 作業批改），上限給足
test.setTimeout(300_000)

// ── 測試用資料 ──────────────────────────────────
const COURSE_TITLE = 'E2E 批改標頭污染課程'
const ASSIGNMENT_TITLE = 'E2E標頭污染作業'
const FEEDBACK_TEXT = 'E2E 標頭污染測試評語：通過'

const STUDENT = {
	username: 'e2e_hdr_pollution_131',
	email: 'e2e_hdr_pollution_131@test.local',
	password: 'e2eHdrPollution131!Pass',
	name: 'E2E標頭污染學員',
}

/** 用來確認全程沒有重新載入頁面的 window 標記 */
const NO_RELOAD_MARKER = '__pcHeaderPollutionMarker'

// ── Helper：學員登入取得 nonce ──
// 用 loginAs()（waitUntil: 'commit'）而不是等落地頁 load：學員登入後落在 /my-account/，
// 是整個 suite 最重的前台頁，等 load 實測 90 秒逾時；登入成功只需要確認已被導離登入頁
async function loginStudentAndGetNonce(
	browser: Browser,
	username: string,
	password: string,
): Promise<{
	context: BrowserContext
	request: APIRequestContext
	nonce: string
}> {
	// baseURL 必須明確指定：loginAs() 用相對路徑 goto，手動 newContext() 不繼承 config 的 use.baseURL
	const context = await browser.newContext({
		baseURL: BASE_URL,
		ignoreHTTPSErrors: true,
	})
	context.setDefaultTimeout(NAV_TIMEOUT)
	context.setDefaultNavigationTimeout(NAV_TIMEOUT)
	const page = await context.newPage()

	await loginAs(page, username, password)

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

/** Helper：切換到指定課程分頁（Tabs 選項文字含「{課程標題} ({count})」） */
async function selectCourseTab(page: Page, courseTitle: string): Promise<void> {
	const tab = page.locator('.ant-tabs-tab').filter({ hasText: courseTitle })
	await expect(
		tab,
		`課程分頁應包含「${courseTitle}」`,
	).toBeVisible({ timeout: 60_000 })
	await tab.click()
}

// ── 共用狀態 ────────────────────────────────────────
let adminApi: ApiClient
let disposeAdmin: () => Promise<void>

let courseId: number
let studentId: number
let studentCtx: BrowserContext
let submissionId: number

test.describe('作業批改不受共用 axios header 污染（Issue #131 W3-AX）', () => {
	test.use({ storageState: '.auth/admin.json' })

	test.beforeAll(async ({ browser }) => {
		test.setTimeout(NAV_TIMEOUT * 2 + 120_000)

		const setup = await setupApiFromBrowser(browser)
		adminApi = setup.api
		disposeAdmin = setup.dispose

		// 1. 課程 + 章節 + 作業
		const created = await adminApi.createCourseWithChapters(
			COURSE_TITLE,
			'0',
			[{ name: 'E2E標頭污染章節', slug: 'e2e-header-pollution-chapter' }],
			'e2e-grading-header-pollution-course',
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
		const assignmentId = (assignResp.data as { id?: number })?.id
		if (assignResp.status >= 400 || !assignmentId) {
			throw new Error(`建立測試作業失敗: ${JSON.stringify(assignResp.data)}`)
		}

		// 2. 學員 + 一年期授權（前置操作會把它改成無限制，值必須真的有變，見檔頭說明）
		studentId = await adminApi.ensureUserViaPowerhouse(
			STUDENT.username,
			STUDENT.email,
			STUDENT.password,
			['subscriber'],
			STUDENT.name,
		)
		await adminApi.grantCourseAccess(
			studentId,
			courseId,
			Math.floor(Date.now() / 1000) + 365 * 24 * 60 * 60,
		)

		// 3. 學員以自己的 nonce 繳交（維持 pending，留給批改測試）
		const student = await loginStudentAndGetNonce(
			browser,
			STUDENT.username,
			STUDENT.password,
		)
		studentCtx = student.context
		const submitResp = await student.request.post(
			`${BASE_URL}/wp-json/power-course/my/assignments/${assignmentId}/submit`,
			{
				headers: {
					'X-WP-Nonce': student.nonce,
					'Content-Type': 'application/json',
				},
				data: { text_body: '標頭污染測試的繳交內容' },
				timeout: 60_000,
			},
		)
		const submitData = (await submitResp.json()) as { id?: number }
		if (submitResp.status() >= 400 || !submitData.id) {
			throw new Error(`學員繳交失敗: ${JSON.stringify(submitData)}`)
		}
		submissionId = submitData.id
	})

	test.afterAll(async () => {
		test.setTimeout(120_000)
		try {
			await adminApi.deleteCourses([courseId])
		} catch {
			/* ignore */
		}
		try {
			await adminApi.deleteUserViaPowerhouse(studentId)
		} catch {
			/* ignore */
		}
		if (studentCtx) await studentCtx.close()
		if (disposeAdmin) await disposeAdmin()
	})

	test('先做過帶 multipart header 的後台操作、不重新載入頁面，批改「通過」仍以 JSON 送出並成功 @regression', async ({
		page,
	}) => {
		// 路由切換若遇到「尚未儲存」確認框就放行（本測試沒有動任何表單，正常不會出現）
		page.on('dialog', (dialog) => {
			console.log(`[W3-AX] 出現對話框並放行：${dialog.message()}`)
			void dialog.accept()
		})

		// ── 1. 前置操作：課程編輯 → 「學員」分頁 → 更新觀看時間限制（走 custom() + multipart header）
		await navigateToAdmin(page, `/courses/edit/${courseId}`)
		await page.waitForSelector('.ant-tabs-tab', { timeout: 60_000 })
		await clickTab(page, '學員')
		await waitForTableLoaded(page)

		await page.evaluate((key) => {
			;(window as unknown as Record<string, unknown>)[key] = 'same-document'
		}, NO_RELOAD_MARKER)

		const studentRow = page
			.locator('.ant-table-tbody tr')
			.filter({ hasText: STUDENT.email })
			.first()
		await expect(studentRow, 'fixture 學員應出現在課程學員列表').toBeVisible({
			timeout: 60_000,
		})
		await studentRow.locator('.ant-table-selection-column .ant-checkbox-input').check()

		const isUpdateStudents = (url: string, method: string) =>
			url.includes('/wp-json/power-course/courses/update-students') &&
			method === 'POST'
		const updateRequestPromise = page.waitForRequest(
			(req) => isUpdateStudents(req.url(), req.method()),
			{ timeout: 60_000 },
		)
		const updateResponsePromise = page.waitForResponse(
			(resp) => isUpdateStudents(resp.url(), resp.request().method()),
			{ timeout: 60_000 },
		)
		await page.getByRole('button', { name: '更新觀看時間限制' }).click()
		const updateRequest = await updateRequestPromise
		const updateResponse = await updateResponsePromise
		const updateContentType =
			(await updateRequest.allHeaders())['content-type'] ?? ''
		const updateEvidence = `Content-Type=${updateContentType}；狀態碼=${updateResponse.status()}；回應=${await updateResponse.text()}`
		console.log(`[W3-AX] 前置操作 POST courses/update-students：${updateEvidence}`)

		// 對照：前置操作必須真的以 multipart 送出，否則後面的綠燈不代表什麼
		expect(
			updateContentType,
			'對照：前置操作（custom() + multipart header）應以 multipart/form-data 送出',
		).toContain('multipart/form-data')
		expect(updateResponse.status(), `前置操作應成功（${updateEvidence}）`).toBe(200)

		// ── 2. 不重新載入頁面，以 HashRouter 切到作業批改頁
		await page.evaluate(() => {
			window.location.hash = '#/assignments'
		})
		await page.waitForSelector('.pc-assignment-status-filter', {
			timeout: 60_000,
		})
		expect(
			await page.evaluate(
				(key) => (window as unknown as Record<string, unknown>)[key],
				NO_RELOAD_MARKER,
			),
			'切到作業批改頁時不應重新載入頁面（window 標記應仍在）',
		).toBe('same-document')

		await selectCourseTab(page, COURSE_TITLE)

		const table = page.locator('.pc-assignment-submission-table')
		await expect(table).toBeVisible({ timeout: 60_000 })
		const row = table.locator('tr').filter({ hasText: STUDENT.email })
		await expect(row, 'fixture 學員的待批改繳交應出現在列表').toBeVisible({
			timeout: 60_000,
		})

		// ── 3. 開詳情 → 批改「通過」+ 評語 → 送出
		await row.getByRole('button', { name: '查看詳情' }).click()
		const detail = page.locator('.pc-assignment-detail')
		await expect(detail).toBeVisible({ timeout: 30_000 })
		const gradeRadio = detail.locator('.pc-assignment-grade-radio')
		await expect(gradeRadio).toBeVisible({ timeout: 30_000 })

		await detail.locator('#feedback').fill(FEEDBACK_TEXT)
		await gradeRadio.getByText('通過', { exact: true }).click()

		const isGrade = (url: string, method: string) =>
			url.includes(
				`/wp-json/power-course/assignment-submissions/${submissionId}/grade`,
			) && method === 'PATCH'
		const gradeRequestPromise = page.waitForRequest(
			(req) => isGrade(req.url(), req.method()),
			{ timeout: 60_000 },
		)
		const gradeResponsePromise = page.waitForResponse(
			(resp) => isGrade(resp.url(), resp.request().method()),
			{ timeout: 60_000 },
		)
		await detail.getByRole('button', { name: '儲存批改結果' }).click()
		const gradeRequest = await gradeRequestPromise
		const gradeResponse = await gradeResponsePromise

		const gradeContentType =
			(await gradeRequest.allHeaders())['content-type'] ?? ''
		const gradeStatus = gradeResponse.status()
		const gradeBodyText = await gradeResponse.text()
		const evidence = `Content-Type=${gradeContentType}；狀態碼=${gradeStatus}；回應=${gradeBodyText}`
		console.log(`[W3-AX] PATCH grade：${evidence}`)

		expect(
			gradeContentType,
			`批改 PATCH 應以 JSON 送出（${evidence}）`,
		).toContain('application/json')
		expect(gradeStatus, `批改 PATCH 應成功（${evidence}）`).toBe(200)
		const graded = JSON.parse(gradeBodyText) as {
			status?: string
			feedback?: string
		}
		expect(graded.status, `批改結果應為 pass（${evidence}）`).toBe('pass')
		expect(graded.feedback, '評語應一併寫入').toBe(FEEDBACK_TEXT)

		// ── 4. 畫面：預設篩選「待批改」會讓這筆離開列表，切到「全部」應顯示「通過」
		await expect(row, '批改通過後應離開「待批改」列表').toHaveCount(0, {
			timeout: 60_000,
		})
		await page.locator('.ant-drawer-close').click()
		await expect(detail).toBeHidden({ timeout: 30_000 })
		await page
			.locator('.pc-assignment-status-filter')
			.getByText('全部', { exact: true })
			.click()
		await expect(row.getByText('通過', { exact: true })).toBeVisible({
			timeout: 60_000,
		})

		// 全程同一份文件：共用 axios 實例的 defaults 一直是前置操作污染過的那份
		expect(
			await page.evaluate(
				(key) => (window as unknown as Record<string, unknown>)[key],
				NO_RELOAD_MARKER,
			),
			'全程不應重新載入頁面',
		).toBe('same-document')
	})
})
