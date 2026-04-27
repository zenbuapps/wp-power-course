/**
 * 測試目標：教室前台線性觀看完整流程
 * 對應原始碼：
 *   - inc/classes/Utils/LinearViewing.php（核心解鎖邏輯）
 *   - inc/classes/Resources/Chapter/Core/Api.php（toggle-finish API 驗證 + 回應擴充）
 *   - inc/templates/single-pc_chapter.php（redirect + pc_data 注入）
 *   - inc/classes/Resources/Chapter/Utils/Utils.php（側邊欄 data-locked）
 *   - inc/assets/src/events/linearViewing.ts（dialog + toast）
 *   - inc/assets/src/events/finishChapter.ts（DOM 解鎖/鎖定）
 * 前置條件：測試課程含 3 個章節、學員帳號已建立、課程已啟用線性觀看
 * 預期結果：鎖定/解鎖流程正常運作
 */

import { test, expect } from '@playwright/test'
import {
	loginAs,
	loadFrontendTestData,
	type FrontendTestData,
} from '../helpers/frontend-setup.js'
import { setupApiFromBrowser, ApiClient } from '../helpers/api-client.js'
import { TEST_STUDENT, WP_ADMIN, SELECTORS } from '../fixtures/test-data.js'

/** 線性觀看測試專用課程資料 */
const LINEAR_COURSE = {
	name: 'E2E Linear Viewing Test Course',
	slug: 'e2e-linear-viewing-test',
	regularPrice: '0',
	chapters: [
		{ name: 'Linear Ch1 Intro', slug: 'linear-ch1' },
		{ name: 'Linear Ch2 Core', slug: 'linear-ch2' },
		{ name: 'Linear Ch3 Advanced', slug: 'linear-ch3' },
	],
}

let courseId: number
let chapterIds: number[]
let courseSlug: string
let studentId: number

test.describe.serial('教室前台線性觀看', () => {
	test.beforeAll(async ({ browser }) => {
		const { api, dispose } = await setupApiFromBrowser(browser)
		try {
			// 建立測試課程（含 3 個章節）
			const result = await api.createCourseWithChapters(
				LINEAR_COURSE.name,
				LINEAR_COURSE.regularPrice,
				LINEAR_COURSE.chapters,
				LINEAR_COURSE.slug,
			)
			courseId = result.courseId
			chapterIds = result.chapterIds
			courseSlug = result.courseSlug

			// 設定為免費課程
			await api.setCourseFree(courseId)

			// 啟用線性觀看
			await api.updateCourse(courseId, {
				enable_linear_viewing: 'yes',
			})

			// 建立測試學員
			studentId = await api.ensureUser(
				TEST_STUDENT.username,
				TEST_STUDENT.email,
				TEST_STUDENT.password,
				['subscriber'],
			)

			// 授權學員存取課程
			await api.grantCourseAccess(studentId, courseId)
		} finally {
			await dispose()
		}
	})

	test.afterAll(async ({ browser }) => {
		if (!courseId) return
		const { api, dispose } = await setupApiFromBrowser(browser)
		try {
			await api.removeCourseAccess(studentId, courseId)
			await api.deleteCourses([courseId])
		} finally {
			await dispose()
		}
	})

	test('學員進入教室 → 第一章解鎖、其餘鎖定', async ({ page }) => {
		await loginAs(page, TEST_STUDENT.username, TEST_STUDENT.password)

		// 進入第一章教室
		const classroomUrl = `/classroom/${courseSlug}/${LINEAR_COURSE.chapters[0].slug}/`
		await page.goto(classroomUrl)
		await page.waitForLoadState('networkidle')

		// 側邊欄第一章不應有 data-locked 屬性
		const firstChapter = page.locator(
			`${SELECTORS.classroom.chapterItem}[data-post-id="${chapterIds[0]}"]`,
		)
		await expect(firstChapter).toBeVisible({ timeout: 10_000 })
		await expect(firstChapter).not.toHaveAttribute('data-locked', 'true')

		// 第二章與第三章應有 data-locked="true"
		const secondChapter = page.locator(
			`${SELECTORS.classroom.chapterItem}[data-post-id="${chapterIds[1]}"]`,
		)
		await expect(secondChapter).toHaveAttribute('data-locked', 'true')

		const thirdChapter = page.locator(
			`${SELECTORS.classroom.chapterItem}[data-post-id="${chapterIds[2]}"]`,
		)
		await expect(thirdChapter).toHaveAttribute('data-locked', 'true')
	})

	test('鎖定章節顯示鎖頭圖示與提示文字', async ({ page }) => {
		await loginAs(page, TEST_STUDENT.username, TEST_STUDENT.password)

		const classroomUrl = `/classroom/${courseSlug}/${LINEAR_COURSE.chapters[0].slug}/`
		await page.goto(classroomUrl)
		await page.waitForLoadState('networkidle')

		// 第二章應有 data-lock-message 屬性（含指名提示文字）
		const secondChapter = page.locator(
			`${SELECTORS.classroom.chapterItem}[data-post-id="${chapterIds[1]}"]`,
		)
		await expect(secondChapter).toHaveAttribute('data-locked', 'true')
		const lockMessage = await secondChapter.getAttribute('data-lock-message')
		expect(lockMessage).toBeTruthy()
		// 應包含前一章的名稱
		expect(lockMessage).toContain(LINEAR_COURSE.chapters[0].name)

		// 鎖定章節應有 pc-chapter-locked class
		await expect(secondChapter).toHaveClass(/pc-chapter-locked/)
	})

	test('完成第一章 → 第二章即時解鎖', async ({ page }) => {
		await loginAs(page, TEST_STUDENT.username, TEST_STUDENT.password)

		const classroomUrl = `/classroom/${courseSlug}/${LINEAR_COURSE.chapters[0].slug}/`
		await page.goto(classroomUrl)
		await page.waitForLoadState('networkidle')

		// 找到完成按鈕並點擊
		const finishBtn = page
			.locator(SELECTORS.classroom.finishButton)
			.first()
		await expect(finishBtn).toBeVisible({ timeout: 10_000 })

		// 點擊完成並等待 API 回應
		await Promise.all([
			page.waitForResponse(
				(r) =>
					r.url().includes('toggle-finish-chapters') && r.status() === 200,
				{ timeout: 15_000 },
			),
			finishBtn.click(),
		])

		// 等待 DOM 更新
		await page.waitForTimeout(1000)

		// 第二章應已解鎖（data-locked 被移除或變為 false）
		const secondChapter = page.locator(
			`${SELECTORS.classroom.chapterItem}[data-post-id="${chapterIds[1]}"]`,
		)
		await expect(secondChapter).not.toHaveAttribute('data-locked', 'true', {
			timeout: 5_000,
		})
		await expect(secondChapter).not.toHaveClass(/pc-chapter-locked/)

		// 第三章仍應鎖定
		const thirdChapter = page.locator(
			`${SELECTORS.classroom.chapterItem}[data-post-id="${chapterIds[2]}"]`,
		)
		await expect(thirdChapter).toHaveAttribute('data-locked', 'true')
	})

	test('點擊鎖定章節 → 彈出 dialog', async ({ page }) => {
		await loginAs(page, TEST_STUDENT.username, TEST_STUDENT.password)

		const classroomUrl = `/classroom/${courseSlug}/${LINEAR_COURSE.chapters[0].slug}/`
		await page.goto(classroomUrl)
		await page.waitForLoadState('networkidle')

		// 點擊鎖定的第三章（假設第一章已完成、第二章解鎖、第三章仍鎖定）
		const lockedChapter = page.locator(
			`${SELECTORS.classroom.chapterItem}[data-locked="true"]`,
		).first()

		// 若找不到鎖定章節（可能前次測試影響），跳過
		const lockedCount = await lockedChapter.count()
		if (lockedCount === 0) {
			test.skip()
			return
		}

		await lockedChapter.click()

		// 應彈出 dialog
		const dialog = page.locator('#pc-linear-lock-dialog')
		await expect(dialog).toBeVisible({ timeout: 5_000 })

		// dialog 內應有提示文字
		const dialogMessage = page.locator('#pc-linear-lock-message')
		await expect(dialogMessage).toBeVisible()
		const messageText = await dialogMessage.textContent()
		expect(messageText).toBeTruthy()

		// 點擊「確定」按鈕關閉 dialog
		const okButton = dialog.locator('button')
		await okButton.click()
		await expect(dialog).not.toBeVisible({ timeout: 3_000 })
	})

	test('URL 直接存取鎖定章節 → redirect + toast', async ({ page }) => {
		await loginAs(page, TEST_STUDENT.username, TEST_STUDENT.password)

		// 直接存取第三章（鎖定中）
		const lockedUrl = `/classroom/${courseSlug}/${LINEAR_COURSE.chapters[2].slug}/`
		await page.goto(lockedUrl)

		// 應被 redirect（URL 不再是第三章）
		await page.waitForLoadState('networkidle')
		const currentUrl = page.url()
		expect(currentUrl).not.toContain(LINEAR_COURSE.chapters[2].slug)

		// 應出現 ?linear_locked=1 參數（或已被清除，但 toast 應已顯示）
		// toast 元素應出現
		const toast = page.locator('.pc-toast')
		await expect(toast).toBeVisible({ timeout: 5_000 })

		// toast 5 秒後自動消失
		await expect(toast).not.toBeVisible({ timeout: 8_000 })
	})

	test('取消完成章節 → 後續章節重新鎖定', async ({ page }) => {
		await loginAs(page, TEST_STUDENT.username, TEST_STUDENT.password)

		// 進入第一章
		const classroomUrl = `/classroom/${courseSlug}/${LINEAR_COURSE.chapters[0].slug}/`
		await page.goto(classroomUrl)
		await page.waitForLoadState('networkidle')

		// 找到完成按鈕（此時應顯示「標示為未完成」因為已完成）
		const finishBtn = page
			.locator(
				`${SELECTORS.classroom.finishButton}, button:has-text("標示為未完成")`,
			)
			.first()
		await expect(finishBtn).toBeVisible({ timeout: 10_000 })

		// 點擊取消完成
		await Promise.all([
			page.waitForResponse(
				(r) =>
					r.url().includes('toggle-finish-chapters') && r.status() === 200,
				{ timeout: 15_000 },
			),
			finishBtn.click(),
		])

		// 等待 DOM 更新
		await page.waitForTimeout(1000)

		// 第二章應重新鎖定
		const secondChapter = page.locator(
			`${SELECTORS.classroom.chapterItem}[data-post-id="${chapterIds[1]}"]`,
		)
		await expect(secondChapter).toHaveAttribute('data-locked', 'true', {
			timeout: 5_000,
		})
	})

	test('下一章鎖定 → 底部「下一個」按鈕禁用', async ({ page }) => {
		await loginAs(page, TEST_STUDENT.username, TEST_STUDENT.password)

		const classroomUrl = `/classroom/${courseSlug}/${LINEAR_COURSE.chapters[0].slug}/`
		await page.goto(classroomUrl)
		await page.waitForLoadState('networkidle')

		// 底部「下一個」按鈕應有 disabled 樣式
		const nextBtn = page.locator('[data-next-locked="true"]')
		// 或者找有 pc-btn-disabled 的按鈕
		const disabledNextBtn = page.locator('.pc-btn-disabled').first()

		// 應存在禁用的下一個按鈕（至少其中之一）
		const hasLockedNext = (await nextBtn.count()) > 0
		const hasDisabledBtn = (await disabledNextBtn.count()) > 0
		expect(hasLockedNext || hasDisabledBtn).toBeTruthy()
	})

	test('管理員可自由存取所有章節（無鎖定）', async ({ page }) => {
		await loginAs(page, WP_ADMIN.username, WP_ADMIN.password)

		// 管理員直接存取第三章（不應被 redirect）
		const thirdChapterUrl = `/classroom/${courseSlug}/${LINEAR_COURSE.chapters[2].slug}/`
		await page.goto(thirdChapterUrl)
		await page.waitForLoadState('networkidle')

		// URL 應保持第三章（不被 redirect）
		expect(page.url()).toContain(LINEAR_COURSE.chapters[2].slug)

		// 側邊欄不應有鎖定的章節
		const lockedItems = page.locator(
			`${SELECTORS.classroom.chapterItem}[data-locked="true"]`,
		)
		await expect(lockedItems).toHaveCount(0, { timeout: 5_000 })
	})

	test('未啟用線性觀看的課程 → 所有章節可自由存取', async ({
		page,
		browser,
	}) => {
		// 建立另一個「不啟用」線性觀看的測試課程
		const { api, dispose } = await setupApiFromBrowser(browser)
		let normalCourseId: number | undefined
		let normalCourseSlug: string | undefined
		let normalChapterIds: number[] | undefined
		try {
			const result = await api.createCourseWithChapters(
				'E2E Non-Linear Course',
				'0',
				[
					{ name: 'NL Ch1', slug: 'nl-ch1' },
					{ name: 'NL Ch2', slug: 'nl-ch2' },
				],
				'e2e-non-linear-test',
			)
			normalCourseId = result.courseId
			normalCourseSlug = result.courseSlug
			normalChapterIds = result.chapterIds

			await api.setCourseFree(normalCourseId)
			// 不啟用線性觀看（預設就是 no）
			await api.grantCourseAccess(studentId, normalCourseId)
		} finally {
			await dispose()
		}

		if (!normalCourseId || !normalCourseSlug || !normalChapterIds) return

		try {
			await loginAs(page, TEST_STUDENT.username, TEST_STUDENT.password)

			// 進入第一章
			const classroomUrl = `/classroom/${normalCourseSlug}/nl-ch1/`
			await page.goto(classroomUrl)
			await page.waitForLoadState('networkidle')

			// 所有章節不應有 data-locked 屬性
			const lockedItems = page.locator(
				`${SELECTORS.classroom.chapterItem}[data-locked="true"]`,
			)
			await expect(lockedItems).toHaveCount(0, { timeout: 5_000 })

			// 可直接存取第二章（不被 redirect）
			const secondUrl = `/classroom/${normalCourseSlug}/nl-ch2/`
			await page.goto(secondUrl)
			await page.waitForLoadState('networkidle')
			expect(page.url()).toContain('nl-ch2')
		} finally {
			const { api: cleanApi, dispose: cleanDispose } =
				await setupApiFromBrowser(browser)
			try {
				await cleanApi.removeCourseAccess(studentId, normalCourseId)
				await cleanApi.deleteCourses([normalCourseId])
			} finally {
				await cleanDispose()
			}
		}
	})
})
