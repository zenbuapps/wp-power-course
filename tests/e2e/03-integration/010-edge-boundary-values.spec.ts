/**
 * 邊界測試：極端值與邊界條件
 *
 * 測試超長名稱、Unicode/Emoji、空白值、零/負價格、極端時間戳等
 * 邊界情境下 API 的穩定性。
 */
import { test, expect } from '@playwright/test'
import { ApiClient, setupApiFromBrowser } from '../helpers/api-client'

let api: ApiClient
let dispose: () => Promise<void>
let courseId: number
let testUserId: number

/** 記錄測試過程中建立的課程 ID，供 afterAll 清理 */
const createdCourseIds: number[] = []

const TEST_USER = {
	username: 'e2e_boundary',
	email: 'e2e_boundary@test.local',
	password: 'e2e_boundary_pass',
}

test.setTimeout(120_000)

test.describe('邊界值測試', () => {
	test.beforeAll(async ({ browser }) => {
		;({ api, dispose } = await setupApiFromBrowser(browser))

		// 建立基本測試課程
		courseId = await api.createCourse('E2E Boundary Base', 'e2e-boundary-base')
		createdCourseIds.push(courseId)

		// 發佈並設定價格
		await api.pcPostForm(`courses/${courseId}`, {
			regular_price: '500',
			status: 'publish',
			type: 'simple',
			course_schedule: '0',
			editor: 'power-editor',
		})

		// 確保測試用戶存在
		testUserId = await api.ensureUser(
			TEST_USER.username,
			TEST_USER.email,
			TEST_USER.password,
		)
	})

	test.afterAll(async () => {
		// 清理學員存取
		try {
			await api.removeCourseAccess(testUserId, courseId)
		} catch { /* ignore */ }

		// 清理所有建立的課程
		for (const id of createdCourseIds) {
			try {
				await api.deleteCourses([id])
			} catch { /* ignore */ }
		}
		await dispose()
	})

	test('超長課程名稱（500 字元）', async () => {
		const longName = 'A'.repeat(500)
		const resp = await api.pcPostForm('courses', {
			name: longName,
			type: 'simple',
			course_schedule: '0',
			editor: 'power-editor',
		})

		// 不應 500 — 可能成功或被截斷
		expect(resp.status).toBeLessThan(500)

		// 清理
		if (resp.status === 200) {
			const body = resp.data as { data?: { id?: string } }
			const newId = Number(body?.data?.id)
			if (newId) {
				createdCourseIds.push(newId)
			}
		}
	})

	test('Unicode 和 Emoji 課程名稱', async () => {
		const emojiName = '🎓 E2E 課程 テスト 🎓'
		const resp = await api.pcPostForm('courses', {
			name: emojiName,
			type: 'simple',
			course_schedule: '0',
			editor: 'power-editor',
		})

		// 不應造成伺服器崩潰
		expect(resp.status).toBeLessThan(500)

		if (resp.status === 200) {
			const body = resp.data as { data?: { id?: string } }
			const newId = Number(body?.data?.id)
			if (newId) {
				createdCourseIds.push(newId)
			}
		}
	})

	test('空白名稱建立課程', async () => {
		const resp = await api.pcPostForm('courses', {
			name: '',
			type: 'simple',
			course_schedule: '0',
			editor: 'power-editor',
		})

		// WooCommerce 預設會用 "Product" 作為名稱 — 不應 500
		expect(resp.status).toBeLessThan(500)

		if (resp.status === 200) {
			const body = resp.data as { data?: { id?: string } }
			const newId = Number(body?.data?.id)
			if (newId) {
				createdCourseIds.push(newId)
			}
		}
	})

	test('零價格課程', async () => {
		const resp = await api.pcPostForm(`courses/${courseId}`, {
			regular_price: '0',
			type: 'simple',
			course_schedule: '0',
			editor: 'power-editor',
		})

		// 零價格是合法的（免費課程）
		expect(resp.status).toBeLessThan(500)
	})

	test('負數價格', async () => {
		const resp = await api.pcPostForm(`courses/${courseId}`, {
			regular_price: '-100',
			type: 'simple',
			course_schedule: '0',
			editor: 'power-editor',
		})

		// WooCommerce 可能會清理為 0 或拒絕，但不應 500
		expect(resp.status).toBeLessThan(500)

		// 還原價格為正常值
		await api.pcPostForm(`courses/${courseId}`, {
			regular_price: '500',
			type: 'simple',
			course_schedule: '0',
			editor: 'power-editor',
		})
	})

	test('超大時間戳到期日（year 2286）', async () => {
		// 先授權學員
		await api.grantCourseAccess(testUserId, courseId)

		// 設定超大時間戳
		const hugeTimestamp = 9999999999
		const resp = await api.pcPostForm('courses/update-students', {
			user_ids: [testUserId],
			course_ids: [courseId],
			timestamp: hugeTimestamp,
		})

		// 不應造成伺服器崩潰
		expect(resp.status).toBeLessThan(500)
	})

	test('過去時間戳到期日（已過期）', async () => {
		// 設定到期日為過去（這是 setCourseExpired 的正常做法）
		const pastTimestamp = Math.floor(Date.now() / 1000) - 86400 * 30
		const resp = await api.pcPostForm('courses/update-students', {
			user_ids: [testUserId],
			course_ids: [courseId],
			timestamp: pastTimestamp,
		})

		// 過去時間戳是合法操作
		expect(resp.status).toBeLessThan(500)
	})
})
