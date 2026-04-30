# Power Course MCP Server — 設定指南

[English](./mcp.md) | 繁體中文

> 透過 [Model Context Protocol（MCP）](https://modelcontextprotocol.io/) 將 AI 代理（Claude Code、Cursor、GPT 等）連接到你的 WordPress 課程系統。

---

## 概覽

Power Course 提供 MCP 伺服器，讓 AI 代理可以程式化地管理你的 LMS — 建立課程、加入學員、查詢報表等 — 全部透過標準化的工具介面。

連接完成後，你可以用自然語言與 WordPress 網站互動：

- 「幫我列出 example.com 網站上所有課程，按照銷量排序」
- 「把用戶 #42 加入 TypeScript 進階課程」
- 「幫我在課程 #123 裡面創新一個章節，主題為: AI 時代的網路行銷，內容你幫我安排」
- 「把課程 #101 的學員名單匯出成 CSV」

AI 客戶端會在背後將你的請求轉換為對應的 MCP 工具呼叫。

---

## 前置需求

### WordPress 帳號

你需要一個具備 **`manage_woocommerce`** 權限的 WordPress 帳號（通常是管理員或商店管理員角色）。

---

## 設定步驟

### 步驟一 — 產生應用程式密碼

1. 前往 **WordPress 後台 → 使用者 → 個人資料**
2. 捲動到 **應用程式密碼** 區塊
3. 輸入名稱（例如 `Claude Code`），點擊 **新增應用程式密碼**
4. **立即複製產生的密碼** — 僅顯示一次

> **提示**：應用程式密碼是 WordPress（5.6+）內建功能，不需要額外安裝外掛。

### 步驟二 — 編碼你的憑證

將 WordPress 使用者名稱與應用程式密碼組合後，進行 Base64 編碼：

```
使用者名稱:xxxx xxxx xxxx xxxx xxxx xxxx
```

可以使用命令列編碼：

```bash
echo -n "admin:ABCD 1234 EFGH 5678 IJKL 9012" | base64
```

或前往 [https://www.base64encode.org/](https://www.base64encode.org/) 輸入 `admin:ABCD 1234 EFGH 5678 IJKL 9012`

輸出結果類似：`YWRtaW46QUJDRCAxMjM0IEVGR0ggNTY3OCBJSktMIDkwMTI=`

### 步驟三 — 設定你的 AI 客戶端

將 MCP 伺服器加入 AI 客戶端的設定檔。

#### Claude Code

MCP 設定有三種範圍，選擇其一：

**方式 A — 專案共享**（推薦給團隊）：加入專案根目錄的 `.mcp.json`

```json
{
  "mcpServers": {
    "power-course": {
      "type": "http",
      "url": "https://yoursite.com/wp-json/power-course/v2/mcp",
      "headers": {
        "Authorization": "Basic YWRtaW46QUJDRCAxMjM0IEVGR0ggNTY3OCBJSktMIDkwMTI"
      }
    }
  }
}
```

**方式 B — 個人全域**（推薦個人使用）：加入 `~/.claude.json`

```json
{
  "mcpServers": {
    "power-course": {
      "type": "http",
      "url": "https://yoursite.com/wp-json/power-course/v2/mcp",
      "headers": {
        "Authorization": "Basic YWRtaW46QUJDRCAxMjM0IEVGR0ggNTY3OCBJSktMIDkwMTI="
      }
    }
  }
}
```

> **注意**：MCP **預設為唯讀模式**。要允許 AI 修改或刪除資料，請至 **WordPress 後台 → Power Course → 設定 → AI** 開啟「允許修改」「允許刪除」開關。（Issue #217：舊版透過 `ALLOW_UPDATE` / `ALLOW_DELETE` 環境變數控制；現已改為後台 UI 控制，環境變數不再生效。）

**方式 C — CLI 快速設定**：

```bash
claude mcp add --transport http power-course \
  https://yoursite.com/wp-json/power-course/v2/mcp \
  --header "Authorization: Basic YWRtaW46QUJDRCAxMjM0IEVGR0ggNTY3OCBJSktMIDkwMTI="
```


#### Cursor

加入專案根目錄的 `.cursor/mcp.json`：

```json
{
  "mcpServers": {
    "power-course": {
      "type": "http",
      "url": "https://yoursite.com/wp-json/power-course/v2/mcp",
      "headers": {
        "Authorization": "Basic YWRtaW46QUJDRCAxMjM0IEVGR0ggNTY3OCBJSktMIDkwMTI="
      }
    }
  }
}
```

#### WP-CLI（STDIO 傳輸）

如果你有伺服器上的 WP-CLI 存取權限，可以使用 STDIO 傳輸取代 HTTP：

```bash
# 列出所有已註冊的 MCP 伺服器
wp mcp-adapter list

# 啟動 Power Course MCP 伺服器
wp mcp-adapter serve --server=power-course-mcp --user=admin
```

### 步驟五 — 驗證連線

請 AI 客戶端列出課程：

> 「幫我列出這個網站所有已發佈的課程」

如果連線正常，你會收到包含課程資料的結構化回應。

---

## 可用工具一覽（41 工具 × 9 領域）

### 課程 Course（6 個）

| 工具               | 說明                                                       |
| ------------------ | ---------------------------------------------------------- |
| `course_list`      | 列出課程，支援分頁、狀態篩選、排序與關鍵字搜尋             |
| `course_get`       | 取得課程完整詳情（章節、價格、限制、訂閱、銷售方案、講師） |
| `course_create`    | 建立新課程（WooCommerce 商品 + `_is_course = yes`）        |
| `course_update`    | 更新課程欄位 — 僅修改傳入的欄位                            |
| `course_delete`    | 永久刪除課程（不可復原）                                   |
| `course_duplicate` | 複製課程（含章節與銷售方案關聯，預設 draft 狀態）          |

### 章節 Chapter（7 個）

| 工具                    | 說明                                   |
| ----------------------- | -------------------------------------- |
| `chapter_list`          | 列出章節，可依課程 ID 或父章節 ID 篩選 |
| `chapter_get`           | 取得章節完整詳情                       |
| `chapter_create`        | 在指定課程下建立新章節                 |
| `chapter_update`        | 更新章節標題、內容或其他欄位           |
| `chapter_delete`        | 將章節移至垃圾桶                       |
| `chapter_sort`          | 原子操作重排章節順序（全成功或全失敗） |
| `chapter_toggle_finish` | 標記章節為已完成 / 未完成              |

### 學員 Student（9 個）

| 工具                         | 說明                                                 |
| ---------------------------- | ---------------------------------------------------- |
| `student_list`               | 列出學員，支援課程篩選、關鍵字搜尋與分頁             |
| `student_get`                | 取得學員詳情（含已註冊課程 ID 清單）                 |
| `student_add_to_course`      | 手動授權學員至課程（可設定到期日）                   |
| `student_remove_from_course` | 撤銷學員課程存取權                                   |
| `student_get_progress`       | 取得學員課程進度摘要（完成章節數、百分比、到期狀態） |
| `student_get_log`            | 查詢學員活動日誌（依 user/course 篩選、分頁）        |
| `student_update_meta`        | 更新學員 user_meta（僅限白名單欄位）                 |
| `student_export_count`       | 預覽 CSV 匯出的學員 × 課程行數                       |
| `student_export_csv`         | 匯出課程學員名單為 CSV（回傳下載 URL）               |

### 講師 Teacher（4 個）

| 工具                         | 說明                                              |
| ---------------------------- | ------------------------------------------------- |
| `teacher_list`               | 列出所有講師（具 `is_teacher = yes` meta 的用戶） |
| `teacher_get`                | 取得講師詳情與授課課程清單                        |
| `teacher_assign_to_course`   | 指派講師到課程（idempotent）                      |
| `teacher_remove_from_course` | 從課程移除講師（idempotent）                      |

### 銷售方案 Bundle（4 個）

| 工具                     | 說明                                           |
| ------------------------ | ---------------------------------------------- |
| `bundle_list`            | 列出銷售方案，支援分頁與課程篩選               |
| `bundle_get`             | 取得方案詳情（綁定課程、商品 ID、數量）        |
| `bundle_set_products`    | 原子操作設定方案商品 ID 與數量（失敗自動回滾） |
| `bundle_delete_products` | 移除方案內全部或指定商品                       |

### 訂單 Order（3 個）

| 工具                  | 說明                                                     |
| --------------------- | -------------------------------------------------------- |
| `order_list`          | 列出 WooCommerce 訂單，依狀態/客戶/日期篩選（相容 HPOS） |
| `order_get`           | 取得訂單詳情與課程相關項目                               |
| `order_grant_courses` | 手動重新觸發訂單課程授權（idempotent）                   |

### 進度 Progress（3 個）

| 工具                             | 說明                                                            |
| -------------------------------- | --------------------------------------------------------------- |
| `progress_get_by_user_course`    | 取得學員在課程中的完整章節級進度                                |
| `progress_mark_chapter_finished` | 明確標記章節為完成/未完成（非切換）                             |
| `progress_reset`                 | **危險操作**：刪除學員在課程中的所有進度（需 `confirm = true`） |

### 留言 Comment（3 個）

| 工具                      | 說明                                                     |
| ------------------------- | -------------------------------------------------------- |
| `comment_list`            | 列出文章留言，支援分頁、類型與狀態篩選                   |
| `comment_create`          | 發表留言或評價（可指定其他用戶，需 `moderate_comments`） |
| `comment_toggle_approved` | 切換留言審核狀態（連帶子留言一併切換）                   |

### 報表 Report（2 個）

| 工具                   | 說明                                                          |
| ---------------------- | ------------------------------------------------------------- |
| `report_revenue_stats` | 日期區間營收統計（訂單數、退款、學員數、完成數），上限 365 天 |
| `report_student_count` | 日期區間新加入學員數（依 interval 分組），上限 365 天         |

---

## MCP 設定

在 **Power Course → 設定 → MCP**（伺服器 / 分類 / Token / 活動）和 **Power Course → 設定 → AI**（修改/刪除權限）設定，或透過 REST API。

| 設定                 | 預設值       | 說明                                |
| -------------------- | ------------ | ----------------------------------- |
| `enabled`            | `false`      | MCP 伺服器全域開關                  |
| `enabled_categories` | `[]`（全部） | 啟用的工具分類 — 空陣列表示全部啟用 |
| `rate_limit_per_min` | `60`         | 每分鐘最大請求數                    |
| `allow_update`       | `false`      | **Issue #217**：允許 AI 透過 MCP 建立 / 更新 / 排序 / 複製等操作 |
| `allow_delete`       | `false`      | **Issue #217**：允許 AI 透過 MCP 刪除 / 移除 / 重置等操作       |

---

## 操作層級權限控制（設定 → AI）

MCP **預設為唯讀模式**。要允許 AI 修改或刪除資料，請至：

> **WordPress 後台 → Power Course → 設定 → AI**

切換以下兩個開關：

| 開關 | 效果 |
|------|------|
| **允許修改** | 啟用 create / update / sort / toggle / duplicate / assign / add / mark / grant 類操作 |
| **允許刪除** | 啟用 delete / remove / reset 類操作 |
| 兩者皆關閉（預設） | **唯讀模式**：僅允許 list / get / export / stats / count 類工具 |

> **遷移提醒（Issue #217）**：舊版透過 `ALLOW_UPDATE` / `ALLOW_DELETE` 環境變數控制此項權限，**新版環境變數已不再生效**，請改至後台 AI Tab 設定。升級後兩個開關預設為 `false`，不會靜默授權。

### 操作類型分類

每個 MCP 工具依其功能自動歸入以下類型：

| 操作類型 | 對應工具名稱模式 | 範例 |
|----------|------------------|------|
| **read** | `*_list`、`*_get`、`*_export_*`、`*_stats`、`*_count` | `course_list`、`student_get`、`report_revenue_stats` |
| **update** | `*_create`、`*_update`、`*_sort`、`*_toggle_*`、`*_duplicate`、`*_set_*`、`*_assign_*`、`*_add_*`、`*_mark_*`、`*_grant_*` | `course_create`、`chapter_sort`、`student_add_to_course`、`chapter_toggle_finish` |
| **delete** | `*_delete`、`*_remove_*`、`*_reset` | `course_delete`、`student_remove_from_course`、`progress_reset` |

### 錯誤回應

當 AI 代理嘗試執行未授權的操作時，會收到 403 錯誤與明確的後台路徑指引：

```
MCP tool「course_delete」的「delete」操作未啟用。
請至 WordPress 後台 → Power Course → 設定 → AI 開啟「允許刪除」。
```

---

## 安全機制

- 所有 MCP 工具強制執行 WordPress 權限檢查（預設需要 `manage_woocommerce`）
- 設定 → AI 中的「允許修改」/「允許刪除」開關提供操作層級的權限控制，預設唯讀
- Token 使用 SHA-256 雜湊儲存 — 明文僅在建立時顯示一次
- 每個 Token 支援 JSON `capabilities` 欄位，可限制可存取的工具
- 活動日誌記錄每次工具呼叫，透過 `wp_cron` 自動清理 30 天前的紀錄
- 危險操作（如 `progress_reset`）需明確傳入 `confirm = true` 參數

---

## 管理 REST API

基礎 URL：`{site_url}/wp-json/power-course/v2/`

所有端點需要 `manage_options` 權限。

| 端點              | 方法   | 說明                                            |
| ----------------- | ------ | ----------------------------------------------- |
| `mcp/settings`    | GET    | 取得 MCP 設定（含 `allow_update` / `allow_delete`） |
| `mcp/settings`    | POST   | 更新 MCP 設定（PATCH 語意，只更新有傳入的欄位）     |
| `mcp/tokens`      | GET    | 列出 API Token（雜湊後，不顯示明文）            |
| `mcp/tokens`      | POST   | 建立新 Token（僅回傳一次明文）                  |
| `mcp/tokens/{id}` | DELETE | 撤銷 Token                                      |
| `mcp/activity`    | GET    | 查詢工具活動日誌（可依 `tool_name` 篩選，分頁） |

---

## 疑難排解

| 問題                          | 解決方式                                                             |
| ----------------------------- | -------------------------------------------------------------------- |
| 401 Unauthorized              | 確認 Base64 憑證正確，且 WordPress 帳號存在                          |
| 403 Forbidden（capability）   | 確認帳號具有 `manage_woocommerce` 權限                               |
| 403 "Operation not allowed"   | 至 WordPress 後台 → Power Course → 設定 → AI，開啟「允許修改」和/或「允許刪除」開關 |
| 工具沒有顯示                  | 確認 MCP 伺服器已啟用，且該工具分類在設定 → MCP 中為啟用狀態         |
| 連線逾時                      | 確認網站 URL 可公開存取；本地環境請使用 STDIO 傳輸                   |
| `localhost` 無法連線          | 使用 tunnel 服務（ngrok、Cloudflare Tunnel）或改用 WP-CLI STDIO 傳輸 |

---

## 相關連結

- [Model Context Protocol 規格](https://modelcontextprotocol.io/)
- [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter)
- [WordPress Abilities API](https://github.com/WordPress/abilities-api)
- [Power Course README](./README.zh-TW.md)
