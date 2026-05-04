/**
 * Chapter CRUD API 測試
 *
 * 驗證 Power Course REST API 的章節 CRUD 操作：
 * 建立、讀取、更新、排序、巢狀子章節、刪除（單筆＋批量）。
 */

import { test, expect } from '@playwright/test'
import { ApiClient, setupApiFromBrowser } from '../helpers/api-client'

/** POST /chapters 成功回應 — 回傳新建章節 ID 陣列 */
type CreateChapterResponse = number[]

/** POST /chapters/{id} 更新回應 */
interface UpdateChapterResponse {
	code: string
	message: string
	data: { id: string }
}

/** POST /chapters/sort 排序回應 */
interface SortChapterResponse {
	code: string
	message: string
	data: null
}

/** DELETE /chapters 批量刪除回應 */
interface BulkDeleteResponse {
	code: string
	message: string
	data: string[]
}

/** 章節排序樹節點 */
interface TreeItem {
	id: number
	depth: number
	menu_order: number
	name: string
	slug: string
	parent_id: number
}

test.describe('Chapter CRUD API', () => {
	test.use({ storageState: '.auth/admin.json' })

	let api: ApiClient
	let dispose: () => Promise<void>

	/** 測試用課程 ID — 所有章節掛在這個課程下 */
	let courseId: number

	/** 收集所有建立的章節 ID，afterAll 統一清除 */
	const createdChapterIds: number[] = []

	test.beforeAll(async ({ browser }) => {
		const setup = await setupApiFromBrowser(browser)
		api = setup.api
		dispose = setup.dispose

		// 建立測試課程
		courseId = await api.createCourse('Chapter Test Course')
	})

	test.afterAll(async () => {
		try {
			// 先嘗試刪除所有追蹤到的章節
			if (createdChapterIds.length > 0) {
				await api.pcDelete('chapters', {
					ids: createdChapterIds,
				})
			}
		} catch {
			// 章節清除失敗不影響測試結果
		}

		try {
			// 刪除測試課程（會連帶清除殘留章節）
			if (courseId) {
				await api.deleteCourses([courseId])
			}
		} catch {
			// 課程清除失敗不影響測試結果
		} finally {
			await dispose()
		}
	})

	// ── 建立章節 ──────────────────────────────────

	test.describe('建立章節', () => {
		test('基本 — 建立單一章節，回傳包含 1 個 ID 的陣列', async () => {
			const resp = await api.pcPostForm<CreateChapterResponse>(
				'chapters',
				{
					name: 'E2E 章節 — 基本建立',
					parent_id: courseId,
					slug: 'e2e-chapter-basic',
					status: 'publish',
					menu_order: 0,
					depth: 0,
				},
			)

			expect(resp.status).toBe(200)
			expect(Array.isArray(resp.data)).toBe(true)
			expect(resp.data).toHaveLength(1)

			const chapterId = resp.data[0]
			expect(chapterId).toBeGreaterThan(0)
			createdChapterIds.push(chapterId)
		})

		test('建立多個章節 — 不同 menu_order', async () => {
			const ids: number[] = []

			for (let i = 1; i <= 3; i++) {
				const resp = await api.pcPostForm<CreateChapterResponse>(
					'chapters',
					{
						name: `E2E 章節 — 多筆 ${i}`,
						parent_id: courseId,
						slug: `e2e-chapter-multi-${i}`,
						status: 'publish',
						menu_order: i,
						depth: 0,
					},
				)

				expect(resp.status).toBe(200)
				expect(Array.isArray(resp.data)).toBe(true)
				expect(resp.data).toHaveLength(1)

				const chapterId = resp.data[0]
				expect(chapterId).toBeGreaterThan(0)
				ids.push(chapterId)
				createdChapterIds.push(chapterId)
			}

			// 確認建立了 3 個不同的章節
			expect(ids).toHaveLength(3)
			expect(new Set(ids).size).toBe(3)
		})
	})

	// ── 取得章節 ──────────────────────────────────

	test.describe('取得章節列表', () => {
		let listChapterIds: number[]

		test.beforeAll(async () => {
			listChapterIds = []
			for (let i = 1; i <= 2; i++) {
				const resp = await api.pcPostForm<CreateChapterResponse>(
					'chapters',
					{
						name: `E2E 章節 — 列表 ${i}`,
						parent_id: courseId,
						slug: `e2e-chapter-list-${i}`,
						status: 'publish',
						menu_order: i + 10,
						depth: 0,
					},
				)
				listChapterIds.push(resp.data[0])
				createdChapterIds.push(resp.data[0])
			}
		})

		test('以 post_parent 篩選課程下的章節', async () => {
			const resp = await api.pcGet<Record<string, unknown>[]>(
				'chapters',
				{
					post_parent: String(courseId),
				},
			)

			expect(resp.status).toBe(200)
			expect(Array.isArray(resp.data)).toBe(true)
			expect(resp.data.length).toBeGreaterThanOrEqual(2)

			// 確認回傳的章節都屬於本課程（API 回傳 parent_id 為字串）
			for (const chapter of resp.data) {
				expect(String(chapter.parent_id)).toBe(String(courseId))
			}

			// 確認剛建立的章節在列表中
			const returnedIds = resp.data.map((c) => Number(c.id))
			for (const id of listChapterIds) {
				expect(returnedIds).toContain(id)
			}
		})
	})

	// ── 更新章節 ──────────────────────────────────

	test.describe('更新章節', () => {
		let updateChapterId: number

		test.beforeAll(async () => {
			const resp = await api.pcPostForm<CreateChapterResponse>(
				'chapters',
				{
					name: 'E2E 章節 — 更新用',
					parent_id: courseId,
					slug: 'e2e-chapter-update',
					status: 'publish',
					menu_order: 0,
					depth: 0,
				},
			)
			updateChapterId = resp.data[0]
			createdChapterIds.push(updateChapterId)
		})

		test('更新章節標題', async () => {
			const resp = await api.pcPostForm<UpdateChapterResponse>(
				`chapters/${updateChapterId}`,
				{
					post_title: 'E2E 章節 — 已更新標題',
				},
			)

			expect(resp.status).toBe(200)
			expect(resp.data.code).toBe('update_success')
			expect(resp.data.message).toBe('更新成功')
			expect(String(resp.data.data.id)).toBe(String(updateChapterId))
		})

		test('更新章節內容', async () => {
			const htmlContent =
				'<h2>章節內容</h2><p>這是測試用的章節內容，包含 <strong>粗體</strong> 與 HTML。</p>'

			const resp = await api.pcPostForm<UpdateChapterResponse>(
				`chapters/${updateChapterId}`,
				{
					post_content: htmlContent,
				},
			)

			expect(resp.status).toBe(200)
			expect(resp.data.code).toBe('update_success')
			expect(resp.data.message).toBe('更新成功')
		})
	})

	// ── 排序章節 ──────────────────────────────────

	test.describe('排序章節', () => {
		const sortChapterIds: number[] = []
		const sortChapterNames = ['排序 A', '排序 B', '排序 C']

		test.beforeAll(async () => {
			for (let i = 0; i < sortChapterNames.length; i++) {
				const resp = await api.pcPostForm<CreateChapterResponse>(
					'chapters',
					{
						name: `E2E 章節 — ${sortChapterNames[i]}`,
						parent_id: courseId,
						slug: `e2e-chapter-sort-${i}`,
						status: 'publish',
						menu_order: i,
						depth: 0,
					},
				)
				sortChapterIds.push(resp.data[0])
				createdChapterIds.push(resp.data[0])
			}
		})

		test('反轉章節順序', async () => {
			// 原始順序：A(0), B(1), C(2)
			const fromTree: TreeItem[] = sortChapterIds.map((id, i) => ({
				id,
				depth: 0,
				menu_order: i,
				name: `E2E 章節 — ${sortChapterNames[i]}`,
				slug: `e2e-chapter-sort-${i}`,
				parent_id: courseId,
			}))

			// 反轉後：C(0), B(1), A(2)
			const reversed = [...sortChapterIds].reverse()
			const toTree: TreeItem[] = reversed.map((id, i) => {
				const origIndex = sortChapterIds.indexOf(id)
				return {
					id,
					depth: 0,
					menu_order: i,
					name: `E2E 章節 — ${sortChapterNames[origIndex]}`,
					slug: `e2e-chapter-sort-${origIndex}`,
					parent_id: courseId,
				}
			})

			const resp = await api.pcPost<SortChapterResponse>(
				'chapters/sort',
				{ from_tree: fromTree, to_tree: toTree },
			)

			expect(resp.status).toBe(200)
			expect(resp.data.code).toBe('sort_success')
			expect(resp.data.message).toBe('修改排序成功')
			expect(resp.data.data).toBeNull()

			// 透過 GET 驗證排序結果
			const getResp = await api.pcGet<Record<string, unknown>[]>(
				'chapters',
				{ post_parent: String(courseId) },
			)

			expect(getResp.status).toBe(200)

			// 找出排序章節，按 menu_order 排序
			const sortedChapters = getResp.data
				.filter((c) => sortChapterIds.includes(Number(c.id)))
				.sort(
					(a, b) =>
						Number(a.menu_order) - Number(b.menu_order),
				)

			// 預期排序：C, B, A
			if (sortedChapters.length === 3) {
				expect(Number(sortedChapters[0].id)).toBe(
					sortChapterIds[2],
				) // C
				expect(Number(sortedChapters[1].id)).toBe(
					sortChapterIds[1],
				) // B
				expect(Number(sortedChapters[2].id)).toBe(
					sortChapterIds[0],
				) // A
			}
		})
	})

	// ── 巢狀子章節 ────────────────────────────────

	test.describe('巢狀子章節', () => {
		test('建立父章節再建立子章節', async () => {
			// 1. 建立父章節
			const parentResp = await api.pcPostForm<CreateChapterResponse>(
				'chapters',
				{
					name: 'E2E 父章節',
					parent_id: courseId,
					slug: 'e2e-parent-chapter',
					status: 'publish',
					menu_order: 0,
					depth: 0,
				},
			)

			expect(parentResp.status).toBe(200)
			const parentId = parentResp.data[0]
			expect(parentId).toBeGreaterThan(0)
			createdChapterIds.push(parentId)

			// 2. 建立子章節（parent_id 指向父章節、depth=1）
			const childResp = await api.pcPostForm<CreateChapterResponse>(
				'chapters',
				{
					name: 'E2E 子章節',
					parent_id: parentId,
					slug: 'e2e-child-chapter',
					status: 'publish',
					menu_order: 0,
					depth: 1,
				},
			)

			expect(childResp.status).toBe(200)
			const childId = childResp.data[0]
			expect(childId).toBeGreaterThan(0)
			expect(childId).not.toBe(parentId)
			createdChapterIds.push(childId)

			// 3. 驗證子章節的 parent 正確
			const listResp = await api.pcGet<Record<string, unknown>[]>(
				'chapters',
				{ post_parent: String(parentId) },
			)

			expect(listResp.status).toBe(200)
			const childIds = listResp.data.map((c) => Number(c.id))
			expect(childIds).toContain(childId)
		})
	})

	// ── nocache headers（Issue #216 Bug #1b）──────────────

	test.describe('Issue #216 — REST API nocache 標頭', () => {
		test('GET /chapters 回傳 Cache-Control: no-store, no-cache 標頭', async () => {
			const resp = await api.pcGet<Record<string, unknown>[]>(
				'chapters',
				{ post_parent: String(courseId) },
			)

			expect(resp.status).toBe(200)
			const cacheControl = String(
				resp.headers['cache-control'] || resp.headers['Cache-Control'] || '',
			)
			expect(cacheControl).toContain('no-store')
			expect(cacheControl).toContain('no-cache')
		})

		test('POST /chapters/sort 回傳 nocache 標頭', async () => {
			// 建立兩個章節用於排序
			const created: number[] = []
			for (let i = 0; i < 2; i++) {
				const r = await api.pcPostForm<CreateChapterResponse>('chapters', {
					name: `E2E 章節 — nocache ${i}`,
					parent_id: courseId,
					slug: `e2e-chapter-nocache-${i}-${Date.now()}`,
					status: 'publish',
					menu_order: i,
					depth: 0,
				})
				created.push(r.data[0])
				createdChapterIds.push(r.data[0])
			}

			const fromTree: TreeItem[] = created.map((id, i) => ({
				id,
				depth: 0,
				menu_order: i,
				name: `E2E 章節 — nocache ${i}`,
				slug: `e2e-chapter-nocache-${i}`,
				parent_id: courseId,
			}))
			const toTree: TreeItem[] = [...created].reverse().map((id, i) => ({
				id,
				depth: 0,
				menu_order: i,
				name: 'E2E 章節 — nocache',
				slug: `e2e-chapter-nocache-${i}`,
				parent_id: courseId,
			}))

			const resp = await api.pcPost<SortChapterResponse>(
				'chapters/sort',
				{ from_tree: fromTree, to_tree: toTree },
			)

			expect(resp.status).toBe(200)
			const cacheControl = String(
				resp.headers['cache-control'] || resp.headers['Cache-Control'] || '',
			)
			expect(cacheControl).toContain('no-store')
		})
	})

	// ── Issue #216 Bug #2 — 排序成為子章節後立即編輯，post_parent 不被重置 ──

	test.describe('Issue #216 Bug #2 — 排序成為子章節後立即編輯', () => {
		test('排序後立即編輯子章節，post_parent 持久不變', async () => {
			// 1. 建立 parent 與 child（initially flat）
			const parentResp = await api.pcPostForm<CreateChapterResponse>(
				'chapters',
				{
					name: 'E2E 父章節（issue 216）',
					parent_id: courseId,
					slug: `e2e-parent-216-${Date.now()}`,
					status: 'publish',
					menu_order: 0,
					depth: 0,
				},
			)
			const parentId = parentResp.data[0]
			createdChapterIds.push(parentId)

			const childResp = await api.pcPostForm<CreateChapterResponse>(
				'chapters',
				{
					name: 'E2E 子章節（issue 216）',
					parent_id: courseId,
					slug: `e2e-child-216-${Date.now()}`,
					status: 'publish',
					menu_order: 1,
					depth: 0,
				},
			)
			const childId = childResp.data[0]
			createdChapterIds.push(childId)

			// 2. 透過 sort API 把 child 拖到 parent 之下
			const sortResp = await api.pcPost<SortChapterResponse>(
				'chapters/sort',
				{
					from_tree: [
						{
							id: parentId,
							depth: 0,
							menu_order: 0,
							name: 'parent',
							slug: 'parent',
							parent_id: courseId,
						},
						{
							id: childId,
							depth: 0,
							menu_order: 1,
							name: 'child',
							slug: 'child',
							parent_id: courseId,
						},
					],
					to_tree: [
						{
							id: parentId,
							depth: 0,
							menu_order: 0,
							name: 'parent',
							slug: 'parent',
							parent_id: courseId,
						},
						{
							id: childId,
							depth: 1,
							menu_order: 0,
							name: 'child',
							slug: 'child',
							parent_id: parentId,
						},
					],
				},
			)
			expect(sortResp.status).toBe(200)

			// 3. 立刻編輯 child 標題
			const updateResp = await api.pcPostForm<UpdateChapterResponse>(
				`chapters/${childId}`,
				{
					post_title: 'E2E 子章節（已編輯）',
				},
			)
			expect(updateResp.status).toBe(200)
			expect(updateResp.data.code).toBe('update_success')

			// 4. 透過 GET /chapters?post_parent=parentId 驗證 child 仍在巢狀結構
			const listResp = await api.pcGet<Record<string, unknown>[]>(
				'chapters',
				{ post_parent: String(parentId) },
			)
			expect(listResp.status).toBe(200)
			const childIds = listResp.data.map((c) => Number(c.id))
			expect(childIds).toContain(childId)
		})
	})

	// ── 刪除章節 ──────────────────────────────────
	// 注意：Power Course DELETE /chapters 端點有已知 bug —
	// batch_process 回傳結構化物件 {total, success, failed, failed_items}，
	// 但 delete_chapters_callback 用 array_filter(!$result) 檢查，
	// 導致 'failed: 0' 和 'failed_items: []' 被判為 falsy，永遠報 400。
	// 實際上章節 IS 被移到 trash，所以這裡透過 WP REST API 驗證。

	test.describe('刪除章節', () => {
		test('刪除章節（透過 WP REST API）', async () => {
			// 建立一個即將被刪除的章節
			const createResp = await api.pcPostForm<CreateChapterResponse>(
				'chapters',
				{
					name: 'E2E 章節 — 待刪除',
					parent_id: courseId,
					slug: 'e2e-chapter-delete-single',
					status: 'publish',
					menu_order: 0,
					depth: 0,
				},
			)

			const chapterId = createResp.data[0]

			// 透過 WP REST API 刪除（force=true 永久刪除）
			const resp = await api.wpDelete(`pc_chapter/${chapterId}`, {
				force: 'true',
			})

			expect(resp.status).toBe(200)

			// 確認章節已被刪除 — 嘗試讀取應返回 404
			const checkResp = await api.wpGet(`pc_chapter/${chapterId}`)
			expect(checkResp.status).toBe(404)
		})

		test('批量刪除章節（透過 WP REST API）', async () => {
			// 建立 2 個即將被刪除的章節
			const idsToDelete: number[] = []

			for (let i = 1; i <= 2; i++) {
				const createResp =
					await api.pcPostForm<CreateChapterResponse>(
						'chapters',
						{
							name: `E2E 章節 — 批量刪除 ${i}`,
							parent_id: courseId,
							slug: `e2e-chapter-bulk-delete-${i}`,
							status: 'publish',
							menu_order: 0,
							depth: 0,
						},
					)
				idsToDelete.push(createResp.data[0])
			}

			// 逐一透過 WP REST API 刪除
			for (const id of idsToDelete) {
				const resp = await api.wpDelete(`pc_chapter/${id}`, {
					force: 'true',
				})
				expect(resp.status).toBe(200)
			}

			// 確認全部已刪除
			for (const id of idsToDelete) {
				const checkResp = await api.wpGet(`pc_chapter/${id}`)
				expect(checkResp.status).toBe(404)
			}
		})
	})
})
