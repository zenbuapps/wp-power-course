@ignore @command
Feature: 批量匯入學員

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- course_id 對應的課程必須存在

    Example: 目標課程不存在時匯入失敗
      When 管理員 "Admin" 上傳學員 CSV 到課程 9999，內容如下：
        | email          | expire_date |
        | new@test.com   | 0           |
      Then 操作失敗

  # ========== 前置（參數）==========

  Rule: 前置（參數）- CSV 必須包含 email 欄位

    Example: CSV 缺少 email 欄位時匯入失敗
      When 管理員 "Admin" 上傳學員 CSV 到課程 100，內容如下：
        | name  | expire_date |
        | Alice | 0           |
      Then 操作失敗，錯誤訊息包含 "email"

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 新 email 自動建立 WordPress 用戶並加入課程

    Example: 成功匯入新用戶
      When 管理員 "Admin" 上傳學員 CSV 到課程 100，內容如下：
        | email            | expire_date |
        | new1@test.com    | 0           |
        | new2@test.com    | 1893456000  |
      Then 操作成功
      And 應建立 2 個新 WordPress 用戶
      And 新用戶 "new1@test.com" 的 avl_course_ids 應包含課程 100
      And 新用戶 "new2@test.com" 的 avl_course_ids 應包含課程 100

  Rule: 後置（狀態）- 以 Action Scheduler 背景執行（每批 50 筆）

    Example: 大量匯入時使用背景任務
      When 管理員 "Admin" 上傳學員 CSV 到課程 100，包含 120 筆記錄
      Then 操作成功
      And 應排程 3 個 "pc_batch_add_students_task" 背景任務

  Rule: 後置（狀態）- 已存在的 email 對應用戶直接加入課程不重複建立

    Example: 已存在的用戶直接加入課程
      Given 系統中有用戶 email "alice@test.com"，userId 2
      When 管理員 "Admin" 上傳學員 CSV 到課程 100，內容如下：
        | email          | expire_date |
        | alice@test.com | 0           |
      Then 操作成功
      And 不應建立新用戶
      And 用戶 "Alice" 的 avl_course_ids 應包含課程 100
