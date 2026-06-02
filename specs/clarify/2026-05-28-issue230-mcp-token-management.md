# Clarify Session 2026-05-28 — Issue #230

## Idea

### 標題：MCP Token 直接在後台管理產生，不要跑到用戶個人資料頁產生應用程式密碼

目前站長要連接 AI 客戶端（Claude Code、Cursor）到 Power Course MCP Server，必須：
WordPress 後台 → 使用者 → 個人資料 → 應用程式密碼 → 手動 Base64 編碼 → 貼回 AI 客戶端。
痛點：跳頁操作、手動編碼、無法集中管理、缺少設定範本。

需求：在 **Power Course → 設定 → AI** 頁面內直接產生 / 查看 / 撤銷 MCP Token，
一鍵複製 Token 或完整的 AI 客戶端設定命令，並能看到每個 Token 的建立 / 最後使用時間與狀態。

### 現況掃描

| 觀察項目 | 內容 |
|---------|------|
| Token 資料表 | `wp_pc_mcp_tokens`（`Migration::TOKENS_TABLE_NAME`），欄位已含 `token_hash` / `user_id` / `name` / `capabilities` / `last_used_at` / `created_at` / **`expires_at`** / `revoked_at`。SHA-256 hash 儲存、明文不落地 |
| 後端 Auth 類 | `inc/classes/Api/Mcp/Auth.php`：`create_token()` / `verify_bearer_token()` / `revoke_token()` / `list_tokens()` / `token_allows_category()` 皆已存在 |
| Bearer 認證流程 | `inc/classes/Api/Mcp/BearerAuth.php`：掛 `determine_current_user` filter，`Authorization: Bearer <token>` 即以 token 建立者身分執行；`verify_bearer_token()` 已檢查 `revoked_at` 與 `expires_at` |
| Token REST 端點 | **已隨舊 MCP Tab 下架移除**（commit `ba95d58d`）。舊版曾存在 `GET/POST mcp/tokens`、`DELETE mcp/tokens/(id)`（commit `6157831c`），需復原 |
| 既有 AI Tab | `js/src/pages/admin/Settings/Ai/index.tsx` + `PermissionControl.tsx`：目前只有「允許修改 / 允許刪除」開關，共用 `useMcpSettings()` |
| 可復用前端元件（已移除，git 可取回） | `Settings/Mcp/Tokens/{CreateTokenModal,PlaintextTokenModal,RevokeTokenButton,index}.tsx`、`hooks/useMcpTokens.tsx`、`types/mcp.ts`（`TMcpToken` / `TMcpTokenCreateResponse`） |
| AI Tab 角色守門 | `specs/features/settings/AI_TAB角色可見性.feature`：AI Tab 僅 `manage_options`（administrator）可見；後端 REST 亦強制 `manage_options` |
| 文件 | `mcp.zh-TW.md` / `mcp.md`「步驟一～三」教學產生應用程式密碼 + 手動 Base64 + `Authorization: Basic` |

### 與既有 feature 的關係

- `AI_TAB角色可見性.feature`：規範 AI Tab 整體的角色可見性（administrator only）— 本 Issue Token 管理區塊**繼承**此守門，不重複規範。
- `AI設定權限控制.feature`：規範全域「允許修改 / 允許刪除」開關 — 與本 Issue 的「逐 Token 權限」是兩件事（Q2 決議：不開放逐 Token 權限，沿用全域開關）。
- 本 Issue：在 AI Tab 內新增「MCP Token 管理」區塊（產生 / 列表 / 撤銷 / 快速設定範本）。

### 關鍵工程落差（相對於已移除的舊實作）

1. **期限支援**：舊 `create_token()` 從不寫入 `expires_at`（資料表欄位早已存在）。需新增期限選項（30 天 / 90 天 / 1 年 / **永不過期**，預設永不過期）。
2. **只列自己**：舊端點呼叫 `list_tokens(0)`（全站所有人）。本 Issue Q6 決議改為 `list_tokens( get_current_user_id() )`（只列目前管理員自己建立的）。
3. **歸位 AI Tab**：舊獨立 MCP Tab 已下架，Token 管理改置入 `Settings/Ai`。
4. **快速設定範本**：產生 Token 後顯示可一鍵複製的 `claude mcp add` CLI 命令 + JSON（Claude Code / Cursor），自動帶入網站 URL（`/wp-json/power-course/v2/mcp`）與 `Authorization: Bearer <token>`。
5. **認證憑證型別**：範本由舊的 `Basic <base64>` 改為 `Bearer <token>`。

## Q&A（用戶確認 `A A A A A A`）

> 第 1 輪由 @j7-dev 補充需求：Token 期限要支援「永不過期 / 永遠」選項。

- **Q1 [情境] 新增 Token 的有效期限選項與預設值**：**A — 下拉選單 30 天 / 90 天 / 1 年 / 永不過期，預設「永不過期」**
  - 理由：預設「永不過期」直接滿足 @j7-dev 的需求且維持後端現有行為（`expires_at` 為空 = 永不過期）；保留期限選項給講究安全的站長。
  - 工程注意：後端 `create_token()` 需擴充接受 `expires_days`（或 `expires_at`）參數並寫入 `expires_at`；永不過期時 `expires_at = NULL`。
- **Q2 [情境] 逐 Token 限定可用工具範圍（capabilities）**：**A — 不開放逐 Token 權限，所有 Token 全權限，由 AI Tab 既有的全域開關控制**
  - 理由：與現有「全域 AI 權限」模型一致，降低 UI 複雜度；非技術站長不需理解逐 Token 權限。建立時 `capabilities = []`（空 = 全部允許）。
- **Q3 [情境] 撤銷後的 Token 處理**：**A — 撤銷後直接從列表消失**
  - 理由：符合「撤銷＝移除」直覺，且後端 `list_tokens()` 已自動濾掉 `revoked_at IS NOT NULL`。稽核需求可走 `pc_mcp_activity` 活動日誌。
- **Q4 [情境] MCP 文件改寫策略**：**A — Bearer Token 設為主要推薦流程，應用程式密碼降為文末「進階／備用」小節保留**
  - 理由：新手看主線、老手仍找得到舊方式，相容性最佳（Basic Auth 仍有效，不移除）。
- **Q5 [情境] 快速設定範本涵蓋的 AI 客戶端**：**A — Claude Code + Cursor 各一份**
  - **追加（@j7-dev 附圖）**：UI 參考附圖，**可以直接複製命令使用** → 範本以可一鍵複製的 `claude mcp add --transport http ...` CLI 命令為主，輔以 JSON 區塊；自動帶入網站 URL 與 `Authorization: Bearer <token>`。
- **Q6 [工程] 多管理員站點 Token 列表顯示範圍**：**A — 只顯示目前登入管理員自己建立的 Token**
  - 理由：Bearer Token 以建立者身分執行，個人管自己的較直覺，避免誤撤他人 Token。工程上 `list_tokens( get_current_user_id() )`。

## 實作方案摘要

### 後端（PHP）

1. **復原 Token REST 端點**（`inc/classes/Api/Mcp/RestController.php`，namespace `power-course`，全部 `manage_options` 守門）：
   - `GET  mcp/tokens` → `get_mcp_tokens_callback`：呼叫 `list_tokens( get_current_user_id() )`，回傳 `id / name / last_used_at / created_at / expires_at`（**新增 `expires_at`**），**不回明文**。
   - `POST mcp/tokens` → `post_mcp_tokens_callback`：必填 `name`；選填 `expires_days`（30 / 90 / 365 / 空=永不過期）；`capabilities = []`；回傳一次性明文 `token` + `warning`。
   - `DELETE mcp/tokens/(?P<id>\d+)` → `delete_mcp_tokens_with_id_callback`：撤銷前驗證該 Token 屬於 `get_current_user_id()`（避免越權撤銷他人 Token）。
2. **`Auth::create_token()` 擴充期限**：新增第 4 參數 `?string $expires_at = null`（或 `int $expires_days = 0`），有值時 `expires_at = gmdate('Y-m-d H:i:s', time() + days*86400)`，寫入 INSERT。
3. **`Auth::list_tokens()` 回傳 `expires_at`**：SELECT 補上 `expires_at` 欄位並納入回傳結構。
4. 所有 callback 第一行 `\nocache_headers();`（遵循 Issue #216 規範）。

### 前端（React / TSX）

1. **置入 AI Tab**：在 `js/src/pages/admin/Settings/Ai/index.tsx` 既有 PermissionControl 卡片下方新增「MCP Token 管理」`<Card>`。
2. **復原 / 移植元件**（自 git commit `6157831c` 取回後調整）：
   - `Tokens/index.tsx`：Token 列表 `<Table>`（欄位：名稱、建立時間、最後使用時間、到期時間 / 永不過期、操作=撤銷）。空列表顯示上手指引。
   - `Tokens/CreateTokenModal.tsx`：表單欄位 `name`（必填）+ `expires`（Select：30 天 / 90 天 / 1 年 / 永不過期，預設永不過期）。
   - `Tokens/PlaintextTokenModal.tsx`：明文 Token + 一鍵複製 + **黃色強警示「僅顯示一次」** + 快速設定範本（Claude Code / Cursor 的 `claude mcp add` CLI 命令與 JSON，自動帶入網站 URL + Bearer token，各含複製鈕）。
   - `Tokens/RevokeTokenButton.tsx`：`Popconfirm` 二次確認後 DELETE。
   - `hooks/useMcpTokens.tsx`：`useMcpTokens()`（list）/ `useCreateToken()` / `useRevokeToken()`。
3. **型別**：`js/src/types/mcp.ts` 補 `TMcpToken`（含 `expires_at`）、`TMcpTokenCreateResponse`。
4. 角色守門沿用既有：AI Tab 本身已對非 administrator 隱藏（`AI_TAB角色可見性.feature`）。
5. i18n：所有新字串走 `@wordpress/i18n` `__()`，英文 msgid，繁中翻譯加進 `scripts/i18n-translations/manual.json`，跑 `pnpm run i18n:build`。

### 文件

- `mcp.zh-TW.md` / `mcp.md`：「步驟一」改為「在 設定 → AI 產生 MCP Token」；「步驟二（Base64 編碼）」移除；「步驟三」設定範本改用 `Authorization: Bearer <token>`。舊的「應用程式密碼 + Basic Auth」流程降為文末「進階 / 備用方式」小節保留（明確標註「既有用戶不受影響，仍可使用」）。

## 不在本 Issue 範圍

- 逐 Token 權限（capabilities 子集）UI（Q2 決議不做）。
- 已撤銷 Token 的稽核列表 / 還原（Q3 決議撤銷即消失）。
- 全站跨管理員的 Token 集中稽核視圖（Q6 決議只列自己）。
- 移除 Application Password / Basic Auth 登入方式（仍保留，相容）。
- 舊獨立 MCP Tab（啟用開關 / Rate Limit / Activity Log 區塊）的整體復原 — 本 Issue 只把 Token 管理放進 AI Tab。

## 產出規格檔案

- `specs/features/settings/MCP_Token產生.feature`（新增）
- `specs/features/settings/MCP_Token列表與撤銷.feature`（新增）
- `specs/features/settings/MCP_Token認證.feature`（新增）
- `specs/clarify/2026-05-28-issue230-mcp-token-management.md`（本檔）
