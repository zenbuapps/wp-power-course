@ignore @command
Feature: 立即發送郵件

  管理員可在郵件模板列表中選取一個或多個模板，並指定一組用戶，
  立即把所有 (email × user) 組合丟進 Action Scheduler 非同步佇列發送。
  此端點繞過自動觸發的條件檢查（trigger_condition），適用於管理員對特定用戶補寄或通知。

  Background:
    Given 系統中有以下用戶：
      | userId | name     | email              | role          |
      | 1      | Admin    | admin@test.com     | administrator |
      | 10     | Alice    | alice@test.com     | customer      |
      | 11     | Bob      | bob@test.com       | customer      |
    And 系統中有以下郵件模板：
      | emailId | post_title | trigger_at     | post_status |
      | 500     | 歡迎信     | course_granted | publish     |
      | 501     | 補寄通知   | course_granted | publish     |

  # ========== 前置（參數）==========

  Rule: 前置（參數）- email_ids 為必填

    Example: 未提供 email_ids 時失敗
      When 管理員 "Admin" 呼叫 POST /emails/send-now，參數如下：
        | user_ids |
        | [10,11]  |
      Then 操作失敗，錯誤為「email_ids 為必填」

  Rule: 前置（參數）- user_ids 為必填

    Example: 未提供 user_ids 時失敗
      When 管理員 "Admin" 呼叫 POST /emails/send-now，參數如下：
        | email_ids |
        | [500]     |
      Then 操作失敗，錯誤為「user_ids 為必填」

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 發送動作透過 Action Scheduler 非同步執行

    Example: 成功將單一模板發給單一用戶
      When 管理員 "Admin" 呼叫 POST /emails/send-now，參數如下：
        | email_ids | user_ids |
        | [500]     | [10]     |
      Then 操作成功
      And 回應 code 為 "send_success"
      And 回應 data.action_id 為正整數
      And Action Scheduler 的 "power_email_send_users" hook 有新增 1 筆 pending action
      And 該 action 的 group 為 "power_email"
      And 該 action 的 args 為 { email_ids: [500], user_ids: [10] }

  Rule: 後置（狀態）- 支援多模板對多用戶（笛卡兒積）

    Example: 成功將 2 模板發送給 2 用戶
      When 管理員 "Admin" 呼叫 POST /emails/send-now，參數如下：
        | email_ids  | user_ids |
        | [500, 501] | [10, 11] |
      Then 操作成功
      And Action Scheduler 新增 1 筆 pending action
      And 該 action 的 args 包含 { email_ids: [500,501], user_ids: [10,11] }
      And 執行時會處理 4 組 (email × user) 組合

  Rule: 後置（狀態）- 立即發送不經過 trigger_condition 檢查

    Example: 用戶未獲得課程權限仍可收到手動發送的信
      Given 用戶 "Alice" 未被加入任何課程
      When 管理員 "Admin" 呼叫 POST /emails/send-now，參數如下：
        | email_ids | user_ids |
        | [500]     | [10]     |
      Then 操作成功
      And 該 action 執行時 Alice 會收到模板 500 的信
      And 不受 trigger_condition filter 阻擋

  Rule: 後置（狀態）- 批次處理使用每批 20 筆、間隔 750ms

    Example: 發送給 50 位用戶採批次處理
      Given 系統中另有 48 位 customer 用戶 (userId 12~59)
      When 管理員 "Admin" 呼叫 POST /emails/send-now，參數如下：
        | email_ids | user_ids        |
        | [500]     | [10,11,...,59]  |
      Then 操作成功
      And send_users_callback 執行時，user_ids 會被分成 3 批（20+20+10）
      And 每批之間暫停 750 毫秒（pause_ms）
