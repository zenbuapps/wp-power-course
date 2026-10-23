/**
 * 外觀設定：行動版吸附對象子選項（Issue #278）
 *
 * 對應原始碼：js/src/pages/admin/Settings/Appearance/index.tsx
 *
 * 驗證：
 * - 「行動版影片與分頁吸附」開啟時顯示子選項「行動版吸附對象」，預設「影片與分頁」
 * - 選「只吸附分頁」儲存後，GET /settings 讀回 tabs_only
 * - 主開關關閉時隱藏子選項（與「偏移距離」相同條件）
 *
 * 設定是全站共用的，afterAll 還原原始設定。
 */

import { test, expect } from '@playwright/test'
import { setupApiFromBrowser } from '../helpers/api-client'
import {
	navigateToAdmin,
	waitForFormLoaded,
	clickTab,
} from '../helpers/admin-page'

/** GET /settings 回傳格式 */
interface SettingsGetResponse {
	data: Record<string, unknown>
}

const SETTING_KEYS = [
	'fix_video_and_tabs_mobile',
	'fix_video_and_tabs_mobile_target',
] as const

const TARGET_LABEL = '行動版吸附對象'

test.describe('外觀設定：行動版吸附對象', () => {
	test.use({ storageState: '.auth/admin.json' })

	const originalSettings: Record<string, unknown> = {}

	/** 以 API 更新設定 */
	const updateSettings = async (
		browser: import('@playwright/test').Browser,
		data: Record<string, string>,
	) => {
		const { api, dispose } = await setupApiFromBrowser(browser)
		try {
			const resp = await api.pcPost('settings', data)
			expect(resp.status, '更新設定應成功').toBe(200)
		} finally {
			await dispose()
		}
	}

	/** 以 API 讀取設定 */
	const getSettings = async (browser: import('@playwright/test').Browser) => {
		const { api, dispose } = await setupApiFromBrowser(browser)
		try {
			const resp = await api.pcGet<SettingsGetResponse>('settings')
			return resp.data.data
		} finally {
			await dispose()
		}
	}

	/** 開啟設定頁並切到外觀設定 */
	const openAppearance = async (page: import('@playwright/test').Page) => {
		await navigateToAdmin(page, '/settings')
		await waitForFormLoaded(page)
		await clickTab(page, '外觀設定')
	}

	test.beforeAll(async ({ browser }) => {
		test.setTimeout(180_000)

		const data = await getSettings(browser)
		for (const key of SETTING_KEYS) {
			originalSettings[key] = data[key]
		}
	})

	test.afterAll(async ({ browser }) => {
		test.setTimeout(120_000)

		await updateSettings(browser, {
			fix_video_and_tabs_mobile: String(
				originalSettings.fix_video_and_tabs_mobile ?? 'no',
			),
			fix_video_and_tabs_mobile_target: String(
				originalSettings.fix_video_and_tabs_mobile_target ?? 'both',
			),
		})
	})

	test('主開關開啟時顯示子選項，選「只吸附分頁」可儲存', async ({
		browser,
		page,
	}) => {
		await updateSettings(browser, {
			fix_video_and_tabs_mobile: 'yes',
			fix_video_and_tabs_mobile_target: 'both',
		})

		await openAppearance(page)

		const pane = page.locator('.ant-tabs-tabpane-active')
		await expect(pane.getByText(TARGET_LABEL)).toBeVisible()
		await expect(
			pane.locator('.ant-radio-button-wrapper-checked'),
			'預設應選「影片與分頁」',
		).toHaveText('影片與分頁')

		await pane
			.locator('.ant-radio-button-wrapper')
			.filter({ hasText: '只吸附分頁' })
			.click()
		// 儲存成功後頁面會立即 reload，成功提示來不及斷言，改等 POST /settings 的回應
		const saveResponse = page.waitForResponse(
			(resp) =>
				resp.url().includes('/power-course/settings') &&
				'POST' === resp.request().method(),
		)
		// antd 會在兩個中文字的按鈕文字中間插入空白（「儲 存」）
		await page.getByRole('button', { name: /^儲\s?存$/ }).click()
		expect((await saveResponse).status(), '儲存應成功').toBe(200)

		const data = await getSettings(browser)
		expect(data.fix_video_and_tabs_mobile_target).toBe('tabs_only')
	})

	test('主開關關閉時隱藏子選項', async ({ browser, page }) => {
		await updateSettings(browser, { fix_video_and_tabs_mobile: 'no' })

		// 主開關的 initialValue 本來就是 no，表單資料載入前子選項也是隱藏的；
		// 必須等 GET /settings 回應、且回應確實是 no，斷言「隱藏」才有意義
		const loadResponse = page.waitForResponse(
			(resp) =>
				resp.url().includes('/power-course/settings') &&
				'GET' === resp.request().method(),
		)
		await openAppearance(page)
		const loaded = (await (await loadResponse).json()) as SettingsGetResponse
		expect(
			loaded.data.fix_video_and_tabs_mobile,
			'前置條件：主開關為關閉',
		).toBe('no')

		const pane = page.locator('.ant-tabs-tabpane-active')
		await expect(pane.getByText(TARGET_LABEL)).toBeHidden()
	})
})
