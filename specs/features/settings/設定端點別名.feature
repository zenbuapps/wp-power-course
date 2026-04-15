@ignore @command @deprecated
Feature: 設定端點別名

  Power Course 有兩組看起來都在處理「設定」的端點：
  - `/settings`：正式端點，由 `Resources/Settings/Core/Api.php` 實作
  - `/options`：**已棄用**（deprecated），由 `Api/Option.php` 實作，內部直接 delegate 至 `/settings` 的 callback

  **Code source:** `inc/classes/Api/Option.php`（整個類別標記 `@deprecated 使用 Settings Api 取代`）

  Background:
    Given 系統中有以下用戶：
      | userId | name  | role          |
      | 1      | Admin | administrator |

  # ========== Deprecated 路徑 ==========

  Rule: /options GET 端點已棄用，但仍可用（100% 轉發到 /settings）

    Example: GET /options 取得設定
      When 管理員 "Admin" 呼叫 GET /options
      Then 操作成功
      And 回應與 GET /settings 完全相同（因 Api/Option::get_options_callback 呼叫 SettingsApi::instance()->get_settings_callback）

  Rule: /options POST 端點已棄用，但仍可用（100% 轉發到 /settings）

    Example: POST /options 更新設定
      When 管理員 "Admin" 呼叫 POST /options，body 與 POST /settings 相同
      Then 操作成功
      And 行為與 POST /settings 完全相同

  # ========== 推薦 ==========

  Rule: 新整合應使用 /settings；/options 保留僅為向下相容

    Example: 前端新功能
      When 前端工程師開發新設定頁面
      Then 應透過 Refine.dev Data Provider 存取 resource="settings"
      And 不應使用 /options

  # ========== 未來 ==========

  Rule: /options 端點屬於歷史技術債，未來可能移除

    Example: 移除前的相容性承諾
      Then 任何依賴 /options 的前端程式碼都必須同步遷移到 /settings
      And 本 feature 作為技術債清單的一部分追蹤

  # ========== duplicate/{id} ==========

  Rule: /duplicate/{id} 與 /courses/duplicate/{id} 為同一功能的兩個路徑

    Example: 兩個路徑的行為一致
      When 管理員呼叫 POST /duplicate/100
      Then 行為與 POST /courses/duplicate/100 完全相同（皆由 Duplicate::process(100, true, true) 實作）
      And 新建立的課程 ID 以 data 欄位回傳
      And 建議統一使用 /courses/duplicate/{id}，但 /duplicate/{id} 也保留可用
