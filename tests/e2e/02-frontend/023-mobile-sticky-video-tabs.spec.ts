/**
 * 測試目標：課程銷售頁「行動版影片與分頁吸附」
 * 對應原始碼：
 * - inc/assets/src/events/coursesProduct.ts（吸附定位）
 * - inc/assets/src/events/tabs.ts（點分頁後的捲動補償）
 * - inc/classes/Resources/Settings/Model/Settings.php（吸附設定）
 *
 * 涵蓋 issue：
 * - #277：吸附斷點要與 Tailwind md:（min-width: 810px）一致 → 810px 不吸附、809px 吸附
 * - #279：吸附狀態下點分頁，新分頁內容頂端不可被吸附列遮住；桌機維持不捲動
 * - #278：fix_video_and_tabs_mobile_target 可只吸附分頁（tabs_only）或只吸附影片（video_only）
 *
 * 設定是全站共用的：本專案 workers = 1，測試依序執行；每支會改吸附對象的測試都自己設定，
 * 不依賴前一支的結果。刻意不用 serial 模式——serial 遇到第一支紅燈就跳過其餘測試，
 * 看不到每個 issue 各自的紅綠。某支失敗時 worker 重啟會重跑 beforeAll / afterAll，
 * afterAll 一律還原原始設定並刪除測試課程。
 */

import { test, expect, type Page } from '@playwright/test'
import { setupApiFromBrowser, type ApiClient } from '../helpers/api-client'

const VIDEO = '#courses-product__feature-video'
const TABS_NAV = '#courses-product__tabs-nav'
const TAB_NAV_DESCRIPTION = '#tab-nav-description'
const TAB_CONTENT_DESCRIPTION = '#tab-content-description'

/** 測試用的黏性偏移距離（px），刻意不用 0，才分得出 top 有沒有正確套用 */
const HEADER_OFFSET = 20

/** 要變更的全站設定欄位，afterAll 會逐一還原 */
const SETTING_KEYS = [
	'fix_video_and_tabs_mobile',
	'fix_video_and_tabs_mobile_target',
	'pc_header_offset',
] as const

type TSettingKey = (typeof SETTING_KEYS)[number]

/** GET /settings 回傳格式 */
interface SettingsGetResponse {
	data: Record<string, unknown>
}

/** 產生夠長的課程描述，讓頁面可以捲進分頁內容深處 */
const LONG_DESCRIPTION = Array.from(
	{ length: 80 },
	(_, i) =>
		`<p>E2E 吸附測試段落 ${i + 1}：這是一段用來把銷售頁撐長的課程描述文字，讓分頁列有機會進入吸附狀態。</p>`,
).join('')

/** 加上 cache buster，避開站台的頁面快取（本機站有 WP Rocket） */
const fresh = (url: string) =>
	`${url}${url.includes('?') ? '&' : '?'}e2e=${Date.now()}`

/** 讀取元素的 computed position */
const getPosition = (page: Page, selector: string) =>
	page.locator(selector).evaluate((el) => getComputedStyle(el).position)

test.describe('行動版影片與分頁吸附', () => {
	test.use({
		storageState: '.auth/admin.json',
		actionTimeout: 45_000,
		navigationTimeout: 90_000,
	})
	test.describe.configure({ timeout: 240_000 })

	let courseId: number
	let courseUrl: string
	const originalSettings: Partial<Record<TSettingKey, unknown>> = {}

	/** 以 JSON 更新全站設定 */
	const updateSettings = async (
		api: ApiClient,
		data: Partial<Record<TSettingKey, string>>,
	) => {
		const resp = await api.pcPost('settings', data)
		expect(resp.status, '更新設定應成功').toBe(200)
	}

	/** 在 beforeAll 以外的地方臨時建立 API client 改設定 */
	const withApi = async (
		browser: import('@playwright/test').Browser,
		fn: (api: ApiClient) => Promise<void>,
	) => {
		const { api, dispose } = await setupApiFromBrowser(browser)
		try {
			await fn(api)
		} finally {
			await dispose()
		}
	}

	/** 以指定寬度開啟課程銷售頁，並等前台 script 初始化完成 */
	const openCourse = async (page: Page, width: number) => {
		await page.setViewportSize({ width, height: 800 })
		await page.goto(fresh(courseUrl), { waitUntil: 'load' })
		await expect(page.locator(TABS_NAV), '分頁列應渲染').toBeVisible()
		await page.waitForFunction(() => !!(window as any).pc_data)
	}

	/** 捲到描述分頁內容深處（讓影片、分頁列都超過吸附門檻），等 throttle 跑完 */
	const scrollDeepIntoContent = async (page: Page) => {
		await page.evaluate((selector) => {
			const el = document.querySelector(selector)
			if (!el) throw new Error(`${selector} 不存在`)
			const top = el.getBoundingClientRect().top + window.scrollY
			window.scrollTo(0, top + 1500)
		}, TAB_CONTENT_DESCRIPTION)
		await page.waitForTimeout(600)
	}

	test.beforeAll(async ({ browser }) => {
		test.setTimeout(240_000)

		await withApi(browser, async (api) => {
			const resp = await api.pcGet<SettingsGetResponse>('settings')
			const data = resp.data.data
			for (const key of SETTING_KEYS) {
				originalSettings[key] = data[key]
			}

			courseId = await api.createCourse('E2E 行動版吸附課程')
			await api.updateCourse(courseId, {
				type: 'simple',
				status: 'publish',
				regular_price: '1000',
			})
			await api.wcPost(`products/${courseId}`, {
				description: LONG_DESCRIPTION,
			})
			courseUrl = await api.getCourseUrl(courseId)

			await updateSettings(api, {
				fix_video_and_tabs_mobile: 'yes',
				fix_video_and_tabs_mobile_target: 'both',
				pc_header_offset: String(HEADER_OFFSET),
			})
		})
	})

	test.afterAll(async ({ browser }) => {
		test.setTimeout(120_000)

		await withApi(browser, async (api) => {
			await api.pcPost('settings', {
				fix_video_and_tabs_mobile:
					originalSettings.fix_video_and_tabs_mobile ?? 'no',
				// 舊版站台沒有這個欄位時，還原成預設值 both
				fix_video_and_tabs_mobile_target:
					originalSettings.fix_video_and_tabs_mobile_target ?? 'both',
				pc_header_offset: String(originalSettings.pc_header_offset ?? '0'),
			})
			if (courseId) {
				await api.deleteCourses([courseId])
			}
		})
	})

	/* ------------------------------------------------------------------ */
	/*  #277：斷點與 Tailwind md:（min-width: 810px）一致                   */
	/* ------------------------------------------------------------------ */

	test('#277 寬度 810px（已是桌機兩欄）時不吸附', async ({ page }) => {
		await openCourse(page, 810)
		await scrollDeepIntoContent(page)

		expect(await getPosition(page, VIDEO), '影片不應 fixed').not.toBe('fixed')
		expect(await getPosition(page, TABS_NAV), '分頁列不應 fixed').not.toBe(
			'fixed',
		)
	})

	test('#277 寬度 809px（行動版版面）時影片與分頁都吸附', async ({ page }) => {
		await openCourse(page, 809)
		await scrollDeepIntoContent(page)

		expect(await getPosition(page, VIDEO), '影片應 fixed').toBe('fixed')
		expect(await getPosition(page, TABS_NAV), '分頁列應 fixed').toBe('fixed')
	})

	/* ------------------------------------------------------------------ */
	/*  #279：點分頁後內容頂端不被吸附列遮住                                */
	/* ------------------------------------------------------------------ */

	/**
	 * 點擊分頁並等捲動停止與 scroll throttle 跑完，
	 * 回傳描述分頁內容頂端、吸附列底端（所有 fixed 的影片 / 分頁列中最低者）與視窗高度
	 */
	const clickTabAndMeasure = async (page: Page) => {
		await page.locator(TAB_NAV_DESCRIPTION).click()
		await page.waitForTimeout(600)

		// 站台主題的 html 帶 scroll-behavior: smooth，jQuery animate 結束後仍會再滑一小段，
		// 固定等待時間不夠穩；等 scrollY 連續兩次取樣不變，再多等一個 scroll throttle（150ms）
		let lastScrollY = -1
		await expect
			.poll(
				async () => {
					const scrollY = await page.evaluate(() => window.scrollY)
					const isStable = scrollY === lastScrollY
					lastScrollY = scrollY
					return isStable
				},
				{ intervals: [250], timeout: 10_000 },
			)
			.toBe(true)
		await page.waitForTimeout(300)

		return page.evaluate(
			({ video, tabsNav, content, headerOffset }) => {
				const stickyBottom = [video, tabsNav]
					.map((selector) => document.querySelector(selector))
					.filter(
						(el): el is Element =>
							!!el && getComputedStyle(el).position === 'fixed',
					)
					.reduce(
						(bottom, el) => Math.max(bottom, el.getBoundingClientRect().bottom),
						headerOffset,
					)
				const contentEl = document.querySelector(content)
				const navEl = document.querySelector(tabsNav)
				const videoEl = document.querySelector(video)
				const videoFixedBottom =
					videoEl && getComputedStyle(videoEl).position === 'fixed'
						? videoEl.getBoundingClientRect().bottom
						: headerOffset
				return {
					contentTop: contentEl?.getBoundingClientRect().top ?? NaN,
					navTop: navEl?.getBoundingClientRect().top ?? NaN,
					videoFixedBottom,
					stickyBottom,
					viewportH: window.innerHeight,
					scrollY: window.scrollY,
				}
			},
			{
				video: VIDEO,
				tabsNav: TABS_NAV,
				content: TAB_CONTENT_DESCRIPTION,
				headerOffset: HEADER_OFFSET,
			},
		)
	}

	test('#279 吸附狀態下點分頁：內容頂端落在吸附列下方且可見', async ({
		page,
	}) => {
		await openCourse(page, 390)
		await scrollDeepIntoContent(page)
		expect(await getPosition(page, TABS_NAV), '前置條件：分頁列已吸附').toBe(
			'fixed',
		)

		const m = await clickTabAndMeasure(page)

		expect(
			m.contentTop,
			`內容頂端（${m.contentTop}）不可被吸附列（底端 ${m.stickyBottom}）遮住`,
		).toBeGreaterThanOrEqual(m.stickyBottom - 1)
		expect(
			m.contentTop,
			'內容頂端應捲回視窗上半部，使用者不必自己往上捲',
		).toBeLessThan(m.viewportH / 2)
		// 分頁列本身也不能被吸附的影片蓋住
		expect(
			m.navTop,
			`分頁列頂端（${m.navTop}）不可被吸附的影片（底端 ${m.videoFixedBottom}）蓋住`,
		).toBeGreaterThanOrEqual(m.videoFixedBottom - 1)
	})

	test('#279 桌機寬度點分頁：維持原本不捲動的行為', async ({ page }) => {
		await openCourse(page, 1280)
		await page.evaluate(() => window.scrollTo(0, 600))
		await page.waitForTimeout(400)
		const before = await page.evaluate(() => window.scrollY)

		const m = await clickTabAndMeasure(page)

		expect(m.scrollY, '桌機點分頁不應自動捲動').toBe(before)
	})

	/* ------------------------------------------------------------------ */
	/*  #278：可只吸附分頁 / 只吸附影片                                     */
	/* ------------------------------------------------------------------ */

	test('#278 tabs_only：影片不吸附，分頁列吸附且 top = header_offset', async ({
		browser,
		page,
	}) => {
		await withApi(browser, (api) =>
			updateSettings(api, { fix_video_and_tabs_mobile_target: 'tabs_only' }),
		)

		await openCourse(page, 390)
		await scrollDeepIntoContent(page)

		expect(await getPosition(page, VIDEO), '影片不應 fixed').not.toBe('fixed')
		expect(await getPosition(page, TABS_NAV), '分頁列應 fixed').toBe('fixed')
		const top = await page
			.locator(TABS_NAV)
			.evaluate((el) => getComputedStyle(el).top)
		expect(top, '分頁列應直接貼在 header_offset 下方').toBe(
			`${HEADER_OFFSET}px`,
		)

		// tabs_only 下點分頁，補償時不可再扣影片高度
		const m = await clickTabAndMeasure(page)
		expect(m.contentTop).toBeGreaterThanOrEqual(m.stickyBottom - 1)
		expect(m.contentTop).toBeLessThan(m.viewportH / 2)
	})

	test('#278 video_only：影片吸附，分頁列不吸附', async ({ browser, page }) => {
		await withApi(browser, (api) =>
			updateSettings(api, { fix_video_and_tabs_mobile_target: 'video_only' }),
		)

		await openCourse(page, 390)
		await scrollDeepIntoContent(page)

		expect(await getPosition(page, VIDEO), '影片應 fixed').toBe('fixed')
		expect(await getPosition(page, TABS_NAV), '分頁列不應 fixed').not.toBe(
			'fixed',
		)
	})
})
