@ignore @command @query @mcp-token
Feature: MCP Token 列表與撤銷 — 在 AI Tab 集中查看與管理自己的 Token

  作為站長，我想在「設定 → AI」頁面看到自己建立的所有 MCP Token，
  清楚知道每個 Token 的名稱、建立時間、最後使用時間與到期狀態，
  並能在懷疑 Token 外洩時立即撤銷，讓使用該 Token 的 AI 請求馬上失效。

  Background:
    Given 系統中有以下用戶：
      | userId | name   | email           | role          |
      | 1      | Admin  | admin@test.com  | administrator |
      | 2      | Admin2 | admin2@test.com | administrator |
    And MCP Server 已啟用（pc_mcp_settings.enabled 為 true）
    And wp_pc_mcp_tokens 已有以下資料：
      | id | user_id | name             | created_at          | last_used_at        | expires_at          | revoked_at |
      | 10 | 1       | 辦公室 — Claude  | 2026-05-01 08:00:00 | 2026-05-27 10:30:00 | NULL                | NULL       |
      | 11 | 1       | 家用 — Cursor    | 2026-05-10 09:00:00 | NULL                | 2026-08-08 09:00:00 | NULL       |
      | 12 | 1       | 已撤銷的舊 Token | 2026-04-01 09:00:00 | 2026-04-15 09:00:00 | NULL                | 2026-04-20 |
      | 20 | 2       | Admin2 私有      | 2026-05-05 09:00:00 | NULL                | NULL                | NULL       |
    And 用戶 "Admin" 已登入並開啟「設定 → AI」頁面

  # ========== 後置（視圖）— 列表欄位 ==========

  Rule: 後置（視圖）- 列表顯示名稱 / 建立時間 / 最後使用時間 / 到期

    Example: Token 列表顯示必要欄位
      When AI Tab 的「MCP Token 管理」區塊載入完成
      Then Token 列表應包含以下欄位：
        | column       | label        |
        | name         | 名稱         |
        | created_at   | 建立時間     |
        | last_used_at | 最後使用時間 |
        | expires_at   | 到期時間     |
        | actions      | 操作         |

    Example: 永不過期的 Token 到期欄位顯示「永不過期」
      When AI Tab 的「MCP Token 管理」區塊載入完成
      Then id 為 10 的列其到期時間欄位應顯示「永不過期」
      And id 為 11 的列其到期時間欄位應顯示具體日期 "2026-08-08"

    Example: 從未使用的 Token 最後使用時間顯示佔位文字
      When AI Tab 的「MCP Token 管理」區塊載入完成
      Then id 為 11 的列其最後使用時間欄位應顯示「尚未使用」（或 "—"）

  Rule: 後置（視圖）- 空列表顯示上手指引

    Example: 沒有任何 Token 時顯示引導文字
      Given 用戶 "Admin" 名下沒有任何未撤銷的 Token
      When AI Tab 的「MCP Token 管理」區塊載入完成
      Then 應顯示空狀態提示，引導管理員點擊「新增 Token」開始設定
      And 應提示「此 Token 專為 Power Course MCP 設計，不需使用 WordPress 應用程式密碼」

  # ========== 後置（資料）— 列表只含自己且不含已撤銷 ==========

  Rule: 後置（資料）- 只列出目前登入管理員自己建立的 Token

    Example: Admin 只看到自己的 Token，看不到 Admin2 的
      When 用戶 "Admin" 觸發 GET mcp/tokens
      Then 回傳清單應包含 id 10、11
      And 回傳清單不應包含 id 20（屬於 Admin2）

    Example: 後端依目前登入者過濾（list_tokens 帶 current_user_id）
      Given 用戶 "Admin2" 已登入
      When 用戶 "Admin2" 觸發 GET mcp/tokens
      Then 回傳清單應只包含 id 20
      And 回傳清單不應包含 id 10、11

  Rule: 後置（資料）- 已撤銷的 Token 不出現在列表

    Example: 已撤銷的 Token 從列表消失
      When 用戶 "Admin" 觸發 GET mcp/tokens
      Then 回傳清單不應包含 id 12（revoked_at 不為空）

  Rule: 後置（資料）- 列表回應不含 Token 明文

    Example: GET mcp/tokens 不回明文
      When 用戶 "Admin" 觸發 GET mcp/tokens
      Then 每筆項目應包含 id / name / created_at / last_used_at / expires_at
      And 每筆項目不應包含 token 明文欄位
      And 每筆項目不應包含 token_hash 欄位

  # ========== 後置（行為）— 撤銷流程 ==========

  Rule: 後置（行為）- 撤銷需二次確認

    Example: 點擊撤銷跳出確認對話框
      Given 用戶 "Admin" 在 Token 列表看到 id 為 10 的 Token
      When 管理員點擊該列的「撤銷」按鈕
      Then 應跳出確認對話框要求二次確認
      And 在未確認前不應送出 DELETE 請求

    Example: 確認後送出 DELETE 並從列表移除
      Given 管理員點擊 id 10 的「撤銷」並在確認對話框按下確認
      When DELETE mcp/tokens/10 成功
      Then id 10 的紀錄 revoked_at 應被寫入撤銷時間
      And 列表 refetch 後不應再出現 id 10

  Rule: 後置（狀態）- 撤銷即時生效

    Example: 撤銷後使用該 Token 的請求立即被拒
      Given id 10 的 Token 明文為 "tokenABC"
      When 管理員撤銷 id 10
      And AI 客戶端以 "Authorization: Bearer tokenABC" 呼叫 MCP 工具
      Then 該請求不應被認證為 Admin
      And MCP 工具呼叫應失敗（未授權）

  # ========== 不變式（安全邊界）— 撤銷權限 ==========

  Rule: 不變式 - 不可撤銷他人的 Token

    Example: Admin 不可撤銷 Admin2 的 Token
      Given 用戶 "Admin" 已登入
      When 用戶 "Admin" 觸發 DELETE mcp/tokens/20（屬於 Admin2）
      Then 後端不應撤銷 id 20
      And id 20 的 revoked_at 應維持為空

    Example: 非 administrator 不可撤銷任何 Token
      Given 用戶具備角色 "editor"（無 manage_options）
      When 該用戶觸發 DELETE mcp/tokens/10
      Then 後端應回傳 HTTP 403
      And id 10 的 revoked_at 應維持為空
