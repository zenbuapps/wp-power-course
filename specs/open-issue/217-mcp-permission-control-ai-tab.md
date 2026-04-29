# Issue #217: MCP 預設唯讀 + AI 設定 Tab

## 問題描述

目前 MCP（Model Context Protocol）的「修改」與「刪除」權限，是透過伺服器**環境變數** `ALLOW_UPDATE` / `ALLOW_DELETE` 控制（commit `2abacd86`）。這要求站長必須有伺服器存取權，對非技術站長不友善，且無法在 WordPress 後台直接管理。

本 Issue 要將控制機制從環境變數搬到後台 UI，新增「AI」設定 Tab，讓站長透過兩個 Switch 開關直接控制 AI 是否能修改 / 刪除課程資料。預設兩者皆關閉，即 MCP 在新版本下**預設為唯讀**。

## 確認的需求決策

| # | 問題 | 決定 |
|---|------|------|
| Q1 | Tab 結構 | **新增獨立的「AI」Tab**，與現有 4 個 Tab（一般 / 外觀 / 自動授權 / MCP）並列為第 5 個 |
| Q2 | AI Tab 內部版型 | **單頁面**，不再分子 Tab；上方一個區塊放兩個 Switch + 教學說明 |
| Q3 | 環境變數遷移 | **完全忽略**環境變數 `ALLOW_UPDATE` / `ALLOW_DELETE`，統一改用 UI 開關（單一資料來源） |
| Q4 | 儲存方式 | 與既有設定頁一致，**按下 Save 才寫入**（`useSave` hook） |
| Q5 | 拒絕時的活動紀錄 | **不寫入** activity log，只在 API response 回傳明確錯誤訊息 |
| Q6 | 權限檢查實作位置 | **`AbstractTool::is_operation_allowed()`** 統一檢查：基類讀 `pc_mcp_settings` 中的兩個欄位，子類 tool 不需感知 |
| Q7 | `chapter_toggle_finish` 分類 | 改為 `OP_UPDATE`（會寫 DB，唯讀模式應拒絕） |
| Q8 | 教學文字 | 兩個 Switch 附近放教學連結，指向 [`mcp.zh-TW.md`](https://github.com/zenbuapps/wp-power-course/blob/master/mcp.zh-TW.md) |

## 技術方案

### 後端（PHP）

**1. 擴充 `pc_mcp_settings` option schema**

`inc/classes/Api/Mcp/Settings.php` 新增兩個欄位：

```php
$defaults = [
    'enabled'            => false,
    'enabled_categories' => [],
    'rate_limit_per_min' => self::DEFAULT_RATE_LIMIT,
    'allow_update'       => false,  // 新增
    'allow_delete'       => false,  // 新增
];
```

新增 4 個方法：
- `is_update_allowed(): bool`
- `is_delete_allowed(): bool`
- `set_update_allowed(bool $allowed): bool`
- `set_delete_allowed(bool $allowed): bool`

**2. 改寫 `AbstractTool::is_operation_allowed()`**

`inc/classes/Api/Mcp/AbstractTool.php` 第 124–141 行：移除 `getenv()` 呼叫，改讀 `Settings`：

```php
final protected function is_operation_allowed(): bool {
    $op = $this->get_operation_type();
    if ( self::OP_READ === $op ) {
        return true;
    }
    $settings = new Settings();
    if ( self::OP_UPDATE === $op ) {
        return $settings->is_update_allowed();
    }
    if ( self::OP_DELETE === $op ) {
        return $settings->is_delete_allowed();
    }
    return false;
}
```

對應的錯誤訊息（第 188–199 行）改為人類友善的指引：

```text
Operation "%2$s" is disabled for MCP tool "%1$s".
Please enable "Allow update" or "Allow delete" in WordPress Admin → Power Course → Settings → AI.
```

**3. 修正 `chapter_toggle_finish` 分類為 `OP_UPDATE`**

`inc/classes/Api/Mcp/Tools/Chapter/ChapterToggleFinishTool.php`：覆寫 `get_operation_type()` 回傳 `self::OP_UPDATE`（因為預設規則 `_get / _list / _stats` 不會命中 `_toggle_finish`，但仍須明確覆寫保險）。

> **檢查**：跑 `tests/integration` 中的 PHPUnit，確保覆寫後 `_toggle_finish` 仍能在「允許修改」開啟時通過。

**4. 移除/淘汰環境變數讀取**

- 全程式碼搜尋 `ALLOW_UPDATE` / `ALLOW_DELETE` / `getenv` 並刪除（除了 release notes / migration guide）
- README、`mcp.zh-TW.md` 同步更新權限管理方式

**5. REST 端點 schema 擴充**

`inc/classes/Api/Mcp/RestController.php`（或對應 settings endpoint）：在更新 `pc_mcp_settings` 的 PUT/PATCH endpoint 中接受 `allow_update` / `allow_delete` 兩個 boolean 欄位，並在 GET 中回傳。

### 前端（React）

**1. 新增 AI Tab**

`js/src/pages/admin/Settings/index.tsx`：
- 新增 `Ai` 目錄，內含 `index.tsx`
- 在 `getItems()` 中加入第 5 個 entry：

```tsx
{
  key: 'ai',
  label: __('AI', 'power-course'),
  children: <AiTabLoader />,
}
```

**2. AI Tab 版型**

`js/src/pages/admin/Settings/Ai/index.tsx`：

```
┌─────────────────────────────────────────┐
│ MCP 權限控制                              │
│                                          │
│ ☐ 允許修改 (Switch)                      │
│   說明：開啟後，AI 可建立、更新、排序課程│
│   章節等資料。                            │
│                                          │
│ ☐ 允許刪除 (Switch)                      │
│   說明：開啟後，AI 可刪除課程、章節，    │
│   重置學員進度等。                        │
│                                          │
│ 📖 教學文件：[如何使用 MCP →]            │
│   (連結 mcp.zh-TW.md)                    │
└─────────────────────────────────────────┘
```

- 兩個 Switch 都用 Ant Design `Switch` + `Form.Item name={['mcp', 'allow_update']}`
- 透過既有 `Form` + `useSettings` + `useSave` 流程，按下頂部 Save 才寫入
- 教學連結用 `<a target="_blank" rel="noopener noreferrer">`

**3. 設定 GET / Save 邏輯**

`js/src/pages/admin/Settings/hooks/useSettings.ts`、`useSave.ts`：確認新欄位 `allow_update` / `allow_delete` 自動隨 `pc_mcp_settings` 一起 GET / PUT。若既有 hook 是把整包 settings 包起來送，多半不需修改；若 hook 有白名單欄位則需加入。

### 資料遷移與相容性

- **環境變數**：完全忽略，但保留偵測程式碼於 `Migration.php` 中 deprecation log 一次（首次升級時 `error_log( 'ALLOW_UPDATE env var is no longer used; please configure via Settings → AI' )`），協助站長從伺服器日誌看到提醒
- **wp_options 預設值**：升級後 `pc_mcp_settings` 中若無 `allow_update` / `allow_delete`，自動 fallback 為 `false`（預設唯讀）。**無論原本是否設過 `ALLOW_UPDATE=1`，升級後一律重置為 false**，站長必須重新到 AI Tab 啟用，避免靜默授權

## 不修改的檔案

- 既有 MCP Tab（`js/src/pages/admin/Settings/Mcp/`）：保留，內容不變
- 41 個具體 tool class（除了 `ChapterToggleFinishTool` 需覆寫 `get_operation_type()`）
- `ActivityLogger`：拒絕時不寫入

## 修改的檔案清單

### 新建

1. `js/src/pages/admin/Settings/Ai/index.tsx`（AI Tab 主元件）
2. `js/src/pages/admin/Settings/Ai/PermissionControl.tsx`（兩個 Switch + 教學區塊）

### 修改

1. `inc/classes/Api/Mcp/Settings.php`（新增 4 個 method + schema 兩欄位）
2. `inc/classes/Api/Mcp/AbstractTool.php`（重寫 `is_operation_allowed()`、改錯誤訊息）
3. `inc/classes/Api/Mcp/Tools/Chapter/ChapterToggleFinishTool.php`（覆寫 `get_operation_type()`）
4. `inc/classes/Api/Mcp/RestController.php`（接受 / 回傳兩個新欄位）
5. `js/src/pages/admin/Settings/index.tsx`（註冊 AI Tab）
6. `js/src/pages/admin/Settings/hooks/useSettings.ts` / `useSave.ts`（如必要）
7. `mcp.zh-TW.md`、`README.md`（文件同步）

## 驗收標準

- [ ] 設定頁出現第 5 個 Tab「AI」，順序為一般 / 外觀 / 自動授權 / MCP / **AI**
- [ ] AI Tab 內顯示「MCP 權限控制」單一區塊，含「允許修改」+「允許刪除」兩個 Switch
- [ ] 全新安裝兩個開關預設為 `false`
- [ ] 升級安裝時即使原 `ALLOW_UPDATE=1` 環境變數存在，兩開關仍為 `false`
- [ ] 兩開關下方/旁邊有教學連結指向 `mcp.zh-TW.md`
- [ ] 開關需按頂部 Save 才寫入，重新整理後保留設定
- [ ] 開啟「允許修改」後，所有 `OP_UPDATE` 類 tool 可執行（含 `chapter_toggle_finish`）
- [ ] 關閉「允許修改」時，`OP_UPDATE` 類 tool 回傳 `mcp_operation_not_allowed`，訊息明確指引到「設定 → AI」
- [ ] 開啟「允許刪除」後，所有 `OP_DELETE` 類 tool 可執行
- [ ] 關閉「允許刪除」時，`OP_DELETE` 類 tool 回傳 `mcp_operation_not_allowed`
- [ ] 讀取類 tool 不受兩開關影響，始終可執行
- [ ] 拒絕操作**不**寫入 ActivityLog（避免日誌爆量）
- [ ] 環境變數 `ALLOW_UPDATE` / `ALLOW_DELETE` 已從程式碼移除，不再被讀取
- [ ] PHPUnit 整合測試覆蓋三條路徑：`OP_READ` 始終允許 / `OP_UPDATE` 受 `allow_update` 控制 / `OP_DELETE` 受 `allow_delete` 控制
