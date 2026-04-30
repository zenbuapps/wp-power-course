/**
 * REST API Client Helper
 *
 * 提供 Power Course / WooCommerce REST API 的簡便呼叫方式。
 * 使用 Playwright 的 request context 發送請求，自動帶上 nonce。
 */
import { type APIRequestContext } from '@playwright/test'

const BASE_URL = process.env.TEST_SITE_URL || 'http://localhost:8889'

/**
 * 從可能包含 PHP warnings/notices 的文字中萃取 JSON
 *
 * PHP 在 WP_DEBUG=true 時可能在 JSON 前輸出 Warning / Notice / Deprecated 等訊息，
 * 導致 JSON.parse() 失敗。此函式找到第一個 `{` 或 `[`，並往後匹配到對應的結尾。
 */
function extractJson(text: string): string {
	const start = text.search(/[\[{]/)
	if (start <= 0) return text // 沒有前綴或就是 JSON
	// 從第一個 JSON 起始字元開始取
	return text.slice(start)
}

interface ApiResponse<T = unknown> {
	status: number
	data: T
	headers: Record<string, string>
}

/**
 * 建立已認證的 API Client
 *
 * @param request - Playwright APIRequestContext
 * @param nonce - WP REST nonce（從 wpApiSettings 取得）
 */
export class ApiClient {
	constructor(
		private request: APIRequestContext,
		private nonce: string = '',
	) {}

	private headers() {
		const h: Record<string, string> = {
			'Content-Type': 'application/json',
		}
		if (this.nonce) {
			h['X-WP-Nonce'] = this.nonce
		}
		return h
	}

	/**
	 * Power Course API — GET
	 */
	async pcGet<T = unknown>(
		endpoint: string,
		params?: Record<string, string>,
	): Promise<ApiResponse<T>> {
		const url = new URL(`${BASE_URL}/wp-json/power-course/${endpoint}`)
		if (params) {
			Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v))
		}
		const resp = await this.request.get(url.toString(), {
			headers: this.headers(),
			timeout: 60_000,
		})
		return {
			status: resp.status(),
			data: (await resp.json()) as T,
			headers: resp.headers(),
		}
	}

	/**
	 * Power Course API — POST (JSON)
	 */
	async pcPost<T = unknown>(
		endpoint: string,
		body?: unknown,
	): Promise<ApiResponse<T>> {
		const resp = await this.request.post(
			`${BASE_URL}/wp-json/power-course/${endpoint}`,
			{
				headers: this.headers(),
				data: body,
			},
		)
		return {
			status: resp.status(),
			data: (await resp.json()) as T,
			headers: resp.headers(),
		}
	}

	/**
	 * Power Course API — POST (form-encoded)
	 *
	 * PHP 端使用 $request->get_body_params() 讀取，
	 * 必須以 application/x-www-form-urlencoded 格式送出。
	 */
	async pcPostForm<T = unknown>(
		endpoint: string,
		formData: Record<string, unknown>,
	): Promise<ApiResponse<T>> {
		// 使用 URLSearchParams 正確處理陣列（PHP 需要 key[] 格式）
		const params = new URLSearchParams()
		const appendNested = (prefix: string, value: unknown): void => {
			if (Array.isArray(value)) {
				value.forEach((item, i) => {
					appendNested(`${prefix}[${i}]`, item)
				})
			} else if (
				value !== null &&
				typeof value === 'object'
			) {
				for (const [k, v] of Object.entries(value as Record<string, unknown>)) {
					appendNested(`${prefix}[${k}]`, v)
				}
			} else if (value !== undefined && value !== null) {
				params.append(prefix, String(value))
			}
		}
		for (const [key, value] of Object.entries(formData)) {
			if (Array.isArray(value)) {
				// 物件陣列：以 key[i][prop]=val 格式遞迴展開
				const hasObject = value.some(
					(v) => v !== null && typeof v === 'object',
				)
				if (hasObject) {
					value.forEach((item, i) => {
						appendNested(`${key}[${i}]`, item)
					})
				} else {
					for (const item of value) {
						params.append(`${key}[]`, String(item))
					}
				}
			} else if (value !== undefined && value !== null) {
				params.append(key, String(value))
			}
		}

		const h: Record<string, string> = {
			'Content-Type': 'application/x-www-form-urlencoded',
		}
		if (this.nonce) h['X-WP-Nonce'] = this.nonce

		const resp = await this.request.post(
			`${BASE_URL}/wp-json/power-course/${endpoint}`,
			{ headers: h, data: params.toString(), timeout: 60_000 },
		)
		const text = await resp.text()
		let data: T
		try {
			data = JSON.parse(extractJson(text)) as T
		} catch {
			throw new Error(
				`pcPostForm ${endpoint} returned non-JSON (status ${resp.status()}): ${text.slice(0, 500)}`,
			)
		}
		return {
			status: resp.status(),
			data,
			headers: resp.headers(),
		}
	}

	/**
	 * Power Course API — DELETE
	 */
	async pcDelete<T = unknown>(
		endpoint: string,
		body?: unknown,
	): Promise<ApiResponse<T>> {
		const resp = await this.request.delete(
			`${BASE_URL}/wp-json/power-course/${endpoint}`,
			{
				headers: this.headers(),
				data: body,
			},
		)
		return {
			status: resp.status(),
			data: (await resp.json()) as T,
			headers: resp.headers(),
		}
	}

	/**
	 * WooCommerce REST API (v3)
	 */
	async wcGet<T = unknown>(
		endpoint: string,
		params?: Record<string, string>,
	): Promise<ApiResponse<T>> {
		const url = new URL(`${BASE_URL}/wp-json/wc/v3/${endpoint}`)
		if (params) {
			Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v))
		}
		const resp = await this.request.get(url.toString(), {
			headers: this.headers(),
			timeout: 60_000,
		})
		return {
			status: resp.status(),
			data: (await resp.json()) as T,
			headers: resp.headers(),
		}
	}

	/**
	 * WooCommerce REST API (v3) — POST
	 */
	async wcPost<T = unknown>(
		endpoint: string,
		body?: unknown,
	): Promise<ApiResponse<T>> {
		const resp = await this.request.post(
			`${BASE_URL}/wp-json/wc/v3/${endpoint}`,
			{
				headers: this.headers(),
				data: body,
			},
		)
		return {
			status: resp.status(),
			data: (await resp.json()) as T,
			headers: resp.headers(),
		}
	}

	/**
	 * WordPress REST API (v2)
	 */
	async wpGet<T = unknown>(
		endpoint: string,
		params?: Record<string, string>,
	): Promise<ApiResponse<T>> {
		const url = new URL(`${BASE_URL}/wp-json/wp/v2/${endpoint}`)
		if (params) {
			Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v))
		}
		const resp = await this.request.get(url.toString(), {
			headers: this.headers(),
			timeout: 60_000,
		})
		return {
			status: resp.status(),
			data: (await resp.json()) as T,
			headers: resp.headers(),
		}
	}

	/**
	 * WordPress REST API (v2) — POST
	 */
	async wpPost<T = unknown>(
		endpoint: string,
		body?: unknown,
	): Promise<ApiResponse<T>> {
		const resp = await this.request.post(
			`${BASE_URL}/wp-json/wp/v2/${endpoint}`,
			{
				headers: this.headers(),
				data: body,
			},
		)
		return {
			status: resp.status(),
			data: (await resp.json()) as T,
			headers: resp.headers(),
		}
	}

	/**
	 * WordPress REST API (v2) — DELETE
	 */
	async wpDelete<T = unknown>(
		endpoint: string,
		params?: Record<string, string>,
	): Promise<ApiResponse<T>> {
		const url = new URL(`${BASE_URL}/wp-json/wp/v2/${endpoint}`)
		if (params) {
			Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v))
		}
		const resp = await this.request.delete(url.toString(), {
			headers: this.headers(),
		})
		return {
			status: resp.status(),
			data: (await resp.json()) as T,
			headers: resp.headers(),
		}
	}

	// ── 便利方法 ──────────────────────────────────

	/**
	 * 建立測試課程
	 *
	 * Power Course API 回傳格式: { code, message, data: { id: "string" } }
	 * 此方法正確解析並回傳數字 ID。
	 */
	async createCourse(name: string = 'E2E 測試課程', slug?: string): Promise<number> {
		const resp = await this.pcPostForm('courses', {
			name,
			// 以下為 PHP 端必須存在的 meta keys，避免 Undefined array key warnings
			type: 'simple',
			course_schedule: '0',
			editor: 'power-editor',
		})
		const body = resp.data as { code: string; data: { id: string } }
		const id = Number(body?.data?.id)
		if (!id || isNaN(id)) {
			throw new Error(
				`課程建立失敗，API 回傳: ${JSON.stringify(resp.data)}`,
			)
		}

		// Power Course API 不設定 WC product slug，需透過 WC REST API 單獨設定
		if (slug) {
			await this.wcPost(`products/${id}`, { slug })
		}

		return id
	}

	/**
	 * 批量刪除課程
	 */
	async deleteCourses(ids: number[]): Promise<void> {
		await this.pcDelete('courses', { ids })
	}

	// ── Phase 2 便利方法 ─────────────────────────

	/**
	 * 建立課程 + 設定價格 + 建立章節（含 slug）
	 *
	 * @returns courseId 及各章節 ID
	 */
	async createCourseWithChapters(
		name: string,
		regularPrice: string,
		chapters: { name: string; slug: string }[],
		courseSlug?: string,
	): Promise<{ courseId: number; chapterIds: number[]; courseSlug: string }> {
		const courseId = await this.createCourse(name, courseSlug)

		// 設定價格 + 發佈（form-encoded — PHP 使用 get_body_params）
		await this.pcPostForm(`courses/${courseId}`, {
			regular_price: regularPrice,
			status: 'publish',
			type: 'simple',
			course_schedule: '0',
			editor: 'power-editor',
		})

		// 建立章節
		const chapterIds: number[] = []
		const chapterSlugs: string[] = []
		for (let i = 0; i < chapters.length; i++) {
			const ch = chapters[i]
			const resp = await this.pcPostForm<number[]>('chapters', {
				name: ch.name,
				parent_id: courseId,
				slug: ch.slug,
				status: 'publish',
				menu_order: i,
				depth: 0,
			})
			const ids = resp.data as number[]
			if (Array.isArray(ids) && ids.length > 0) {
				chapterIds.push(ids[0])
				chapterSlugs.push(ch.slug)
			}
		}

		// 呼叫 sort API 設定 parent_course_id meta（前台 template 依賴此 meta）
		if (chapterIds.length > 0) {
			const tree = chapterIds.map((id, i) => ({
				id: String(id),
				depth: '0',
				menu_order: String(i),
				name: chapters[i].name,
				slug: chapterSlugs[i],
				parent_id: String(courseId),
			}))
			await this.pcPost('chapters/sort', {
				from_tree: tree,
				to_tree: tree,
			})
		}

		// 取得最終 slug（WC 可能做了 sanitize）
		const product = await this.wcGet<{ slug: string }>(`products/${courseId}`)
		const finalSlug = (product.data as { slug: string }).slug

		return { courseId, chapterIds, courseSlug: finalSlug }
	}

	/**
	 * 取得課程商品的前台 URL（permalink）
	 */
	async getCourseUrl(courseId: number): Promise<string> {
		const resp = await this.wcGet<{ permalink: string }>(
			`products/${courseId}`,
		)
		return (resp.data as { permalink: string }).permalink
	}

	/**
	 * 授權學員存取課程
	 */
	async grantCourseAccess(
		userId: number,
		courseId: number,
		expireDate: number | string = 0,
	): Promise<void> {
		const resp = await this.pcPostForm('courses/add-students', {
			user_ids: [userId],
			course_ids: [courseId],
			expire_date: expireDate,
		})
		if (resp.status >= 400) {
			throw new Error(`grantCourseAccess failed (${resp.status}): ${JSON.stringify(resp.data)}`)
		}
	}

	/**
	 * 移除學員課程存取權限
	 */
	async removeCourseAccess(
		userId: number,
		courseId: number,
	): Promise<void> {
		const resp = await this.pcPostForm('courses/remove-students', {
			user_ids: [userId],
			course_ids: [courseId],
		})
		if (resp.status >= 400) {
			throw new Error(`removeCourseAccess failed (${resp.status}): ${JSON.stringify(resp.data)}`)
		}
	}

	/**
	 * 冪等建立使用者（若已存在則忽略）
	 *
	 * @returns 使用者 ID
	 */
	async ensureUser(
		username: string,
		email: string,
		password: string,
		roles: string[] = ['subscriber'],
	): Promise<number> {
		// 先查詢是否存在
		const search = await this.wpGet<{ id: number }[]>('users', {
			search: username,
			context: 'edit',
		})
		const users = search.data as { id: number; slug: string }[]
		const existing = Array.isArray(users) ? users.find((u) => u.slug === username) : undefined
		if (existing) return existing.id

		// 不存在則建立
		const resp = await this.wpPost<{ id: number }>('users', {
			username,
			email,
			password,
			roles,
		})
		const created = resp.data as { id: number }
		if (!created?.id) {
			throw new Error(
				`使用者建立失敗: ${JSON.stringify(resp.data)}`,
			)
		}
		return created.id
	}

	/**
	 * 啟用 BACS（銀行轉帳）付款方式
	 */
	async enableBacsPayment(): Promise<void> {
		await this.wcPost('payment_gateways/bacs', { enabled: true })
	}

	/**
	 * 更新課程 meta（JSON）
	 */
	async updateCourse(
		courseId: number,
		data: Record<string, unknown>,
	): Promise<void> {
		await this.pcPostForm(`courses/${courseId}`, data)
	}

	/**
	 * Issue #10：以 JSON body 更新課程，支援空陣列等 form-encoded 無法表達的場景
	 *
	 * 與正式前端透過 Refine REST data provider 一致（axios JSON content-type）。
	 * 用於需要明確傳遞 `[]`、`null` 等型別的場景（例如清空 trial_videos）。
	 */
	async updateCourseJson(
		courseId: number,
		data: Record<string, unknown>,
	): Promise<ApiResponse<unknown>> {
		return await this.pcPost(`courses/${courseId}`, data)
	}

	/**
	 * 設定課程為未來排程開課
	 */
	async setCourseFutureSchedule(
		courseId: number,
		timestamp: number,
	): Promise<void> {
		await this.pcPostForm(`courses/${courseId}`, {
			course_schedule: String(timestamp),
		})
	}

	/**
	 * 設定課程講師
	 */
	async setCourseTeacher(
		courseId: number,
		teacherIds: number[],
	): Promise<void> {
		await this.pcPostForm(`courses/${courseId}`, {
			teacher_ids: teacherIds,
		})
	}

	/**
	 * 設定課程為免費
	 */
	async setCourseFree(courseId: number): Promise<void> {
		await this.pcPostForm(`courses/${courseId}`, {
			regular_price: '0',
			is_free: 'yes',
		})
	}

	/**
	 * 設定課程到期時間（過去時間 → 已過期）
	 */
	async setCourseExpired(
		userId: number,
		courseId: number,
	): Promise<void> {
		// 設定到期日為過去的時間戳
		const pastTimestamp = Math.floor(Date.now() / 1000) - 86400
		const resp = await this.pcPostForm('courses/update-students', {
			user_ids: [userId],
			course_ids: [courseId],
			timestamp: pastTimestamp,
		})
		if (resp.status >= 400) {
			throw new Error(`setCourseExpired failed (${resp.status}): ${JSON.stringify(resp.data)}`)
		}
	}
}

/**
 * 從頁面 wpApiSettings 取得 nonce
 */
export async function getNonceFromPage(
	page: import('@playwright/test').Page,
): Promise<string> {
	return await page.evaluate(() => {
		// @ts-expect-error — wpApiSettings is injected by WP
		return window.wpApiSettings?.nonce || ''
	})
}

/**
 * 在 beforeAll/afterAll 中建立 API Client
 *
 * worker-scoped browser fixture 在 beforeAll 中可用。
 * 此函式建立獨立的 context → 頁面 → 取 nonce → 回傳 ApiClient。
 */
export async function setupApiFromBrowser(
	browser: import('@playwright/test').Browser,
): Promise<{ api: ApiClient; dispose: () => Promise<void> }> {
	const context = await browser.newContext({
		storageState: '.auth/admin.json',
	})
	const page = await context.newPage()
	const baseUrl = process.env.TEST_SITE_URL || 'http://localhost:8889'
	await page.goto(`${baseUrl}/wp-admin/`, {
		waitUntil: 'domcontentloaded',
		timeout: 30_000,
	})
	// 等待 wpApiSettings 可用（比 body.wp-admin selector 更可靠）
	await page.waitForFunction(() => !!(window as any).wpApiSettings?.nonce, {
		timeout: 30_000,
	})
	const nonce = await getNonceFromPage(page)
	return {
		api: new ApiClient(context.request, nonce),
		dispose: async () => {
			await page.close()
			await context.close()
		},
	}
}
