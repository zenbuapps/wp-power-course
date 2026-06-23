@ignore @command
Feature: 更新課程權限包

  # 站長編輯既有權限包：可改名、改範圍、改期限模式。
  # 範圍變更（縮小／改範圍）即時生效（compute-on-read），影響已購用戶——
  # 此處規格僅規範資料更新；「影響 N 位已購用戶」的警告由 UI 層處理（見 specs/ui/課程權限包管理頁.md）。

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
    And 系統中有以下課程：
      | courseId | name        | _is_course | status  |
      | 100      | HTML 入門課 | yes        | publish |
      | 101      | HTML 進階課 | yes        | publish |
    And 系統中有以下課程分類：
      | termId | name | taxonomy    | parent |
      | 10     | HTML | product_cat | 0      |
      | 20     | PHP  | product_cat | 0      |
    And 系統中有以下課程權限包：
      | passId | name              | scope_type | limit_mode | status | term_ids | course_ids |
      | 300    | HTML 入門課程權限 | category   | permanent  | active | [10]     |            |

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 被更新的權限包必須存在

    Example: 更新不存在的權限包時操作失敗
      When 管理員 "Admin" 更新課程權限包 9999，參數如下：
        | name     |
        | 改名測試 |
      Then 操作失敗

  # ========== 前置（參數）==========

  Rule: 前置（參數）- name 若提供則不可為空字串

    Example: 將名稱更新為空字串時操作失敗
      When 管理員 "Admin" 更新課程權限包 300，參數如下：
        | name |
        |      |
      Then 操作失敗，錯誤訊息包含 "name"

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 成功更新權限包名稱

    Example: 成功更新權限包名稱
      When 管理員 "Admin" 更新課程權限包 300，參數如下：
        | name              |
        | HTML 全系列課程權限 |
      Then 操作成功
      And 權限包 300 的 name 應為 "HTML 全系列課程權限"

  Rule: 後置（狀態）- 成功變更範圍類型與範圍內容

    Example: 將分類範圍改為特定課程範圍
      When 管理員 "Admin" 更新課程權限包 300，參數如下：
        | scope_type |
        | specific   |
      And 權限包範圍包含以下課程：
        | course_id |
        | 100       |
      Then 操作成功
      And 權限包 300 的 scope_type 應為 "specific"
      And 權限包 300 的 course_ids 應為 [100]

    Example: 縮小分類範圍（移除一個分類）
      Given 系統中有以下課程權限包：
        | passId | name     | scope_type | limit_mode | status | term_ids |
        | 301    | 雙分類包 | category   | permanent  | active | [10, 20] |
      When 管理員 "Admin" 更新課程權限包 301，範圍分類標籤如下：
        | term_id | taxonomy    |
        | 10      | product_cat |
      Then 操作成功
      And 權限包 301 的 term_ids 應為 [10]

  Rule: 後置（狀態）- 成功變更期限模式為限時 N 天

    Example: 將永久權限包改為限時 90 天
      When 管理員 "Admin" 更新課程權限包 300，參數如下：
        | limit_mode | limit_value | limit_unit |
        | limited    | 90          | day        |
      Then 操作成功
      And 權限包 300 的 limit_mode 應為 "limited"
      And 權限包 300 的 limit_value 應為 90
