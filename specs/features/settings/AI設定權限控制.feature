@ignore @command
Feature: AI 設定 — MCP 修改 / 刪除權限控制

  作為站長，我想透過後台 UI 控制 AI（透過 MCP）能不能修改或刪除課程資料，
  而不是必須登入伺服器去設定環境變數。
  預設兩個開關都是關閉的（唯讀模式），確保 AI 在未經授權前不會誤改 / 誤刪資料。

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
    And MCP 設定為預設值：
      | enabled | enabled_categories | rate_limit_per_min | allow_update | allow_delete |
      | true    | []                 | 60                 | false        | false        |
    And 已建立 MCP API Token "test-token" 給用戶 "Admin"

  # ========== 後置（狀態）— 設定讀寫 ==========

  Rule: 後置（狀態）- AI Tab 兩個開關預設關閉

    Example: 全新安裝時 allow_update / allow_delete 預設為 false
      When 管理員 "Admin" 開啟「設定 → AI」頁籤
      Then 「允許修改」Switch 應為關閉
      And 「允許刪除」Switch 應為關閉

  Rule: 後置（狀態）- 開關需按 Save 才寫入

    Example: Toggle 後未按 Save，重新整理後狀態還原
      Given 管理員 "Admin" 在「設定 → AI」頁籤
      When 管理員將「允許修改」Switch 切換為開啟
      And 管理員未按頂部「Save」按鈕，直接重新整理頁面
      Then 「允許修改」Switch 應為關閉

    Example: Toggle 後按 Save 寫入 wp_options
      Given 管理員 "Admin" 在「設定 → AI」頁籤
      When 管理員將「允許修改」Switch 切換為開啟
      And 管理員按頂部「Save」按鈕
      Then 操作成功
      And 設定 pc_mcp_settings.allow_update 應為 true
      And 設定 pc_mcp_settings.allow_delete 應為 false

    Example: 兩個開關獨立控制
      Given 管理員 "Admin" 在「設定 → AI」頁籤
      When 管理員將「允許修改」Switch 切換為開啟
      And 管理員將「允許刪除」Switch 切換為開啟
      And 管理員按頂部「Save」按鈕
      Then 設定 pc_mcp_settings.allow_update 應為 true
      And 設定 pc_mcp_settings.allow_delete 應為 true

  Rule: 後置（狀態）- 升級時忽略既有環境變數，預設仍為 false

    Example: 即使 ALLOW_UPDATE=1，升級後 allow_update 仍為 false
      Given 環境變數 ALLOW_UPDATE 為 "1"
      And 環境變數 ALLOW_DELETE 為 "1"
      When 系統升級至支援 AI Tab 的版本
      And 管理員 "Admin" 開啟「設定 → AI」頁籤
      Then 「允許修改」Switch 應為關閉
      And 「允許刪除」Switch 應為關閉

  # ========== 後置（狀態）— MCP Tool 權限檢查 ==========

  Rule: 後置（狀態）- 讀取類 tool 不受開關影響

    Example: 兩個開關關閉時，course_list 仍可呼叫
      Given MCP 設定 allow_update = false
      And MCP 設定 allow_delete = false
      When AI 透過 token "test-token" 呼叫 MCP tool "course_list"，參數 {}
      Then 操作成功
      And 回傳資料不為空

    Example: 兩個開關關閉時，chapter_get 仍可呼叫
      Given MCP 設定 allow_update = false
      And MCP 設定 allow_delete = false
      And 系統中有章節 ID 100
      When AI 透過 token "test-token" 呼叫 MCP tool "chapter_get"，參數 { "chapter_id": 100 }
      Then 操作成功

  Rule: 後置（狀態）- 修改類 tool 受 allow_update 控制

    Example: allow_update 關閉時，course_create 操作失敗
      Given MCP 設定 allow_update = false
      When AI 透過 token "test-token" 呼叫 MCP tool "course_create"，參數 { "post_title": "新課程" }
      Then 操作失敗，錯誤碼為 "mcp_operation_not_allowed"
      And 錯誤訊息應包含「Allow update」
      And 錯誤訊息應包含「Settings → AI」
      And 系統中不應建立新課程

    Example: allow_update 開啟時，course_create 操作成功
      Given MCP 設定 allow_update = true
      When AI 透過 token "test-token" 呼叫 MCP tool "course_create"，參數 { "post_title": "新課程" }
      Then 操作成功
      And 系統中存在標題為「新課程」的課程

    Example: allow_update 關閉時，chapter_toggle_finish 也被拒絕
      Given MCP 設定 allow_update = false
      When AI 透過 token "test-token" 呼叫 MCP tool "chapter_toggle_finish"，參數 { "chapter_id": 100, "is_finished": true }
      Then 操作失敗，錯誤碼為 "mcp_operation_not_allowed"

    Example: allow_update 開啟、allow_delete 關閉時，刪除類 tool 仍被拒絕
      Given MCP 設定 allow_update = true
      And MCP 設定 allow_delete = false
      And 系統中有課程 ID 200
      When AI 透過 token "test-token" 呼叫 MCP tool "course_delete"，參數 { "course_id": 200 }
      Then 操作失敗，錯誤碼為 "mcp_operation_not_allowed"
      And 錯誤訊息應包含「Allow delete」
      And 系統中課程 ID 200 應仍存在

  Rule: 後置（狀態）- 刪除類 tool 受 allow_delete 控制

    Example: allow_delete 關閉時，course_delete 操作失敗
      Given MCP 設定 allow_delete = false
      And 系統中有課程 ID 200
      When AI 透過 token "test-token" 呼叫 MCP tool "course_delete"，參數 { "course_id": 200 }
      Then 操作失敗，錯誤碼為 "mcp_operation_not_allowed"
      And 系統中課程 ID 200 應仍存在

    Example: allow_delete 開啟時，course_delete 操作成功
      Given MCP 設定 allow_delete = true
      And 系統中有課程 ID 200
      When AI 透過 token "test-token" 呼叫 MCP tool "course_delete"，參數 { "course_id": 200 }
      Then 操作成功
      And 系統中課程 ID 200 應不存在

    Example: allow_delete 關閉時，progress_reset 也被拒絕
      Given MCP 設定 allow_delete = false
      And 系統中有用戶 5 的學習進度資料
      When AI 透過 token "test-token" 呼叫 MCP tool "progress_reset"，參數 { "user_id": 5, "course_id": 200 }
      Then 操作失敗，錯誤碼為 "mcp_operation_not_allowed"

  # ========== 後置（狀態）— 拒絕時不記錄 activity log ==========

  Rule: 後置（狀態）- 權限拒絕不寫入 ActivityLogger

    Example: 拒絕的呼叫不出現在活動紀錄
      Given MCP 設定 allow_update = false
      And ActivityLogger 中有 0 筆紀錄
      When AI 透過 token "test-token" 呼叫 MCP tool "course_create"，參數 { "post_title": "X" }
      Then 操作失敗，錯誤碼為 "mcp_operation_not_allowed"
      And ActivityLogger 中應仍為 0 筆紀錄

  # ========== 前置（資料）— 教學文字 ==========

  Rule: 前置（資料）- AI Tab 提供教學連結

    Example: AI Tab 顯示 mcp.zh-TW.md 連結
      When 管理員 "Admin" 開啟「設定 → AI」頁籤
      Then 頁面應顯示連結指向 "https://github.com/zenbuapps/wp-power-course/blob/master/mcp.zh-TW.md"
      And 連結應在新分頁開啟（target="_blank"）

    Example: 兩個 Switch 旁邊各自有簡短說明
      When 管理員 "Admin" 開啟「設定 → AI」頁籤
      Then 「允許修改」附近應有說明文字描述「建立、更新、排序」類操作
      And 「允許刪除」附近應有說明文字描述「刪除、移除、重置」類操作
