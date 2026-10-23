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
 * 設定是全站共用的：本專案 workers = 1，測試依序執行；每支測試開頭都自己設定吸附對象，
 * 不依賴前一支的結果或測試順序。刻意不用 serial 模式——serial 遇到第一支紅燈就跳過其餘測試，
 * 看不到每個 issue 各自的紅綠。某支失敗時 worker 重啟會重跑 beforeAll / afterAll，
 * afterAll 一律還原原始設定並刪除測試課程。
 */

import { test, expect, type Page } from '@playwright/test'
import { setupApiFromBrowser, type ApiClient } from '../helpers/api-client'

const VIDEO = '#courses-product__feature-video'
const TABS_NAV = '#courses-product__tabs-nav'
const TAB_CONTENT_DESCRIPTION = '#tab-content-description'
/** 點分頁測試切到的分頁：內容很短，切換後頁面高度會大幅改變（貼近真實操作） */
const TAB_NAV_QA = '#tab-nav-qa'
/** 目前顯示中的分頁內容 */
const ACTIVE_TAB_CONTENT = 'div.active[id^="tab-content-"]'

/** 位置比對的容許誤差（px）：影片高度可能有小數，捲動位置會被無條件捨去 */
const TOLERANCE = 2

type TStickyTarget = 'both' | 'tabs_only' | 'video_only'

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

	/** 設定吸附對象；每支測試開頭都呼叫，不依賴其他測試留下的設定 */
	const useTarget = (
		browser: import('@playwright/test').Browser,
		target: TStickyTarget,
	) =>
		withApi(browser, (api) =>
			updateSettings(api, { fix_video_and_tabs_mobile_target: target }),
		)

	/** 立即捲到指定位置（避開佈景主題的 scroll-behavior: smooth），並等 scroll throttle（150ms）跑完 */
	const scrollToInstant = async (page: Page, top: number) => {
		await page.evaluate(
			(y) => window.scrollTo({ top: y, behavior: 'instant' as ScrollBehavior }),
			top,
		)
		await page.waitForTimeout(400)
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

	test('#277 寬度 810px（已是桌機兩欄）時不吸附', async ({ browser, page }) => {
		await useTarget(browser, 'both')
		await openCourse(page, 810)
		await scrollDeepIntoContent(page)

		expect(await getPosition(page, VIDEO), '影片不應 fixed').not.toBe('fixed')
		expect(await getPosition(page, TABS_NAV), '分頁列不應 fixed').not.toBe(
			'fixed',
		)
	})

	test('#277 寬度 809px（行動版版面）時影片與分頁都吸附', async ({
		browser,
		page,
	}) => {
		await useTarget(browser, 'both')
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
	 * 回傳目前顯示中的分頁內容頂端、分頁列位置、吸附列底端（所有 fixed 的影片 / 分頁列中最低者）與視窗高度
	 */
	const clickTabAndMeasure = async (page: Page, tabNav: string) => {
		// 切到短分頁後頁面會變短，捲動位置會被文件底部夾住，量到的就是瀏覽器的限制而不是補償邏輯；
		// 在 body 最後墊高（分頁列下方，不影響分頁列與其上方的位置）
		await page.evaluate(() => {
			const spacer = document.createElement('div')
			spacer.style.height = '3000px'
			document.body.appendChild(spacer)
		})
		await page.locator(tabNav).click()
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
					navBottom: navEl?.getBoundingClientRect().bottom ?? NaN,
					navMarginBottom: navEl
						? parseFloat(getComputedStyle(navEl).marginBottom) || 0
						: 0,
					videoFixedBottom,
					stickyBottom,
					viewportH: window.innerHeight,
					scrollY: window.scrollY,
				}
			},
			{
				video: VIDEO,
				tabsNav: TABS_NAV,
				content: ACTIVE_TAB_CONTENT,
				headerOffset: HEADER_OFFSET,
			},
		)
	}

	/**
	 * 補償後的精準位置：分頁列貼在吸附位置（影片下緣或 header_offset），
	 * 新分頁內容緊接在分頁列下方，兩者都不可留空隙或被遮住
	 */
	const expectCompensatedLayout = (
		m: Awaited<ReturnType<typeof clickTabAndMeasure>>,
		expectedNavTop: number,
	) => {
		expect(
			m.contentTop,
			`內容頂端（${m.contentTop}）不可被吸附列（底端 ${m.stickyBottom}）遮住`,
		).toBeGreaterThanOrEqual(m.stickyBottom - 1)
		expect(
			Math.abs(m.navTop - expectedNavTop),
			`分頁列頂端（${m.navTop}）應貼在吸附位置（${expectedNavTop}），不可留空隙`,
		).toBeLessThanOrEqual(TOLERANCE)
		expect(
			Math.abs(m.contentTop - (m.navBottom + m.navMarginBottom)),
			`內容頂端（${m.contentTop}）應緊接在分頁列（底端 ${m.navBottom} + margin ${m.navMarginBottom}）下方`,
		).toBeLessThanOrEqual(TOLERANCE)
	}

	test('#279 吸附狀態下切到另一個分頁：分頁列貼齊影片下緣、內容緊接其下', async ({
		browser,
		page,
	}) => {
		await useTarget(browser, 'both')
		await openCourse(page, 390)
		await scrollDeepIntoContent(page)
		expect(await getPosition(page, TABS_NAV), '前置條件：分頁列已吸附').toBe(
			'fixed',
		)
		expect(await getPosition(page, VIDEO), '前置條件：影片已吸附').toBe('fixed')

		const m = await clickTabAndMeasure(page, TAB_NAV_QA)

		await expect(page.locator('#tab-content-qa'), '應切到問答分頁').toHaveClass(
			/active/,
		)
		// both：分頁列貼在吸附中的影片下緣
		expectCompensatedLayout(m, m.videoFixedBottom)
	})

	test('#279 桌機寬度點分頁：維持原本不捲動的行為', async ({
		browser,
		page,
	}) => {
		await useTarget(browser, 'both')
		await openCourse(page, 1280)
		await scrollToInstant(page, 600)
		const before = await page.evaluate(() => window.scrollY)

		const m = await clickTabAndMeasure(page, TAB_NAV_QA)

		expect(m.scrollY, '桌機點分頁不應自動捲動').toBe(before)
	})

	/* ------------------------------------------------------------------ */
	/*  #278：可只吸附分頁 / 只吸附影片                                     */
	/* ------------------------------------------------------------------ */

	test('#278 tabs_only：影片不吸附，分頁列吸附且 top = header_offset', async ({
		browser,
		page,
	}) => {
		await useTarget(browser, 'tabs_only')

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

		// tabs_only 下點分頁，補償時不可再扣影片高度：分頁列貼在 header_offset
		const m = await clickTabAndMeasure(page, TAB_NAV_QA)
		expectCompensatedLayout(m, HEADER_OFFSET)
	})

	test('#278 tabs_only：分頁列頂端碰到 header_offset 才吸附，且吸附瞬間不跳位', async ({
		browser,
		page,
	}) => {
		await useTarget(browser, 'tabs_only')
		await openCourse(page, 390)

		// 分頁列在文件流中的頂端位置（此時尚未捲動，沒有任何元素 fixed）
		const navDocTop = await page
			.locator(TABS_NAV)
			.evaluate((el) => el.getBoundingClientRect().top + window.scrollY)

		// 分頁列頂端距 header_offset 還差 5px：不可提早吸附（門檻若仍扣影片高度，這裡就已經 fixed）
		await scrollToInstant(page, navDocTop - HEADER_OFFSET - 5)
		expect(
			await getPosition(page, TABS_NAV),
			'分頁列尚未碰到 header_offset，不應吸附',
		).not.toBe('fixed')
		const navTopBefore = await page
			.locator(TABS_NAV)
			.evaluate((el) => el.getBoundingClientRect().top)
		expect(Math.abs(navTopBefore - (HEADER_OFFSET + 5))).toBeLessThanOrEqual(
			TOLERANCE,
		)

		// 再捲 10px，分頁列越過 header_offset：應吸附在 header_offset，不往上或往下跳
		await scrollToInstant(page, navDocTop - HEADER_OFFSET + 5)
		expect(await getPosition(page, TABS_NAV), '分頁列越過門檻應吸附').toBe(
			'fixed',
		)
		const navTopAfter = await page
			.locator(TABS_NAV)
			.evaluate((el) => el.getBoundingClientRect().top)
		expect(
			Math.abs(navTopAfter - HEADER_OFFSET),
			`吸附後分頁列頂端（${navTopAfter}）應在 header_offset（${HEADER_OFFSET}）`,
		).toBeLessThanOrEqual(TOLERANCE)
	})

	test('#278 video_only：影片吸附，分頁列不吸附', async ({ browser, page }) => {
		await useTarget(browser, 'video_only')

		await openCourse(page, 390)
		await scrollDeepIntoContent(page)

		expect(await getPosition(page, VIDEO), '影片應 fixed').toBe('fixed')
		expect(await getPosition(page, TABS_NAV), '分頁列不應 fixed').not.toBe(
			'fixed',
		)
	})
})
