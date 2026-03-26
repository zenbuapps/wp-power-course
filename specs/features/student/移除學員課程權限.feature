@ignore @command
Feature: 移除學員課程權限

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email           |
      | 1      | Admin | admin@test.com  |
      | 2      | Alice | alice@test.com  |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |
    And 用戶 "Alice" 已被加入課程 100，expire_date 0

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 學員必須已有該課程的存取權

    Example: 學員無此課程權限時移除失敗
      Given 用戶 "Bob" 未被加入課程 100
      When 管理員 "Admin" 從課程 100 移除 userId 3
      Then 操作失敗

  # ========== 前置（參數）==========

  Rule: 前置（參數）- user_ids 和 course_ids 不可為空

    Example: 未提供 user_ids 時操作失敗
      When 管理員 "Admin" 從課程 100 移除 user_ids []
      Then 操作失敗，錯誤訊息包含 "user_ids"

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 刪除 avl_course_ids user meta 中對應的 course_id

    Example: 成功移除學員課程權限
      When 管理員 "Admin" 從課程 100 移除 userId 2
      Then 操作成功
      And 用戶 "Alice" 的 avl_course_ids 應不包含課程 100

  Rule: 後置（狀態）- 學員的章節進度記錄不自動清除

    Example: 移除權限後章節進度仍保留
      Given 用戶 "Alice" 在課程 100 的章節 200 有 finished_at
      When 管理員 "Admin" 從課程 100 移除 userId 2
      Then 操作成功
      And 章節 200 對用戶 "Alice" 的 chaptermeta finished_at 仍存在

  Rule: 後置（狀態）- 應觸發 power_course_after_remove_student_from_course action

    Example: 移除學員後觸發 action hook
      When 管理員 "Admin" 從課程 100 移除 userId 2
      Then 操作成功
      And action "power_course_after_remove_student_from_course" 應以參數 (2, 100) 被觸發
