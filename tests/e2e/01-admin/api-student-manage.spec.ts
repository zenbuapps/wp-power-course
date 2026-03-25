/**
 * Student Management API 測試
 *
 * 驗證 Power Course REST API 的學員管理操作：
 * 新增學員、移除學員、更新到期日、查詢學員列表。
 */

import { test, expect } from '@playwright/test'
import { ApiClient, setupApiFromBrowser } from '../helpers/api-client'

/** POST /courses/add-students 成功回應 */
interface AddStudentsResponse {
	code: string
	message: string
	data: { user_ids: string; course_ids: string }
}

/** POST /courses/remove-students 成功回應 */
interface RemoveStudentsResponse {
	code: string
	message: string
	data: { user_ids: string; course_ids: string }
}

/** POST /courses/update-students 成功回應 */
interface UpdateStudentsResponse {
	code: string
	message: string
	data: { user_ids: string; course_ids: string; timestamp: string }
}

/** GET /students 學員物件 */
interface StudentUser {
	id: string
	user_login: string
	[key: string]: unknown
}

test.describe('Student Management API', () => {
	test.use({ storageState: '.auth/admin.json' })
	test.setTimeout(60_000)

	let api: ApiClient
	let dispose: () => Promise<void>

	/** 測試用課程 ID */
	const createdCourseIds: number[] = []
	let courseId1: number
	let courseId2: number

	/** 測試用使用者 ID */
	let userId1: number
	let userId2: number

	test.beforeAll(async ({ browser }) => {
		const setup = await setupApiFromBrowser(browser)
		api = setup.api
		dispose = setup.dispose

		// 建立 2 個測試課程
		courseId1 = await api.createCourse('學員管理測試課程 A')
		courseId2 = await api.createCourse('學員管理測試課程 B')
		createdCourseIds.push(courseId1, courseId2)

		// 建立 2 個測試使用者
		userId1 = await api.ensureUser(
			'e2e_stu_api_1',
			'e2e_stu_api_1@test.local',
			'test1234!',
		)
		userId2 = await api.ensureUser(
			'e2e_stu_api_2',
			'e2e_stu_api_2@test.local',
			'test1234!',
		)
	})

	test.afterAll(async () => {
		try {
			// 移除所有學員權限（忽略錯誤）
			for (const uid of [userId1, userId2]) {
				for (const cid of createdCourseIds) {
					try {
						await api.removeCourseAccess(uid, cid)
					} catch {
						// 已移除或不存在，忽略
					}
				}
			}

			// 刪除測試課程
			if (createdCourseIds.length > 0) {
				await api.deleteCourses(createdCourseIds)
			}
		} catch {
			// 清除失敗不影響測試結果
		} finally {
			await dispose()
		}
	})

	// ── 新增學員 ──────────────────────────────────

	test.describe('新增學員到課程', () => {
		test('單一學員 — 無限期', async () => {
			const resp = await api.pcPostForm<AddStudentsResponse>(
				'courses/add-students',
				{
					user_ids: [userId1],
					course_ids: [courseId1],
					expire_date: 0,
				},
			)

			expect(resp.status).toBe(200)
			expect(resp.data.code).toBe('add_students_success')
			expect(resp.data.message).toBe('新增學員成功')
			expect(resp.data.data.user_ids).toContain(String(userId1))
			expect(resp.data.data.course_ids).toContain(String(courseId1))
		})

		test('多位學員 — 同時新增', async () => {
			const resp = await api.pcPostForm<AddStudentsResponse>(
				'courses/add-students',
				{
					user_ids: [userId1, userId2],
					course_ids: [courseId1],
					expire_date: 0,
				},
			)

			expect(resp.status).toBe(200)
			expect(resp.data.code).toBe('add_students_success')
			expect(resp.data.data.user_ids).toContain(String(userId1))
			expect(resp.data.data.user_ids).toContain(String(userId2))
		})

		test('指定到期日 — 未來時間戳', async () => {
			// 設定 90 天後到期
			const futureTimestamp = Math.floor(Date.now() / 1000) + 90 * 24 * 60 * 60

			const resp = await api.pcPostForm<AddStudentsResponse>(
				'courses/add-students',
				{
					user_ids: [userId2],
					course_ids: [courseId2],
					expire_date: futureTimestamp,
				},
			)

			expect(resp.status).toBe(200)
			expect(resp.data.code).toBe('add_students_success')
			expect(resp.data.data.user_ids).toContain(String(userId2))
			expect(resp.data.data.course_ids).toContain(String(courseId2))
		})

		test('新增學員到多個課程', async () => {
			const resp = await api.pcPostForm<AddStudentsResponse>(
				'courses/add-students',
				{
					user_ids: [userId1],
					course_ids: [courseId1, courseId2],
					expire_date: 0,
				},
			)

			expect(resp.status).toBe(200)
			expect(resp.data.code).toBe('add_students_success')
			expect(resp.data.data.course_ids).toContain(String(courseId1))
			expect(resp.data.data.course_ids).toContain(String(courseId2))
		})

		test('重複新增學員 — 冪等不報錯', async () => {
			// 先確保已新增
			await api.pcPostForm('courses/add-students', {
				user_ids: [userId1],
				course_ids: [courseId1],
				expire_date: 0,
			})

			// 再次新增相同學員
			const resp = await api.pcPostForm<AddStudentsResponse>(
				'courses/add-students',
				{
					user_ids: [userId1],
					course_ids: [courseId1],
					expire_date: 0,
				},
			)

			// 應該成功或至少不報 500 錯誤
			expect(resp.status).toBeLessThan(500)
		})
	})

	// ── 查詢學員 ──────────────────────────────────

	test.describe('查詢課程學員列表', () => {
		test.beforeAll(async () => {
			// 確保兩位學員都在課程 1 中
			await api.pcPostForm('courses/add-students', {
				user_ids: [userId1, userId2],
				course_ids: [courseId1],
				expire_date: 0,
			})
		})

		test('帶 course_id 篩選 — 回傳學員與分頁 headers', async () => {
			const resp = await api.pcGet<StudentUser[]>('students', {
				meta_value: String(courseId1),
				posts_per_page: '10',
				paged: '1',
				orderby: 'user_login',
				order: 'ASC',
			})

			expect(resp.status).toBe(200)
			expect(Array.isArray(resp.data)).toBe(true)

			// 驗證分頁 headers
			expect(resp.headers['x-wp-total']).toBeDefined()
			expect(resp.headers['x-wp-totalpages']).toBeDefined()

			const total = Number(resp.headers['x-wp-total'])
			expect(total).toBeGreaterThanOrEqual(2)

			// 驗證回傳資料包含測試學員
			const studentIds = resp.data.map((u) => u.id)
			expect(studentIds).toContain(String(userId1))
			expect(studentIds).toContain(String(userId2))
		})
	})

	// ── 移除學員 ──────────────────────────────────

	test.describe('移除學員權限', () => {
		test.beforeAll(async () => {
			// 確保學員 2 在課程 1 中
			await api.pcPostForm('courses/add-students', {
				user_ids: [userId2],
				course_ids: [courseId1],
				expire_date: 0,
			})
		})

		test('移除單一學員 — 成功回應並驗證', async () => {
			const resp = await api.pcPostForm<RemoveStudentsResponse>(
				'courses/remove-students',
				{
					user_ids: [userId2],
					course_ids: [courseId1],
				},
			)

			expect(resp.status).toBe(200)
			expect(resp.data.code).toBe('remove_students_success')
			expect(resp.data.message).toBe('移除學員成功')
			expect(resp.data.data.user_ids).toContain(String(userId2))

			// 查詢確認學員已移除
			const listResp = await api.pcGet<StudentUser[]>('students', {
				meta_value: String(courseId1),
				posts_per_page: '100',
				paged: '1',
			})

			const studentIds = listResp.data.map((u) => u.id)
			expect(studentIds).not.toContain(String(userId2))
		})
	})

	// ── 更新到期日 ────────────────────────────────

	test.describe('更新學員到期日', () => {
		test.beforeAll(async () => {
			// 確保學員 1 在課程 1 中
			await api.pcPostForm('courses/add-students', {
				user_ids: [userId1],
				course_ids: [courseId1],
				expire_date: 0,
			})
		})

		test('設定期限 — 未來時間戳', async () => {
			const futureTimestamp = Math.floor(Date.now() / 1000) + 60 * 24 * 60 * 60

			const resp = await api.pcPostForm<UpdateStudentsResponse>(
				'courses/update-students',
				{
					user_ids: [userId1],
					course_ids: [courseId1],
					timestamp: futureTimestamp,
				},
			)

			expect(resp.status).toBe(200)
			expect(resp.data.code).toBe('update_students_success')
			expect(resp.data.message).toBe('批次調整觀看期限成功')
			expect(resp.data.data.user_ids).toContain(String(userId1))
			expect(Number(resp.data.data.timestamp)).toBe(futureTimestamp)
		})

		test('設為無限期 — timestamp=0', async () => {
			const resp = await api.pcPostForm<UpdateStudentsResponse>(
				'courses/update-students',
				{
					user_ids: [userId1],
					course_ids: [courseId1],
					timestamp: 0,
				},
			)

			expect(resp.status).toBe(200)
			expect(resp.data.code).toBe('update_students_success')
			expect(resp.data.message).toBe('批次調整觀看期限成功')
			expect(Number(resp.data.data.timestamp)).toBe(0)
		})
	})

	// ── 移除後重新新增 ────────────────────────────

	test.describe('移除後重新新增', () => {
		test('移除再新增 — 權限恢復', async () => {
			// 先確保學員在課程中
			await api.pcPostForm('courses/add-students', {
				user_ids: [userId2],
				course_ids: [courseId2],
				expire_date: 0,
			})

			// 移除學員
			const removeResp = await api.pcPostForm<RemoveStudentsResponse>(
				'courses/remove-students',
				{
					user_ids: [userId2],
					course_ids: [courseId2],
				},
			)
			expect(removeResp.status).toBe(200)
			expect(removeResp.data.code).toBe('remove_students_success')

			// 確認已移除
			const afterRemove = await api.pcGet<StudentUser[]>('students', {
				meta_value: String(courseId2),
				posts_per_page: '100',
				paged: '1',
			})
			const removedIds = afterRemove.data.map((u) => u.id)
			expect(removedIds).not.toContain(String(userId2))

			// 重新新增
			const addResp = await api.pcPostForm<AddStudentsResponse>(
				'courses/add-students',
				{
					user_ids: [userId2],
					course_ids: [courseId2],
					expire_date: 0,
				},
			)
			expect(addResp.status).toBe(200)
			expect(addResp.data.code).toBe('add_students_success')

			// 確認權限恢復
			const afterAdd = await api.pcGet<StudentUser[]>('students', {
				meta_value: String(courseId2),
				posts_per_page: '100',
				paged: '1',
			})
			const restoredIds = afterAdd.data.map((u) => u.id)
			expect(restoredIds).toContain(String(userId2))
		})
	})
})
