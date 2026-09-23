/**
 * 測試目標：教室作業區塊（Issue #131 第一波 P8）
 *
 * 對應規格：specs/ui/教室作業區塊.md、specs/features/assignment/{繳交作業,查詢學員作業}.feature
 * 對應計劃：specs/plan/issue-131-assignment-grading/implementation-plan.md
 *   §9 P8（前台教室作業區塊）、§4 DEC-15（教室作業區塊技術決策）、
 *   §12 R-04（受保護目錄在 Nginx 無效）／R-14（MIME 偽造／下載 XSS）／R-20（本機前端驗證的牆）
 * 對應原始碼（P8 Green 階段建立，Red 階段尚不存在）：
 *   - inc/templates/components/assignment/{section.php,card.php,overview.php}
 *   - inc/templates/pages/classroom/body.php（插入點：the_content() 之後、留言之前）
 *   - inc/assets/src/events/assignments/{index.ts,upload.ts,submit.ts,render.ts}
 *
 * 資料準備（S-RED-E2E 硬性規則 1：一律走 REST，不走 UI）：
 * - 課程 / 章節 / 作業：管理員 REST 建立
 * - 學員：Powerhouse V2 API（ensureUserViaPowerhouse，本站 /wp/v2/users 被 mu-plugin 移除）
 * - 學員上傳附件與繳交作業一律透過「瀏覽器 UI 操作」（setInputFiles + 點擊繳交），
 *   因為本檔的受測對象正是教室前台 UI 本身；只有「批改」透過管理員 REST
 *   `PATCH assignment-submissions/{id}/grade`（任務指示明定）
 *
 * 資料範圍拆兩組獨立 describe（team-lead 2026-09-22 要求「瘦身 beforeAll」）：
 * - 主課程情境：只建 1 課程 + 2 作業，供「繳交／批改／下載／已逾期／管理員預覽」情境使用
 * - 線性觀看情境：只建 1 課程（2 章節）+ 1 作業，只供「總覽列出鎖定作業」情境使用
 * 兩組各自獨立 beforeAll/afterAll，避免單一 beforeAll 把兩門課程、5 個章節、3 份作業、
 * 1 個學員、3 次授權全部疊在一起，在本機站台的併發負載下超過 hook timeout。
 *
 * 介面契約（test-creator 決定，Green 階段須照做，亦見任務回報第 3 節；
 * 2026-09-22 依 team-lead 裁決更新總覽項目結構，見下方「重要疑義」）：
 * - 區塊根：#pc-classroom-assignments（body.php 插入點，the_content() 之後、留言之前）
 * - 課程作業總覽（可收合，位於區塊頂部，DEC-15）：.pc-assignment-overview
 *   - 收合切換：.pc-assignment-overview__toggle
 *   - 內容區：.pc-assignment-overview__body
 *   - 統計文字：.pc-assignment-overview__stats（內含「作業總數：N」「已通過：N」「通過率：N%」）
 *   - 總覽項目（依章節順序列出「全課程」每一份作業，含尚未解鎖章節的）：
 *     .pc-assignment-overview__item[data-assignment-id]
 *     內含標題／所屬章節／狀態徽章；**不**渲染繳交表單（無 file input／textarea／提交按鈕，
 *     避免放大攻擊面），鎖定時額外顯示鎖定訊息：.pc-assignment-overview__lock-message
 * - 章節下方作業卡片（僅目前章節自己的作業，含完整可互動的繳交表單）：
 *   .pc-assignment-list > .pc-assignment-card[data-assignment-id]
 * - 卡片內部（class 前綴皆為 .pc-assignment-card__）：
 *   - 標題／作業說明／截止時間／狀態徽章（未繳交／待批改／通過／未通過）／附件上限：
 *     皆以可見文字字面值斷言，無專用 class
 *   - overdue：已逾期標記
 *   - feedback：評語
 *   - text-input：文字輸入框（textarea）
 *   - file-input：附件上傳（input[type=file]）
 *   - attachment-link：已上傳附件的下載連結（顯示檔名）
 *   - submit-btn：繳交按鈕（文字「繳交」／「重新繳交」；狀態為通過時整顆按鈕隱藏）
 *
 * 【重要疑義，team-lead 2026-09-22 已裁決，記錄於此供後續讀者對照】
 * 原始問題：inc/templates/single-pc_chapter.php 對非 manage_woocommerce 使用者的鎖定章節
 * 一律 wp_safe_redirect + exit（既有行為，非本波 P8 範圍，018 spec 已驗證），學員到不了
 * 鎖定章節自己的教室頁，UI 規格「鎖定章節的作業表單停用並顯示鎖定訊息」字面上難以觸及。
 *
 * 裁決：
 * 1. 「課程作業總覽」列出每份作業與狀態 ≠ 渲染每份作業的完整繳交表單；總覽項目只含
 *    標題／所屬章節／狀態徽章，不含 file input / textarea / 提交按鈕（把上傳表單複製到
 *    總覽會放大攻擊面，且規格沒有要求）。
 * 2. 「鎖定章節的表單停用＋鎖定訊息」仍是產品要求的防禦性渲染，位置在章節自己的作業
 *    區塊（伺服器端防禦：single-pc_chapter.php 的 redirect 有 get_current_chapter_id()
 *    回傳 falsy 時不導頁的分支）；但這個狀態在正常測試資料下前台不可達，**不**用前台 E2E
 *    驗證，改由 API 層 403（03-integration/016-assignment-http-contract.spec.ts + PHPUnit）
 *    守住。trade-off：這段防禦性渲染本身沒有 E2E 直接覆蓋。
 * 3. 本檔改測「總覽會列出鎖定章節的作業並標示狀態，但不提供繳交入口，且顯示鎖定訊息」
 *    ——這是總覽本身一定會被學員看到（在可造訪的章節頁面上）的行為，不受 redirect 影響。
 *
 * 範圍限制（不在本檔覆蓋，屬其他波次或已由其他測試覆蓋）：
 * - 討論串（第二波，查詢作業討論串.feature／發送作業討論訊息.feature）
 * - 必填文字／必填檔案的前端標示細節（後端驗證已由 PHPUnit／016 spec 覆蓋）
 * - 直連受保護目錄檔案是否被擋（R-04，本機為 Nginx，僅斷言 API 回應不含 url／stored_path）
 * - 章節自己的作業區塊在「鎖定章節防禦性渲染」下的表單停用（見上方裁決 2，改由 API 層守）
 *
 * 預期結果（Green 完成前，本檔全數失敗，且失敗原因必須是「功能不存在」——
 * 找不到 #pc-classroom-assignments / .pc-assignment-card / .pc-assignment-overview__item
 * 等元素，不是登入或資料準備失敗）。
 */
import { test, expect, type Page, type Locator } from '@playwright/test'

import { loginAs } from '../helpers/frontend-setup'
import { setupApiFromBrowser, type ApiClient } from '../helpers/api-client'
import { WP_ADMIN } from '../fixtures/test-data'

test.setTimeout(90_000)

// ── 測試資料 ──────────────────────────────────
const STUDENT = {
	username: 'e2e_classroom_assign_131',
	email: 'e2e_classroom_assign_131@test.local',
	password: 'e2eClassroomAssign131!Pass',
	name: 'E2E教室作業學員',
}

const MAIN_COURSE = {
	name: 'E2E 教室作業區塊課程',
	slug: 'e2e-classroom-assignments-course',
	chapters: [{ name: 'E2E教室作業章節', slug: 'e2e-classroom-assign-chapter' }],
}

const LINEAR_COURSE = {
	name: 'E2E 教室作業線性觀看課程',
	slug: 'e2e-classroom-assign-linear-course',
	chapters: [
		{ name: 'E2E鎖定測試章節一', slug: 'e2e-classroom-lock-ch1' },
		{ name: 'E2E鎖定測試章節二', slug: 'e2e-classroom-lock-ch2' },
	],
}

const ASSIGNMENT1_TITLE = 'E2E教室作業-文字檔案'
const ASSIGNMENT1_INSTRUCTIONS = 'E2E作業說明：請上傳截圖並簡述心得'
const ASSIGNMENT2_TITLE = 'E2E教室作業-已逾期'
const ASSIGNMENT3_TITLE = 'E2E教室作業-鎖定章節'

// 1x1 透明 PNG（比照既有 P7 spec 慣例，finfo 可正確辨識為 image/png）
const PNG_1PX_BASE64 =
	'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='
const ATTACHMENT_BUFFER = Buffer.from(PNG_1PX_BASE64, 'base64')
const ATTACHMENT_NAME = 'E2E教室作業附件.png'
const ATTACHMENT_MIME = 'image/png'

// ── Helper（兩組 describe 共用） ──────────────────

async function gotoClassroom(page: Page, courseSlug: string, chapterSlug: string): Promise<void> {
	await page.goto(`/classroom/${courseSlug}/${chapterSlug}/`)
	await page.waitForLoadState('networkidle')
}

/** 教室作業區塊根 locator（#pc-classroom-assignments） */
function assignmentSection(page: Page): Locator {
	return page.locator('#pc-classroom-assignments')
}

/** 目前章節自己的作業卡片（.pc-assignment-list 內，含完整互動表單） */
function chapterCard(section: Locator, assignmentId: number): Locator {
	return section.locator(`.pc-assignment-list .pc-assignment-card[data-assignment-id="${assignmentId}"]`)
}

/** 課程作業總覽內的單一項目（標題／所屬章節／狀態，不含表單，見檔頭裁決） */
function overviewItem(overviewBody: Locator, assignmentId: number): Locator {
	return overviewBody.locator(`.pc-assignment-overview__item[data-assignment-id="${assignmentId}"]`)
}

/**
 * 確保「課程作業總覽」內容區為展開狀態並回傳其 locator。
 *
 * DEC-15 只要求「可收合」，未指定預設收合／展開狀態，故先探測目前可見性，
 * 不可見時才點擊切換按鈕，避免對未指定的預設狀態做出過度假設。
 */
async function ensureOverviewExpanded(section: Locator): Promise<Locator> {
	const overview = section.locator('.pc-assignment-overview')
	await expect(
		overview,
		'應有課程作業總覽區塊（.pc-assignment-overview），且位於區塊頂部',
	).toBeVisible({ timeout: 15_000 })

	const body = overview.locator('.pc-assignment-overview__body')
	const toggle = overview.locator('.pc-assignment-overview__toggle')
	await expect(
		toggle,
		'課程作業總覽應可收合，需有切換控制（.pc-assignment-overview__toggle）',
	).toBeVisible({ timeout: 10_000 })

	if (!(await body.isVisible())) {
		await toggle.click()
		await expect(body).toBeVisible({ timeout: 5_000 })
	}
	return body
}

// ═════════════════════════════════════════════════════
// 組 A：主課程情境（繳交／批改／下載／已逾期／管理員預覽）
// ═════════════════════════════════════════════════════
test.describe('教室作業區塊（Issue #131 P8）－主課程情境', () => {
	let adminApi: ApiClient
	let disposeAdmin: () => Promise<void>

	let mainCourseId: number
	let mainCourseSlug: string
	let mainChapterSlug: string
	let assignment1Id: number
	let assignment2Id: number
	let submission1Id: number
	let studentId: number

	test.beforeAll(async ({ browser }) => {
		// 本機站台併發負載下，建 1 課程 + 1 章節 + 2 作業 + 1 學員 + 1 次授權仍可能逼近
		// 120s，拆組後只做這組的資料，仍放寬到 240s（team-lead 2026-09-22 要求）。
		test.setTimeout(240_000)

		const setup = await setupApiFromBrowser(browser)
		adminApi = setup.api
		disposeAdmin = setup.dispose

		// 1. 主課程：1 章節、2 份作業（文字+檔案／已逾期）
		const mainCreated = await adminApi.createCourseWithChapters(
			MAIN_COURSE.name,
			'0',
			MAIN_COURSE.chapters,
			MAIN_COURSE.slug,
		)
		mainCourseId = mainCreated.courseId
		mainCourseSlug = mainCreated.courseSlug
		mainChapterSlug = mainCreated.chapterSlugs[0]
		const chapterMainId = mainCreated.chapterIds[0]
		await adminApi.setCourseFree(mainCourseId)

		const a1 = await adminApi.pcPost<{ id: number }>('assignments', {
			chapter_id: chapterMainId,
			title: ASSIGNMENT1_TITLE,
			instructions: ASSIGNMENT1_INSTRUCTIONS,
			allow_text: 'yes',
			allow_file: 'yes',
			max_files: 3,
			due_at: 0,
			menu_order: 0,
		})
		if (a1.status >= 400 || !(a1.data as { id?: number })?.id) {
			throw new Error(`建立測試作業 1 失敗: ${JSON.stringify(a1.data)}`)
		}
		assignment1Id = (a1.data as { id: number }).id

		const pastDueAt = Math.floor(Date.now() / 1000) - 3 * 86400
		const a2 = await adminApi.pcPost<{ id: number }>('assignments', {
			chapter_id: chapterMainId,
			title: ASSIGNMENT2_TITLE,
			instructions: 'E2E作業說明：逾期仍可繳交',
			allow_text: 'yes',
			allow_file: 'no',
			due_at: pastDueAt,
			menu_order: 10,
		})
		if (a2.status >= 400 || !(a2.data as { id?: number })?.id) {
			throw new Error(`建立測試作業 2 失敗: ${JSON.stringify(a2.data)}`)
		}
		assignment2Id = (a2.data as { id: number }).id

		// 2. 學員帳號 + 授權觀看主課程
		studentId = await adminApi.ensureUserViaPowerhouse(
			STUDENT.username,
			STUDENT.email,
			STUDENT.password,
			['subscriber'],
			STUDENT.name,
		)
		await adminApi.grantCourseAccess(studentId, mainCourseId)
	})

	test.afterAll(async () => {
		test.setTimeout(60_000)
		try {
			if (mainCourseId) await adminApi.deleteCourses([mainCourseId])
		} catch {
			/* ignore */
		}
		// 附件實體檔不刪（D9/D16，計劃 §13 限制條件）
		if (studentId) {
			try {
				await adminApi.deleteUserViaPowerhouse(studentId)
			} catch {
				/* ignore */
			}
		}
		if (disposeAdmin) await disposeAdmin()
	})

	// ═════════════════════════════════════════════
	// 主要流程：卡片 → 上傳 → 繳交 → 批改 → 重新載入 → 下載附件 → 總覽正確
	// （有嚴格順序依賴，使用 serial；同批已完成的 P7 spec 之教訓——
	//  只對「真的有狀態依賴」的子集用 serial，其餘情境獨立於外層，
	//  避免一個功能不存在就讓其餘情境的真實紅燈原因被 did not run 蓋掉）
	// ═════════════════════════════════════════════
	test.describe.serial('主要流程：繳交與批改', () => {
		test('學員看到作業卡片，關鍵欄位齊全 @happy @smoke', async ({ page }) => {
			await loginAs(page, STUDENT.username, STUDENT.password)
			await gotoClassroom(page, mainCourseSlug, mainChapterSlug)

			const section = assignmentSection(page)
			await expect(
				section,
				'章節內容下方應有作業區塊（#pc-classroom-assignments），但目前找不到——功能尚未實作',
			).toBeVisible({ timeout: 15_000 })

			const card = chapterCard(section, assignment1Id)
			await expect(card, '應顯示作業卡片').toBeVisible({ timeout: 10_000 })
			await expect(card.getByText(ASSIGNMENT1_TITLE, { exact: true })).toBeVisible()
			await expect(card.getByText(ASSIGNMENT1_INSTRUCTIONS)).toBeVisible()
			await expect(card.getByText('未繳交', { exact: true })).toBeVisible()
			await expect(card.getByText('上限 3 份', { exact: true })).toBeVisible()

			await expect(card.locator('.pc-assignment-card__text-input')).toBeVisible()
			await expect(card.locator('.pc-assignment-card__file-input')).toBeVisible()
			await expect(
				card.locator('.pc-assignment-card__submit-btn').getByText('繳交', { exact: true }),
			).toBeVisible()
		})

		test('上傳檔案並填寫文字後繳交，徽章變待批改、按鈕變重新繳交 @happy', async ({ page }) => {
			await loginAs(page, STUDENT.username, STUDENT.password)
			await gotoClassroom(page, mainCourseSlug, mainChapterSlug)

			const section = assignmentSection(page)
			const card = chapterCard(section, assignment1Id)
			await expect(card).toBeVisible({ timeout: 15_000 })

			const fileInput = card.locator('.pc-assignment-card__file-input')
			const [uploadResp] = await Promise.all([
				page.waitForResponse(
					(r) =>
						r.url().includes('/wp-json/power-course/my/assignment-attachments') &&
						r.request().method() === 'POST' &&
						r.status() < 400,
					{ timeout: 20_000 },
				),
				fileInput.setInputFiles({
					name: ATTACHMENT_NAME,
					mimeType: ATTACHMENT_MIME,
					buffer: ATTACHMENT_BUFFER,
				}),
			])
			const uploadBody = (await uploadResp.json()) as Record<string, unknown>
			expect(uploadBody.url, '上傳附件回應不應包含 url（受保護目錄，DEC-09/R-04）').toBeUndefined()
			expect(
				uploadBody.stored_path,
				'上傳附件回應不應包含 stored_path（受保護目錄，DEC-09/R-04）',
			).toBeUndefined()

			await expect(
				card.locator('.pc-assignment-card__attachment-link').getByText(ATTACHMENT_NAME),
				'上傳後應只顯示檔名',
			).toBeVisible({ timeout: 10_000 })

			await card.locator('.pc-assignment-card__text-input').fill('E2E學員心得：截圖如附件')

			const submitBtn = card.locator('.pc-assignment-card__submit-btn')
			const [submitResp] = await Promise.all([
				page.waitForResponse(
					(r) =>
						r.url().includes(`/wp-json/power-course/my/assignments/${assignment1Id}/submit`) &&
						r.request().method() === 'POST' &&
						r.status() < 400,
					{ timeout: 15_000 },
				),
				submitBtn.click(),
			])
			const submitBody = (await submitResp.json()) as { id?: number }
			expect(submitBody.id, '繳交成功回應應含繳交紀錄 id').toBeTruthy()
			submission1Id = submitBody.id as number

			await expect(
				card.getByText('待批改', { exact: true }),
				'繳交後徽章應變為待批改',
			).toBeVisible({ timeout: 10_000 })
			await expect(
				submitBtn.getByText('重新繳交', { exact: true }),
				'已繳交後按鈕文案應變為重新繳交',
			).toBeVisible({ timeout: 10_000 })
		})

		test('以 API 批改通過後重新載入，徽章變通過、顯示評語、隱藏重新繳交 @happy', async ({ page }) => {
			expect(submission1Id, '前一測試應已取得 submission1Id').toBeTruthy()
			const feedbackText = 'E2E 測試評語：完成度良好'
			const grade = await adminApi.pcPatch(`assignment-submissions/${submission1Id}/grade`, {
				status: 'pass',
				feedback: feedbackText,
			})
			expect(grade.status, `批改 API 失敗: ${JSON.stringify(grade.data)}`).toBeLessThan(400)

			await loginAs(page, STUDENT.username, STUDENT.password)
			await gotoClassroom(page, mainCourseSlug, mainChapterSlug)

			const section = assignmentSection(page)
			const card = chapterCard(section, assignment1Id)
			await expect(card).toBeVisible({ timeout: 15_000 })

			await expect(
				card.getByText('通過', { exact: true }),
				'批改通過後徽章應顯示通過',
			).toBeVisible({ timeout: 10_000 })
			await expect(
				card.locator('.pc-assignment-card__feedback').getByText(feedbackText),
				'應顯示講師評語',
			).toBeVisible({ timeout: 10_000 })
			await expect(
				card.locator('.pc-assignment-card__submit-btn'),
				'狀態為通過時應隱藏重新繳交按鈕',
			).toHaveCount(0)
		})

		test('點自己的附件可下載 @happy', async ({ page }) => {
			await loginAs(page, STUDENT.username, STUDENT.password)
			await gotoClassroom(page, mainCourseSlug, mainChapterSlug)

			const section = assignmentSection(page)
			const card = chapterCard(section, assignment1Id)
			await expect(card).toBeVisible({ timeout: 15_000 })

			const attachmentLink = card
				.locator('.pc-assignment-card__attachment-link')
				.getByText(ATTACHMENT_NAME)
			await expect(attachmentLink, '應顯示可點擊下載的附件連結').toBeVisible({ timeout: 10_000 })

			const downloadPromise = page.waitForEvent('download', { timeout: 15_000 })
			await attachmentLink.click()
			const download = await downloadPromise
			expect(download.suggestedFilename()).toBe(ATTACHMENT_NAME)
		})

		test('課程作業總覽數字正確，位於區塊頂部且可收合，依章節順序列出 @happy', async ({ page }) => {
			await loginAs(page, STUDENT.username, STUDENT.password)
			await gotoClassroom(page, mainCourseSlug, mainChapterSlug)

			const section = assignmentSection(page)
			await expect(section).toBeVisible({ timeout: 15_000 })

			// 總覽必須是區塊內第一個子元素（DEC-15：位於頂部）
			const firstChild = section.locator(':scope > *').first()
			await expect(firstChild).toHaveClass(/pc-assignment-overview/)

			const overviewBody = await ensureOverviewExpanded(section)
			const stats = overviewBody.locator('.pc-assignment-overview__stats')
			// 主課程共 2 份作業（assignment1 已批改通過、assignment2 未繳交）
			await expect(stats.getByText('作業總數：2', { exact: true })).toBeVisible({
				timeout: 10_000,
			})
			await expect(stats.getByText('已通過：1', { exact: true })).toBeVisible()
			await expect(stats.getByText('通過率：50%', { exact: true })).toBeVisible()

			// 依章節順序（menu_order）列出每份作業與狀態
			const item1 = overviewItem(overviewBody, assignment1Id)
			const item2 = overviewItem(overviewBody, assignment2Id)
			await expect(item1.getByText('通過', { exact: true })).toBeVisible()
			await expect(item2.getByText('未繳交', { exact: true })).toBeVisible()

			const overviewTitles = await overviewBody
				.locator('.pc-assignment-overview__item')
				.allTextContents()
			const idx1 = overviewTitles.findIndex((t) => t.includes(ASSIGNMENT1_TITLE))
			const idx2 = overviewTitles.findIndex((t) => t.includes(ASSIGNMENT2_TITLE))
			expect(idx1, `總覽應含 ${ASSIGNMENT1_TITLE}`).toBeGreaterThanOrEqual(0)
			expect(
				idx1,
				`總覽應依 menu_order 排序，${ASSIGNMENT1_TITLE} 應排在 ${ASSIGNMENT2_TITLE} 之前，實際: ${JSON.stringify(overviewTitles)}`,
			).toBeLessThan(idx2)
		})
	})

	// ═════════════════════════════════════════════
	// 獨立情境（各自使用獨立的作業，不依賴上面主要流程的執行順序）
	// ═════════════════════════════════════════════

	test('已過截止時間且未繳交顯示已逾期仍可繳交；批改為未通過時徽章正確 @happy @edge', async ({
		page,
	}) => {
		await loginAs(page, STUDENT.username, STUDENT.password)
		await gotoClassroom(page, mainCourseSlug, mainChapterSlug)

		const section = assignmentSection(page)
		const card = chapterCard(section, assignment2Id)
		await expect(card, '應顯示已逾期的作業卡片').toBeVisible({ timeout: 15_000 })

		await expect(card.getByText('未繳交', { exact: true })).toBeVisible()
		await expect(
			card.locator('.pc-assignment-card__overdue').getByText('已逾期', { exact: true }),
			'超過截止時間且未繳交應標示已逾期',
		).toBeVisible({ timeout: 10_000 })
		await expect(card.getByText(/\d{4}-\d{2}-\d{2}/), '應顯示截止時間').toBeVisible()

		const textInput = card.locator('.pc-assignment-card__text-input')
		const submitBtn = card.locator('.pc-assignment-card__submit-btn')
		await expect(textInput, '已逾期仍應可填寫繳交').not.toBeDisabled()
		await expect(submitBtn, '已逾期仍應可點擊繳交').not.toBeDisabled()

		await textInput.fill('E2E學員補交：已逾期但仍嘗試繳交')
		const [submitResp] = await Promise.all([
			page.waitForResponse(
				(r) =>
					r.url().includes(`/wp-json/power-course/my/assignments/${assignment2Id}/submit`) &&
					r.request().method() === 'POST',
				{ timeout: 15_000 },
			),
			submitBtn.click(),
		])
		expect(submitResp.status(), '已逾期仍應繳交成功（截止時間不阻擋繳交）').toBeLessThan(400)
		const submitBody = (await submitResp.json()) as { id?: number }
		expect(submitBody.id, '繳交成功回應應含 id').toBeTruthy()
		await expect(card.getByText('待批改', { exact: true })).toBeVisible({ timeout: 10_000 })

		// 批改為未通過，驗證第 4 種徽章狀態（未繳交／待批改／通過 已在其他測試涵蓋）
		const gradeFail = await adminApi.pcPatch(`assignment-submissions/${submitBody.id}/grade`, {
			status: 'fail',
			feedback: 'E2E 測試評語：未通過',
		})
		expect(gradeFail.status, `批改為未通過失敗: ${JSON.stringify(gradeFail.data)}`).toBeLessThan(400)

		await page.reload({ waitUntil: 'domcontentloaded' })
		await expect(
			chapterCard(assignmentSection(page), assignment2Id).getByText('未通過', { exact: true }),
			'批改為未通過後徽章應顯示未通過',
		).toBeVisible({ timeout: 10_000 })
	})

	test('管理員預覽模式下作業表單與按鈕全部停用 @happy @security', async ({ page }) => {
		await loginAs(page, WP_ADMIN.username, WP_ADMIN.password)
		await gotoClassroom(page, mainCourseSlug, mainChapterSlug)

		const section = assignmentSection(page)
		const card = chapterCard(section, assignment1Id)
		await expect(card, '管理員預覽時應仍顯示作業卡片（改用管理員端作業列表）').toBeVisible({
			timeout: 15_000,
		})

		await expect(card.locator('.pc-assignment-card__text-input')).toBeDisabled()
		await expect(card.locator('.pc-assignment-card__file-input')).toBeDisabled()
		await expect(card.locator('.pc-assignment-card__submit-btn')).toBeDisabled()
	})
})

// ═════════════════════════════════════════════════════
// 組 B：線性觀看情境（總覽列出鎖定作業，不提供繳交入口）
// ═════════════════════════════════════════════════════
test.describe('教室作業區塊（Issue #131 P8）－線性觀看情境', () => {
	let adminApi: ApiClient
	let disposeAdmin: () => Promise<void>

	let linearCourseId: number
	let linearCourseSlug: string
	let chapterL1Slug: string
	let assignment3Id: number
	let studentId: number

	test.beforeAll(async ({ browser }) => {
		test.setTimeout(240_000)

		const setup = await setupApiFromBrowser(browser)
		adminApi = setup.api
		disposeAdmin = setup.dispose

		// 線性觀看課程：2 章節，第二章掛 1 份作業（用於「總覽列出鎖定作業」情境，見檔頭裁決）
		const linearCreated = await adminApi.createCourseWithChapters(
			LINEAR_COURSE.name,
			'0',
			LINEAR_COURSE.chapters,
			LINEAR_COURSE.slug,
		)
		linearCourseId = linearCreated.courseId
		linearCourseSlug = linearCreated.courseSlug
		const chapterL2Id = linearCreated.chapterIds[1]
		chapterL1Slug = linearCreated.chapterSlugs[0]
		await adminApi.setCourseFree(linearCourseId)
		await adminApi.updateCourse(linearCourseId, { enable_linear_viewing: 'yes' })

		const a3 = await adminApi.pcPost<{ id: number }>('assignments', {
			chapter_id: chapterL2Id,
			title: ASSIGNMENT3_TITLE,
			allow_text: 'yes',
			menu_order: 0,
		})
		if (a3.status >= 400 || !(a3.data as { id?: number })?.id) {
			throw new Error(`建立測試作業 3 失敗: ${JSON.stringify(a3.data)}`)
		}
		assignment3Id = (a3.data as { id: number }).id

		studentId = await adminApi.ensureUserViaPowerhouse(
			STUDENT.username,
			STUDENT.email,
			STUDENT.password,
			['subscriber'],
			STUDENT.name,
		)
		await adminApi.grantCourseAccess(studentId, linearCourseId)
	})

	test.afterAll(async () => {
		test.setTimeout(60_000)
		try {
			if (linearCourseId) await adminApi.deleteCourses([linearCourseId])
		} catch {
			/* ignore */
		}
		if (studentId) {
			try {
				await adminApi.deleteUserViaPowerhouse(studentId)
			} catch {
				/* ignore */
			}
		}
		if (disposeAdmin) await disposeAdmin()
	})

	test('線性觀看鎖定章節的作業於課程作業總覽列出但不提供繳交入口，並提示鎖定訊息 @happy @security', async ({
		page,
	}) => {
		// 見檔頭「重要疑義」（team-lead 2026-09-22 裁決）：鎖定章節本身的教室頁會被
		// single-pc_chapter.php 導頁攔截，學員到不了；改在「學員可正常造訪的章節頁面」
		// （此處為第一章）驗證課程作業總覽內鎖定章節作業的顯示——只列出標題／章節／狀態，
		// 不提供繳交表單。
		await loginAs(page, STUDENT.username, STUDENT.password)
		await gotoClassroom(page, linearCourseSlug, chapterL1Slug)

		const section = assignmentSection(page)
		await expect(section).toBeVisible({ timeout: 15_000 })
		const overviewBody = await ensureOverviewExpanded(section)

		const lockedItem = overviewItem(overviewBody, assignment3Id)
		await expect(
			lockedItem,
			'課程作業總覽應列出鎖定章節的作業（標題＋所屬章節＋狀態）',
		).toBeVisible({ timeout: 10_000 })
		await expect(lockedItem.getByText(ASSIGNMENT3_TITLE, { exact: true })).toBeVisible()
		await expect(lockedItem.getByText(LINEAR_COURSE.chapters[1].name)).toBeVisible()

		// 裁決：總覽只列出標題／章節／狀態，不渲染繳交表單（避免放大攻擊面，規格未要求）
		await expect(
			lockedItem.locator('input[type="file"]'),
			'總覽項目不應提供檔案上傳入口',
		).toHaveCount(0)
		await expect(lockedItem.locator('textarea'), '總覽項目不應提供文字輸入框').toHaveCount(0)
		await expect(
			lockedItem.getByRole('button', { name: /繳交/ }),
			'總覽項目不應提供繳交按鈕',
		).toHaveCount(0)

		await expect(
			lockedItem.locator('.pc-assignment-overview__lock-message'),
			'鎖定章節的作業應顯示鎖定訊息',
		).toContainText('此章節尚未解鎖，請先完成前面的章節')
	})
})
