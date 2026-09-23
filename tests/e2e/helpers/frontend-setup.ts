/**
 * 前台測試共用 Setup/Teardown
 *
 * 提供冪等的測試資料建立與快取機制，
 * 避免每個 spec 重複建立課程、章節、使用者。
 */

import fs from 'fs'
import path from 'path'
import { fileURLToPath } from 'url'
import { Page } from '@playwright/test'
import { ApiClient, setupApiFromBrowser } from './api-client.js'
import { FRONTEND_COURSE, TEST_SUBSCRIBER } from '../fixtures/test-data.js'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const CACHE_FILE = path.resolve(__dirname, '..', '.frontend-test-data.json')

export interface FrontendTestData {
	courseId: number
	courseSlug: string
	chapterIds: number[]
	courseUrl: string
	chapterSlugs: string[]
	subscriberId: number
}

/**
 * 冪等建立前台測試所需的共用資料。
 * 使用 .frontend-test-data.json 快取，避免重複建立。
 */
export async function ensureFrontendTestData(
	api: ApiClient,
): Promise<FrontendTestData> {
	// 嘗試讀取快取
	if (fs.existsSync(CACHE_FILE)) {
		try {
			const cached = JSON.parse(
				fs.readFileSync(CACHE_FILE, 'utf-8'),
			) as FrontendTestData
			// 驗證課程仍然存在
			const resp = await api.pcGet<{ id: number }>(`courses/${cached.courseId}`)
			if (resp.status === 200) {
				return cached
			}
		} catch {
			// 快取無效，重新建立
		}
	}

	// 1. 建立課程 + 章節（含明確 slug 避免中文 URL 問題）
	const { courseId, chapterIds, courseSlug } = await api.createCourseWithChapters(
		FRONTEND_COURSE.name,
		FRONTEND_COURSE.regularPrice,
		FRONTEND_COURSE.chapters,
		FRONTEND_COURSE.slug,
	)

	// 2. 取得課程前台 URL
	const courseUrl = await api.getCourseUrl(courseId)

	// 3. 建立訂閱者帳號
	// 走 Powerhouse 而非 wp/v2/users：本站台沒有註冊 /wp/v2/users 路由，
	// ensureUser() 會拿到 rest_no_route 404 並拋出，導致下面的 BACS 啟用與
	// 快取寫入整段被跳過——而 globalSetup 把它當 non-fatal 吞掉，於是
	// 「測試訂閱者不存在」這件事不會有人發現，只會看到前台測試莫名其妙登入失敗。
	const subscriberId = await api.ensureUserViaPowerhouse(
		TEST_SUBSCRIBER.username,
		TEST_SUBSCRIBER.email,
		TEST_SUBSCRIBER.password,
		['subscriber'],
	)

	// 4. 啟用 BACS 付款
	await api.enableBacsPayment()

	const data: FrontendTestData = {
		courseId,
		courseSlug,
		chapterIds,
		courseUrl,
		chapterSlugs: FRONTEND_COURSE.chapters.map((ch) => ch.slug),
		subscriberId,
	}

	// 寫入快取
	fs.writeFileSync(CACHE_FILE, JSON.stringify(data, null, 2))
	return data
}

/**
 * 以指定使用者登入 WordPress 前台
 */
export async function loginAs(
	page: Page,
	username: string,
	password: string,
): Promise<void> {
	// 先清除現有 cookies，避免已登入其他帳號時重導到 wp-admin
	await page.context().clearCookies()
	await page.goto('/wp-login.php')
	await page.locator('#user_login').fill(username)
	await page.locator('#user_pass').fill(password)
	await page.locator('#wp-submit').click({ noWaitAfter: true, timeout: 15_000 })

	// 等待離開登入流程（管理員→wp-admin, 訂閱者→首頁或 profile）。
	//
	// 陷阱：WordPress 在登入成功後可能插入「確認網站管理員電子郵件地址」攔截頁
	// （wp-login.php?action=confirm_admin_email），它的 pathname **仍然是**
	// /wp-login.php。只用 pathname 判斷會永遠等不到，30 秒後 timeout，
	// 看起來像「帳密錯誤登入失敗」——實際上登入早就成功了（#login_error 數量為 0）。
	const isConfirmAdminEmail = (url: URL) =>
		'confirm_admin_email' === url.searchParams.get('action')

	// waitUntil 必須明寫 domcontentloaded：預設是 'load'，而前台頁面（/my-account/
	// 等）常有遲遲不完成的資源，load 事件 30 秒內不會觸發。實測 log：
	//   navigated to "https://.../my-account/" → "domcontentloaded" event fired → 逾時
	// 也就是導頁其實早就成功了，卡住的是「等所有資源載完」這個與斷言無關的條件。
	await page.waitForURL(
		(url) => !url.pathname.includes('wp-login') || isConfirmAdminEmail(url),
		{ timeout: 30_000, waitUntil: 'domcontentloaded' },
	)

	// 攔截頁：按「電子郵件地址正確無誤」放行。
	//
	// 選用這顆而不是「稍後提醒我」：前者會把確認時間寫進 admin_email_lifespan，
	// 攔截頁半年內不再出現；後者只延後三天，等於讓同一個坑週期性復發。
	// 實測 markup（zh_TW）：
	//   <input type="submit" name="correct-admin-email" id="correct-admin-email"
	//          class="button button-primary button-large" value="電子郵件地址正確無誤">
	// 次要動作「稍後提醒我」是 .admin-email__actions-secondary 內的 <a>，作為 fallback。
	if (isConfirmAdminEmail(new URL(page.url()))) {
		const confirmBtn = page.locator('#correct-admin-email')
		const laterLink = page.locator('.admin-email__actions-secondary a')
		const target = (await confirmBtn.count()) > 0 ? confirmBtn : laterLink

		await target.click({ noWaitAfter: true, timeout: 15_000 })
		await page.waitForURL((url) => !url.pathname.includes('wp-login'), {
			timeout: 30_000,
			waitUntil: 'domcontentloaded',
		})
	}
}

/**
 * 以管理員身分登入（從 storageState 還原）
 */
export async function loginAsAdmin(page: Page): Promise<void> {
	const authFile = path.resolve(__dirname, '..', '.auth', 'admin.json')
	if (!fs.existsSync(authFile)) {
		throw new Error('admin storageState not found — run global-setup first')
	}
	await page.context().addCookies(
		JSON.parse(fs.readFileSync(authFile, 'utf-8')).cookies,
	)
}

/**
 * 同步讀取前台測試資料快取。
 * 供 spec beforeAll 使用 — 資料由 global-setup 預先建立。
 */
export function loadFrontendTestData(): FrontendTestData {
	if (!fs.existsSync(CACHE_FILE)) {
		throw new Error(
			'Frontend test data cache not found. Ensure global-setup ran successfully.',
		)
	}
	return JSON.parse(fs.readFileSync(CACHE_FILE, 'utf-8')) as FrontendTestData
}

/**
 * 清除前台測試資料快取
 */
export function clearFrontendTestDataCache(): void {
	if (fs.existsSync(CACHE_FILE)) {
		fs.unlinkSync(CACHE_FILE)
	}
}
