@ignore @command @mcp-token
Feature: MCP Token 後台產生 — 設定 → AI 頁面內直接產生 Bearer Token

  作為站長，我想在 Power Course「設定 → AI」頁面內直接產生 MCP Bearer Token，
  而不需要跳到「WordPress 後台 → 使用者 → 個人資料」產生應用程式密碼、再手動做 Base64 編碼。
  產生後系統應一次顯示明文 Token 與可一鍵複製的 AI 客戶端設定範本，
  讓非技術站長也能照抄完成 Claude Code / Cursor 的連線設定。

  Background:
    Given 系統中有以下用戶：
      | userId | name   | email           | role          |
      | 1      | Admin  | admin@test.com  | administrator |
      | 2      | Admin2 | admin2@test.com | administrator |
    And MCP Server 已啟用（pc_mcp_settings.enabled 為 true）
    And 用戶 "Admin" 已登入並開啟「設定 → AI」頁面
    And AI Tab 顯示「MCP Token 管理」區塊
    And 後端 Token REST 端點為：
      | method | endpoint               | 說明                       |
      | GET    | mcp/tokens             | 列出目前管理員自己的 Token |
      | POST   | mcp/tokens             | 建立 Token，回傳一次性明文 |
      | DELETE | mcp/tokens/(?P<id>\d+) | 撤銷指定 Token             |

  # ========== 前置（參數）— 建立 Token 的輸入驗證 ==========

  Rule: 前置（參數）- Token 名稱為必填

    Example: 未輸入名稱時不可建立
      Given 管理員點擊「新增 Token」開啟建立對話框
      When 管理員未輸入名稱直接送出
      Then 系統不應送出 POST mcp/tokens 請求
      And 對話框應顯示「Token 名稱為必填」的欄位錯誤

    Example: 後端對空名稱回傳 400
      When 管理員觸發 POST mcp/tokens 且 name 為空字串
      Then 後端應回傳 HTTP 400
      And 錯誤碼應為 "invalid_name"

  Rule: 前置（參數）- 有效期限為下拉選單，預設「永不過期」

    Example: 建立對話框預設選中「永不過期」
      When 管理員點擊「新增 Token」開啟建立對話框
      Then 有效期限下拉選單應提供以下選項：
        | value        | label    |
        | 30           | 30 天    |
        | 90           | 90 天    |
        | 365          | 1 年     |
        | never        | 永不過期 |
      And 有效期限預設值應為「永不過期」

    Example: 永不過期送出時 expires_days 不帶值
      Given 管理員輸入名稱 "Claude Code — 我的筆電"
      And 有效期限維持預設「永不過期」
      When 管理員送出建立
      Then POST mcp/tokens 的 body 不應包含 expires_days（或為空）

    Example: 選擇 90 天送出時 expires_days 為 90
      Given 管理員輸入名稱 "辦公室 — Cursor"
      And 有效期限選擇「90 天」
      When 管理員送出建立
      Then POST mcp/tokens 的 body 的 expires_days 應為 90

  # ========== 後置（狀態）— 建立後資料寫入 ==========

  Rule: 後置（狀態）- 永不過期的 Token expires_at 為 NULL

    Example: 永不過期 Token 寫入後 expires_at 為空
      Given 管理員輸入名稱 "我的 AI"
      And 有效期限為「永不過期」
      When 管理員送出建立
      Then 操作成功
      And wp_pc_mcp_tokens 新增一筆 name 為 "我的 AI" 的紀錄
      And 該筆紀錄的 expires_at 應為 NULL
      And 該筆紀錄的 user_id 應為 1（建立者 Admin）
      And 該筆紀錄的 capabilities 應為 NULL（全權限，沿用全域開關）

    Example: 設定 30 天的 Token expires_at 為建立時間 +30 天
      Given 現在時間為 "2026-05-28 00:00:00" UTC
      And 管理員輸入名稱 "短期測試"
      And 有效期限為「30 天」
      When 管理員送出建立
      Then wp_pc_mcp_tokens 該筆紀錄的 expires_at 應約為 "2026-06-27 00:00:00" UTC

    Example: Token 以 SHA-256 hash 儲存，不落地明文
      Given 管理員建立名稱 "安全檢查" 的 Token
      When 查詢 wp_pc_mcp_tokens 該筆紀錄
      Then token_hash 欄位應為 64 字元的十六進位字串
      And 資料表中不應存在任何欄位儲存 Token 明文

  # ========== 後置（視圖）— 一次性明文與強警示 ==========

  Rule: 後置（視圖）- 建立成功後明文僅顯示一次並一鍵複製

    Example: 建立成功顯示明文 Token 與複製按鈕
      Given 管理員輸入名稱 "我的 AI" 並送出建立
      When 後端回傳 data.token 明文
      Then 畫面應顯示該明文 Token
      And 畫面應提供「複製」按鈕可一鍵複製明文
      And 畫面應顯示黃色警告框含粗體文字提醒「此密碼僅顯示一次，請立即複製」

    Example: 關閉明文對話框後無法再取回明文
      Given 管理員已建立 Token 並看到明文對話框
      When 管理員關閉明文對話框
      And 管理員重新整理頁面
      Then 列表中該 Token 不應再顯示明文
      And 無任何 UI 或 API 可再次取回該 Token 的明文

    Example: 後端建立回應只回一次明文
      When 管理員觸發 POST mcp/tokens 且 name 為 "一次性"
      Then 後端回應 data.token 應為明文
      And 後端回應應含 data.warning 提醒僅顯示一次
      And 後續 GET mcp/tokens 的列表項目不應包含 token 明文欄位

  # ========== 後置（視圖）— 快速設定範本 ==========

  Rule: 後置（視圖）- 明文對話框同時顯示可複製的 AI 客戶端設定範本

    Example: 顯示 Claude Code 與 Cursor 的設定範本
      Given 站台網址為 "https://yoursite.com"
      And 管理員剛建立明文為 "abcd1234" 的 Token
      When 明文對話框渲染快速設定範本
      Then 應顯示 "Claude Code" 的設定範本，各附「複製」按鈕
      And 應顯示 "Cursor" 的設定範本，各附「複製」按鈕

    Example: 範本自動帶入網站 URL 與 Bearer Token（CLI 命令）
      Given 站台網址為 "https://yoursite.com"
      And 管理員剛建立明文為 "abcd1234" 的 Token
      When 管理員複製 Claude Code 的 CLI 命令範本
      Then 複製內容應為可直接執行的命令：
        """
        claude mcp add --transport http power-course \
          https://yoursite.com/wp-json/power-course/v2/mcp \
          --header "Authorization: Bearer abcd1234"
        """

    Example: 範本使用 Bearer 認證而非 Basic
      Given 管理員剛建立明文為 "abcd1234" 的 Token
      When 明文對話框渲染設定範本
      Then 範本中的 Authorization 標頭應為 "Bearer abcd1234"
      And 範本不應出現 "Basic" 字樣
      And 範本不應要求使用者自行做 Base64 編碼

  # ========== 不變式（安全邊界）— 權限守門 ==========

  Rule: 不變式 - 建立 Token 限 administrator

    Example: 非 administrator 觸發 POST mcp/tokens 回 403
      Given 用戶具備角色 "shop_manager"（無 manage_options）
      When 該用戶觸發 POST mcp/tokens
      Then 後端應回傳 HTTP 403
      And 不應有任何 Token 被建立

    Example: 建立的 Token 歸屬於目前登入管理員
      Given 用戶 "Admin2" 已登入
      When 用戶 "Admin2" 建立名稱 "Admin2 的 Token"
      Then wp_pc_mcp_tokens 該筆紀錄的 user_id 應為 2
