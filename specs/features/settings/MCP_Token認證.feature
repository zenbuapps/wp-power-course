@ignore @command @mcp-token
Feature: MCP Bearer Token 認證 — 以後台產生的 Token 呼叫 MCP 工具

  作為連接 AI 客戶端的站長，我希望用後台產生的 Bearer Token 就能成功呼叫 MCP 工具，
  且過期或撤銷的 Token 會立即被拒絕；同時既有使用 WordPress 應用程式密碼（Basic Auth）
  的站長不受影響，舊方式仍然可用。

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
    And MCP Server 已啟用（pc_mcp_settings.enabled 為 true）
    And 對外 MCP endpoint 為 "/wp-json/power-course/v2/mcp"
    And Bearer 認證掛在 determine_current_user filter（priority 20，在 cookie auth 之後）

  # ========== 後置（邏輯）— Bearer Token 認證成功 ==========

  Rule: 後置（邏輯）- 有效 Bearer Token 以建立者身分通過認證

    Example: 有效 Token 認證為建立者並可呼叫工具
      Given Admin 已建立明文為 "validToken" 的永不過期 Token
      When AI 客戶端以 "Authorization: Bearer validToken" 呼叫 MCP 工具 course.list
      Then 請求應被認證為用戶 Admin（userId 1）
      And MCP 工具 course.list 應成功回應

    Example: 認證成功時更新最後使用時間
      Given Admin 已建立明文為 "validToken" 的 Token
      And 該 Token 的 last_used_at 原為空
      When AI 客戶端以 "Authorization: Bearer validToken" 成功呼叫任一 MCP 工具
      Then wp_pc_mcp_tokens 該 Token 的 last_used_at 應被更新為當前時間

  # ========== 後置（狀態）— 過期 / 撤銷 / 無效 Token 被拒 ==========

  Rule: 後置（狀態）- 過期 Token 被拒

    Example: expires_at 已過的 Token 認證失敗
      Given Admin 已建立明文為 "expiredToken" 的 Token
      And 該 Token 的 expires_at 為過去時間
      When AI 客戶端以 "Authorization: Bearer expiredToken" 呼叫 MCP 工具
      Then 請求不應被認證為 Admin
      And MCP 工具呼叫應失敗（未授權）

    Example: 永不過期 Token 不因時間失效
      Given Admin 已建立 expires_at 為 NULL 的 Token "foreverToken"
      When 一年後 AI 客戶端以 "Authorization: Bearer foreverToken" 呼叫 MCP 工具
      Then 請求仍應被認證為 Admin
      And MCP 工具呼叫應成功

  Rule: 後置（狀態）- 撤銷 Token 被拒

    Example: revoked_at 已寫入的 Token 認證失敗
      Given Admin 已建立明文為 "revokedToken" 的 Token
      And 該 Token 已於後台被撤銷（revoked_at 不為空）
      When AI 客戶端以 "Authorization: Bearer revokedToken" 呼叫 MCP 工具
      Then 請求不應被認證為 Admin

  Rule: 後置（狀態）- 不存在的 Token 被拒

    Example: 隨機 Token 字串認證失敗
      When AI 客戶端以 "Authorization: Bearer nonexistent" 呼叫 MCP 工具
      Then 請求不應被認證為任何使用者

    Example: MCP Server 停用時 Bearer Token 不生效
      Given MCP Server 已停用（pc_mcp_settings.enabled 為 false）
      And Admin 持有有效 Token "validToken"
      When AI 客戶端以 "Authorization: Bearer validToken" 呼叫 MCP 工具
      Then Bearer 認證不應介入（不以 Token 認證使用者）

  # ========== 不變式（相容性）— 應用程式密碼仍可用 ==========

  Rule: 不變式 - 既有 Application Password（Basic Auth）方式仍然有效

    Example: 使用應用程式密碼的 Basic Auth 仍可呼叫 MCP 工具
      Given Admin 仍持有一組 WordPress 應用程式密碼
      When AI 客戶端以 "Authorization: Basic <base64(admin:app-password)>" 呼叫 MCP 工具
      Then 請求應被認證為 Admin
      And MCP 工具呼叫應成功
      And 此相容路徑不應因新增 Bearer Token 功能而被移除

  # ========== 後置（邏輯）— 全域權限開關仍套用 ==========

  Rule: 後置（邏輯）- Bearer Token 為全權限，寫入動作仍受全域開關限制

    Example: allow_update 關閉時，Bearer Token 仍不能改資料
      Given pc_mcp_settings.allow_update 為 false
      And Admin 以有效 Bearer Token 通過認證
      When AI 客戶端呼叫具修改性質的 MCP 工具 course.update
      Then 該修改工具應被全域權限拒絕
      And Token 本身的全權限不繞過全域「允許修改」開關
