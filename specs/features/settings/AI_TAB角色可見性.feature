@ignore @ui @role-gate
Feature: Settings 頁面 AI Tab 的角色可見性

  作為一個 WordPress 後台使用者，當我打開 Power Course 設定頁時，
  我只想看到我有權限存取的設定 Tab。
  AI Tab 因為對應的後端 REST API 已強制 administrator 權限（manage_options），
  非 admin 使用者不應該在 UI 上看到這個 Tab；
  否則點下去會 403 fail，造成「看得到卻用不到」的 UX 矛盾。

  Background:
    Given 系統中有以下用戶：
      | userId | name      | email             | role          |
      | 1      | Admin     | admin@test.com    | administrator |
      | 2      | Manager   | manager@test.com  | shop_manager  |
      | 3      | Editor    | editor@test.com   | editor        |
      | 4      | Subscriber| sub@test.com      | subscriber    |
    And 後台 Settings 頁面已註冊 4 個 tab：
      | tabKey     | tabLabel             |
      | general    | General settings     |
      | appearance | Appearance settings  |
      | auto-grant | Auto-grant           |
      | ai         | AI                   |

  # ========== 後置（視圖）— Tab 可見性 ==========

  Rule: 後置（視圖）- administrator 可看到 AI Tab

    Example: administrator 進入 Settings 頁時看到 4 個 tab
      Given 用戶 "Admin" 已登入
      When 用戶 "Admin" 開啟「wp-admin/admin.php?page=power-course#/settings」
      Then Settings 頁面應顯示 4 個 tab
      And tab 順序應為：
        | order | tabKey     |
        | 1     | general    |
        | 2     | appearance |
        | 3     | auto-grant |
        | 4     | ai         |
      And 用戶可點擊 "AI" tab 並看到 AI 設定內容

  Rule: 後置（視圖）- 非 administrator 看不到 AI Tab

    Example: shop_manager 進入 Settings 頁時 AI Tab 不存在
      Given 用戶 "Manager" 已登入
      When 用戶 "Manager" 開啟「wp-admin/admin.php?page=power-course#/settings」
      Then Settings 頁面應顯示 3 個 tab
      And tab 順序應為：
        | order | tabKey     |
        | 1     | general    |
        | 2     | appearance |
        | 3     | auto-grant |
      And 頁面上不應存在文字 "AI" 的 tab
      And DOM 中不應掛載 AiTabLoader 元件

    Example: editor 進入 Settings 頁時 AI Tab 不存在
      Given 用戶 "Editor" 已登入
      When 用戶 "Editor" 開啟「wp-admin/admin.php?page=power-course#/settings」
      Then Settings 頁面應顯示 3 個 tab
      And 頁面上不應存在文字 "AI" 的 tab

    Example: subscriber 進入 Settings 頁時 AI Tab 不存在
      Given 用戶 "Subscriber" 已登入
      When 用戶 "Subscriber" 開啟「wp-admin/admin.php?page=power-course#/settings」
      Then Settings 頁面應顯示 3 個 tab
      And 頁面上不應存在文字 "AI" 的 tab

  # ========== 後置（資料）— 前端權限判定來源 ==========

  Rule: 後置（資料）- 前端透過加密 env 的 IS_ADMIN 判定可見性

    Example: administrator 的 env.IS_ADMIN 為 true
      Given 用戶 "Admin" 已登入
      When 系統 enqueue 前端 bundle
      Then `window.power_course_data.env` 解密後 `IS_ADMIN` 欄位應為 true
      And 前端常數 `env.IS_ADMIN` 應為 true

    Example: shop_manager 的 env.IS_ADMIN 為 false
      Given 用戶 "Manager" 已登入
      When 系統 enqueue 前端 bundle
      Then `window.power_course_data.env` 解密後 `IS_ADMIN` 欄位應為 false
      And 前端常數 `env.IS_ADMIN` 應為 false

    Example: env.IS_ADMIN 缺失時 fallback 為 false（fail-safe）
      Given 前端 bundle 為舊版 cache，PHP 端尚未注入 IS_ADMIN
      When 前端讀取 `env?.IS_ADMIN`
      Then `Boolean(env?.IS_ADMIN)` 應為 false
      And AI Tab 不應出現（fail-safe：寧可隱藏也不誤開）

  # ========== 後置（邏輯）— admin 判定基準 ==========

  Rule: 後置（邏輯）- 使用 current_user_can('manage_options') 判定 admin

    Example: 後端 IS_ADMIN 由 current_user_can('manage_options') 決定
      Given 用戶 "Admin" 具備 capability "manage_options"
      When 系統 enqueue 前端 bundle
      Then PHP 端 `current_user_can('manage_options')` 應為 true
      And 加密 env 中的 `IS_ADMIN` 應為 true

    Example: Multisite super admin 也判定為 admin
      Given 環境為 multisite
      And 用戶 "SuperAdmin" 為 super admin（具備 manage_options）
      When 系統 enqueue 前端 bundle
      Then 加密 env 中的 `IS_ADMIN` 應為 true

    Example: 缺乏 manage_options 的角色一律判定為非 admin
      Given 用戶具備角色 "shop_manager"（無 manage_options）
      When 系統 enqueue 前端 bundle
      Then 加密 env 中的 `IS_ADMIN` 應為 false

  # ========== 後置（行為）— Reusable RoleGate 元件 ==========

  Rule: 後置（行為）- RoleGate 元件支援未來其他敏感 tab 套用

    Example: RoleGate 對 admin 顯示 children
      Given React 元件 `<RoleGate capability="admin">SecretContent</RoleGate>`
      And `env.IS_ADMIN` 為 true
      When 元件 render
      Then 畫面應顯示 "SecretContent"

    Example: RoleGate 對非 admin 顯示 fallback（預設 null）
      Given React 元件 `<RoleGate capability="admin">SecretContent</RoleGate>`
      And `env.IS_ADMIN` 為 false
      When 元件 render
      Then 畫面不應顯示 "SecretContent"
      And 不應 render 任何替代內容（fallback 預設 null）

    Example: RoleGate 對非 admin 顯示自訂 fallback
      Given React 元件 `<RoleGate capability="admin" fallback={<Tip />}>SecretContent</RoleGate>`
      And `env.IS_ADMIN` 為 false
      When 元件 render
      Then 畫面應顯示 `<Tip />` 元件
      And 不應顯示 "SecretContent"

  # ========== 不變式（安全邊界）— 後端權限不受 UI 影響 ==========

  Rule: 不變式 - UI 隱藏不取代後端權限檢查

    Example: 即使前端被改造、AI Tab 強制顯示，後端 REST 仍會 403
      Given 用戶 "Manager"（非 admin）登入
      And 攻擊者透過 devtools 強制 render AI Tab
      When 攻擊者觸發 GET /wp-json/power-course/mcp/settings
      Then 後端應回傳 HTTP 403
      And 後端 permission_callback 應拒絕請求（`current_user_can('manage_options')` 失敗）
      And 任何寫入動作（PUT/PATCH）都不應成功
