@ignore @query
Feature: 全域匯出學員 CSV

  全域匯出跨越所有課程的「學員 × 課程」組合 CSV，
  與單課程匯出（students/export/{id}）為不同 API 端點。
  API: GET /power-course/v2/students/export-all

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email           | role          | billing_last_name | billing_first_name | last_name | first_name |
      | 1      | Admin | admin@test.com  | administrator |                   |                    |           |            |
      | 2      | Alice | alice@test.com  | subscriber    | 劉                | 小明               |           |            |
      | 3      | Bob   | bob@test.com    | subscriber    |                   |                    | Wang      | Bob        |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |
      | 101      | JS 進階課  | yes        | publish |
    And 用戶 "Alice" 已被加入課程 100，expire_date 0
    And 用戶 "Alice" 已被加入課程 101，expire_date 0
    And 用戶 "Bob" 已被加入課程 100，expire_date 1893456000

  # ========== 後置（回應）- 回傳所有學員 x 課程組合的 CSV ==========

  Rule: 後置（回應）- 回傳所有學員 x 課程組合的 CSV

    Example: 成功匯出全域學員名單
      When 管理員 "Admin" 匯出全域學員 CSV
      Then 操作成功
      And Content-Type 應為 "text/csv"
      And CSV 第一列應為欄位標頭
      And CSV 應包含 3 筆資料（Alice x PHP, Alice x JS, Bob x PHP）
      And CSV 欄位應包含以下欄位：
        | user_id | last_name | first_name | display_name | user_email | user_registered | course_name | course_id | progress | expire_date_label | is_expired | subscription_id |

  # ========== 前置（參數）- 支援 search 篩選 ==========

  Rule: 前置（參數）- 支援 search 關鍵字篩選

    Example: 以關鍵字搜尋後匯出
      When 管理員 "Admin" 匯出全域學員 CSV，search = "alice"
      Then 操作成功
      And CSV 應包含 2 筆資料（僅 Alice 的課程）

    Example: 以用戶 ID 搜尋後匯出
      When 管理員 "Admin" 匯出全域學員 CSV，search = "3"
      Then 操作成功
      And CSV 應包含 1 筆資料（僅 Bob x PHP）

    Example: 以姓名合併搜尋（billing_last_name + billing_first_name）
      When 管理員 "Admin" 匯出全域學員 CSV，search = "劉小明"
      Then 操作成功
      And CSV 應包含 2 筆資料（僅 Alice 的課程）

  # ========== 前置（參數）- 支援 avl_course_ids 篩選 ==========

  Rule: 前置（參數）- 支援 avl_course_ids 課程篩選

    Example: 篩選特定課程後匯出
      When 管理員 "Admin" 匯出全域學員 CSV，avl_course_ids = [100]
      Then 操作成功
      And CSV 應包含 2 筆資料（Alice x PHP, Bob x PHP）

    Example: 篩選多門課程後匯出
      When 管理員 "Admin" 匯出全域學員 CSV，avl_course_ids = [100, 101]
      Then 操作成功
      And CSV 應包含 3 筆資料

  # ========== 前置（參數）- 支援 include 指定用戶 ==========

  Rule: 前置（參數）- 支援 include 指定用戶 ID 篩選

    Example: 指定用戶 ID 後匯出
      When 管理員 "Admin" 匯出全域學員 CSV，include = [2]
      Then 操作成功
      And CSV 應包含 2 筆資料（僅 Alice 的課程）

  # ========== 後置（回應）- 無符合條件學員時回傳空 CSV ==========

  Rule: 後置（回應）- 無符合條件學員時回傳僅含標頭的 CSV

    Example: 篩選條件無匹配時匯出空檔案
      When 管理員 "Admin" 匯出全域學員 CSV，search = "nobody"
      Then 操作成功
      And Content-Type 應為 "text/csv"
      And CSV 僅包含標頭列，無資料列

  # ========== 異常處理 ==========

  Rule: 後置（回應）- 伺服器錯誤時回傳 500

    Example: 匯出過程中發生例外時回傳錯誤
      Given 資料庫連線異常
      When 管理員 "Admin" 匯出全域學員 CSV
      Then 回應狀態碼為 500
      And 回應的 code 應為 "export_all_error"
      And 回應的 message 應為 "匯出失敗"

  # ========== 權限檢查 ==========

  Rule: 前置（狀態）- 非管理員不可匯出全域學員 CSV

    Example: 一般用戶嘗試匯出時失敗
      When 用戶 "Alice" 匯出全域學員 CSV
      Then 操作失敗
      And 回應狀態碼為 403
