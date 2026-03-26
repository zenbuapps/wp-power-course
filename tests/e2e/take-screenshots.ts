/**
 * CI 自動截圖腳本
 *
 * 在 PHPUnit 測試通過後，根據變更分類自動截取對應頁面截圖。
 * 需先跑過 global-setup（透過 smoke test）建立 auth state + 測試資料。
 *
 * 用法：npx tsx take-screenshots.ts --mode admin|frontend|both
 */
import { chromium, type Page, type BrowserContext } from '@playwright/test'
import { navigateToAdmin, waitForProTableLoaded, waitForFormLoaded } from './helpers/admin-page.js'
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

type Mode = 'admin' | 'frontend' | 'both'

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
}

async function takeAdminScreenshots(context: BrowserContext): Promise<void> {
	const page = await context.newPage()

	try {
		// 課程列表
		console.log('[Admin] Taking course list screenshot...')
		await navigateToAdmin(page, '/courses')
		await waitForProTableLoaded(page)
		await takeScreenshot(page, 'course-list')

		// 設定頁面
		console.log('[Admin] Taking settings screenshot...')
		await navigateToAdmin(page, '/settings')
		await waitForFormLoaded(page)
		await takeScreenshot(page, 'settings')
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
		console.log('[Frontend] Taking course product page screenshot...')
		await frontendPage.goto(testData.courseUrl, {
			waitUntil: 'domcontentloaded',
			timeout: 30_000,
		})
		await frontendPage.waitForSelector(SELECTORS.courseProduct.featureContent, {
			timeout: 15_000,
		}).catch(() => {
			// 銷售頁元素可能不存在（已授權的課程），繼續截圖
		})
		await takeScreenshot(frontendPage, 'course-product')

		// 教室頁面
		if (testData.chapterSlugs.length > 0) {
			console.log('[Frontend] Taking classroom screenshot...')
			const classroomUrl = `/classroom/${testData.chapterSlugs[0]}`
			await frontendPage.goto(`${BASE_URL}${classroomUrl}`, {
				waitUntil: 'domcontentloaded',
				timeout: 30_000,
			})
			await frontendPage.waitForSelector(SELECTORS.classroom.body, {
				timeout: 15_000,
			}).catch(() => {
				// 教室頁面可能需要額外時間載入
			})
			await takeScreenshot(frontendPage, 'classroom')
		}
	} finally {
		await frontendPage.close()
	}
}

async function main(): Promise<void> {
	const mode = parseArgs()
	console.log(`[Screenshot] Mode: ${mode}`)
	console.log(`[Screenshot] Base URL: ${BASE_URL}`)

	// 確保截圖目錄存在
	if (!fs.existsSync(SCREENSHOT_DIR)) {
		fs.mkdirSync(SCREENSHOT_DIR, { recursive: true })
	}

	// 確認 auth state 存在
	if (!fs.existsSync(STORAGE_STATE)) {
		console.error('[Screenshot] Admin storage state not found. Run global-setup first (via smoke test).')
		process.exit(1)
	}

	const browser = await chromium.launch({ headless: true })

	try {
		// 使用 admin storageState 建立 context
		const context = await browser.newContext({
			storageState: STORAGE_STATE,
			viewport: { width: 1920, height: 1080 },
			ignoreHTTPSErrors: true,
			locale: 'zh-TW',
			timezoneId: 'Asia/Taipei',
		})

		if (mode === 'admin' || mode === 'both') {
			await takeAdminScreenshots(context)
		}

		if (mode === 'frontend' || mode === 'both') {
			await takeFrontendScreenshots(context)
		}

		await context.close()
	} finally {
		await browser.close()
	}

	// 列出產出的截圖
	const screenshots = fs.readdirSync(SCREENSHOT_DIR).filter(f => f.endsWith('.png'))
	console.log(`\n[Screenshot] Done! ${screenshots.length} screenshots saved:`)
	screenshots.forEach(f => console.log(`  - ${f}`))
}

main().catch((error) => {
	console.error('[Screenshot] Fatal error:', error)
	process.exit(1)
})
