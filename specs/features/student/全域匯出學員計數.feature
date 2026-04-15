@ignore @query
Feature: 全域匯出學員計數

  取得全域匯出的預估筆數，供前端 Modal 確認流程使用。
  API: GET /power-course/v2/students/export-count
  回應格式: { count: int }

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email           | role          | billing_last_name | billing_first_name |
      | 1      | Admin | admin@test.com  | administrator |                   |                    |
      | 2      | Alice | alice@test.com  | subscriber    | 劉                | 小明               |
      | 3      | Bob   | bob@test.com    | subscriber    |                   |                    |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |
      | 101      | JS 進階課  | yes        | publish |
    And 用戶 "Alice" 已被加入課程 100，expire_date 0
    And 用戶 "Alice" 已被加入課程 101，expire_date 0
    And 用戶 "Bob" 已被加入課程 100，expire_date 1893456000

  # ========== 後置（回應）- 回傳預估匯出筆數 ==========

  Rule: 後置（回應）- 回傳所有學員 x 課程組合的計數

    Example: 取得全域匯出預估筆數
      When 管理員 "Admin" 查詢全域匯出預估筆數
      Then 操作成功
      And 回應的 count 應為 3

  # ========== 前置（參數）- 支援 search 篩選 ==========

  Rule: 前置（參數）- search 篩選後的計數

    Example: 以關鍵字篩選後的計數
      When 管理員 "Admin" 查詢全域匯出預估筆數，search = "alice"
      Then 操作成功
      And 回應的 count 應為 2

  # ========== 前置（參數）- 支援 avl_course_ids 篩選 ==========

  Rule: 前置（參數）- avl_course_ids 篩選後的計數

    Example: 篩選特定課程後的計數
      When 管理員 "Admin" 查詢全域匯出預估筆數，avl_course_ids = [100]
      Then 操作成功
      And 回應的 count 應為 2

  # ========== 前置（參數）- 支援 include 指定用戶 ==========

  Rule: 前置（參數）- include 指定用戶後的計數

    Example: 指定用戶 ID 後的計數
      When 管理員 "Admin" 查詢全域匯出預估筆數，include = [2]
      Then 操作成功
      And 回應的 count 應為 2

  # ========== 後置（回應）- 無匹配時計數為 0 ==========

  Rule: 後置（回應）- 無匹配學員時計數為零

    Example: 無匹配學員時計數為零
      When 管理員 "Admin" 查詢全域匯出預估筆數，search = "nobody"
      Then 操作成功
      And 回應的 count 應為 0

  # ========== 異常處理 ==========

  Rule: 後置（回應）- 伺服器錯誤時回傳 500

    Example: 計數過程中發生例外時回傳錯誤
      Given 資料庫連線異常
      When 管理員 "Admin" 查詢全域匯出預估筆數
      Then 回應狀態碼為 500
      And 回應的 code 應為 "export_count_error"
      And 回應的 message 應為 "取得匯出筆數失敗"

  # ========== 權限檢查 ==========

  Rule: 前置（狀態）- 非管理員不可查詢匯出計數

    Example: 一般用戶嘗試查詢時失敗
      When 用戶 "Alice" 查詢全域匯出預估筆數
      Then 操作失敗
      And 回應狀態碼為 403
