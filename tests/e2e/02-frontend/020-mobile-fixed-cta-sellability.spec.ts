/**
 * 測試目標：行動裝置底部固定 CTA 隨銷售狀態變化（Issue #262）
 * 對應原始碼：inc/templates/pages/course-product/body.php
 *
 * 前置條件：課程開啟 enable_mobile_fixed_cta、viewport < 810px（md 斷點）
 * 預期結果：
 * - 有可售方案            → 可點的 <a>，錨到 #course-pricing
 * - 方案全數下線          → 停用的 <button disabled>，不得改賣課程商品本體
 * - 課程從未建立任何方案  → 可點的 <a>，直接把課程本體加入購物車（既有行為）
 */

import { test, expect } from '@playwright/test'
import { setupApiFromBrowser } from '../helpers/api-client'

/** CTA 容器：body.php 中唯一的 md:hidden 固定底部區塊 */
const CTA_BOX = 'div.tw-fixed.bottom-0'

test.describe('行動裝置固定 CTA 隨銷售狀態變化', () => {
	// 手機寬度才會渲染此 CTA（Tailwind md 斷點）
	test.use({
		storageState: '.auth/admin.json',
		viewport: { width: 390, height: 844 },
		// 每次都帶 cache buster、繞過站台頁面快取，課程頁必須整頁重新渲染，
		// 在本機站上會超過預設的 navigationTimeout 15s。
		actionTimeout: 45_000,
		navigationTimeout: 90_000,
	})
	test.describe.configure({ timeout: 240_000, mode: 'serial' })

	let courseId: number
	let bundleId: number
	let soloCourseId: number
	let courseUrl: string
	let soloCourseUrl: string

	test.beforeAll(async ({ browser }) => {
		// describe.configure 的 timeout 不套用到 hook，需在 hook 內顯式放寬；
		// 本 hook 要建兩門課程 + 一個方案，在較慢的本機站上會超過預設的 30s。
		test.setTimeout(240_000)

		const { api, dispose } = await setupApiFromBrowser(browser)
		try {
			// 課程 A：有銷售方案
			courseId = await api.createCourse('E2E CTA 可售狀態課程')
			await api.updateCourse(courseId, {
				type: 'simple',
				regular_price: '1500',
				enable_mobile_fixed_cta: 'yes',
			})
			courseUrl = await api.getCourseUrl(courseId)

			const resp = await api.pcPostForm('bundle_products', {
				name: 'E2E CTA 方案',
				type: 'simple',
				bundle_type: 'single_course',
				status: 'publish',
				regular_price: '399',
				link_course_ids: [courseId],
			})
			bundleId = Number((resp.data as { data?: { id?: string } })?.data?.id)
			expect(bundleId, 'bundle 應建立成功').toBeGreaterThan(0)

			// 課程 B：完全沒有銷售方案
			soloCourseId = await api.createCourse('E2E CTA 無方案課程')
			await api.updateCourse(soloCourseId, {
				type: 'simple',
				regular_price: '990',
				enable_mobile_fixed_cta: 'yes',
			})
			soloCourseUrl = await api.getCourseUrl(soloCourseId)
		} finally {
			await dispose()
		}
	})

	test.afterAll(async ({ browser }) => {
		test.setTimeout(120_000)

		const { api, dispose } = await setupApiFromBrowser(browser)
		try {
			const ids = [courseId, soloCourseId].filter(Boolean)
			if (ids.length) {
				await api.deleteCourses(ids)
			}
		} finally {
			await dispose()
		}
	})

	/** 加上 cache buster，避開站台的頁面快取（本機站有 WP Rocket） */
	const fresh = (url: string) =>
		`${url}${url.includes('?') ? '&' : '?'}e2e=${Date.now()}`

	test('有可售方案：CTA 可點並錨到方案區塊', async ({ page }) => {
		await page.goto(fresh(courseUrl), { waitUntil: 'domcontentloaded' })

		const cta = page.locator(`${CTA_BOX} a, ${CTA_BOX} button`).first()
		await expect(cta, 'CTA 應渲染').toBeVisible()
		await expect(cta).toHaveJSProperty('tagName', 'A')
		await expect(cta).toHaveAttribute('href', '#course-pricing')
	})

	test('方案全數下線：CTA 停用，且不改賣課程商品本體', async ({
		browser,
		page,
	}) => {
		const { api, dispose } = await setupApiFromBrowser(browser)
		try {
			await api.pcPostForm(`bundle_products/${bundleId}`, {
				type: 'simple',
				status: 'draft',
			})
		} finally {
			await dispose()
		}

		await page.goto(fresh(courseUrl), { waitUntil: 'domcontentloaded' })

		const cta = page.locator(`${CTA_BOX} a, ${CTA_BOX} button`).first()
		await expect(cta, 'CTA 應渲染').toBeVisible()
		await expect(cta).toHaveJSProperty('tagName', 'BUTTON')
		await expect(cta).toBeDisabled()

		// 關鍵：不得退回「把課程商品本體加入購物車」（Issue #262 的第三個症狀）
		const html = await page.locator(CTA_BOX).first().innerHTML()
		expect(html, '無可售方案時不應出現 add-to-cart 連結').not.toContain(
			'add-to-cart',
		)
	})

	test('課程從未建立方案：CTA 可點並直接加入購物車', async ({ page }) => {
		await page.goto(fresh(soloCourseUrl), { waitUntil: 'domcontentloaded' })

		const cta = page.locator(`${CTA_BOX} a, ${CTA_BOX} button`).first()
		await expect(cta, 'CTA 應渲染').toBeVisible()
		await expect(cta).toHaveJSProperty('tagName', 'A')
		await expect(cta).toHaveAttribute('href', /add-to-cart=\d+/)
	})
})
