@ignore @command
Feature: 指派講師到課程

  Background:
    Given 系統中有以下用戶：
      | userId | name    | email              | role          |
      | 1      | Admin   | admin@test.com     | administrator |
      | 10     | Teacher | teacher@test.com   | editor        |
      | 11     | Teacher2| teacher2@test.com  | editor        |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 課程必須存在

    Example: 課程不存在時指派失敗
      When 管理員 "Admin" 指派講師 [10] 到課程 9999
      Then 操作失敗，錯誤為「課程不存在」

  Rule: 前置（狀態）- 用戶必須存在

    Example: 用戶不存在時指派失敗
      When 管理員 "Admin" 指派講師 [9999] 到課程 100
      Then 操作失敗，錯誤為「用戶不存在」

  # ========== 前置（參數）==========

  Rule: 前置（參數）- teacher_ids 不可為空

    Example: 未提供講師 ID 時操作失敗
      When 管理員 "Admin" 指派講師 [] 到課程 100
      Then 操作失敗，錯誤訊息包含 "teacher_ids"

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 以多筆 teacher_ids meta rows 儲存

    Example: 成功指派多位講師
      When 管理員 "Admin" 指派講師 [10, 11] 到課程 100
      Then 操作成功
      And 課程 100 應有 2 筆 teacher_ids meta rows
      And 課程 100 的 teacher_ids meta 應包含 userId 10
      And 課程 100 的 teacher_ids meta 應包含 userId 11

  Rule: 後置（狀態）- 重新指派時先刪除再新增（完全替換）

    Example: 重新指派後原講師被移除
      Given 課程 100 的 teacher_ids 為 [10]
      When 管理員 "Admin" 指派講師 [11] 到課程 100
      Then 操作成功
      And 課程 100 應有 1 筆 teacher_ids meta rows
      And 課程 100 的 teacher_ids meta 應不包含 userId 10
      And 課程 100 的 teacher_ids meta 應包含 userId 11
