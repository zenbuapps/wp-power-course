/**
 * Settings REST API 測試
 *
 * 驗證 GET/POST /wp-json/power-course/settings 的行為，
 * 包含讀取、單一更新、批次更新、部分更新不影響其他欄位、浮水印、yes/no 開關、還原預設值。
 */

import { test, expect } from '@playwright/test'
import { ApiClient, setupApiFromBrowser } from '../helpers/api-client'

/** GET /settings 回傳格式 */
interface SettingsGetResponse {
	code: string
	message: string
	data: Record<string, unknown>
}

/** POST /settings 回傳格式 */
interface SettingsPostResponse {
	code: string
	message: string
	data: Record<string, unknown>
}

/** 預設值，用於 afterAll 還原 */
const DEFAULTS: Record<string, unknown> = {
	course_access_trigger: 'completed',
	hide_myaccount_courses: 'no',
	fix_video_and_tabs_mobile: 'no',
	pc_header_offset: '0',
	hide_courses_in_main_query: 'no',
	hide_courses_in_search_result: 'no',
	pc_watermark_qty: 0,
	pc_pdf_watermark_qty: 0,
}

test.describe('Settings API', () => {
	let api: ApiClient
	let dispose: () => Promise<void>
	let originalSettings: Record<string, unknown>

	test.beforeAll(async ({ browser }) => {
		const setup = await setupApiFromBrowser(browser)
		api = setup.api
		dispose = setup.dispose

		// 備份目前的設定，以便 afterAll 還原
		const resp = await api.pcGet<SettingsGetResponse>('settings')
		originalSettings = { ...resp.data.data }
	})

	test.afterAll(async () => {
		try {
			// 還原為測試前的原始設定
			if (originalSettings) {
				await api.pcPost<SettingsPostResponse>('settings', originalSettings)
			}
		} catch {
			// 還原失敗不影響測試結果
		} finally {
			await dispose()
		}
	})

	/* ------------------------------------------------------------------ */
	/*  1. 取得所有設定                                                    */
	/* ------------------------------------------------------------------ */
	test('取得所有設定 — GET /settings 回傳正確結構', async () => {
		const resp = await api.pcGet<SettingsGetResponse>('settings')

		expect(resp.status).toBe(200)
		expect(resp.data.code).toBe('get_options_success')
		expect(resp.data.message).toBe('獲取選項成功')
		expect(resp.data.data).toBeDefined()
		expect(typeof resp.data.data).toBe('object')

		// 驗證已知欄位都存在
		const data = resp.data.data
		expect(data).toHaveProperty('course_access_trigger')
		expect(data).toHaveProperty('hide_myaccount_courses')
		expect(data).toHaveProperty('fix_video_and_tabs_mobile')
		expect(data).toHaveProperty('pc_header_offset')
		expect(data).toHaveProperty('hide_courses_in_main_query')
		expect(data).toHaveProperty('hide_courses_in_search_result')
		expect(data).toHaveProperty('pc_watermark_qty')
		expect(data).toHaveProperty('pc_watermark_text')
		expect(data).toHaveProperty('pc_pdf_watermark_qty')
	})

	/* ------------------------------------------------------------------ */
	/*  2. 更新單一設定                                                    */
	/* ------------------------------------------------------------------ */
	test('更新單一設定 — pc_header_offset 改為 100', async () => {
		const postResp = await api.pcPost<SettingsPostResponse>('settings', {
			pc_header_offset: '100',
		})

		expect(postResp.status).toBe(200)
		expect(postResp.data.code).toBe('post_user_success')
		expect(postResp.data.message).toBe('修改成功')
		expect(postResp.data.data.pc_header_offset).toBe('100')

		// 用 GET 再次確認
		const getResp = await api.pcGet<SettingsGetResponse>('settings')
		expect(getResp.data.data.pc_header_offset).toBe('100')

		// 還原
		await api.pcPost<SettingsPostResponse>('settings', {
			pc_header_offset: '0',
		})
	})

	/* ------------------------------------------------------------------ */
	/*  3. 更新多個設定                                                    */
	/* ------------------------------------------------------------------ */
	test('一次更新多個設定', async () => {
		const payload = {
			pc_header_offset: '50',
			hide_myaccount_courses: 'yes',
			hide_courses_in_main_query: 'yes',
		}

		const postResp = await api.pcPost<SettingsPostResponse>('settings', payload)

		expect(postResp.status).toBe(200)
		expect(postResp.data.data.pc_header_offset).toBe('50')
		expect(postResp.data.data.hide_myaccount_courses).toBe('yes')
		expect(postResp.data.data.hide_courses_in_main_query).toBe('yes')

		// 用 GET 再次確認
		const getResp = await api.pcGet<SettingsGetResponse>('settings')
		expect(getResp.data.data.pc_header_offset).toBe('50')
		expect(getResp.data.data.hide_myaccount_courses).toBe('yes')
		expect(getResp.data.data.hide_courses_in_main_query).toBe('yes')

		// 還原
		await api.pcPost<SettingsPostResponse>('settings', {
			pc_header_offset: '0',
			hide_myaccount_courses: 'no',
			hide_courses_in_main_query: 'no',
		})
	})

	/* ------------------------------------------------------------------ */
	/*  4. 部分更新不影響其他欄位                                           */
	/* ------------------------------------------------------------------ */
	test('部分更新不影響其他欄位', async () => {
		// 先取得目前所有設定作為基準
		const before = await api.pcGet<SettingsGetResponse>('settings')
		const dataBefore = before.data.data

		// 只更新一個欄位
		await api.pcPost<SettingsPostResponse>('settings', {
			pc_header_offset: '999',
		})

		// 確認目標欄位已更新，其他欄位不變
		const after = await api.pcGet<SettingsGetResponse>('settings')
		const dataAfter = after.data.data

		expect(dataAfter.pc_header_offset).toBe('999')

		// 其他已知欄位應維持不變
		expect(dataAfter.course_access_trigger).toBe(dataBefore.course_access_trigger)
		expect(dataAfter.hide_myaccount_courses).toBe(dataBefore.hide_myaccount_courses)
		expect(dataAfter.fix_video_and_tabs_mobile).toBe(dataBefore.fix_video_and_tabs_mobile)
		expect(dataAfter.hide_courses_in_main_query).toBe(dataBefore.hide_courses_in_main_query)
		expect(dataAfter.hide_courses_in_search_result).toBe(dataBefore.hide_courses_in_search_result)
		expect(dataAfter.pc_watermark_qty).toBe(dataBefore.pc_watermark_qty)
		expect(dataAfter.pc_watermark_text).toBe(dataBefore.pc_watermark_text)
		expect(dataAfter.pc_pdf_watermark_qty).toBe(dataBefore.pc_pdf_watermark_qty)

		// 還原
		await api.pcPost<SettingsPostResponse>('settings', {
			pc_header_offset: String(dataBefore.pc_header_offset),
		})
	})

	/* ------------------------------------------------------------------ */
	/*  5. 更新浮水印設定                                                  */
	/* ------------------------------------------------------------------ */
	test('更新浮水印設定 — qty 與 text', async () => {
		const customText = '{display_name} 正在觀看 {post_title} — IP:{ip}'

		const postResp = await api.pcPost<SettingsPostResponse>('settings', {
			pc_watermark_qty: 3,
			pc_watermark_text: customText,
			pc_pdf_watermark_qty: 5,
		})

		expect(postResp.status).toBe(200)
		expect(Number(postResp.data.data.pc_watermark_qty)).toBe(3)
		expect(postResp.data.data.pc_watermark_text).toBe(customText)
		expect(Number(postResp.data.data.pc_pdf_watermark_qty)).toBe(5)

		// 用 GET 再次確認
		const getResp = await api.pcGet<SettingsGetResponse>('settings')
		expect(Number(getResp.data.data.pc_watermark_qty)).toBe(3)
		expect(getResp.data.data.pc_watermark_text).toBe(customText)
		expect(Number(getResp.data.data.pc_pdf_watermark_qty)).toBe(5)

		// 還原
		await api.pcPost<SettingsPostResponse>('settings', {
			pc_watermark_qty: 0,
			pc_watermark_text: '{display_name} {post_title} IP:{ip}',
			pc_pdf_watermark_qty: 0,
		})
	})

	/* ------------------------------------------------------------------ */
	/*  6. yes/no 開關設定                                                 */
	/* ------------------------------------------------------------------ */
	test('yes/no 開關設定 — toggle hide_myaccount_courses', async () => {
		// 設為 yes
		const yesResp = await api.pcPost<SettingsPostResponse>('settings', {
			hide_myaccount_courses: 'yes',
		})
		expect(yesResp.status).toBe(200)
		expect(yesResp.data.data.hide_myaccount_courses).toBe('yes')

		// GET 確認
		const getYes = await api.pcGet<SettingsGetResponse>('settings')
		expect(getYes.data.data.hide_myaccount_courses).toBe('yes')

		// 設為 no
		const noResp = await api.pcPost<SettingsPostResponse>('settings', {
			hide_myaccount_courses: 'no',
		})
		expect(noResp.status).toBe(200)
		expect(noResp.data.data.hide_myaccount_courses).toBe('no')

		// GET 確認
		const getNo = await api.pcGet<SettingsGetResponse>('settings')
		expect(getNo.data.data.hide_myaccount_courses).toBe('no')
	})

	/* ------------------------------------------------------------------ */
	/*  7. 還原預設值                                                      */
	/* ------------------------------------------------------------------ */
	test('還原預設值 — 所有欄位回到初始狀態', async () => {
		// 先把所有欄位改為非預設值
		await api.pcPost<SettingsPostResponse>('settings', {
			course_access_trigger: 'processing',
			hide_myaccount_courses: 'yes',
			fix_video_and_tabs_mobile: 'yes',
			pc_header_offset: '200',
			hide_courses_in_main_query: 'yes',
			hide_courses_in_search_result: 'yes',
			pc_watermark_qty: 10,
			pc_watermark_text: 'custom watermark',
			pc_pdf_watermark_qty: 8,
		})

		// 用預設值覆蓋
		const resetResp = await api.pcPost<SettingsPostResponse>('settings', DEFAULTS)

		expect(resetResp.status).toBe(200)
		expect(resetResp.data.code).toBe('post_user_success')

		// GET 確認每個欄位都回到預設值
		const getResp = await api.pcGet<SettingsGetResponse>('settings')
		const data = getResp.data.data

		expect(data.course_access_trigger).toBe('completed')
		expect(data.hide_myaccount_courses).toBe('no')
		expect(data.fix_video_and_tabs_mobile).toBe('no')
		expect(data.pc_header_offset).toBe('0')
		expect(data.hide_courses_in_main_query).toBe('no')
		expect(data.hide_courses_in_search_result).toBe('no')
		expect(Number(data.pc_watermark_qty)).toBe(0)
		expect(data.pc_watermark_text).toBeDefined()
		expect(Number(data.pc_pdf_watermark_qty)).toBe(0)
	})
})
