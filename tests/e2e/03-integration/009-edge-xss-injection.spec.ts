/**
 * 邊界測試：XSS / Injection 防護
 *
 * 確認 REST API 對含有 script 標籤、HTML 注入、SQL injection 等惡意輸入
 * 能正常處理（清理或拒絕），不會造成 500 錯誤。
 */
import { test, expect } from '@playwright/test'
import { ApiClient, setupApiFromBrowser } from '../helpers/api-client'

let api: ApiClient
let dispose: () => Promise<void>
let courseId: number

test.setTimeout(120_000)

test.describe('XSS / Injection 邊界測試', () => {
	test.beforeAll(async ({ browser }) => {
		;({ api, dispose } = await setupApiFromBrowser(browser))

		// 建立一個測試課程供後續使用
		courseId = await api.createCourse('E2E XSS Test Base', 'e2e-xss-base')
	})

	test.afterAll(async () => {
		try {
			await api.deleteCourses([courseId])
		} catch { /* ignore cleanup errors */ }
		await dispose()
	})

	test('課程名稱含 script 標籤', async () => {
		const xssName = '<script>alert(1)</script>Test'
		const resp = await api.pcPostForm('courses', {
			name: xssName,
			type: 'simple',
			course_schedule: '0',
			editor: 'power-editor',
		})

		// 不應造成伺服器錯誤
		expect(resp.status).toBeLessThan(500)

		// 若建立成功，檢查名稱是否被清理（不含原始 script 標籤）
		if (resp.status === 200) {
			const body = resp.data as { data?: { id?: string } }
			const newId = Number(body?.data?.id)
			if (newId) {
				const getResp = await api.pcGet(`courses/${newId}`)
				if (getResp.status === 200) {
					const courseData = JSON.stringify(getResp.data)
					expect(courseData).not.toContain('<script>')
				}
				// 清理建立的課程
				try {
					await api.deleteCourses([newId])
				} catch { /* ignore */ }
			}
		}
	})

	test('課程描述含 XSS img onerror', async () => {
		const xssDesc = '<img onerror="alert(1)" src=x>'
		const resp = await api.pcPostForm(`courses/${courseId}`, {
			description: xssDesc,
			type: 'simple',
			course_schedule: '0',
			editor: 'power-editor',
		})

		// WordPress 管理員擁有 unfiltered_html capability，
		// 所以 onerror 不會被移除 — 這裡只驗證 API 不會 500
		expect(resp.status).toBeLessThan(500)
	})

	test('章節標題含 HTML 和 script', async () => {
		const xssTitle = '<b>Bold</b><script>xss</script>'
		const resp = await api.pcPostForm('chapters', {
			name: xssTitle,
			parent_id: courseId,
			slug: 'e2e-xss-chapter',
			status: 'publish',
			menu_order: 0,
			depth: 0,
		})

		// 不應造成伺服器崩潰
		expect(resp.status).toBeLessThan(500)

		// 若建立成功，清理章節
		if (resp.status === 200) {
			const ids = resp.data as number[]
			if (Array.isArray(ids) && ids.length > 0) {
				try {
					await api.wpDelete(`pc_chapter/${ids[0]}`, { force: 'true' })
				} catch { /* ignore */ }
			}
		}
	})

	test('搜尋參數含 SQL injection', async () => {
		const sqlInjection = "' OR 1=1 --"
		const resp = await api.pcGet('courses', {
			s: sqlInjection,
		})

		// WordPress 的 WP_Query 會參數化查詢，不應 500
		expect(resp.status).toBeLessThan(500)
	})

	test('設定值含特殊字元（浮水印文字）', async () => {
		// 浮水印文字 spec 允許 placeholder，不應該被 sanitize 而導致 500
		const xssWatermark = '{display_name}<script>alert(1)</script>'
		const resp = await api.pcPost('settings', {
			pc_watermark_text: xssWatermark,
		})

		// 不應造成伺服器崩潰
		expect(resp.status).toBeLessThan(500)
	})
})
