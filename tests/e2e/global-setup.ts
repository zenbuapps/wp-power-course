/**
 * Playwright Global Setup
 *
 * 測試開始前執行：
 * 1. 套用 LC bypass（注入 'lc' => false 到 plugin.php）
 * 2. 登入 WordPress Admin
 * 3. 儲存認證狀態供後續測試使用
 * 4. 透過 REST API 停用 Coming Soon、切換 Classic Checkout、清除舊資料
 * 5. 建立前台測試共用資料（課程、章節、訂閱者、BACS）
 */
import { chromium, type FullConfig } from '@playwright/test'
import { applyLcBypass } from './helpers/lc-bypass'
import { ApiClient, getNonceFromPage } from './helpers/api-client'
import { ensureFrontendTestData, clearFrontendTestDataCache } from './helpers/frontend-setup'
import { WP_ADMIN } from './fixtures/test-data'
import path from 'path'
import { fileURLToPath } from 'url'
import fs from 'fs'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const STORAGE_STATE_PATH = path.join(__dirname, '.auth', 'admin.json')

async function globalSetup(config: FullConfig): Promise<void> {
	const baseURL =
		config.projects[0]?.use?.baseURL || 'http://localhost:8889'

	// 1. 套用 LC bypass
	console.log('[Global Setup] Applying LC bypass...')
	applyLcBypass()

	// 2. 確保 .auth 目錄存在
	const authDir = path.dirname(STORAGE_STATE_PATH)
	if (!fs.existsSync(authDir)) {
		fs.mkdirSync(authDir, { recursive: true })
	}

	// 3. 登入 WordPress Admin 並儲存 storageState
	console.log('[Global Setup] Logging in to WordPress Admin...')
	const browser = await chromium.launch()
	const context = await browser.newContext({ ignoreHTTPSErrors: true })
	const page = await context.newPage()

	try {
		await page.goto(`${baseURL}/wp-login.php`, {
			waitUntil: 'domcontentloaded',
			timeout: 30_000,
		})

		await page.fill('#user_login', WP_ADMIN.username)
		await page.fill('#user_pass', WP_ADMIN.password)

		// 送出表單後直接導航到 wp-admin（避免 waitForNavigation race condition）
		await page.locator('#wp-submit').click()
		await page.waitForTimeout(2_000)
		await page.goto(`${baseURL}/wp-admin/`, {
			waitUntil: 'domcontentloaded',
			timeout: 60_000,
		})

		console.log('[Global Setup] Login successful, saving storage state...')
		await context.storageState({ path: STORAGE_STATE_PATH })

		// 4. 取得 nonce（直接從登入後的 dashboard 頁面取，避免二次導航問題）
		const nonce = await getNonceFromPage(page)
		console.log('[Global Setup] Nonce acquired.')

		// 3.5 刷新 WordPress 永久連結（透過 REST API 觸發 flush_rewrite_rules）
		console.log('[Global Setup] Flushing rewrite rules via REST API...')
		try {
			await context.request.post(`${baseURL}/wp-json/wp/v2/settings`, {
				headers: { 'X-WP-Nonce': nonce },
				data: { permalink_structure: '/%postname%/' },
			})
			console.log('[Global Setup] Rewrite rules flushed.')
		} catch (e) {
			console.warn('[Global Setup] Flush rewrite rules warning (non-fatal):', e)
		}
		const api = new ApiClient(context.request, nonce)

		// 4.1 停用 WooCommerce "Coming Soon" 模式
		console.log('[Global Setup] Disabling WooCommerce Coming Soon mode via REST API...')
		try {
			await context.request.post(`${baseURL}/wp-json/wp/v2/settings`, {
				headers: { 'X-WP-Nonce': nonce },
				data: { woocommerce_coming_soon: 'no' },
			})
		} catch (e) {
			console.warn('[Global Setup] Coming Soon disable (non-fatal):', e)
		}

		// 4.2 強制 WooCommerce 使用 Classic Checkout（WC 9.x 預設 Block Checkout）
		console.log('[Global Setup] Switching to Classic Checkout via REST API...')
		try {
			// 找到 checkout 頁面 ID
			const pagesResp = await context.request.get(
				`${baseURL}/wp-json/wp/v2/pages?slug=checkout&per_page=1`,
				{ headers: { 'X-WP-Nonce': nonce } },
			)
			const pages = await pagesResp.json()
			if (Array.isArray(pages) && pages.length > 0) {
				const checkoutPageId = pages[0].id
				await context.request.post(
					`${baseURL}/wp-json/wp/v2/pages/${checkoutPageId}`,
					{
						headers: { 'X-WP-Nonce': nonce },
						data: {
							content: '<!-- wp:shortcode -->[woocommerce_checkout]<!-- /wp:shortcode -->',
						},
					},
				)
				console.log(`[Global Setup] Checkout page #${checkoutPageId} switched to classic shortcode.`)
			} else {
				console.warn('[Global Setup] Checkout page not found by slug.')
			}
		} catch (e) {
			console.warn('[Global Setup] Classic checkout switch (non-fatal):', e)
		}

		// 4.3 清除舊 E2E 測試資料，避免重複 slug 造成 404
		console.log('[Global Setup] Cleaning old E2E test data via REST API...')
		try {
			const coursesResp = await api.pcGet<{ id: number; name: string }[]>('courses', {
				per_page: '100',
			})
			if (coursesResp.status === 200 && Array.isArray(coursesResp.data)) {
				const e2eCourseIds = coursesResp.data
					.filter((c) => c.name?.toLowerCase().startsWith('e2e'))
					.map((c) => c.id)
				if (e2eCourseIds.length > 0) {
					console.log(`[Global Setup] Deleting ${e2eCourseIds.length} old E2E courses...`)
					await api.deleteCourses(e2eCourseIds)
				}
			}
		} catch (e) {
			console.warn('[Global Setup] Cleanup warning (non-fatal):', e)
		}

		// 4.4 清除舊 pc_chapter posts（含已刪除的），避免 slug 衝突與 Fatal Error
		console.log('[Global Setup] Cleaning old pc_chapter posts via WP REST API...')
		try {
			for (const status of ['publish', 'draft', 'trash', 'pending', 'private']) {
				const chapResp = await api.wpGet<{ id: number; title: { rendered: string } }[]>(
					'pc_chapter',
					{ per_page: '100', status },
				)
				if (chapResp.status === 200 && Array.isArray(chapResp.data) && chapResp.data.length > 0) {
					console.log(`[Global Setup] Found ${chapResp.data.length} pc_chapter posts (status=${status}), force-deleting...`)
					for (const ch of chapResp.data) {
						try {
							await api.wpDelete(`pc_chapter/${ch.id}`, { force: 'true' })
						} catch {
							// ignore individual delete failures
						}
					}
				}
			}
			console.log('[Global Setup] pc_chapter cleanup done.')
		} catch (e) {
			console.warn('[Global Setup] Chapter cleanup warning (non-fatal):', e)
		}

		// 5. 建立前台測試共用資料（課程、章節、訂閱者帳號、BACS 付款）
		console.log('[Global Setup] Ensuring frontend test data...')
		clearFrontendTestDataCache()
		try {
			await ensureFrontendTestData(api)
			console.log('[Global Setup] Frontend test data ready.')
		} catch (e) {
			console.warn('[Global Setup] Frontend test data setup warning (non-fatal):', e)
		}
	} catch (error) {
		console.error('[Global Setup] Failed:', error)
		throw error
	} finally {
		await browser.close()
	}

	console.log('[Global Setup] Complete.')
}

export default globalSetup
