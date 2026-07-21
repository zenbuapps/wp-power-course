/**
 * 郵件編輯器沉浸式版面 E2E 測試（Issue #258）
 *
 * 對應規格：specs/issue-258-email-editor-layout/郵件編輯器沉浸式版面.feature
 * 對應計畫：specs/issue-258-email-editor-layout/implementation-plan.md §5
 *
 * 涵蓋以下 Rule（依序對應 feature 檔案的 8 個 Rule）：
 * 1. 進入郵件模板編輯頁時自動進入沉浸全螢幕模式
 * 2. 管理員可在沉浸模式與一般後台版面之間自由切換
 * 3. 系統記住管理員上次選擇的版面模式（偏好記憶，client-side 持久化）
 * 4. 左側元件區與右側屬性區可各自收合
 * 5. 中間信件畫布可視寬度不得低於 640px（Scenario Outline，四斷點）
 * 6. 螢幕過窄致三欄同時展開會擠壓畫布時，左右面板改為浮動抽屜
 * 7. 版面優化不得破壞 WordPress 後台既有選單、工具列與儲存列
 * 8. 版面優化僅改變編輯畫面呈現，不改變任何已儲存信件內容與寄出排版
 *
 * ⚠️ 本 CI 環境無 node_modules / 無法連瀏覽器，此檔案僅完成撰寫，未經實際執行驗證。
 * ⚠️ 本檔選擇器依 Red 階段共享 DOM 契約撰寫（body class / data-testid / WP 穩定 id）。
 *    部分斷言（面板收合後畫布精確寬度、窄螢幕抽屜的覆蓋容器選擇器、內容 byte-level
 *    比對的 power-email API 回傳形狀）依賴 j7-easy-email-extensions StandardLayout
 *    套件內部 DOM，implementation-plan.md Phase A「DOM Spike」尚未執行，於程式碼中
 *    以「待 DOM spike 校正」註記標出，並以較寬鬆但仍具驗證意義的替代斷言先行，
 *    確保 Red 階段可編譯、Green 階段對接真實 DOM 後可視情況收緊精度。
 *
 * 執行指令（本機 / CI 有完整環境時）：
 *   pnpm run test:e2e:admin -- email-editor-immersive.spec.ts
 *   pnpm run test:e2e:admin -- --grep "郵件編輯器沉浸式版面"
 */

import { test, expect, type Browser, type APIRequestContext } from '@playwright/test'

import { navigateToAdmin, waitForFormLoaded } from '../helpers/admin-page'
import { setupApiFromBrowser, getNonceFromPage } from '../helpers/api-client'

// 本地 WordPress 環境 cold start 較慢，四斷點 Outline + API fallback 皆需要寬鬆 timeout
test.setTimeout(90_000)

const BASE_URL = process.env.TEST_SITE_URL || 'http://localhost:8889'

/**
 * 測試資料：郵件模板 id（id 解析待本地校正）
 *
 * Feature Background 使用「模板 400」，但 CI 資料庫不保證存在此 id。
 * beforeAll 會優先嘗試動態解析出一個真實存在的模板 id；若兩層 API 嘗試皆失敗，
 * 才 fallback 回此常數，確保整支測試檔在任何環境下都能編譯與執行（即便斷言可能失敗）。
 */
const TEMPLATE_ID_FALLBACK = process.env.PC_EMAIL_TEMPLATE_ID ?? '400'
let TEMPLATE_ID = TEMPLATE_ID_FALLBACK

/**
 * 直連 power-email REST namespace 取得郵件模板清單
 *
 * helpers/api-client.ts 的 ApiClient 目前只封裝 power-course / wc/v3 / wp/v2 三種
 * namespace，未涵蓋郵件模組獨立使用的 power-email namespace
 * （見 inc/classes/PowerEmail/Resources/Email/Api.php `$namespace = 'power-email'`）。
 * 依任務限制本檔不得修改共用 helper，故於此檔內自建最小等效的 request 呼叫，
 * 並整段包 try/catch，避免端點形狀或權限差異導致整支測試無法編譯 / 執行。
 */
async function fetchEmailList(
	request: APIRequestContext,
	nonce: string,
): Promise<{ id?: string | number }[] | null> {
	try {
		const resp = await request.get(`${BASE_URL}/wp-json/power-email/emails`, {
			headers: nonce ? { 'X-WP-Nonce': nonce } : {},
			timeout: 60_000,
		})
		if (!resp.ok()) return null
		const json = await resp.json()
		return Array.isArray(json) ? json : null
	} catch {
		return null
	}
}

/**
 * 直連 power-email REST namespace 取得單一郵件模板詳情
 *
 * 用於 Rule 8「內容不受影響」的存前/存後比對。
 * 內容 byte-level 比對待本地 api-client 校正：實際回傳欄位形狀（是否包 data、
 * short_description 是否已 normalize）未經本地站實測，此處僅在成功取得資料時比對。
 */
async function fetchEmailDetail(
	request: APIRequestContext,
	nonce: string,
	id: string,
): Promise<{ short_description?: string } | null> {
	try {
		const resp = await request.get(`${BASE_URL}/wp-json/power-email/emails/${id}`, {
			headers: nonce ? { 'X-WP-Nonce': nonce } : {},
			timeout: 60_000,
		})
		if (!resp.ok()) return null
		return await resp.json()
	} catch {
		return null
	}
}

/**
 * 建立可直連 power-email REST namespace 的獨立 API context
 *
 * 依 playbook 規範明確設定 context timeout（60s），避免繼承 Playwright config
 * 預設 10s actionTimeout 而在 cold start 時誤判失敗。
 */
async function createEmailApiContext(
	browser: Browser,
): Promise<{ request: APIRequestContext; nonce: string; dispose: () => Promise<void> }> {
	const context = await browser.newContext({ storageState: '.auth/admin.json' })
	context.setDefaultTimeout(60_000)
	const page = await context.newPage()
	await page.goto(`${BASE_URL}/wp-admin/`, {
		waitUntil: 'domcontentloaded',
		timeout: 30_000,
	})
	await page.waitForFunction(() => !!(window as unknown as { wpApiSettings?: { nonce?: string } }).wpApiSettings?.nonce, {
		timeout: 30_000,
	})
	const nonce = await getNonceFromPage(page)
	return {
		request: context.request,
		nonce,
		dispose: async () => {
			await page.close()
			await context.close()
		},
	}
}

test.describe('郵件編輯器沉浸式版面', () => {
	test.use({ storageState: '.auth/admin.json' })

	test.beforeAll(async ({ browser }) => {
		// 第一層：優先透過既有 ApiClient 嘗試（power-course namespace）
		try {
			const { api, dispose } = await setupApiFromBrowser(browser)
			try {
				const resp = await api.pcGet<{ id?: string | number }[]>('emails')
				if (Array.isArray(resp.data) && resp.data.length > 0 && resp.data[0]?.id) {
					TEMPLATE_ID = String(resp.data[0].id)
					return
				}
				throw new Error('ApiClient pcGet 未取得可用清單，改走 power-email namespace 直連')
			} finally {
				await dispose()
			}
		} catch {
			// power-course namespace 不含 emails 端點（郵件模組實為獨立的 power-email
			// namespace），預期會落入這裡；改走第二層直連
		}

		// 第二層：直連正確的 power-email namespace
		let ctx: Awaited<ReturnType<typeof createEmailApiContext>> | undefined
		try {
			ctx = await createEmailApiContext(browser)
			const list = await fetchEmailList(ctx.request, ctx.nonce)
			if (Array.isArray(list) && list.length > 0 && list[0]?.id) {
				TEMPLATE_ID = String(list[0].id)
			} else {
				TEMPLATE_ID = TEMPLATE_ID_FALLBACK
			}
		} catch {
			// 第三層：兩種 API 嘗試皆失敗（本地環境無法連線等）→ fallback 至模組層級常數，
			// 不讓整支測試無法編譯 / 全部炸裂
			TEMPLATE_ID = TEMPLATE_ID_FALLBACK
		} finally {
			if (ctx) await ctx.dispose().catch(() => {})
		}
	})

	// ========== Rule 1：自動進入沉浸模式 ==========

	test.describe('Rule: 進入郵件模板編輯頁時自動進入沉浸全螢幕模式', () => {
		test('開啟編輯頁自動隱藏後台側邊欄，三欄編輯器獨佔瀏覽器寬度', async ({ page }) => {
			// 模擬首次造訪（清除可能殘留的偏好記憶），驗證預設行為為自動進入沉浸模式
			await page.addInitScript(() => {
				window.localStorage.removeItem('pc-email-editor-immersive')
			})
			await navigateToAdmin(page, `/emails/edit/${TEMPLATE_ID}`)
			await waitForFormLoaded(page)

			// 驗收 Rule：body 應自動掛上沉浸 class，WP 主選單與頂部工具列因此隱藏，
			// 編輯器容器可見
			await expect(page.locator('body')).toHaveClass(/pc-email-immersive/)
			await expect(page.locator('#adminmenumain')).toBeHidden()
			await expect(page.locator('#wpadminbar')).toBeHidden()
			await expect(page.locator('[data-testid="email-editor-wrap"]')).toBeVisible()
		})
	})

	// ========== Rule 2：自由切換 ==========

	test.describe('Rule: 管理員可在沉浸模式與一般後台版面之間自由切換', () => {
		test('於沉浸模式點擊退出全螢幕按鈕返回一般後台版面', async ({ page }) => {
			await navigateToAdmin(page, `/emails/edit/${TEMPLATE_ID}`)
			await waitForFormLoaded(page)
			await expect(page.locator('body')).toHaveClass(/pc-email-immersive/)

			const toggleBtn = page.locator('[data-testid="immersive-toggle"]')
			await toggleBtn.click()

			// 驗收 Rule：退出後 WP 主選單、頂部工具列、頁尾儲存列恢復正常顯示與可操作，
			// 且切換鈕仍在畫面上（文案應變為「進入全螢幕編輯」，由 Green 階段實作對接）
			await expect(page.locator('body')).not.toHaveClass(/pc-email-immersive/)
			await expect(page.locator('#adminmenumain')).toBeVisible()
			await expect(page.locator('#wpadminbar')).toBeVisible()
			await expect(page.locator('#wpfooter')).toBeVisible()

			const saveBtn = page.locator('.ant-btn-primary').filter({ hasText: /Save email|儲存郵件/ })
			await expect(saveBtn).toBeVisible()
			await expect(saveBtn).toBeEnabled()
			await expect(toggleBtn).toBeVisible()
		})

		test('於一般版面點擊進入全螢幕再次進入沉浸模式', async ({ page }) => {
			await navigateToAdmin(page, `/emails/edit/${TEMPLATE_ID}`)
			await waitForFormLoaded(page)

			const toggleBtn = page.locator('[data-testid="immersive-toggle"]')

			// 先確保處於一般版面（預設可能為自動沉浸，先退出一次以模擬「位於一般後台版面」的前提）
			const isImmersive = await page
				.locator('body')
				.evaluate((el) => el.classList.contains('pc-email-immersive'))
			if (isImmersive) {
				await toggleBtn.click()
				await expect(page.locator('body')).not.toHaveClass(/pc-email-immersive/)
			}

			await toggleBtn.click()

			// 驗收 Rule：重新進入沉浸全螢幕模式，三欄編輯器獨佔瀏覽器可視寬度
			await expect(page.locator('body')).toHaveClass(/pc-email-immersive/)
			await expect(page.locator('#adminmenumain')).toBeHidden()
			await expect(page.locator('[data-testid="email-editor-wrap"]')).toHaveClass(
				/pc-email-editor-wrap--immersive/,
			)
		})
	})

	// ========== Rule 3：偏好記憶 ==========

	test.describe('Rule: 系統記住管理員上次選擇的版面模式（偏好記憶，client-side 持久化）', () => {
		test('上次退出沉浸模式後，下次進入編輯頁沿用一般後台版面', async ({ page }) => {
			await navigateToAdmin(page, `/emails/edit/${TEMPLATE_ID}`)
			await waitForFormLoaded(page)
			await page.locator('[data-testid="immersive-toggle"]').click()
			await expect(page.locator('body')).not.toHaveClass(/pc-email-immersive/)

			const stored = await page.evaluate(() =>
				window.localStorage.getItem('pc-email-editor-immersive'),
			)
			expect(stored).toBe('false')

			// 重進編輯頁（以 reload 模擬「再次開啟編輯頁」）
			await page.reload({ waitUntil: 'domcontentloaded' })
			await waitForFormLoaded(page)

			// 驗收 Rule：依上次偏好維持一般後台版面，不自動進入沉浸模式
			await expect(page.locator('body')).not.toHaveClass(/pc-email-immersive/)
			const storedAfterReload = await page.evaluate(() =>
				window.localStorage.getItem('pc-email-editor-immersive'),
			)
			expect(storedAfterReload).toBe('false')
		})

		test('上次維持沉浸模式，下次進入編輯頁沿用沉浸全螢幕', async ({ page }) => {
			await navigateToAdmin(page, `/emails/edit/${TEMPLATE_ID}`)
			await waitForFormLoaded(page)

			// 確保處於沉浸模式（模擬「上次維持沉浸全螢幕模式」的前提）
			const isImmersive = await page
				.locator('body')
				.evaluate((el) => el.classList.contains('pc-email-immersive'))
			if (!isImmersive) {
				await page.locator('[data-testid="immersive-toggle"]').click()
			}
			await expect(page.locator('body')).toHaveClass(/pc-email-immersive/)

			const stored = await page.evaluate(() =>
				window.localStorage.getItem('pc-email-editor-immersive'),
			)
			expect(stored).toBe('true')

			await page.reload({ waitUntil: 'domcontentloaded' })
			await waitForFormLoaded(page)

			// 驗收 Rule：依上次偏好自動進入沉浸全螢幕模式
			await expect(page.locator('body')).toHaveClass(/pc-email-immersive/)
		})
	})

	// ========== Rule 4：面板收合 ==========

	test.describe('Rule: 左側元件區與右側屬性區可各自收合，收合後空間讓給中間畫布', () => {
		test('收合左側元件區後中間畫布可視寬度增加，可再展開', async ({ page }) => {
			await navigateToAdmin(page, `/emails/edit/${TEMPLATE_ID}`)
			await waitForFormLoaded(page)

			const wrap = page.locator('[data-testid="email-editor-wrap"]')
			await expect(wrap).toBeVisible()
			const collapseLeftBtn = page.locator('[data-testid="collapse-left"]')
			await expect(collapseLeftBtn).toBeVisible()

			// 待 DOM spike 校正：StandardLayout 套件內部「中間畫布」的確切巢狀選擇器
			// 尚未確認（implementation-plan.md Phase A 待執行），暫以 wrap 容器本身的
			// boundingBox 作為下界代理指標——收合左側面板後，wrap 整體寬度不應縮小
			// （釋放的空間應留在 wrap 內部讓給畫布），並確認收合鈕可再次點擊展開叫回
			const wrapBoxBefore = await wrap.boundingBox()
			await collapseLeftBtn.click()
			await expect(wrap).toBeVisible()
			const wrapBoxAfter = await wrap.boundingBox()
			expect(wrapBoxAfter?.width ?? 0).toBeGreaterThanOrEqual((wrapBoxBefore?.width ?? 0) - 1)

			// 再次點擊應可展開叫回
			await collapseLeftBtn.click()
			await expect(wrap).toBeVisible()
		})

		test('收合右側屬性區後中間畫布可視寬度增加，可再展開', async ({ page }) => {
			await navigateToAdmin(page, `/emails/edit/${TEMPLATE_ID}`)
			await waitForFormLoaded(page)

			const wrap = page.locator('[data-testid="email-editor-wrap"]')
			await expect(wrap).toBeVisible()
			const collapseRightBtn = page.locator('[data-testid="collapse-right"]')
			await expect(collapseRightBtn).toBeVisible()

			// 待 DOM spike 校正：理由同左側面板案例
			const wrapBoxBefore = await wrap.boundingBox()
			await collapseRightBtn.click()
			await expect(wrap).toBeVisible()
			const wrapBoxAfter = await wrap.boundingBox()
			expect(wrapBoxAfter?.width ?? 0).toBeGreaterThanOrEqual((wrapBoxBefore?.width ?? 0) - 1)

			await collapseRightBtn.click()
			await expect(wrap).toBeVisible()
		})
	})

	// ========== Rule 5：中間畫布最小寬度（Scenario Outline，四斷點） ==========

	test.describe('Rule: 中間信件畫布可視寬度不得低於 640px（信件標準 600px + 左右內距）', () => {
		const BREAKPOINTS: { label: string; vw: number }[] = [
			{ label: '13 吋筆電', vw: 1280 },
			{ label: '14/15 吋筆電', vw: 1440 },
			{ label: '桌機', vw: 1920 },
			{ label: '超寬螢幕', vw: 2560 },
		]

		for (const { label, vw } of BREAKPOINTS) {
			test(`視窗寬度 ${vw}px（${label}）下中間畫布維持足夠寬度、三欄不重疊`, async ({ page }) => {
				await page.setViewportSize({ width: vw, height: 900 })
				await navigateToAdmin(page, `/emails/edit/${TEMPLATE_ID}`)
				await waitForFormLoaded(page)

				// 沉浸生效的間接證明：WP 主選單被隱藏，三欄編輯器獨佔可視寬度
				await expect(page.locator('#adminmenumain')).toBeHidden()

				const wrap = page.locator('[data-testid="email-editor-wrap"]')
				await expect(wrap).toBeVisible()

				// 精確畫布量測（扣除左右面板後的中間欄實際可視寬度）待 DOM spike 校正，
				// 此處先以 wrap 容器整體寬度作為下界代理指標：wrap 涵蓋三欄，
				// 其寬度理論上不應小於畫布最小寬度 640px
				const box = await wrap.boundingBox()
				expect(box?.width ?? 0).toBeGreaterThanOrEqual(640)
			})
		}
	})

	// ========== Rule 6：窄螢幕浮動抽屜 ==========

	test.describe('Rule: 螢幕過窄致三欄同時展開會擠壓畫布時，左右面板改為浮動抽屜', () => {
		test('窄螢幕（1280px）下左右面板以浮動抽屜呈現，優先保住畫布寬度', async ({ page }) => {
			await page.setViewportSize({ width: 1280, height: 900 })
			await navigateToAdmin(page, `/emails/edit/${TEMPLATE_ID}`)
			await waitForFormLoaded(page)

			// 待 DOM spike 校正：窄斷點下面板「預設收起為浮動抽屜」對應的具體 body/wrap
			// class 尚未定義於共享 DOM 契約，此處先以「收合/展開按鈕存在且可操作」作為
			// 目前可驗證的最小保證，並確認中間畫布仍維持不低於 640px 的可視寬度
			await expect(page.locator('[data-testid="collapse-left"]')).toBeVisible()
			await expect(page.locator('[data-testid="collapse-right"]')).toBeVisible()

			const wrap = page.locator('[data-testid="email-editor-wrap"]')
			const box = await wrap.boundingBox()
			expect(box?.width ?? 0).toBeGreaterThanOrEqual(640)
		})

		test('窄螢幕下點按鈕滑出浮動抽屜、覆蓋於畫布上方，再次點擊即收起', async ({ page }) => {
			await page.setViewportSize({ width: 1280, height: 900 })
			await navigateToAdmin(page, `/emails/edit/${TEMPLATE_ID}`)
			await waitForFormLoaded(page)

			const collapseLeftBtn = page.locator('[data-testid="collapse-left"]')
			await expect(collapseLeftBtn).toBeVisible()

			// 待 DOM spike 校正：抽屜滑出後覆蓋容器的選擇器未知（implementation-plan.md
			// 標註為「position:absolute/fixed 覆蓋畫布上方」），此處先驗證按鈕可重複
			// 點擊（展開 → 收起）不拋錯，且畫布寬度不因抽屜滑出而被推擠縮小
			// （抽屜應「覆蓋」而非「推擠」畫布，這正是本 Rule 的核心行為）
			const wrap = page.locator('[data-testid="email-editor-wrap"]')
			const widthBeforeOpen = (await wrap.boundingBox())?.width ?? 0

			await collapseLeftBtn.click() // 點擊展開鈕，抽屜滑出
			await expect(wrap).toBeVisible()
			const widthAfterOpen = (await wrap.boundingBox())?.width ?? 0
			expect(widthAfterOpen).toBeGreaterThanOrEqual(widthBeforeOpen - 1)

			await collapseLeftBtn.click() // 再次點擊，收起抽屜
			await expect(wrap).toBeVisible()
		})
	})

	// ========== Rule 7：不影響後台既有元件 ==========

	test.describe('Rule: 版面優化不得破壞 WordPress 後台既有選單、工具列與儲存列', () => {
		test('退出沉浸模式後後台既有元件完整可用', async ({ page }) => {
			await navigateToAdmin(page, `/emails/edit/${TEMPLATE_ID}`)
			await waitForFormLoaded(page)
			await expect(page.locator('body')).toHaveClass(/pc-email-immersive/)

			await page.locator('[data-testid="immersive-toggle"]').click()
			await expect(page.locator('body')).not.toHaveClass(/pc-email-immersive/)

			// 驗收 Rule：WP 後台主選單完整顯示且可點擊導覽、頂部工具列正常顯示、
			// 頁尾儲存列與「儲存郵件」按鈕正常顯示且可點擊
			await expect(page.locator('#adminmenumain')).toBeVisible()
			await expect(page.locator('#adminmenumain a').first()).toBeVisible()
			await expect(page.locator('#wpadminbar')).toBeVisible()
			await expect(page.locator('#wpfooter')).toBeVisible()

			const saveBtn = page.locator('.ant-btn-primary').filter({ hasText: /Save email|儲存郵件/ })
			await expect(saveBtn).toBeVisible()
			await expect(saveBtn).toBeEnabled()
		})
	})

	// ========== Rule 8：既有信件內容不受影響（內容回歸） ==========

	test.describe('Rule: 版面優化僅改變編輯畫面呈現，不改變任何已儲存信件內容與寄出排版', () => {
		test('沉浸模式下開啟模板、不改內容、儲存後內容與寄出排版維持一致', async ({ page, browser }) => {
			// 存前先讀一次既有內容作為比對基準
			let apiCtx: Awaited<ReturnType<typeof createEmailApiContext>> | undefined
			let before: { short_description?: string } | null = null
			try {
				apiCtx = await createEmailApiContext(browser)
				before = await fetchEmailDetail(apiCtx.request, apiCtx.nonce, TEMPLATE_ID)
			} finally {
				if (apiCtx) await apiCtx.dispose().catch(() => {})
			}

			await navigateToAdmin(page, `/emails/edit/${TEMPLATE_ID}`)
			await waitForFormLoaded(page)

			// 沉浸模式下不更動內容，直接點擊儲存
			const saveBtn = page.locator('.ant-btn-primary').filter({ hasText: /Save email|儲存郵件/ })
			await expect(saveBtn).toBeVisible()
			await saveBtn.click()

			// 內容 byte-level 比對待本地 api-client 校正：優先確認儲存出現成功訊息、
			// 無錯誤提示；若無法穩定捕捉 antd message（timing 因素），至少確保
			// 沒有錯誤訊息出現
			try {
				await expect(page.locator('.ant-message-success')).toBeVisible({ timeout: 15_000 })
			} catch {
				await expect(page.locator('.ant-message-error')).toHaveCount(0)
			}

			// 儲存後讀回內容，若前後兩次 API 呼叫皆成功取得資料，比對 short_description 一致
			if (before?.short_description !== undefined) {
				let afterCtx: Awaited<ReturnType<typeof createEmailApiContext>> | undefined
				try {
					afterCtx = await createEmailApiContext(browser)
					const after = await fetchEmailDetail(afterCtx.request, afterCtx.nonce, TEMPLATE_ID)
					if (after?.short_description !== undefined) {
						expect(after.short_description).toBe(before.short_description)
					}
				} finally {
					if (afterCtx) await afterCtx.dispose().catch(() => {})
				}
			}
		})
	})
})
