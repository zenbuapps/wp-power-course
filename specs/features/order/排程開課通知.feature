@ignore @command
Feature: 排程開課通知

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          |
      | 2      | Alice | alice@test.com |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  | course_schedule |
      | 100      | PHP 基礎課 | yes        | publish | 1893456000      |
    And 用戶 "Alice" 已被加入課程 100，expire_date 0
    And 課程 100 的 course_launch_action_done 為 "no"

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 課程排程時間必須已到達

    Example: 排程時間未到時不觸發
      Given 當前時間為 2025-01-01
      And 課程 100 的 course_schedule 為 1893456000（2029-12-31）
      When Action Scheduler 執行 power_course_schedule_action
      Then 課程 100 的 course_launch_action_done 應仍為 "no"

  Rule: 前置（狀態）- course_launch_action_done 必須為 no（避免重複觸發）

    Example: 已觸發過的課程不重複觸發
      Given 課程 100 的 course_launch_action_done 為 "yes"
      When Action Scheduler 執行 power_course_schedule_action
      Then 不應觸發 "power_course_course_launch" action

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 觸發 power_course_course_launch action

    Example: 排程時間到達時觸發開課
      Given 當前時間已超過課程 100 的 course_schedule
      When Action Scheduler 執行 power_course_schedule_action
      Then action "power_course_course_launch" 應以參數 (100) 被觸發
      And 課程 100 的 course_launch_action_done 應為 "yes"

  Rule: 後置（狀態）- 觸發課程開課郵件通知

    Example: 開課時發送通知郵件給所有已開通學員
      Given 課程 100 有觸發類型為 "course_launch" 的郵件模板
      And 當前時間已超過課程 100 的 course_schedule
      When Action Scheduler 執行 power_course_schedule_action
      Then 應發送郵件給 "alice@test.com"
