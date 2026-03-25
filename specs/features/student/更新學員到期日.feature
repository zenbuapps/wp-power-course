@ignore @command
Feature: 更新學員到期日

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email           |
      | 1      | Admin | admin@test.com  |
      | 2      | Alice | alice@test.com  |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |
    And 用戶 "Alice" 已被加入課程 100，expire_date 1893456000

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 學員必須已有該課程的存取權

    Example: 學員無此課程權限時更新失敗
      When 管理員 "Admin" 更新 userId 3 在課程 100 的到期日為 0
      Then 操作失敗，錯誤為「學員無此課程存取權」

  # ========== 前置（參數）==========

  Rule: 前置（參數）- user_ids 和 course_ids 不可為空

    Example: 未提供必要參數時操作失敗
      When 管理員 "Admin" 更新 user_ids [] 在課程 100 的到期日為 0
      Then 操作失敗，錯誤訊息包含 "user_ids"

  Rule: 前置（參數）- timestamp 必須為整數（0 或 10 位 unix timestamp）

    Example: timestamp 格式不合法時操作失敗
      When 管理員 "Admin" 更新 userId 2 在課程 100 的到期日為 "abc"
      Then 操作失敗，錯誤為「expire_date 必須為整數」

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 更新 coursemeta 中的 expire_date

    Example: 成功延長到期日
      When 管理員 "Admin" 更新 userId 2 在課程 100 的到期日為 1924992000
      Then 操作成功
      And 課程 100 對用戶 "Alice" 的 coursemeta expire_date 應為 "1924992000"

    Example: 成功改為永久存取
      When 管理員 "Admin" 更新 userId 2 在課程 100 的到期日為 0
      Then 操作成功
      And 課程 100 對用戶 "Alice" 的 coursemeta expire_date 應為 "0"

  Rule: 後置（狀態）- 應觸發 power_course_after_update_student_from_course action

    Example: 更新到期日後觸發 action hook
      When 管理員 "Admin" 更新 userId 2 在課程 100 的到期日為 0
      Then 操作成功
      And action "power_course_after_update_student_from_course" 應以參數 (2, 100, 0) 被觸發
