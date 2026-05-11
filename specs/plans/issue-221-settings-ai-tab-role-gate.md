# Issue #221 實作計畫：Settings 頁面 AI Tab 僅對 admin 可見

> 規格來源：`specs/open-issue/221-settings-ai-tab-role-gate.md`、`specs/features/settings/AI_TAB角色可見性.feature`
> 釐清結論：`A A A A C A A`（隱藏 AI Tab / `manage_options` / env 加密注入 `IS_ADMIN` / hash redirect / 建立 reusable `<RoleGate>` / E2E 覆蓋 / 不新增翻譯字串）
> 範圍模式：**HOLD SCOPE**（明確 bug + UX 修正，影響 5 個檔案，不擴張範圍）

---

## 1. 目標與不目標

### ✅ 目標

- 非 admin（非 `manage_options`）使用者進入 `wp-admin/admin.php?page=power-course#/settings` 時，**看不到 AI Tab**
- 維持後端 REST permission_callback（`current_user_can('manage_options')`）為**唯一安全邊界**；前端 gate 僅為 UX 改善
- 提供 reusable `<RoleGate>` 元件，預留給未來其他敏感 tab／頁面套用
- 自動化 E2E 覆蓋 administrator + shop_manager 兩個角色，杜絕回歸

### ❌ 不目標（明確不做）

- ❌ 不放寬 AI Tab 後端權限（保持 `manage_options`，不開放給 shop_manager / editor / subscriber）
- ❌ 不動 General / Appearance / Auto-grant 三個 tab 的可見性
- ❌ 不為 AI Tab 加 disabled tooltip 或 fallback 頁（Q1 採方案 A：直接隱藏）
- ❌ 不新增任何使用者可見 UI 文字（Q7：方案 A，零新字串、不必跑 `pnpm run i18n:build`）
- ❌ 不動 REST endpoint、不動 `inc/classes/Api/Mcp/RestController.php` 任何邏輯
- ❌ 不在本 issue 推送 hash route 受控化（目前 `Tabs` 是 `defaultActiveKey="general"` 非受控，URL hash 不參與 tab 切換；Q4 redirect 邏輯依規格 doc 第 149 行 **目前無需實作**，僅留設計決策供未來受控 tab 使用）

---

## 2. 資料流與信任邊界

```
[PHP / Bootstrap::enqueue_script]
   ├─ current_user_can('manage_options')  →  bool $is_admin
   ├─ 寫入 PowerhouseUtils::simple_encrypt 加密 env：'IS_ADMIN' => $is_admin
   └─ wp_localize_script → window.power_course_data.env （加密字串）
                                  │
                                  ▼
[JS / utils/env.tsx]
   ├─ simpleDecrypt(window.power_course_data.env)
   └─ export const IS_ADMIN: boolean = Boolean(env?.IS_ADMIN)   ← fail-safe
                                  │
                ┌─────────────────┴─────────────────┐
                ▼                                   ▼
[components/RoleGate/index.tsx]            [pages/admin/Settings/index.tsx]
  capability === 'admin' && !IS_ADMIN        getItems() 內 if (IS_ADMIN) push AI tab
  → return fallback                          → AI tab key 對非 admin 完全不存在
  否則 return children
```

**信任邊界（必須在 PR review 反覆強調）：**

| 層級 | 角色 | 強度 |
|------|------|------|
| 後端 REST `permission_callback('manage_options')` | **真正的權限執行點** | ✅ 安全邊界 |
| 加密 env 的 `IS_ADMIN` boolean | UX 用，可被使用者篡改 | ❌ **不是**安全邊界 |
| 前端 `<RoleGate>` / `getItems()` 過濾 | UX 用，DOM 上可被注入 | ❌ **不是**安全邊界 |

> 即使攻擊者透過 devtools 強制 render AI Tab，後端 REST 仍會回 403（feature 規格 `不變式` 已要求）。

---

## 3. 修改清單

### 3.1 新建檔案

| # | 檔案 | 目的 |
|---|------|------|
| N1 | `js/src/components/RoleGate/index.tsx` | Reusable role gate 元件（Q5 採方案 C 預留擴充） |
| N2 | `tests/e2e/01-admin/settings-ai-tab-role-gate.spec.ts` | E2E 驗證 admin 看到 AI tab / shop_manager 看不到 |

### 3.2 修改檔案

| # | 檔案 | 修改摘要 |
|---|------|---------|
| M1 | `inc/classes/Bootstrap.php` | `enqueue_script()` 加密 env 陣列加入 `'IS_ADMIN' => \current_user_can('manage_options')` |
| M2 | `js/src/utils/env.tsx` | 新 `export const IS_ADMIN: boolean = Boolean(env?.IS_ADMIN)` |
| M3 | `js/src/pages/admin/Settings/index.tsx` | `getItems()` 改用 mutable array + `if (IS_ADMIN) items.push(AI)` |

### 3.3 不修改但需確認的檔案

- `inc/classes/Api/Mcp/RestController.php`（後端 `permission_callback` 保持不動）
- `inc/classes/Api/Mcp/AbstractTool.php`（權限模型保持不動）
- `tests/e2e/01-admin/ai-tab.spec.ts`（既有 Issue #217 測試，必須保持綠燈；其使用 admin storage state，與本 issue 邏輯一致）

---

## 4. 詳細實作步驟

### Step M1：`inc/classes/Bootstrap.php` 加密 env 加入 IS_ADMIN

**位置**：第 255–273 行，`PowerhouseUtils::simple_encrypt` 的陣列內。

**修改點**：在 `'COURSE_PERMALINK_STRUCTURE'` 之後追加：

```php
'IS_ADMIN'                   => \current_user_can('manage_options'),
```

**注意事項**：

- `current_user_can('manage_options')` 是 WordPress 標準 admin capability；multisite 環境中 super admin 也會回 `true`，與後端 REST `permission_callback` 一致。
- 因為 `enqueue_script()` 是 `public static`，在 admin 與 frontend 兩條進入點都會跑——本 boolean 對前台 vanilla TS 無害（前台不讀 `IS_ADMIN`，但留著供未來使用）。
- 不會破壞既有 env 的反向相容：舊版 React bundle 多收到一個欄位不會錯。
- PHPCS / PHPStan level 9 不會有問題（`current_user_can` 在 WP stub 內已宣告回傳 `bool`）。

### Step M2：`js/src/utils/env.tsx` 匯出 IS_ADMIN

**修改點**：在第 14 行 `DEFAULT_IMAGE` 之前追加：

```tsx
export const IS_ADMIN: boolean = Boolean(env?.IS_ADMIN)
```

**注意事項**：

- 使用 `Boolean(env?.IS_ADMIN)`（不是 `env?.IS_ADMIN ?? false`），確保 `undefined`、`null`、`0`、`'false'`（字串）等所有 falsy 情況一律 fallback 為 `false`，符合 **fail-safe**（feature 規格 example：「env.IS_ADMIN 缺失時 fallback 為 false」）。
- TypeScript strict mode 下顯式標註 `: boolean` 型別。
- 不破壞既有 `env`/`API_URL`/`APP1_SELECTOR`/`APP2_SELECTOR`/`DEFAULT_IMAGE` 任一 export。

### Step N1：`js/src/components/RoleGate/index.tsx` Reusable 元件

**完整新檔內容**：

```tsx
/**
 * RoleGate
 *
 * UI 層的可見性閘門元件。
 * 注意：本元件不是安全邊界——真正的權限執行由後端 REST permission_callback 負責。
 */
import { ReactNode } from 'react'

import { IS_ADMIN } from '@/utils/env'

type Capability = 'admin'

type RoleGateProps = {
	/** 需要的 capability 等級，目前僅支援 'admin' */
	capability?: Capability
	/** 通過時 render 的內容 */
	children: ReactNode
	/** 未通過時 render 的內容（預設 null） */
	fallback?: ReactNode
}

export const RoleGate = ({
	capability = 'admin',
	children,
	fallback = null,
}: RoleGateProps) => {
	if (capability === 'admin' && !IS_ADMIN) {
		return <>{fallback}</>
	}
	return <>{children}</>
}

export default RoleGate
```

**注意事項**：

- 同時提供 named export（`RoleGate`）與 default export，與專案內 `components/` 慣例一致。
- `capability` 用 union type `'admin'` 預留擴充：未來若需要 `'shop_manager'`、`'editor'` 等級，只需擴 union + 對應 env 欄位，使用點不需大改。
- `react.rule.md` 規範：使用 Tab 縮排、單引號、不加分號、目錄名即元件名（`RoleGate/index.tsx`）。
- 本 issue 採用 `getItems()` 過濾方案（Step M3），所以 `<RoleGate>` 元件**雖建立但不在 Settings 中使用**——這是刻意決策（規格 doc 第 135 行）。元件本身仍交付供未來其他 tab 套用，並由 E2E spec 額外驗證（見 Step N2 後段）。

### Step M3：`js/src/pages/admin/Settings/index.tsx` 條件式 push AI Tab

**修改點**：把現有的 array literal 改為 mutable array + 條件 push。

**Before**：

```tsx
const getItems = (): TabsProps['items'] => [
	{ key: 'general', label: __('General settings', 'power-course'), children: <General /> },
	{ key: 'appearance', label: __('Appearance settings', 'power-course'), children: <Appearance /> },
	{ key: 'auto-grant', label: __('Auto-grant', 'power-course'), children: <AutoGrant /> },
	{ key: 'ai', label: __('AI', 'power-course'), children: <AiTabLoader /> },
]
```

**After**：

```tsx
import { IS_ADMIN } from '@/utils/env'

const getItems = (): TabsProps['items'] => {
	const items: NonNullable<TabsProps['items']> = [
		{ key: 'general', label: __('General settings', 'power-course'), children: <General /> },
		{ key: 'appearance', label: __('Appearance settings', 'power-course'), children: <Appearance /> },
		{ key: 'auto-grant', label: __('Auto-grant', 'power-course'), children: <AutoGrant /> },
	]

	if (IS_ADMIN) {
		items.push({
			key: 'ai',
			label: __('AI', 'power-course'),
			children: <AiTabLoader />,
		})
	}

	return items
}
```

**注意事項**：

- 用 `NonNullable<TabsProps['items']>` 解決 `TabsProps['items']` 是 `TabItemType[] | undefined` 的型別問題（`.push` 在 union 上不可用）。
- AI tab key 在非 admin 路徑下**完全不存在於 items 陣列**，等同 React 不會 mount `<AiTabLoader>`／不會 trigger lazy `import('./Ai')`，符合 feature 規格 example：「DOM 中不應掛載 AiTabLoader 元件」。
- `defaultActiveKey="general"` 不需動，第一個 tab 始終是 General。
- import 順序遵守 `react.rule.md`：builtin → external → internal → parent → sibling，需空行分隔。`IS_ADMIN` 來自 `@/utils/env` 屬 internal（第 3 群組）。
- 不新增任何使用者可見字串，**不需要**跑 `pnpm run i18n:build`、不會動 `.pot`／`.po`／`.mo`／`.json`（feature 規格 Q7）。

### Step N2：`tests/e2e/01-admin/settings-ai-tab-role-gate.spec.ts` E2E 測試

**測試矩陣**：

| 角色 | 期望 |
|------|------|
| `administrator` | Settings 頁出現 **4 個** tab，含 "AI" |
| `shop_manager` | Settings 頁出現 **3 個** tab，**不含** "AI" |

**測試骨架**：

```ts
/**
 * Issue #221 E2E：Settings AI Tab 角色可見性
 *
 * 驗收：
 *   - administrator 看到 4 個 tab（含 AI）
 *   - shop_manager 看到 3 個 tab（不含 AI）
 *   - DOM 中對非 admin 不應掛載 AiTabLoader
 */
import { test, expect, type BrowserContext } from '@playwright/test'

import { setupApiFromBrowser, type ApiClient } from '../helpers/api-client'
import { navigateToAdmin, waitForFormLoaded } from '../helpers/admin-page'

const BASE_URL = process.env.TEST_SITE_URL || 'http://localhost:8889'

const MANAGER = {
	username: 'e2e_role_manager',
	password: 'e2e_role_manager_pass',
	email: 'e2e_role_manager@test.local',
}

let adminApi: ApiClient
let disposeAdmin: () => Promise<void>

async function loginAndSaveSession(
	ctx: BrowserContext,
	username: string,
	password: string,
): Promise<void> {
	const page = await ctx.newPage()
	await page.goto(`${BASE_URL}/wp-login.php`, { waitUntil: 'domcontentloaded' })
	await page.fill('#user_login', username)
	await page.fill('#user_pass', password)
	await page.locator('#wp-submit').click()
	await page.waitForURL((url) => !url.pathname.includes('wp-login'), { timeout: 15_000 })
	await page.close()
}

test.describe('Settings AI Tab 角色可見性（Issue #221）', () => {
	test.beforeAll(async ({ browser }) => {
		;({ api: adminApi, dispose: disposeAdmin } = await setupApiFromBrowser(browser))
		await adminApi.ensureUser(MANAGER.username, MANAGER.email, MANAGER.password, ['shop_manager'])
	})

	test.afterAll(async () => {
		await disposeAdmin()
	})

	test('administrator 看得到 AI Tab（共 4 個 tab）', async ({ page }) => {
		// 預設 storageState 已是 admin
		await navigateToAdmin(page, '/settings')
		await waitForFormLoaded(page)

		const tabs = page.locator('.ant-tabs-tab')
		await expect(tabs).toHaveCount(4)
		await expect(page.getByRole('tab', { name: /AI/ })).toBeVisible()
	})

	test('shop_manager 看不到 AI Tab（僅 3 個 tab）', async ({ browser }) => {
		// 切換成 shop_manager session
		const ctx = await browser.newContext({ ignoreHTTPSErrors: true })
		await loginAndSaveSession(ctx, MANAGER.username, MANAGER.password)
		const page = await ctx.newPage()

		await navigateToAdmin(page, '/settings')
		await waitForFormLoaded(page)

		const tabs = page.locator('.ant-tabs-tab')
		await expect(tabs).toHaveCount(3)
		await expect(page.getByRole('tab', { name: /AI/ })).toHaveCount(0)

		await ctx.close()
	})
})
```

**注意事項**：

- 使用既有的 `setupApiFromBrowser`、`ensureUser`（已驗證可建立 shop_manager 角色帳號）。
- shop_manager 測試**自建獨立 BrowserContext**，避免污染 admin storageState；測試結束 `ctx.close()` 清理。
- `navigateToAdmin` + `waitForFormLoaded` 已處理 SPA loading + Tabs 渲染等待。
- 不依賴文字 label 的 i18n（用 `getByRole('tab', { name: /AI/ })` regex），中／英／日 locale 均可通過。
- E2E spec 不直接測試 `<RoleGate>` 元件邏輯（element 級別由 ts/tsx 編譯期型別檢查與 React 行為保證）；其 UI 行為在未來真正使用該元件的 tab 出現時補測。

---

## 5. 實作順序（依依賴關係）

| 順序 | 步驟 | 依賴 | 備註 |
|------|------|------|------|
| 1 | M1 Bootstrap.php 注入 `IS_ADMIN` | — | 後端先就緒，前端讀才有值 |
| 2 | M2 env.tsx 匯出 `IS_ADMIN` | M1 | 純前端 build-time |
| 3 | N1 RoleGate 元件 | M2 | 依賴 `IS_ADMIN` export；本 issue 未使用，但先建立交付 |
| 4 | M3 Settings index.tsx 條件式 push | M2 | 主功能落地 |
| 5 | N2 E2E spec | M1+M2+M3 全部完成 | 整合驗收 |

> **可平行性**：M1 與 M2 可平行寫（兩個檔案無互相依賴的程式碼，僅 runtime 上前端讀 PHP 注入值）；N1 與 M3 也可平行。但測試 N2 必須最後跑。

---

## 6. 測試策略

### 6.1 自動化測試（必跑）

| 類型 | 範圍 | 指令 |
|------|------|------|
| **E2E（admin project）** | 新增 `settings-ai-tab-role-gate.spec.ts`（2 個 test case） | `pnpm run test:e2e:admin` |
| **E2E 回歸** | 既有 `ai-tab.spec.ts`（Issue #217）必須保持綠燈 | 同上 |
| **TypeScript build** | 確認 `IS_ADMIN` export、`RoleGate` 元件、`Settings/index.tsx` 通過 strict mode | `pnpm run build` |
| **ESLint + Prettier** | 確認新檔符合 `react.rule.md`（Tab 縮排、單引號、無分號） | `pnpm run lint:ts` |
| **PHPStan level 9** | 確認 Bootstrap.php 修改不破壞型別 | `composer run phpstan` |
| **PHPCS** | WordPress coding standards | `pnpm run lint:php` |

### 6.2 手動驗證（建議在 PR review 前跑）

- 在本地 `https://local-turbo.powerhouse.tw/wp-admin` 用 admin 登入，確認看到 4 tab、AI tab 內容正常
- 用 playwright-cli 切換到 shop_manager 帳號（或新建一個），確認只看到 3 tab、無 AI 字樣
- 開啟 devtools console 執行：
  ```js
  const env = window.power_course_data.env
  // 用 antd-toolkit 的 simpleDecrypt 解密確認 IS_ADMIN 欄位
  ```
- 嘗試攻擊面：以 shop_manager 身分手動 `fetch('/wp-json/power-course/mcp/settings')`，必須回 403（feature 規格不變式）

### 6.3 不需測試的項目（明確排除）

- ❌ 不寫 PHPUnit：本 issue 後端只多注入一個 boolean，無業務邏輯可單元測；E2E 已覆蓋 end-to-end 行為
- ❌ 不寫 React 單元測試：專案目前無 React unit test 框架（`react.rule.md` 第 8 行：「目前無前端 unit test」）

---

## 7. 風險評估與對策

| # | 風險 | 影響 | 對策 |
|---|------|------|------|
| R1 | 前端 `IS_ADMIN` 被使用者篡改 | 看到 AI tab，但仍無法操作 | 後端 REST `permission_callback('manage_options')` **未變動**，仍為唯一安全邊界；feature 規格不變式 example 已驗證 |
| R2 | `simple_encrypt` 不是密碼學安全 | env 可被解密讀取 capability boolean | 本來就是 UX 層資訊（admin 與否在 wp-admin 任何頁面 DOM 都可推導），未洩漏敏感資訊 |
| R3 | 舊版 React bundle + 新版 PHP（升級期 cache 不一致） | `env.IS_ADMIN` undefined | `Boolean(env?.IS_ADMIN)` fallback `false` ⇒ admin 暫時看不到 AI tab（清 cache 即解決），**不會發生「非 admin 看到 AI tab」** |
| R4 | 新版 React bundle + 舊版 PHP | 同 R3 | 同 R3，fail-safe 為 `false` |
| R5 | Multisite 環境 super admin 行為不一致 | 期望 super admin 看得到 AI tab | `current_user_can('manage_options')` 對 super admin 也回 `true`（WP 內建），與 single site admin 行為一致 |
| R6 | 前台 vanilla TS bundle 載到 `IS_ADMIN` | 無風險：前台不讀此值 | 加密 env 多一個 boolean 欄位無副作用 |
| R7 | E2E 在 CI 中 shop_manager 建立失敗 | 測試 flaky | 使用 `ensureUser` 冪等建立；CI workers=1、retries=1 已可承受偶發網路抖動 |
| R8 | hash route 直接輸入 `#/settings`（無 `/ai`） | 既有非受控 Tabs 行為 | 規格 doc 已確認：`defaultActiveKey="general"` 始終 fallback，hash 不參與 tab 切換；Q4 redirect 邏輯**本 issue 不實作** |

---

## 8. 驗收 Checklist（PR review / acceptance-evaluator 對照）

### 功能正確性

- [ ] administrator 進 `#/settings` 看到 **4 個** tab，順序：general → appearance → auto-grant → ai
- [ ] shop_manager 進同一頁看到 **3 個** tab，順序：general → appearance → auto-grant
- [ ] editor 進同一頁看到 **3 個** tab（不含 AI）
- [ ] subscriber 進同一頁看到 **3 個** tab（不含 AI，若有權限進入 wp-admin 的話）
- [ ] administrator 點 AI tab 後，原有 MCP 設定功能（Issue #217）完全不受影響
- [ ] 非 admin 即使透過 devtools 強制 render AI tab，呼叫 `/wp-json/power-course/mcp/settings` 回 403

### 程式碼品質

- [ ] `pnpm run build` 成功，無 TypeScript error
- [ ] `pnpm run lint:ts` 通過（無 ESLint error / warn）
- [ ] `pnpm run lint:php`（phpcbf + phpcs + phpstan）全綠
- [ ] `composer run phpstan` level 9 全綠
- [ ] 新檔 `RoleGate/index.tsx` 與 `settings-ai-tab-role-gate.spec.ts` 符合 `.claude/rules/react.rule.md` 與 `e2e-testing.rule.md`

### 規範遵守

- [ ] 註解全部使用繁體中文（`react.rule.md` / 本專案 CLAUDE.md）
- [ ] 不新增任何使用者可見 UI 字串（Q7：方案 A，無翻譯影響）
- [ ] 不執行 `pnpm run i18n:build`，無 `.pot` / `.po` / `.mo` / `.json` diff
- [ ] commit message 用 Conventional Commits + 繁中：`feat(settings): 隱藏 AI Tab 給非 admin 使用者`

### 測試

- [ ] `pnpm run test:e2e:admin` 中新 spec `settings-ai-tab-role-gate.spec.ts` 2 個 case 全綠
- [ ] 既有 `ai-tab.spec.ts`（Issue #217）保持綠燈
- [ ] CI workflow 跑完不退步

---

## 9. 交接給 TDD Coordinator

### 9.1 Red→Green→Refactor 拆解建議

| Phase | 步驟 | 對應 Agent |
|-------|------|-----------|
| **Red 1** | 寫 E2E spec `settings-ai-tab-role-gate.spec.ts` 並執行——預期 administrator case **通過**（因為現況本來就看得到 AI tab）但 shop_manager case **失敗**（因為現況也看得到 AI tab） | `@zenbu-powers:test-creator` |
| **Green 1** | 實作 M1 + M2 + M3，使 shop_manager case 通過 | `@zenbu-powers:wordpress-master`（PHP M1） + `@zenbu-powers:react-master`（M2+M3） |
| **Green 2** | 實作 N1（RoleGate 元件） | `@zenbu-powers:react-master` |
| **Refactor** | 確認 import 順序、註解、型別標註 | `@zenbu-powers:react-master` |
| **Review** | PHP 端 PR review | `@zenbu-powers:wordpress-reviewer` |
| **Review** | React 端 PR review | `@zenbu-powers:react-reviewer` |
| **Security** | 確認後端權限邊界未被誤動、前端 gate 未被誤當安全機制 | `@zenbu-powers:security-reviewer` |
| **Doc sync** | 本 issue 不影響 CLAUDE.md / rules，可略過 | `@zenbu-powers:doc-updater`（可選） |

### 9.2 拆分注意事項

- **M1 與 M2/M3 可平行派發**（PHP 與 TS 兩條獨立 PR-friendly 分支），但合併到本 issue/221 同一分支
- N1 雖然本 issue 不使用，仍需交付——測試上以「元件 export 正常 + TypeScript 編譯通過」為驗收（不寫 unit test）
- 若 Green 1 在實作過程發現 `simple_encrypt` 對 PHP bool 的序列化有問題（理論上 JSON 化會變 `true`/`false`），前端 `Boolean(env?.IS_ADMIN)` 已能 cover；無需額外處理
- E2E spec 在本地跑時，需確認 `.env` 的 `TEST_USERNAME` / `TEST_PASSWORD` 為 admin，否則 storageState 不會是 admin

### 9.3 交接信號

> 本計畫已完整、可直接執行。`tdd-coordinator` 不需再 clarify。

---

## 10. 附錄

### A. 參考檔案

- 規格：`specs/open-issue/221-settings-ai-tab-role-gate.md`
- BDD：`specs/features/settings/AI_TAB角色可見性.feature`
- 既有 AI Tab 測試：`tests/e2e/01-admin/ai-tab.spec.ts`
- 既有權限驗證範例：`tests/e2e/03-integration/004-permission.spec.ts`
- E2E Helper：`tests/e2e/helpers/api-client.ts`、`tests/e2e/helpers/admin-page.ts`
- E2E global setup：`tests/e2e/global-setup.ts`

### B. 設計決策日誌

| 時間 | 決策 | 替代方案 | 採用理由 |
|------|------|---------|---------|
| 2026-05-11 | Q1=A 直接隱藏 tab | B disabled / C 提示頁 / D 開放權限 | WP 後台「看不到=用不到」慣例，最乾淨無翻譯成本 |
| 2026-05-11 | Q2=A `manage_options` | B `administrator` role / C 自訂 cap | 與後端 REST permission 一致，multisite 自動處理 |
| 2026-05-11 | Q3=A 加密 env 注入 | B `wp_localize_script` 明文 / C 新 REST endpoint | 與既有機制一致，無新增成本 |
| 2026-05-11 | Q4=A redirect 至 `#/settings` | B 403 提示頁 / C 不處理 | 經查證目前非受控 Tabs 已自動 fallback，**本 issue 不需實作 redirect** |
| 2026-05-11 | Q5=C 建立 RoleGate 元件但本 issue 不用 | A 只動 AI / B 全 4 tab 都加 gate | 範圍可控，預留擴充 |
| 2026-05-11 | Q6=A 加 E2E（admin + shop_manager） | B PHPUnit / C 純 manual | 權限類功能高風險必須自動化 |
| 2026-05-11 | Q7=A 不新增翻譯字串 | B 加翻譯 | Q1=A 純隱藏，零新字串 |
| 2026-05-11 | Settings index.tsx 採 `getItems()` 過濾而非 `<RoleGate>` wrap | wrap `<RoleGate>` | 完全不 mount AiTabLoader，效能與 DOM 更乾淨；feature example 「DOM 中不應掛載 AiTabLoader」 |
