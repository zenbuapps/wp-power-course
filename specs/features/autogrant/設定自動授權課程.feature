@ignore @command
Feature: 設定自動授權課程

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |
      | 101      | 免費入門課 | yes        | publish |

  # ========== 前置（參數）==========

  Rule: 前置（參數）- course_ids 必須為有效課程

    Example: 設定不存在的課程為自動授權失敗
      When 管理員 "Admin" 設定自動授權課程 ids [9999]
      Then 操作失敗，錯誤為「課程不存在」

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 儲存 auto_grant_course_ids 到 power_course_settings

    Example: 成功設定自動授權課程列表
      When 管理員 "Admin" 設定自動授權課程 ids [100, 101]
      Then 操作成功
      And 設定 auto_grant_course_ids 應為 [100, 101]

    Example: 清空自動授權課程列表
      When 管理員 "Admin" 設定自動授權課程 ids []
      Then 操作成功
      And 設定 auto_grant_course_ids 應為 []

  Rule: 後置（狀態）- 新用戶註冊時自動獲得授權課程（觸發 user_register hook）

    Example: 新用戶註冊後自動取得設定中的課程存取權
      Given 設定 auto_grant_course_ids 為 [101]
      When 新用戶 "Charlie" 以 email "charlie@test.com" 註冊
      Then 用戶 "Charlie" 的 avl_course_ids 應包含課程 101
      And 課程 101 對用戶 "Charlie" 的 coursemeta expire_date 應為 "0"
      And 課程 101 對用戶 "Charlie" 的 coursemeta course_granted_at 應不為空

    Example: 自動授權課程列表為空時不授權
      Given 設定 auto_grant_course_ids 為 []
      When 新用戶 "Dave" 以 email "dave@test.com" 註冊
      Then 用戶 "Dave" 的 avl_course_ids 應為空

  Rule: 後置（狀態）- 自動授權應觸發標準的 AddStudentToCourse 流程

    Example: 自動授權後觸發 action hook 和郵件通知
      Given 設定 auto_grant_course_ids 為 [101]
      When 新用戶 "Charlie" 以 email "charlie@test.com" 註冊
      Then action "power_course_after_add_student_to_course" 應被觸發
