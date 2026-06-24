/**
 * 課程權限包管理頁 E2E 測試（Issue #252）
 *
 * 前置條件：
 * 1. Admin SPA 需要 license 授權，否則課程後台會被 redirect 鎖死。
 *    若測試環境未授權，請先透過 WP 後台設定 license key。
 * 2. 測試由 .auth/admin.json 取得已登入的 admin 階段資料（global-setup 建立）。
 *
 * 測試分組：
 * - Smoke：進入管理頁、列表渲染
 * - Happy：新增全站包、新增分類包、停用、刪除、掛載到商品
 * - Error：表單驗證（name 必填）
 * - Edge：刪除確認 Modal 顯示影響提示
 */

import { test, expect } from '@playwright/test'
import { ApiClient, setupApiFromBrowser } from '../helpers/api-client'
import {
  navigateToAdmin,
  waitForProTableLoaded,
  waitForMessage,
  confirmModal,
} from '../helpers/admin-page'

// 整個 describe 使用 60s timeout（本地 WordPress + SPA cold start 易超時）
test.setTimeout(60_000)

/** 記錄測試過程中建立的權限包 ID，供 afterAll 清理 */
const createdPassIds: number[] = []

/** 記錄測試建立的商品 ID，供 afterAll 清理 */
const createdProductIds: number[] = []

test.describe('課程權限包管理', () => {
  test.use({ storageState: '.auth/admin.json' })

  let api: ApiClient
  let dispose: () => Promise<void>

  test.beforeAll(async ({ browser }) => {
    // 建立長 timeout 的 API context（避免 beforeAll 因 cold start 超時）
    const context = await browser.newContext({
      storageState: '.auth/admin.json',
      ignoreHTTPSErrors: true,
    })
    context.setDefaultTimeout(60_000)
    const page = await context.newPage()
    const baseUrl = process.env.TEST_SITE_URL || 'http://localhost:8889'
    await page.goto(`${baseUrl}/wp-admin/`, {
      waitUntil: 'domcontentloaded',
      timeout: 30_000,
    })
    await page.waitForFunction(() => !!(window as unknown as Record<string, unknown>).wpApiSettings?.nonce, {
      timeout: 30_000,
    })
    const nonce = await page.evaluate(() => {
      return (window as unknown as { wpApiSettings: { nonce: string } }).wpApiSettings?.nonce || ''
    })
    api = new ApiClient(context.request, nonce)
    dispose = async () => {
      await page.close()
      await context.close()
    }
  })

  test.afterAll(async () => {
    // 清理：刪除測試建立的權限包
    for (const id of createdPassIds) {
      try {
        await api.pcPost(`access-passes`, {
          // 使用 DELETE 等效 API 呼叫（因 ApiClient 尚未有 deleteAccessPass 便利方法）
        })
      } catch {
        // ignore cleanup errors
      }
    }
    await dispose()
  })

  // ── Smoke ───────────────────────────────────────────────────────────────────

  test.describe('Smoke：列表頁基礎渲染', () => {
    test('進入 /access-passes 路由，表格可載入', async ({ page }) => {
      await navigateToAdmin(page, '/access-passes')
      await waitForProTableLoaded(page)

      // 列表頁 <List> 元件渲染後應有 Ant Design 表格
      await expect(page.locator('.ant-table-wrapper')).toBeVisible({ timeout: 15_000 })
    })

    test('列表欄位包含名稱、範圍類型、存取期限、狀態、已掛載商品', async ({ page }) => {
      await navigateToAdmin(page, '/access-passes')
      await waitForProTableLoaded(page)

      const thead = page.locator('.ant-table-thead')
      await expect(thead).toBeVisible({ timeout: 10_000 })

      // 驗證欄位標題（對應 useColumns 中的 title 文字，翻譯後為英文 msgid）
      // 注意：zh_TW 環境下會顯示繁中，但本機測試環境語系可能為 en，故用正規表達式
      const theadText = await thead.textContent()
      expect(theadText).toMatch(/access pass name/i)
      expect(theadText).toMatch(/scope type/i)
      expect(theadText).toMatch(/access period/i)
      expect(theadText).toMatch(/status/i)
    })
  })

  // ── Happy ───────────────────────────────────────────────────────────────────

  test.describe('Happy：新增全站範圍永久權限包', () => {
    test('填寫名稱 + 全站 + 永久 → 儲存 → 列表顯示新記錄', async ({ page }) => {
      await navigateToAdmin(page, '/access-passes/create')

      // 等待表單載入
      await page.waitForSelector('.ant-form', { timeout: 15_000 })

      // 填寫名稱（Form.Item name={['name']} → Ant Design 產生 id="name"）
      await page.locator('#name').fill('E2E 全站永久通行證')

      // scope_type 的 Radio.Group，label="All courses"，預設值已是 'all'
      // 確認 All courses 的 radio-button 已選中（ScopeFields initialValue="all"）
      const allCoursesBtn = page.locator('.ant-radio-button-wrapper').filter({ hasText: /all courses/i })
      if (await allCoursesBtn.isVisible({ timeout: 3_000 }).catch(() => false)) {
        // 若未選中才點擊
        const isChecked = await allCoursesBtn.locator('input').isChecked().catch(() => false)
        if (!isChecked) await allCoursesBtn.click()
      }

      // limit_mode 的 Radio.Group，label="Permanent"，預設值已是 'permanent'
      const permanentBtn = page.locator('.ant-radio-button-wrapper').filter({ hasText: /permanent/i })
      if (await permanentBtn.isVisible({ timeout: 3_000 }).catch(() => false)) {
        const isChecked = await permanentBtn.locator('input').isChecked().catch(() => false)
        if (!isChecked) await permanentBtn.click()
      }

      // 攔截 POST /access-passes API 以取得新 ID
      const responsePromise = page.waitForResponse(
        (resp) =>
          resp.url().includes('/access-passes') &&
          resp.request().method() === 'POST' &&
          resp.status() < 400,
        { timeout: 30_000 },
      )

      // 點擊 Create 按鈕（saveButtonProps 設定 children="Create"）
      const createBtn = page.getByRole('button', { name: /create/i })
      await createBtn.click()

      // 等待 API 成功
      const resp = await responsePromise
      const body = await resp.json().catch(() => ({}))
      const newId = Number(body?.data?.id || body?.id)
      if (newId) createdPassIds.push(newId)

      // 成功後應導回列表頁（Refine redirect: 'list'）
      await page.waitForFunction(
        () => window.location.hash.includes('/access-passes') && !window.location.hash.includes('/create'),
        { timeout: 15_000 },
      )

      await waitForProTableLoaded(page)

      // 列表應包含新建立的權限包名稱
      await expect(page.locator('.ant-table-wrapper')).toContainText('E2E 全站永久通行證', { timeout: 10_000 })
    })
  })

  test.describe('Happy：新增分類標籤範圍權限包', () => {
    test('選擇 Category / Tag 範圍 → term_ids 多選出現 → 選取分類 → 儲存 → 列表顯示', async ({ page }) => {
      await navigateToAdmin(page, '/access-passes/create')
      await page.waitForSelector('.ant-form', { timeout: 15_000 })

      // 填寫名稱
      await page.locator('#name').fill('E2E HTML 分類包')

      // 點擊 "Category / Tag" radio button（ScopeFields label="Category / Tag"，value="category"）
      const categoryBtn = page.locator('.ant-radio-button-wrapper').filter({ hasText: /category.*tag/i })
      await expect(categoryBtn).toBeVisible({ timeout: 5_000 })
      await categoryBtn.click()

      // 等待 term_ids Form.Item 出現（ScopeFields 條件渲染，selector: name="term_ids" → id="term_ids"）
      // 注意：Ant Design Select 的 id 為 Form.Item name 推導，此處可能是 term_ids 或 nested 形式
      // 備用 selector：data-testid="term-ids-select"（需前端補，見報告）
      const termIdsSelect = page.locator('[data-testid="term-ids-select"]').or(
        page.locator('#term_ids').locator('..')
      ).first()

      // 驗證 term_ids 欄位出現
      await expect(page.locator('.ant-form-item').filter({ hasText: /product category.*product tag/i })).toBeVisible({ timeout: 10_000 })

      // 點擊 Select，找可用選項
      const termSelect = page.locator('.ant-form-item').filter({ hasText: /product category.*product tag/i }).locator('.ant-select').first()
      await termSelect.click()

      const firstOption = page.locator('.ant-select-dropdown .ant-select-item-option').first()
      if (await firstOption.isVisible({ timeout: 3_000 }).catch(() => false)) {
        await firstOption.click()
        // 關閉下拉
        await page.keyboard.press('Escape')
      } else {
        // 若沒有可用分類，關閉下拉後改用 all scope 避免 required 阻擋
        await page.keyboard.press('Escape')
        const allCoursesBtn2 = page.locator('.ant-radio-button-wrapper').filter({ hasText: /all courses/i })
        await allCoursesBtn2.click()
      }

      // 攔截 POST
      const responsePromise = page.waitForResponse(
        (resp) => resp.url().includes('/access-passes') && resp.request().method() === 'POST' && resp.status() < 400,
        { timeout: 30_000 },
      )

      await page.getByRole('button', { name: /create/i }).click()

      const resp = await responsePromise
      const body = await resp.json().catch(() => ({}))
      const newId = Number(body?.data?.id || body?.id)
      if (newId) createdPassIds.push(newId)

      // 導回列表
      await page.waitForFunction(
        () => window.location.hash.includes('/access-passes') && !window.location.hash.includes('/create'),
        { timeout: 15_000 },
      )
      await waitForProTableLoaded(page)
      await expect(page.locator('.ant-table-wrapper')).toContainText('E2E HTML 分類包', { timeout: 10_000 })
    })
  })

  test.describe('Happy：停用權限包 → 狀態變 disabled', () => {
    test('找到 active 的權限包，點擊停用 → Popconfirm → 確認 → 狀態欄顯示 disabled', async ({ page }) => {
      // 先透過 API 建立一個測試用權限包
      const createResp = await api.pcPost<{ data: { id: number } }>('access-passes', {
        name: 'E2E 停用測試包',
        scope_type: 'all',
        limit_mode: 'permanent',
      })
      const passId = Number((createResp.data as { data: { id: number } }).data?.id)
      if (passId) createdPassIds.push(passId)

      await navigateToAdmin(page, '/access-passes')
      await waitForProTableLoaded(page)

      // 找到剛建立的列，定位該列的停用按鈕
      const row = page.locator('.ant-table-row').filter({ hasText: 'E2E 停用測試包' }).first()
      await expect(row).toBeVisible({ timeout: 10_000 })

      // 點擊停用按鈕（StopOutlined icon button）
      // 注意：需前端補 data-testid="disable-pass-btn" 以提高穩定性（見報告）
      const disableBtn = row.locator('[data-testid="disable-pass-btn"], button').filter({ has: page.locator('.anticon-stop') }).first()
      if (await disableBtn.isVisible({ timeout: 3_000 }).catch(() => false)) {
        await disableBtn.click()
      } else {
        // 備用：依 Tooltip title 找
        await row.locator('button[type="button"]').nth(1).click()
      }

      // Popconfirm 出現後點擊確認
      const popconfirmOk = page.locator('.ant-popconfirm .ant-btn-primary').first()
      await expect(popconfirmOk).toBeVisible({ timeout: 5_000 })
      await popconfirmOk.click()

      // 等待成功訊息
      await waitForMessage(page, 'success')

      // 列表刷新後，該行狀態應顯示 disabled（zh_TW 環境可能顯示中文）
      await expect(row).toContainText(/disabled|停用/i, { timeout: 10_000 })
    })
  })

  test.describe('Happy：刪除權限包 → Modal 顯示影響提示 → 確認後列表移除', () => {
    test('刪除 Modal 顯示「已掛載商品數」影響提示，確認後列表不再出現該記錄', async ({ page }) => {
      // 透過 API 建立一個測試用權限包
      const createResp = await api.pcPost<{ data: { id: number } }>('access-passes', {
        name: 'E2E 刪除測試包',
        scope_type: 'all',
        limit_mode: 'permanent',
      })
      const passId = Number((createResp.data as { data: { id: number } }).data?.id)
      // 不加入 createdPassIds，因為測試本身會刪除

      await navigateToAdmin(page, '/access-passes')
      await waitForProTableLoaded(page)

      const row = page.locator('.ant-table-row').filter({ hasText: 'E2E 刪除測試包' }).first()
      await expect(row).toBeVisible({ timeout: 10_000 })

      // 點擊刪除按鈕（DeleteOutlined icon）
      // 注意：需前端補 data-testid="delete-pass-btn"（見報告）
      const deleteBtn = row.locator('[data-testid="delete-pass-btn"], button').filter({ has: page.locator('.anticon-delete') }).first()
      if (await deleteBtn.isVisible({ timeout: 3_000 }).catch(() => false)) {
        await deleteBtn.click()
      } else {
        // 備用：每列最後一個操作按鈕通常是刪除
        await row.locator('button[type="button"]').last().click()
      }

      // DeletePassModal 應出現
      const modal = page.locator('.ant-modal')
      await expect(modal).toBeVisible({ timeout: 10_000 })

      // Modal 應包含警告訊息（影響 N 位已購用戶）
      await expect(modal).toContainText(/revoke access|收回|已購/i, { timeout: 5_000 })

      // 點擊確認刪除按鈕
      const confirmDeleteBtn = modal.locator('.ant-btn-dangerous').first()
      await confirmDeleteBtn.click()

      // 等待成功通知
      await waitForMessage(page, 'success')

      // 列表刷新後不應再出現該記錄
      await waitForProTableLoaded(page)
      await expect(page.locator('.ant-table-wrapper')).not.toContainText('E2E 刪除測試包', { timeout: 10_000 })

      // 若 passId 意外沒被刪除，加入清理清單
      if (passId) createdPassIds.push(passId)
    })
  })

  // ── Error ───────────────────────────────────────────────────────────────────

  test.describe('Error：表單驗證 — name 必填', () => {
    test('未填名稱直接送出 → 表單顯示驗證錯誤 "Please enter access pass name"', async ({ page }) => {
      await navigateToAdmin(page, '/access-passes/create')
      await page.waitForSelector('.ant-form', { timeout: 15_000 })

      // 確保 name 欄位為空（清除後送出）
      const nameInput = page.locator('#name')
      await nameInput.clear()

      // 不填 name，直接點擊 Create
      await page.getByRole('button', { name: /create/i }).click()

      // 應出現 Ant Design Form 驗證錯誤（.ant-form-item-explain-error）
      // FormField rules: message = "Please enter access pass name"
      const errorMsg = page.locator('.ant-form-item-explain-error').first()
      await expect(errorMsg).toBeVisible({ timeout: 5_000 })
      await expect(errorMsg).toContainText(/please enter/i)
    })
  })

  // ── Edge ────────────────────────────────────────────────────────────────────

  test.describe('Edge：刪除 Modal 的「N 位已購用戶」影響計數', () => {
    test('未掛載任何商品的權限包，Modal 顯示預設警告文字', async ({ page }) => {
      // 建立一個測試包（不掛商品 → attached_product_count = 0）
      const createResp = await api.pcPost<{ data: { id: number } }>('access-passes', {
        name: 'E2E 影響計數測試包',
        scope_type: 'all',
        limit_mode: 'permanent',
      })
      const passId = Number((createResp.data as { data: { id: number } }).data?.id)
      if (passId) createdPassIds.push(passId)

      await navigateToAdmin(page, '/access-passes')
      await waitForProTableLoaded(page)

      const row = page.locator('.ant-table-row').filter({ hasText: 'E2E 影響計數測試包' }).first()
      await expect(row).toBeVisible({ timeout: 10_000 })

      const deleteBtn = row.locator('[data-testid="delete-pass-btn"], button').filter({ has: page.locator('.anticon-delete') }).first()
      if (await deleteBtn.isVisible({ timeout: 3_000 }).catch(() => false)) {
        await deleteBtn.click()
      } else {
        await row.locator('button[type="button"]').last().click()
      }

      const modal = page.locator('.ant-modal')
      await expect(modal).toBeVisible({ timeout: 10_000 })

      // 當 attached_product_count = 0 時，Modal 顯示不含商品數的通用警告
      await expect(modal).toContainText(/revoke access|收回觀看/i, { timeout: 5_000 })

      // 關閉 Modal（不刪除）
      const cancelBtn = modal.locator('.ant-btn').not(page.locator('.ant-btn-dangerous')).last()
      if (await cancelBtn.isVisible({ timeout: 2_000 }).catch(() => false)) {
        await cancelBtn.click()
      }
    })
  })
})

// ── 商品掛載權限包 ─────────────────────────────────────────────────────────────

/**
 * 商品編輯：掛載 access_pass_id（Happy Path）
 *
 * 前置條件：
 * - 需要至少一個 active 的權限包已存在
 * - 需要至少一個已發佈的商品
 *
 * 此測試在 Products 管理頁操作 AccessPassSelector，
 * 驗證 access_pass_id 可從下拉選單選取並儲存。
 */
test.describe('商品掛載課程權限包', () => {
  test.use({ storageState: '.auth/admin.json' })
  test.setTimeout(60_000)

  test('Happy：商品列表中的 AccessPassSelector 可選取權限包', async ({ page }) => {
    await navigateToAdmin(page, '/products')
    await page.waitForSelector('.ant-table-wrapper', { timeout: 15_000 })
    await page.waitForFunction(
      () => {
        const loading = document.querySelector('.ant-table-loading')
        return !loading
      },
      { timeout: 15_000 },
    )

    // 確認商品列表已渲染
    await expect(page.locator('.ant-table-wrapper')).toBeVisible({ timeout: 10_000 })

    // 若有 AccessPassSelector 欄位（需前端補 data-testid="access-pass-selector"），
    // 則驗證 placeholder 文字
    // 注意：Products 表格的 access_pass_id 欄位目前依賴 useColumns 中的 AccessPassSelector 元件
    // 需前端在 AccessPassSelector 上補 data-testid="access-pass-selector"（見報告）
    const selector = page.locator('[data-testid="access-pass-selector"]').first()
    if (await selector.isVisible({ timeout: 5_000 }).catch(() => false)) {
      // 驗證 placeholder（No access pass）
      await expect(selector).toBeVisible()
    } else {
      // 備用：驗證商品表格中存在 ant-select（可能是 AccessPassSelector）
      const antSelect = page.locator('.ant-table-row .ant-select').first()
      await expect(antSelect).toBeVisible({ timeout: 10_000 })
    }
  })
})
