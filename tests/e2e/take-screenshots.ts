/**
 * CI 自動截圖腳本
 *
 * 在 PHPUnit 測試通過後，根據變更分類自動截取對應頁面截圖。
 * 需先跑過 global-setup（透過 smoke test）建立 auth state + 測試資料。
 *
 * 健壯性設計：
 * - 截圖前驗證 admin 登入狀態，失敗時自動重新登入
 * - 單張截圖失敗不會中斷整個流程，會記錄診斷截圖
 * - 至少一張截圖成功即視為整體成功（exit 0）
 *
 * 用法：npx tsx take-screenshots.ts --mode admin|frontend|both
 */
import { chromium, type Page, type BrowserContext } from '@playwright/test'
import { waitForProTableLoaded, waitForFormLoaded } from './helpers/admin-page.js'
import { loadFrontendTestData, loginAs } from './helpers/frontend-setup.js'
import { ApiClient, getNonceFromPage } from './helpers/api-client.js'
import { WP_ADMIN, TEST_SUBSCRIBER, SELECTORS } from './fixtures/test-data.js'
import path from 'path'
import fs from 'fs'
import { fileURLToPath } from 'url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const SCREENSHOT_DIR = path.join(__dirname, 'screenshots')
const STORAGE_STATE = path.join(__dirname, '.auth', 'admin.json')
const BASE_URL = process.env.TEST_SITE_URL || 'http://localhost:8889'

/** Admin SPA 基礎 URL */
const ADMIN_PAGE = '/wp-admin/admin.php?page=power-course'

/** CI 環境下使用更長的 timeout */
const SPA_LOAD_TIMEOUT = process.env.CI ? 30_000 : 15_000

type Mode = 'admin' | 'frontend' | 'both'

let successCount = 0
let failCount = 0

function parseArgs(): Mode {
	const modeIdx = process.argv.indexOf('--mode')
	if (modeIdx === -1 || !process.argv[modeIdx + 1]) {
		console.error('Usage: npx tsx take-screenshots.ts --mode admin|frontend|both')
		process.exit(1)
	}
	const mode = process.argv[modeIdx + 1] as Mode
	if (!['admin', 'frontend', 'both'].includes(mode)) {
		console.error(`Invalid mode: ${mode}. Must be admin, frontend, or both.`)
		process.exit(1)
	}
	return mode
}

async function takeScreenshot(page: Page, name: string): Promise<void> {
	const filePath = path.join(SCREENSHOT_DIR, `${name}.png`)
	// 等待動畫穩定
	await page.waitForTimeout(800)
	await page.screenshot({ path: filePath, fullPage: true })
	console.log(`[Screenshot] ${name}.png saved`)
	successCount++
}

/**
 * 診斷截圖 — 在截圖失敗時記錄頁面實際狀態
 */
async function takeDiagnosticScreenshot(page: Page, name: string): Promise<void> {
	try {
		const filePath = path.join(SCREENSHOT_DIR, `debug-${name}.png`)
		await page.screenshot({ path: filePath, fullPage: true })
		const url = page.url()
		const title = await page.title()
		console.log(`[Diagnostic] debug-${name}.png saved`)
		console.log(`[Diagnostic] URL: ${url}`)
		console.log(`[Diagnostic] Title: ${title}`)
	} catch (e) {
		console.warn(`[Diagnostic] Failed to take diagnostic screenshot: ${e}`)
	}
}

/**
 * 驗證 admin 登入狀態，若 session 無效則重新登入
 *
 * @returns true 表示 admin session 有效
 */
async function ensureAdminLogin(context: BrowserContext): Promise<boolean> {
	const page = await context.newPage()
	try {
		console.log('[Auth] Verifying admin session...')
		await page.goto(`${BASE_URL}/wp-admin/`, {
			waitUntil: 'domcontentloaded',
			timeout: 30_000,
		})

		const url = page.url()
		console.log(`[Auth] After navigation URL: ${url}`)

		// 檢查是否在 admin dashboard（body 有 .wp-admin class）
		const isAdmin = await page
			.locator('body.wp-admin')
			.isAttached({ timeout: 5_000 })
			.catch(() => false)

		if (isAdmin) {
			console.log('[Auth] Admin session valid.')
			return true
		}

		// Session 無效（可能被重導到 login page），嘗試重新登入
		console.warn('[Auth] Session invalid (possibly redirected to login), attempting re-login...')
		await page.goto(`${BASE_URL}/wp-login.php`, {
			waitUntil: 'domcontentloaded',
			timeout: 30_000,
		})

		await page.fill('#user_login', WP_ADMIN.username)
		await page.fill('#user_pass', WP_ADMIN.password)
		await page.locator('#wp-submit').click()
		await page.waitForTimeout(3_000)

		await page.goto(`${BASE_URL}/wp-admin/`, {
			waitUntil: 'domcontentloaded',
			timeout: 30_000,
		})

		const isAdminNow = await page
			.locator('body.wp-admin')
			.isAttached({ timeout: 5_000 })
			.catch(() => false)

		if (isAdminNow) {
			console.log('[Auth] Re-login successful, saving updated storage state...')
			await context.storageState({ path: STORAGE_STATE })
			return true
		}

		console.error('[Auth] Re-login failed. WordPress admin is inaccessible.')
		await takeDiagnosticScreenshot(page, 'auth-failed')
		return false
	} finally {
		await page.close()
	}
}

/**
 * 導航到 Admin SPA 指定路由（截圖專用，含登入重導檢測與重試）
 */
async function navigateToAdminSafe(page: Page, hash: string): Promise<void> {
	const url = `${ADMIN_PAGE}#${hash}`

	await page.goto(url, {
		waitUntil: 'domcontentloaded',
		timeout: 30_000,
	})

	// 檢查是否被重導到登入頁
	const currentUrl = page.url()
	if (currentUrl.includes('wp-login.php')) {
		throw new Error(
			`Redirected to login page (${currentUrl}). Admin session may have expired.`,
		)
	}

	// 等待 React SPA 根節點掛載
	await page.waitForSelector('#power_course', {
		state: 'attached',
		timeout: SPA_LOAD_TIMEOUT,
	})

	// 等待 Ant Design Spin 消失（SPA 資料載入完畢）
	await page.waitForFunction(
		() => {
			const spinners = document.querySelectorAll('.ant-spin-spinning')
			return spinners.length === 0
		},
		{ timeout: SPA_LOAD_TIMEOUT },
	)
}

async function takeAdminScreenshots(context: BrowserContext): Promise<void> {
	const page = await context.newPage()

	try {
		// 課程列表
		try {
			console.log('[Admin] Taking course list screenshot...')
			await navigateToAdminSafe(page, '/courses')
			await waitForProTableLoaded(page)
			await takeScreenshot(page, 'course-list')
		} catch (e) {
			console.error(`[Admin] Course list screenshot failed: ${e}`)
			await takeDiagnosticScreenshot(page, 'course-list-failed')
			failCount++
		}

		// 設定頁面
		try {
			console.log('[Admin] Taking settings screenshot...')
			await navigateToAdminSafe(page, '/settings')
			await waitForFormLoaded(page)
			await takeScreenshot(page, 'settings')
		} catch (e) {
			console.error(`[Admin] Settings screenshot failed: ${e}`)
			await takeDiagnosticScreenshot(page, 'settings-failed')
			failCount++
		}
	} finally {
		await page.close()
	}
}

async function takeFrontendScreenshots(context: BrowserContext): Promise<void> {
	// 載入前台測試資料（由 global-setup 建立）
	let testData
	try {
		testData = loadFrontendTestData()
	} catch {
		console.warn('[Frontend] No frontend test data found, skipping frontend screenshots.')
		return
	}

	// 建立 admin context 取得 API client，授權 subscriber 存取課程
	const adminPage = await context.newPage()
	try {
		await adminPage.goto(`${BASE_URL}/wp-admin/`, {
			waitUntil: 'domcontentloaded',
			timeout: 30_000,
		})
		await adminPage.waitForFunction(
			() => !!(window as any).wpApiSettings?.nonce,
			{ timeout: 30_000 },
		)
		const nonce = await getNonceFromPage(adminPage)
		const api = new ApiClient(context.request, nonce)

		// 確保 subscriber 有課程存取權
		console.log('[Frontend] Granting course access to subscriber...')
		await api.grantCourseAccess(testData.subscriberId, testData.courseId)
	} finally {
		await adminPage.close()
	}

	// 以 subscriber 身分瀏覽前台
	const frontendPage = await context.newPage()
	try {
		// 登入 subscriber
		console.log('[Frontend] Logging in as subscriber...')
		await loginAs(frontendPage, TEST_SUBSCRIBER.username, TEST_SUBSCRIBER.password)

		// 課程銷售頁
		try {
			console.log('[Frontend] Taking course product page screenshot...')
			await frontendPage.goto(testData.courseUrl, {
				waitUntil: 'domcontentloaded',
				timeout: 30_000,
			})
			await frontendPage
				.waitForSelector(SELECTORS.courseProduct.featureContent, {
					timeout: 15_000,
				})
				.catch(() => {
					// 銷售頁元素可能不存在（已授權的課程），繼續截圖
				})
			await takeScreenshot(frontendPage, 'course-product')
		} catch (e) {
			console.error(`[Frontend] Course product screenshot failed: ${e}`)
			await takeDiagnosticScreenshot(frontendPage, 'course-product-failed')
			failCount++
		}

		// 教室頁面
		if (testData.chapterSlugs.length > 0) {
			try {
				console.log('[Frontend] Taking classroom screenshot...')
				const classroomUrl = `/classroom/${testData.chapterSlugs[0]}`
				await frontendPage.goto(`${BASE_URL}${classroomUrl}`, {
					waitUntil: 'domcontentloaded',
					timeout: 30_000,
				})
				await frontendPage
					.waitForSelector(SELECTORS.classroom.body, {
						timeout: 15_000,
					})
					.catch(() => {
						// 教室頁面可能需要額外時間載入
					})
				await takeScreenshot(frontendPage, 'classroom')
			} catch (e) {
				console.error(`[Frontend] Classroom screenshot failed: ${e}`)
				await takeDiagnosticScreenshot(frontendPage, 'classroom-failed')
				failCount++
			}
		}
	} finally {
		await frontendPage.close()
	}
}

async function main(): Promise<void> {
	const mode = parseArgs()
	console.log(`[Screenshot] Mode: ${mode}`)
	console.log(`[Screenshot] Base URL: ${BASE_URL}`)
	console.log(`[Screenshot] CI: ${process.env.CI || 'false'}`)
	console.log(`[Screenshot] SPA timeout: ${SPA_LOAD_TIMEOUT}ms`)

	// 確保截圖目錄存在
	if (!fs.existsSync(SCREENSHOT_DIR)) {
		fs.mkdirSync(SCREENSHOT_DIR, { recursive: true })
	}

	// 確認 auth state 存在（由 global-setup 建立）
	if (!fs.existsSync(STORAGE_STATE)) {
		console.warn(
			'[Screenshot] Admin storage state not found. Will attempt fresh login.',
		)
	}

	const browser = await chromium.launch({ headless: true })

	try {
		// 建立 browser context（帶 admin storageState，若檔案存在）
		const contextOptions: Parameters<typeof browser.newContext>[0] = {
			baseURL: BASE_URL,
			viewport: { width: 1920, height: 1080 },
			ignoreHTTPSErrors: true,
			locale: 'zh-TW',
			timezoneId: 'Asia/Taipei',
		}
		if (fs.existsSync(STORAGE_STATE)) {
			contextOptions.storageState = STORAGE_STATE
		}
		const context = await browser.newContext(contextOptions)

		// 驗證 admin 登入狀態（失敗時自動重新登入）
		const isLoggedIn = await ensureAdminLogin(context)
		if (!isLoggedIn) {
			console.error(
				'[Screenshot] Cannot establish admin session. Aborting.',
			)
			// 即使登入失敗，也不用 exit(1)——diagnostics 截圖已在 screenshots/ 裡
			// 如果有 diagnostic 截圖就算部分成功
			const diagShots = fs
				.readdirSync(SCREENSHOT_DIR)
				.filter((f) => f.endsWith('.png'))
			if (diagShots.length > 0) {
				console.log(
					`[Screenshot] ${diagShots.length} diagnostic screenshots saved.`,
				)
				process.exit(0)
			}
			process.exit(1)
		}

		if (mode === 'admin' || mode === 'both') {
			try {
				await takeAdminScreenshots(context)
			} catch (e) {
				console.error(`[Screenshot] Admin screenshots group failed: ${e}`)
				failCount++
			}
		}

		if (mode === 'frontend' || mode === 'both') {
			try {
				await takeFrontendScreenshots(context)
			} catch (e) {
				console.error(`[Screenshot] Frontend screenshots group failed: ${e}`)
				failCount++
			}
		}

		await context.close()
	} finally {
		await browser.close()
	}

	// 列出產出的截圖（含診斷截圖）
	const screenshots = fs
		.readdirSync(SCREENSHOT_DIR)
		.filter((f) => f.endsWith('.png'))
	const normalShots = screenshots.filter((f) => !f.startsWith('debug-'))
	const debugShots = screenshots.filter((f) => f.startsWith('debug-'))

	console.log(`\n[Screenshot] Done!`)
	console.log(
		`[Screenshot] ${successCount} succeeded, ${failCount} failed.`,
	)
	if (normalShots.length > 0) {
		console.log(`[Screenshot] Screenshots:`)
		normalShots.forEach((f) => console.log(`  - ${f}`))
	}
	if (debugShots.length > 0) {
		console.log(`[Screenshot] Diagnostic screenshots:`)
		debugShots.forEach((f) => console.log(`  - ${f}`))
	}

	// 至少有一張正常截圖就算成功
	if (successCount === 0) {
		console.error(
			`[Screenshot] No screenshots were taken successfully.`,
		)
		// 如果有 diagnostic 截圖就 exit 0（讓 workflow 上傳 diagnostic 供分析）
		if (debugShots.length > 0) {
			console.log(
				`[Screenshot] Exiting with 0 to allow diagnostic upload.`,
			)
			process.exit(0)
		}
		process.exit(1)
	}
}

main().catch((error) => {
	console.error('[Screenshot] Fatal error:', error)
	process.exit(1)
})
