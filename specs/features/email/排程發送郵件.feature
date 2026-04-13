@ignore @command
Feature: 排程發送郵件

  管理員可將一組郵件模板指定在未來某個時間（Unix timestamp）發送給一組用戶。
  透過 `as_schedule_single_action` 排入 Action Scheduler。

  Background:
    Given 系統中有以下用戶：
      | userId | name     | email              | role          |
      | 1      | Admin    | admin@test.com     | administrator |
      | 10     | Alice    | alice@test.com     | customer      |
    And 系統中有以下郵件模板：
      | emailId | post_title | trigger_at     | post_status |
      | 500     | 課程提醒   | course_granted | publish     |

  # ========== 前置（參數）==========

  Rule: 前置（參數）- email_ids / user_ids / timestamp 三者皆為必填

    Example: 缺少 timestamp 時失敗
      When 管理員 "Admin" 呼叫 POST /emails/send-schedule，參數如下：
        | email_ids | user_ids |
        | [500]     | [10]     |
      Then 操作失敗，錯誤為「timestamp 為必填」

  Rule: 前置（參數）- timestamp 必須為整數（Unix timestamp）

    Example: timestamp 為字串時失敗
      When 管理員 "Admin" 呼叫 POST /emails/send-schedule，參數如下：
        | email_ids | user_ids | timestamp   |
        | [500]     | [10]     | "not-a-num" |
      Then 操作失敗，錯誤為「timestamp 必須為整數」

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- Action Scheduler 新增 1 筆 pending action

    Example: 成功排程未來 1 小時後發送
      Given 目前時間為 "2026-04-13 10:00:00"
      When 管理員 "Admin" 呼叫 POST /emails/send-schedule，參數如下：
        | email_ids | user_ids | timestamp  |
        | [500]     | [10]     | 1776679200 |
      Then 操作成功
      And 回應 code 為 "schedule_success"
      And 回應 data.action_id 為正整數
      And Action Scheduler 的 "power_email_send_users" hook 新增 1 筆 pending action
      And 該 action 的 schedule 為 "2026-04-13 11:00:00"
      And 該 action 的 group 為 "power_email"

  Rule: 後置（狀態）- 排程過去時間會立即執行（past-due）

    Example: 排程 timestamp 在過去時仍可建立
      Given 目前時間為 "2026-04-13 10:00:00"
      When 管理員 "Admin" 呼叫 POST /emails/send-schedule，參數如下：
        | email_ids | user_ids | timestamp  |
        | [500]     | [10]     | 1000000000 |
      Then 操作成功
      And Action Scheduler 建立的 action 因 timestamp 已過，下次執行時會被歸類為 past-due 並立即執行
