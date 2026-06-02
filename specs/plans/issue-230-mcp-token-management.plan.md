# Issue #230 — MCP Token 後台管理（設定 → AI）實作計畫

> 範圍模式：**HOLD SCOPE（復原 + 增強）**。本 Issue 大部分後端基礎已存在（`Auth` / `BearerAuth` / `Migration`），
> 工作主要是「復原已下架的 Token REST 端點 + 前端管理介面，並補上期限選項與兩個越權防護」。
> 預估影響約 14~16 個檔案，多為從 git 取回後調整，風險集中在後端三個小修改與兩個安全不變式。

關聯規格：
- `specs/clarify/2026-05-28-issue230-mcp-token-management.md`（決議 `A A A A A A`）
- `specs/features/settings/MCP_Token產生.feature`
- `specs/features/settings/MCP_Token列表與撤銷.feature`
- `specs/features/settings/MCP_Token認證.feature`

---

## 0. 現況與既有資產（已驗證）

| 項目 | 現況 | 對本 Issue 的意義 |
|------|------|------------------|
| `inc/classes/Api/Mcp/Migration.php` | `pc_mcp_tokens` 已含 `expires_at DATETIME NULL` | **不需改 schema** |
| `inc/classes/Api/Mcp/Auth.php` | `create_token(3 args)` 從不寫 `expires_at`；`list_tokens()` 不回 `expires_at`；`verify_bearer_token()` **已檢查** `expires_at` 與 `revoked_at` | 需擴充 create / list；verify 不動 |
| `inc/classes/Api/Mcp/BearerAuth.php` | `determine_current_user` priority 20，server 停用時不介入 | **不需改**（認證 feature 屬既有行為） |
| `inc/classes/Api/Mcp/RestController.php` | 只剩 `mcp/settings` GET/POST；token 端點已下架 | 需復原 3 條 token 路由 + callbacks |
| 舊 token 端點 callbacks | 存在於 commit `6157831c`（`list_tokens(0)`、回 `token`+`warning`） | 取回後修正 2 個 bug（見 §4 風險） |
| 舊前端元件 | `Settings/Mcp/Tokens/{index,CreateTokenModal,PlaintextTokenModal,RevokeTokenButton}.tsx` + `hooks/useMcpTokens.tsx` 存在於 `6157831c` | 取回後**搬到 `Settings/Ai/`** 並調整 |
| `js/src/pages/admin/Settings/Ai/index.tsx` | 只有 PermissionControl + Save | 新增「MCP Token 管理」`<Card>` |
| `js/src/types/mcp.ts` | 已移除 `TMcpToken` / `TMcpTokenCreateResponse` | 需補回（含 `expires_at`） |
| `js/src/utils/env.tsx` | `SITE_URL` 已由 Bootstrap 注入但**未 export** | 需 export 供範本帶入網址 |
| AI Tab 角色守門 | `Settings/index.tsx` 以 `IS_ADMIN` 隱藏；後端 `permission_callback` 強制 `manage_options` | **沿用，不重做**（繼承 Issue #221） |
| `tests/Integration/Mcp/AuthTest.php` | 已存在，呼叫 `create_token($id,$name,[])`（3 參數） | 新增第 4 參數須為 optional 以維持相容 |

MCP 對外 endpoint：`{SITE_URL}/wp-json/power-course/v2/mcp`（`Server::ROUTE_NAMESPACE='power-course/v2'` + `ROUTE='mcp'`）。

---

## 1. 資料流分析

```
[建立 Token]
CreateTokenModal(form: name, expires)
  → useCreateMcpToken().create({ name, expires_days })   // never → 不帶 expires_days
  → POST power-course/mcp/tokens
  → RestController::post_mcp_tokens_callback
      ├─ manage_options 守門
      ├─ name 必填（空 → 400 invalid_name）
      ├─ expires_days(30/90/365) → expires_at = gmdate(now + days)；never → null
      ├─ Auth::create_token(user_id, name, [], expires_at)   // capabilities 一律 []
      └─ 回 { id, name, token(明文), warning }               // 僅此一次
  → PlaintextTokenModal 顯示明文 + 黃色警示 + 快速設定範本（帶入 SITE_URL + Bearer token）

[列表]
TokensList mount
  → useMcpTokens() → GET power-course/mcp/tokens
  → RestController::get_mcp_tokens_callback
      ├─ manage_options 守門
      └─ Auth::list_tokens( get_current_user_id() )          // 只列自己、自動濾 revoked
  → Table（name / created_at / last_used_at / expires_at / actions）

[撤銷]
RevokeTokenButton → Popconfirm 確認
  → useRevokeMcpToken().revoke(id) → DELETE power-course/mcp/tokens/{id}
  → RestController::delete_mcp_tokens_with_id_callback
      ├─ manage_options 守門
      ├─ 驗證該 token 屬於 get_current_user_id()（否則 403，不撤銷）   ← 新增不變式
      └─ Auth::revoke_token(id) → 寫 revoked_at
  → invalidate → list refetch → 該列消失

[認證（既有，本 Issue 不改邏輯，僅測試驗證）]
AI Client "Authorization: Bearer <token>"
  → BearerAuth::authenticate (determine_current_user, prio 20)
  → server enabled? → Auth::verify_bearer_token（檢查 revoked_at / expires_at）
  → 以建立者身分執行 MCP 工具；寫入動作仍受全域 allow_update/allow_delete 限制
```

---

## 2. 錯誤處理與失敗模式登記表

| # | 失敗情境 | 偵測點 | 處置 |
|---|---------|--------|------|
| E1 | 名稱為空 | 前端 Form `required` + 後端 callback | 前端欄位錯誤、不送出；後端回 400 `invalid_name` |
| E2 | 非 administrator 呼叫 token 端點 | `permission_callback`（`manage_options`） | 403 forbidden，無 Token 變更 |
| E3 | 撤銷他人 Token | DELETE callback ownership 檢查 | 403（或 404），目標 `revoked_at` 維持 NULL |
| E4 | 撤銷不存在 / 已撤銷 Token | `Auth::revoke_token` 回 false | 404 `revoke_failed` |
| E5 | 明文未複製即關閉 | 設計使然（明文不落地、不可再取回） | UI 黃色強警示；事後只能撤銷重建 |
| E6 | 過期 / 撤銷 Token 呼叫 MCP | `verify_bearer_token`（既有） | 不認證 → 401/未授權 |
| E7 | server 停用時持 Token 呼叫 | `BearerAuth::authenticate`（既有） | Bearer 不介入 |
| E8 | 複製到剪貼簿失敗（無 clipboard 權限） | 前端 `navigator.clipboard` catch | `message.error` 提示手動選取 |
| E9 | `SITE_URL` 為空 | `env.tsx` fallback | 範本以相對路徑 `/wp-json/...` 或留空提醒 |

---

## 3. 實作步驟（依依賴順序）

### Phase 1 — 後端 PHP

**1.1 `inc/classes/Api/Mcp/Auth.php`：`create_token()` 擴充期限**
- 簽章改為：`create_token( int $user_id, string $name, array $capabilities, ?string $expires_at = null ): string`
  （第 4 參數 optional，維持 `AuthTest.php` 既有 3 參數呼叫相容）
- INSERT 陣列加入 `'expires_at' => $expires_at`（值為 null 時 `$wpdb->insert` 寫入 NULL），format 補一個 `%s`。
- 更新 docblock。

**1.2 `Auth::list_tokens()` 回傳 `expires_at`**
- 兩段 SELECT（user-scoped / all）都補上 `expires_at` 欄位。
- 回傳結構新增 `'expires_at' => isset( $row->expires_at ) ? (string) $row->expires_at : null`。
- 更新 docblock 回傳型別。

**1.3 `Auth` 新增 ownership 查詢 helper（供 DELETE 越權防護）**
- 新增 `public function find_token_owner( int $token_id ): ?int`
  — `SELECT user_id FROM ... WHERE id = %d`，回 user_id 或 null。
- （不改既有 `revoke_token` 簽章，維持 `AuthTest` 相容）

**1.4 `inc/classes/Api/Mcp/RestController.php`：復原 token 端點**
- `$apis` 補三條：
  ```php
  [ 'endpoint' => 'mcp/tokens',             'method' => 'get'    ],
  [ 'endpoint' => 'mcp/tokens',             'method' => 'post'   ],
  [ 'endpoint' => 'mcp/tokens/(?P<id>\d+)', 'method' => 'delete' ],
  ```
- `get_mcp_tokens_callback`：
  - 第一行 `\nocache_headers();`
  - `check_permission()` 守門
  - `Auth::list_tokens( \get_current_user_id() )`（**改自舊的 `list_tokens(0)`**）
  - payload 每筆 `{ id, name, created_at, last_used_at, expires_at }`（**不回 `token_hash`、不回明文**；`capabilities` 可省略，沿用全域開關）
- `post_mcp_tokens_callback`：
  - 第一行 `\nocache_headers();`
  - `check_permission()` 守門
  - `name` 必填，空 → `WP_Error('invalid_name', ..., ['status'=>400])`
  - 讀 `expires_days`：值屬 `{30,90,365}` 時 `$expires_at = \gmdate('Y-m-d H:i:s', time() + days*DAY_IN_SECONDS)`；否則（含 never / 空 / 非法）`$expires_at = null`
  - `capabilities = []`（Q2 全權限）
  - `Auth::create_token( get_current_user_id(), $name, [], $expires_at )`
  - 回 `{ id, name, token: $plain, warning }`（**欄位名為 `token`**，與前端一致）
- `delete_mcp_tokens_with_id_callback`：
  - 第一行 `\nocache_headers();`
  - `check_permission()` 守門
  - `$token_id = absint($request['id'])`；<=0 → 400 `invalid_id`
  - **ownership 檢查**：`Auth::find_token_owner($token_id) !== get_current_user_id()` → `WP_Error('forbidden', ..., ['status'=>403])`，**不撤銷**（滿足 spec 不變式）
  - `Auth::revoke_token((string)$token_id)`；false → 404 `revoke_failed`
  - 回 `{ id }` + message
- 更新 class header 註解（補回 token 端點說明）。

**1.5 PHP 品質**：`pnpm run lint:php`（phpcbf + phpcs + phpstan level 9）須全綠。

### Phase 2 — 後端 PHP 測試（先寫測試，TDD）

**2.1 `tests/Integration/Mcp/AuthTest.php` 擴充**
- `create_token` 帶 `expires_at` → DB `expires_at` 寫入該值
- `create_token` 不帶 `expires_at` → DB `expires_at` 為 NULL（永不過期）
- `list_tokens` 回傳項目含 `expires_at` 鍵
- 過期 Token（`expires_at` 為過去）`verify_bearer_token` → false
- 永不過期 Token（NULL）→ verify 成功

**2.2 新增 `tests/Integration/Mcp/RestControllerTokensTest.php`**（繼承 `IntegrationTestCase`）
- GET：只列當前使用者、排除 revoked、項目不含 `token_hash` / 明文
- GET：Admin2 登入只看到自己的
- POST 空 name → WP_Error status 400 `invalid_name`，無新增
- POST 成功 → 回 `token`+`warning`，DB user_id = 當前、capabilities NULL
- POST `expires_days=90` → `expires_at ≈ now+90d`；never/空 → NULL
- DELETE 自己的 → `revoked_at` 寫入；列表不再出現
- DELETE 他人的（越權）→ 403、目標 `revoked_at` 維持 NULL
- 非 admin（subscriber）呼叫 → check_permission 回 403（以 `wp_set_current_user` 切換後直接呼叫 callback 斷言 `WP_Error` status 403）

> 測試呼叫方式：沿用 `SettingsTest` 風格直接實例化 callback + `wp_set_current_user()`；
> 權限分支透過 callback 內 `check_permission()` 回傳的 `WP_Error` 斷言，不需起完整 REST server。

### Phase 3 — 前端型別與環境

**3.1 `js/src/types/mcp.ts`**：補回
```ts
export type TMcpToken = {
  id: number
  name: string
  last_used_at: string | null
  created_at: string
  expires_at: string | null   // null = 永不過期
}
export type TMcpTokenCreateResponse = {
  id: number
  name: string
  token: string               // 明文，僅建立時回一次（對齊後端 `token` 欄位）
  warning: string
}
```
> ⚠️ **不要**沿用舊的 `plaintext_token` 命名（舊 bug，後端實際回 `token`）。

**3.2 `js/src/utils/env.tsx`**：新增 `export const SITE_URL = env?.SITE_URL || ''`

### Phase 4 — 前端 hooks 與元件（皆新建於 `Settings/Ai/`）

**4.1 `js/src/pages/admin/Settings/Ai/hooks/useMcpTokens.tsx`**（自 `6157831c` 取回後調整）
- `useMcpTokens()` → GET `mcp/tokens`
- `useCreateMcpToken()` → POST；`onSuccess` 讀 `response.data.data.token`（**修正欄位**）
- `useRevokeMcpToken()` → DELETE `mcp/tokens/{id}`；成功後 `invalidate`
- 所有 `message.*` 文案改 `__()`（英文 msgid，禁止硬編中文）

**4.2 `Settings/Ai/Tokens/CreateTokenModal.tsx`**
- 表單欄位：`name`（必填，max 100）+ `expires`（Select：`30`/`90`/`365`/`never`，**預設 `never`**）
- 送出時：never → `{ name }`（不帶 `expires_days`）；其餘 → `{ name, expires_days: Number(expires) }`
- 移除舊的 capabilities checkbox 區塊（Q2 不做逐 Token 權限）
- label/placeholder/okText/cancelText 全 `__()`

**4.3 `Settings/Ai/Tokens/PlaintextTokenModal.tsx`**
- 保留：明文區塊 + 複製按鈕 + **黃色 `Alert type="warning"`「此密碼僅顯示一次，請立即複製」（含粗體）**
- **新增「快速設定範本」**：自 `SITE_URL` 組出 `{SITE_URL}/wp-json/power-course/v2/mcp`
  - Claude Code CLI 命令（可一鍵複製、可直接執行）：
    ```
    claude mcp add --transport http power-course \
      <endpoint> \
      --header "Authorization: Bearer <token>"
    ```
  - Claude Code JSON（`.mcp.json` / `~/.claude.json`）區塊 + 複製鈕
  - Cursor JSON（`.cursor/mcp.json`）區塊 + 複製鈕
  - 認證一律 `Authorization: Bearer <token>`，**不得出現 `Basic`、不需 Base64**
- 字串用 `sprintf` + placeholder 帶入 endpoint / token（英文 msgid + translator comment）

**4.4 `Settings/Ai/Tokens/RevokeTokenButton.tsx`**（取回後 i18n）
- `Popconfirm` 二次確認，`okButtonProps={{ danger:true }}`，確認後 `revoke(id)`
- 文案全 `__()`

**4.5 `Settings/Ai/Tokens/index.tsx`**（`TokensList`）
- `<Table>` 欄位：名稱 / 建立時間 / 最後使用時間 / 到期時間 / 操作
  - 到期時間 render：`expires_at === null` → `__('Never expires','power-course')`；否則格式化日期
  - 最後使用 render：null → `__('Never used','power-course')`（或 `—`）
- 頂部「新增 Token」按鈕 → 開 `CreateTokenModal`
- 建立成功 → 開 `PlaintextTokenModal`
- 空列表 emptyText：上手指引 +「此 Token 專為 Power Course MCP 設計，不需使用 WordPress 應用程式密碼」
- 移除舊的 `disabled` capabilities 欄位

**4.6 `Settings/Ai/index.tsx`**：在 PermissionControl `<Card>` 下方新增
```tsx
<Card title={__('MCP Token management', 'power-course')}>
  <TokensList />
</Card>
```
（保持既有 PermissionControl + Save 區塊不變）

**4.7 前端品質**：`pnpm run lint:ts` + `pnpm run build`（TS strict、零 `any`）須通過。

### Phase 5 — i18n

- 所有新字串：英文 msgid + `'power-course'` domain；含變數用 `sprintf` + `%s`/`%1$s` + `/* translators */`
- 繁中翻譯寫入 `scripts/i18n-translations/manual.json`（**禁止手改 `.po`**）
- 跑 `pnpm run i18n:build`，一起 commit `.pot` / `.po` / `.mo` / `.json`
- 新詞彙若不在 `.claude/rules/i18n.rule.md` 術語表，先補術語表（例：MCP Token、Never expires、Revoke、Copy）

### Phase 6 — 文件

- `mcp.zh-TW.md` / `mcp.md`：
  - **步驟一**改寫為「在 **Power Course → 設定 → AI → MCP Token 管理** 點『新增 Token』產生 Bearer Token 並複製」
  - **移除步驟二（Base64 編碼）**
  - **步驟三**所有範本（Claude Code A/B/C、Cursor）`Authorization` 改 `Bearer <token>`
  - 文末新增「**進階 / 備用：使用 WordPress 應用程式密碼（Basic Auth）**」小節，保留舊流程並註明「既有用戶不受影響，仍可使用」
  - 概覽「前置需求」可同步說明兩種認證方式

### Phase 7 — E2E（次要，建議）

`tests/e2e/01-admin/` 新增 MCP Token 流程（登入見 `.env`）：
- 進 設定 → AI，見「MCP Token 管理」
- 新增 Token → 明文 modal 顯示 token + 複製 + 黃色警示 + 範本
- 列表出現該 Token（名稱、建立時間）
- 撤銷 → Popconfirm → 確認 → 消失

> E2E 為次要安全網；**主要驗證以 Phase 2 的 PHP Integration Test 為準**（與專案測試重心一致）。

---

## 4. 風險評估與注意事項

| # | 風險 | 嚴重度 | 對策 |
|---|------|--------|------|
| R1 | 舊程式 `plaintext_token` 欄位名與後端回傳 `token` 不一致 → 明文顯示為 undefined | 高 | 新型別/hook 一律用 `token`，建立後讀 `response.data.data.token` |
| R2 | 撤銷未檢查擁有者 → 任一 admin 可撤他人 Token | 高（安全） | DELETE callback 加 `find_token_owner` 比對 + 403 |
| R3 | 列表沿用 `list_tokens(0)` 顯示全站 Token | 中 | 改 `list_tokens( get_current_user_id() )`（Q6） |
| R4 | `expires_at` 時區不一致導致誤判過期 | 中 | 一律 UTC：寫入用 `gmdate`，比對沿用既有 `verify_bearer_token`（`strtotime` vs `time()`） |
| R5 | `create_token` 改簽章破壞既有 `AuthTest` | 中 | 第 4 參數 optional（`?string $expires_at = null`） |
| R6 | i18n 未跑 `i18n:build` 或手改 `.po` | 中 | 走 `manual.json` + `pnpm run i18n:build`，四檔一起 commit |
| R7 | `SITE_URL` 未 export / 為空導致範本網址錯 | 低 | `env.tsx` export + fallback；範本以 `SITE_URL` 為空時退相對路徑 |
| R8 | 全域 `allow_update` 與 Token 全權限關係被誤解 | 低 | 認證 feature 已界定：Token 全權限**不繞過**全域開關（既有 AbstractTool 行為，不改） |
| R9 | PHPStan level 9 對 `$wpdb->insert` null 值/format 嚴格 | 低 | 比照既有 `create_token` 的 phpcs ignore 與型別註記 |

### 明確不在本 Issue 範圍（Out of Scope）
- 逐 Token capabilities 子集 UI（Q2 否決）
- 已撤銷 Token 的稽核列表 / 還原（Q3：撤銷即消失）
- 全站跨管理員集中稽核視圖（Q6：只列自己）
- 移除 Application Password / Basic Auth（保留相容）
- 舊獨立 MCP Tab（啟用開關 / Rate Limit / Activity Log 區塊）整體復原

---

## 5. 交付驗收對照（Issue 驗收標準 → 實作落點）

| 驗收標準 | 落點 |
|---------|------|
| 設定 → AI 見「MCP Token 管理」 | 4.6 Ai/index.tsx + 4.5 TokensList |
| 新增 Token（輸入名稱產生） | 4.2 CreateTokenModal + 1.4 POST callback |
| 明文 + 一鍵複製 + 僅顯示一次提醒 | 4.3 PlaintextTokenModal |
| 同時顯示 Claude Code / Cursor 範本（帶入 URL+認證、可複製） | 4.3（CLI + JSON，Bearer） |
| 列表顯示名稱 / 建立 / 最後使用時間 | 4.5 Table + 1.4 GET callback |
| 撤銷後請求立即被拒 | 1.4 DELETE → revoked_at；既有 verify_bearer_token |
| 已撤銷不顯示於列表 | `list_tokens` 既有濾 revoked |
| 非 admin 看不到 AI Tab / Token 功能 | 既有 IS_ADMIN + permission_callback（不重做） |
| 文件更新為新流程 | Phase 6 |
| Bearer Token 可呼叫工具且 App Password 仍可用 | MCP_Token認證.feature；既有 BearerAuth（測試驗證） |
| Token 期限支援「永不過期」 | 1.1/1.4 expires_at NULL + 4.2 預設 never |

---

## 6. 建議實作順序（給 tdd-coordinator）

1. **Red**：先寫 Phase 2 PHP 測試（Auth 擴充 + RestControllerTokensTest）
2. **Green（後端）**：Phase 1（Auth create/list/find_owner → RestController 三端點），跑綠
3. **後端品質**：`pnpm run lint:php`
4. **前端**：Phase 3（types + env）→ Phase 4（hooks → 元件 → Ai/index 整合）
5. **前端品質**：`pnpm run lint:ts` + `pnpm run build`
6. **i18n**：Phase 5 `pnpm run i18n:build`，commit 四檔
7. **文件**：Phase 6
8. **E2E（選配）**：Phase 7
9. 全量 `composer run test` + lint 收尾

> PHP 後端為前端的契約來源，必須先完成並測綠，前端才接得上。
