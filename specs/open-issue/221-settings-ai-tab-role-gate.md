# Issue #221: Settings 頁面 AI Tab 僅對 admin 可見

## 問題描述

`/wp-admin/admin.php?page=power-course#/settings` 的 Settings 頁面目前有 4 個 Tab：
**General / Appearance / Auto-grant / AI**。

**現況**：AI Tab 在 React 前端是「永遠 render」的——`js/src/pages/admin/Settings/index.tsx`
的 `getItems()` 無條件加入 AI tab。但 AI Tab 對應的後端 REST API（`power-course/mcp/settings`）
已經強制 `manage_options`（admin 權限），非 admin 點擊後會收到 403 / 空白。

**問題**：非 admin（如 editor / shop_manager / subscriber）進入 Settings 看得到 AI Tab，
但點下去會 fail，造成 UX 不一致——「為什麼讓我看到我沒辦法用的東西？」

**期望**：讓 AI Tab 在 **UI 層也對非 admin 隱藏**，符合 WordPress 後台「看不到 = 用不到」的慣例。

---

## 確認的需求決策（Issue #221 留言 `A A A A C A A`）

| # | 問題 | 決定 |
|---|------|------|
| Q1 | 最終行為 | **A：直接隱藏 AI Tab**——非 admin 進 Settings 只看到 General / Appearance / Auto-grant 三個 tab |
| Q2 | admin 判定 | **A：`current_user_can('manage_options')`**——與後端 REST `permission_callback` 一致，multisite 自動處理 super admin |
| Q3 | 傳遞機制 | **A：env 加密注入 `IS_ADMIN` boolean**——在 `Bootstrap::enqueue_script()` 的 `simple_encrypt` env 中加 `IS_ADMIN`，前端透過 `import { env } from '@/utils/env'` 讀取 |
| Q4 | Hash route 直接輸入 | **A：自動 redirect 到 `#/settings`**（顯示第一個可見的 tab：General） |
| Q5 | 是否處理其他 tab | **C：本 issue 只動 AI Tab，但建立 reusable `<RoleGate>` 元件** 供未來新 tab 套用 |
| Q6 | 自動化測試 | **A：加 E2E 測試**——admin 可見 AI Tab + 至少 1 個非 admin role 不可見 |
| Q7 | 翻譯字串 | **A：不需新增翻譯字串**（方案 A 純隱藏，無新 UI 文字） |

---

## 技術方案

### 後端（PHP）

**1. `Bootstrap::enqueue_script()` 加入 `IS_ADMIN` 至加密 env**

`inc/classes/Bootstrap.php` 第 255–273 行：

```php
$encrypt_env = PowerhouseUtils::simple_encrypt(
    [
        'SITE_URL'                   => \untrailingslashit(\site_url()),
        // ... 既有欄位 ...
        'IS_ADMIN'                   => \current_user_can('manage_options'),  // 新增
    ]
);
```

> **設計理由**：
> - 與既有 env 注入機制一致（無需新增 REST endpoint / 動 `wp_localize_script` 結構）
> - `simple_encrypt` 已加密，避免明文洩漏 capability 結構
> - boolean 值最小化資訊面，僅暴露「是否為 admin」這個本來就由 UI 元素可推導的資訊

### 前端（React / TypeScript）

**2. 擴充 `js/src/utils/env.tsx`，匯出 `IS_ADMIN` 常數**

```tsx
export const env = simpleDecrypt(encryptedEnv)
export const API_URL = env?.API_URL || '/wp-json'
// ... 既有常數 ...
export const IS_ADMIN: boolean = Boolean(env?.IS_ADMIN)  // 新增
```

> 用 `Boolean()` 包一層，確保 `undefined`（舊版 PHP 端尚未注入時）安全 fallback 為 `false`。

**3. 新建 reusable `<RoleGate>` 元件**

`js/src/components/RoleGate/index.tsx`（新檔）：

```tsx
import { ReactNode } from 'react'
import { IS_ADMIN } from '@/utils/env'

type RoleGateProps = {
  /** 需要的 capability 等級；目前僅支援 'admin' */
  capability?: 'admin'
  /** 通過時 render 的內容 */
  children: ReactNode
  /** 未通過時 render 的內容（預設 null） */
  fallback?: ReactNode
}

export const RoleGate = ({ capability = 'admin', children, fallback = null }: RoleGateProps) => {
  if (capability === 'admin' && !IS_ADMIN) {
    return <>{fallback}</>
  }
  return <>{children}</>
}
```

> **設計理由**：
> - 簡單的條件 render wrapper，避免每處都重複寫 `IS_ADMIN ? ... : null`
> - 未來若需要支援 `shop_manager`、`editor` 等其他 capability 等級，可擴充 `capability` union type 與對應的 env 欄位
> - 命名 `RoleGate` 強調是 UI 層的可見性 gate，並非安全邊界（安全由後端 capability 檢查負責）

**4. 修改 `js/src/pages/admin/Settings/index.tsx`：條件式加入 AI Tab**

```tsx
import { IS_ADMIN } from '@/utils/env'

const getItems = (): TabsProps['items'] => {
  const items: TabsProps['items'] = [
    {
      key: 'general',
      label: __('General settings', 'power-course'),
      children: <General />,
    },
    {
      key: 'appearance',
      label: __('Appearance settings', 'power-course'),
      children: <Appearance />,
    },
    {
      key: 'auto-grant',
      label: __('Auto-grant', 'power-course'),
      children: <AutoGrant />,
    },
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

> **替代方案**：也可以包 `<RoleGate>` 在 `children` 內，但會白白載入 AI Tab 的 lazy chunk + 在 tab bar 留空位。
> **採用做法**：直接在 `getItems()` 過濾，AI Tab key 對非 admin 完全不存在於 Tabs items，更乾淨。

**5. Tab 選中狀態的容錯**

Ant Design `Tabs` 在 `defaultActiveKey` 對應的 key 不存在時會自動 fallback 到第一個 item。
本 issue 場景：

- 預設 `defaultActiveKey="general"`——不影響
- 若未來改用 `activeKey`（受控）+ URL hash 同步（如 `#/settings/ai`），非 admin 嘗試直接訪問該 hash 時，
  必須在 Tabs 的 `onChange` / 初始化邏輯加上：**若選中 key 不在可見 items 中，redirect/fallback 至第一個可見 tab**

> 目前 `Settings` 頁面是 `defaultActiveKey`（非受控），且 React Router 不參與 tab 切換——
> 經驗證**現況下 non-admin 直接輸入 `#/settings` 不會看到 AI tab，亦無 hash route 進入 AI tab 的路徑**。
> 因此 Q4 的 redirect 邏輯**目前不需要實作**，但本 doc 仍記錄該決策，供未來若改為受控 tab 時遵循。

### 測試（E2E / Playwright）

**6. 新增 E2E spec：`tests/e2e/admin/settings-ai-tab-role-gate.spec.ts`**

測試矩陣：

| 角色 | 期望結果 |
|------|---------|
| `administrator` | 進 Settings 看到 **4 個** tab，含「AI」 |
| `shop_manager` | 進 Settings 看到 **3 個** tab，**不含**「AI」 |

> 至少覆蓋 administrator + shop_manager 兩個角色；理論上 editor / subscriber 行為與 shop_manager 一致，依 testing 成本可自行擴充。

---

## 不修改的檔案

- 後端 REST API permission_callback：本來就已強制 `manage_options`，**不動**
- 既有 `inc/classes/Api/Mcp/Settings.php` / `AbstractTool.php`：權限模型完全不變
- 其他 3 個 tab（General / Appearance / Auto-grant）：保持現狀

---

## 修改的檔案清單

### 新建

1. `js/src/components/RoleGate/index.tsx`（reusable role gate 元件）
2. `tests/e2e/admin/settings-ai-tab-role-gate.spec.ts`（E2E 驗證 spec）

### 修改

1. `inc/classes/Bootstrap.php`（`enqueue_script()` 加入 `IS_ADMIN` 到加密 env）
2. `js/src/utils/env.tsx`（export `IS_ADMIN` 常數）
3. `js/src/pages/admin/Settings/index.tsx`（`getItems()` 條件式加入 AI Tab）

---

## 驗收標準

- [ ] administrator 登入後進入 `wp-admin/admin.php?page=power-course#/settings`，看到 **4 個** tab（含 AI）
- [ ] shop_manager / editor / subscriber 登入後進入同一頁，**看不到** AI tab，只看到 3 個 tab
- [ ] 非 admin 不會在 DOM 中渲染 AI tab 的 lazy chunk（透過 React DevTools 確認 `<AiTabLoader>` 未掛載）
- [ ] administrator 切換到 AI tab 時，原本功能（讀寫 `pc_mcp_settings`）完全不受影響
- [ ] `env.IS_ADMIN` 在 admin 為 `true`、非 admin 為 `false`（瀏覽器 console `window.power_course_data` 解密後可驗證）
- [ ] E2E spec `settings-ai-tab-role-gate.spec.ts` 覆蓋 administrator + shop_manager 兩種角色並通過
- [ ] 未新增任何使用者可見字串，無需跑 `pnpm run i18n:build`
- [ ] `pnpm run build`、`pnpm run lint:ts`、`composer run phpstan` 全綠
- [ ] `<RoleGate>` 元件雖在本 issue 未使用（採方案 4 的 getItems 過濾），但已提交於 `js/src/components/RoleGate/`，供未來 tab 套用

---

## 風險與注意事項

| 風險 | 對策 |
|------|------|
| 前端 `IS_ADMIN` 可被使用者篡改 | **安全邊界仍由後端 capability 檢查負責**——前端 gate 只是 UX 改善，非安全機制。後端 REST `permission_callback('manage_options')` 才是真正的權限執行點 |
| `simple_encrypt` 是 base64-like 加密，非真正密碼學安全 | 同上，僅作為防止意外洩漏明文，最終安全靠後端 |
| 既有 React bundle 解密失敗 | `env?.IS_ADMIN` optional chaining + `Boolean()` fallback `false`，最壞情況非 admin 也看不到 AI tab，**fail-safe** |
| Multisite 環境 | `current_user_can('manage_options')` 對 super admin 與 single site admin 都會回 true，行為一致 |
| 升級兼容性 | 用戶若使用舊版 cache 的 React bundle + 新版 PHP，`env.IS_ADMIN` 會是 `undefined`，fallback 為 `false`，最壞情況：admin 也暫時看不到 AI tab（清 cache 即解決），**不會發生「非 admin 看到 AI tab」的安全問題** |
