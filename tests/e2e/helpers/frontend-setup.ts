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
	const subscriberId = await api.ensureUser(
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
	// 等待離開登入頁面（管理員→wp-admin, 訂閱者→首頁或 profile）
	await page.waitForURL((url) => !url.pathname.includes('wp-login'), {
		timeout: 30_000,
	})
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
