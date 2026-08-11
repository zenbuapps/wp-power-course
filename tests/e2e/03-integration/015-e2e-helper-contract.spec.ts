/**
 * 測試目標：E2E 共用 helper 自身的契約
 * 對應原始碼：tests/e2e/helpers/api-client.ts
 *
 * 為什麼要測測試工具：getNonceFromPage() 被 40+ 支 spec 間接依賴，
 * 它「取不到 nonce 時該拋錯還是回空字串」是兩種呼叫情境共用的分岔點：
 *
 * - 一般情境（setupApiFromBrowser / global-setup）：拿不到就是錯，必須當場失敗，
 *   否則空 nonce 會一路往下跑，真正的錯誤要到後面某支 REST 回 401/404 才浮現。
 * - 權限測試（004-permission）：刻意以低權限身分取 nonce，該身分的 wp-admin
 *   未必會 enqueue wp-api-request，空 nonce 是預期輸入而非錯誤。
 *
 * 這兩條路徑在本機站上都不容易被自然觸發（站台缺 /wp/v2/users 路由，
 * 004-permission 在更早的 ensureUser 就中止），所以在這裡直接驗證契約本身。
 * 用 about:blank 而非真實頁面：要驗的是「頁面沒有 wpApiSettings 時的行為」，
 * 不該受站台有哪些外掛 enqueue 了什麼影響。
 */

import { test, expect } from '@playwright/test'
import { getNonceFromPage } from '../helpers/api-client'

test.describe('E2E helper 契約：getNonceFromPage', () => {
	test('required: false — 頁面沒有 wpApiSettings 時回空字串，不拋錯', async ({
		page,
	}) => {
		await page.goto('about:blank')

		const nonce = await getNonceFromPage(page, { required: false })

		expect(nonce, '低權限 / 無 nonce 情境應回空字串供呼叫端自行判斷').toBe('')
	})

	test('預設（required）— 頁面沒有 wpApiSettings 時當場拋錯', async ({
		page,
	}) => {
		await page.goto('about:blank')

		await expect(
			getNonceFromPage(page),
			'預設情境拿不到 nonce 必須立刻失敗，不可靜默回空字串',
		).rejects.toThrow(/wpApiSettings/)
	})

	test('取得到 nonce 時兩種模式都回傳該值', async ({ page }) => {
		await page.goto('about:blank')
		await page.evaluate(() => {
			;(window as unknown as Record<string, unknown>).wpApiSettings = {
				nonce: 'deadbeef01',
			}
		})

		expect(await getNonceFromPage(page)).toBe('deadbeef01')
		expect(await getNonceFromPage(page, { required: false })).toBe('deadbeef01')
	})
})
