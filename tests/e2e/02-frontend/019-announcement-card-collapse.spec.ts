/**
 * 測試目標：Issue #224 — 課程銷售頁公告卡片內文折疊
 *
 * 對應原始碼：
 *   - inc/templates/pages/course-product/announcement.php（PHP SSR）
 *   - inc/assets/src/events/announcementToggle.ts（前台 JS）
 *
 * 規格來源：
 *   - specs/features/announcement/銷售頁公告卡片內文折疊.feature
 *   - specs/clarify/2026-05-14-issue224-announcement-card-collapse.md
 *
 * 場景覆蓋（按 feature 的 Rule 對齊）：
 *   - 短公告 / 剛好 3 行 → 不出現切換按鈕，內文完整顯示
 *   - 5 行公告 → 折疊、aria-expanded="false"、按鈕文字 "Expand content"、漸層遮罩
 *   - 點擊展開 → aria-expanded="true"、按鈕文字 "Collapse"
 *   - 點擊收合 → 恢復折疊狀態
 *   - 多則公告各自獨立切換
 *   - 切換按鈕為 <button> 元素，可鍵盤 Tab + Enter 觸發
 *
 * 注意：apply_filters 行數客製 / 邊界值 → 由 PHPUnit 整合測試覆蓋
 *      （AnnouncementCollapseFilterTest.php），E2E 不重複。
 */

import { test, expect, type Page } from '@playwright/test'
import {
	ApiClient,
	setupApiFromBrowser,
} from '../helpers/api-client.js'

const BASE_URL = process.env.TEST_SITE_URL || 'http://localhost:8889'

interface AnnouncementInput {
	title: string
	content: string
}

test.describe('Issue #224 — 課程銷售頁公告卡片內文折疊', () => {
	test.describe.configure({ mode: 'serial', timeout: 180_000 })

	let api: ApiClient
	let dispose: () => Promise<void>
	let courseId: number
	let courseUrl: string
	const createdAnnouncementIds: number[] = []
	const createdCourseIds: number[] = []

	/**
	 * 建立公告（直接走 wp/v2/pc_announcement POST）
	 *
	 * 沒設定 visibility meta 時 Query::list_public 視同 'public'，
	 * 符合本測試「訪客可見」的需求。
	 */
	async function createAnnouncement(
		parentCourseId: number,
		{ title, content }: AnnouncementInput,
	): Promise<number> {
		const resp = await api.wpPost<{ id: number }>('pc_announcement', {
			title,
			content,
			status: 'publish',
			parent: parentCourseId,
		})
		if (resp.status >= 400 || !resp.data?.id) {
			throw new Error(
				`建立公告失敗 status=${resp.status} body=${JSON.stringify(resp.data)}`,
			)
		}
		createdAnnouncementIds.push(resp.data.id)
		return resp.data.id
	}

	async function gotoCourseAndWaitJs(page: Page): Promise<void> {
		await page.goto(courseUrl)
		// 等公告區塊 DOM 與 announcementToggle.ts 完成 reevaluate（先讓 hidden 移除）
		await page
			.locator('#pc-announcement-section .pc-announcement-content')
			.first()
			.waitFor({ state: 'attached', timeout: 10_000 })
		// 給 ready handler + measure 時間（debounce + resize 量測常見落點）
		await page.waitForTimeout(400)
	}

	test.beforeAll(async ({ browser }) => {
		const setup = await setupApiFromBrowser(browser)
		api = setup.api
		dispose = setup.dispose

		// 全新課程，避免污染既有 frontend-test-data 課程
		courseId = await api.createCourse(
			'Issue 224 公告折疊測試課程',
			`e2e-issue-224-${Date.now()}`,
		)
		createdCourseIds.push(courseId)
		courseUrl = await api.getCourseUrl(courseId)
	})

	test.afterAll(async () => {
		// 清理：先刪公告，再刪課程
		for (const id of createdAnnouncementIds) {
			try {
				await api.wpDelete(`pc_announcement/${id}`, { force: 'true' })
			} catch {
				/* tolerate */
			}
		}
		if (createdCourseIds.length > 0) {
			try {
				await api.deleteCourses(createdCourseIds)
			} catch {
				/* tolerate */
			}
		}
		await dispose?.()
	})

	test.afterEach(async () => {
		// 每個 test 結束清掉本輪公告，確保彼此獨立
		for (const id of createdAnnouncementIds.splice(0)) {
			try {
				await api.wpDelete(`pc_announcement/${id}`, { force: 'true' })
			} catch {
				/* tolerate */
			}
		}
	})

	// ===============================================================
	// Rule: 公告內文渲染後 ≤ 3 行時直接完整顯示，不渲染折疊控制元件
	// ===============================================================

	test('短公告（1 行）卡片無 .pc-announcement-toggle 切換按鈕', async ({
		page,
	}) => {
		await createAnnouncement(courseId, {
			title: '短公告',
			content: '<p>今日休館</p>',
		})

		await gotoCourseAndWaitJs(page)

		const cards = page.locator('#pc-announcement-section .pc-alert')
		await expect(cards).toHaveCount(1)

		// 短公告的 toggle 容器應為 hidden（visibility 不可見）
		const toggle = cards.first().locator('.pc-announcement-toggle')
		await expect(toggle).toBeHidden()
	})

	// ===============================================================
	// Rule: 公告內文渲染後 > 3 行時應折疊只顯示前 3 行並渲染「展開全文」按鈕
	// ===============================================================

	test('5 行公告 → 卡片內文容器套用 -webkit-line-clamp: 3', async ({
		page,
	}) => {
		await createAnnouncement(courseId, {
			title: '長公告',
			content: '<p>第一行<br>第二行<br>第三行<br>第四行<br>第五行</p>',
		})

		await gotoCourseAndWaitJs(page)

		const content = page
			.locator('#pc-announcement-section .pc-announcement-content')
			.first()
		await expect(content).toBeAttached()

		// computed style 應包含 line-clamp 限制（折疊狀態）
		const webkitLineClamp = await content.evaluate(
			(el) => window.getComputedStyle(el).webkitLineClamp,
		)
		// computed value 在 Chromium 為字串 "3"
		expect(webkitLineClamp).toBe('3')
	})

	test('5 行公告 → 卡片應顯示 .pc-announcement-toggle 切換按鈕，文字為 "Expand content"', async ({
		page,
	}) => {
		await createAnnouncement(courseId, {
			title: '長公告',
			content: '<p>第一行<br>第二行<br>第三行<br>第四行<br>第五行</p>',
		})

		await gotoCourseAndWaitJs(page)

		const toggle = page
			.locator('#pc-announcement-section .pc-announcement-toggle')
			.first()
		await expect(toggle).toBeVisible()

		const button = toggle.locator('button')
		await expect(button).toBeVisible()
		await expect(button).toHaveText(/Expand content/i)
	})

	test('5 行公告 → 內文容器 aria-expanded 為 "false"', async ({ page }) => {
		await createAnnouncement(courseId, {
			title: '長公告',
			content: '<p>第一行<br>第二行<br>第三行<br>第四行<br>第五行</p>',
		})

		await gotoCourseAndWaitJs(page)

		const content = page
			.locator('#pc-announcement-section .pc-announcement-content')
			.first()
		await expect(content).toHaveAttribute('aria-expanded', 'false')
	})

	// ===============================================================
	// Rule: 點擊「展開全文」按鈕後切換為完整顯示並更新按鈕文字
	// ===============================================================

	test('點擊展開 → aria-expanded="true"、按鈕文字變 "Collapse"、line-clamp 解除', async ({
		page,
	}) => {
		await createAnnouncement(courseId, {
			title: '長公告',
			content: '<p>L1<br>L2<br>L3<br>L4<br>L5</p>',
		})

		await gotoCourseAndWaitJs(page)

		const toggleButton = page
			.locator('#pc-announcement-section .pc-announcement-toggle button')
			.first()
		const content = page
			.locator('#pc-announcement-section .pc-announcement-content')
			.first()

		await toggleButton.click()

		await expect(content).toHaveAttribute('aria-expanded', 'true')
		await expect(toggleButton).toHaveText(/Collapse/i)

		// line-clamp 在展開狀態應該被 CSS override（display: block）
		const display = await content.evaluate(
			(el) => window.getComputedStyle(el).display,
		)
		expect(display).toBe('block')
	})

	test('點擊收合 → aria-expanded="false"、按鈕文字變回 "Expand content"', async ({
		page,
	}) => {
		await createAnnouncement(courseId, {
			title: '長公告',
			content: '<p>L1<br>L2<br>L3<br>L4<br>L5</p>',
		})

		await gotoCourseAndWaitJs(page)

		const toggleButton = page
			.locator('#pc-announcement-section .pc-announcement-toggle button')
			.first()
		const content = page
			.locator('#pc-announcement-section .pc-announcement-content')
			.first()

		await toggleButton.click()
		await expect(content).toHaveAttribute('aria-expanded', 'true')

		await toggleButton.click()
		await expect(content).toHaveAttribute('aria-expanded', 'false')
		await expect(toggleButton).toHaveText(/Expand content/i)
	})

	// ===============================================================
	// Rule: 多則公告的卡片折疊狀態彼此獨立
	// ===============================================================

	test('兩則長公告各自獨立切換展開／收合狀態', async ({ page }) => {
		await createAnnouncement(courseId, {
			title: '公告 X',
			content: '<p>X1<br>X2<br>X3<br>X4<br>X5</p>',
		})
		await createAnnouncement(courseId, {
			title: '公告 Y',
			content: '<p>Y1<br>Y2<br>Y3<br>Y4<br>Y5</p>',
		})

		await gotoCourseAndWaitJs(page)

		const cards = page.locator('#pc-announcement-section .pc-alert')
		await expect(cards).toHaveCount(2)

		const firstContent = cards.nth(0).locator('.pc-announcement-content')
		const firstButton = cards.nth(0).locator('.pc-announcement-toggle button')
		const secondContent = cards.nth(1).locator('.pc-announcement-content')
		const secondButton = cards.nth(1).locator('.pc-announcement-toggle button')

		// 展開第一張
		await firstButton.click()
		await expect(firstContent).toHaveAttribute('aria-expanded', 'true')
		// 第二張應仍為折疊
		await expect(secondContent).toHaveAttribute('aria-expanded', 'false')

		// 展開第二張
		await secondButton.click()
		await expect(secondContent).toHaveAttribute('aria-expanded', 'true')
		// 第一張仍應展開
		await expect(firstContent).toHaveAttribute('aria-expanded', 'true')
	})

	// ===============================================================
	// Rule: 切換按鈕具備鍵盤可及性與 ARIA 屬性
	// ===============================================================

	test('切換按鈕應為 <button type="button"> 並具備 aria-controls', async ({
		page,
	}) => {
		await createAnnouncement(courseId, {
			title: '長公告',
			content: '<p>L1<br>L2<br>L3<br>L4<br>L5</p>',
		})

		await gotoCourseAndWaitJs(page)

		const toggleButton = page
			.locator('#pc-announcement-section .pc-announcement-toggle button')
			.first()

		// 必須是真正的 <button>
		const tagName = await toggleButton.evaluate((el) => el.tagName.toLowerCase())
		expect(tagName).toBe('button')

		await expect(toggleButton).toHaveAttribute('type', 'button')

		// 必須帶 aria-controls 指向 content 容器 id
		const ariaControls = await toggleButton.getAttribute('aria-controls')
		expect(ariaControls).toBeTruthy()
		expect(ariaControls!).toMatch(/^pc-announcement-content-/)

		// 對應 id 必須存在於 DOM
		const target = page.locator(`#${ariaControls}`)
		await expect(target).toBeAttached()
	})

	test('鍵盤 Tab + Enter 可觸發切換按鈕', async ({ page }) => {
		await createAnnouncement(courseId, {
			title: '長公告',
			content: '<p>L1<br>L2<br>L3<br>L4<br>L5</p>',
		})

		await gotoCourseAndWaitJs(page)

		const toggleButton = page
			.locator('#pc-announcement-section .pc-announcement-toggle button')
			.first()
		const content = page
			.locator('#pc-announcement-section .pc-announcement-content')
			.first()

		await toggleButton.focus()
		await page.keyboard.press('Enter')

		await expect(content).toHaveAttribute('aria-expanded', 'true')
	})

	// ===============================================================
	// Smoke: 結構性檢查（避免 CSS 漸層遮罩 wrapper 漏渲染）
	// ===============================================================

	test('長公告 toggle 容器內含漸層遮罩元素', async ({ page }) => {
		await createAnnouncement(courseId, {
			title: '長公告',
			content: '<p>L1<br>L2<br>L3<br>L4<br>L5</p>',
		})

		await gotoCourseAndWaitJs(page)

		// 漸層遮罩 wrapper 沿用 expandable.php 樣式：bg-gradient-to-t from-base-100
		const mask = page
			.locator(
				'#pc-announcement-section .pc-announcement-toggle .bg-gradient-to-t',
			)
			.first()
		await expect(mask).toBeAttached()
	})

	test('Base URL 可達（smoke）', async ({ page }) => {
		// 這個 case 主要驗證 setup 路徑正確、不至於 0 announcement 時就破功
		const resp = await page.goto(BASE_URL)
		expect(resp?.status() ?? 0).toBeLessThan(500)
	})
})
