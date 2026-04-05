@ignore @command
Feature: 刪除郵件模板

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
    And 系統中有以下郵件模板：
      | emailId | post_title   | trigger_at     |
      | 400     | 課程開通通知 | course_granted |

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 郵件模板必須存在

    Example: 不存在的模板刪除失敗
      When 管理員 "Admin" 刪除郵件模板 [9999]
      Then 操作失敗，錯誤為「郵件模板不存在」

  # ========== 前置（參數）==========

  Rule: 前置（參數）- 必要參數必須提供

    Example: 未提供 ids 時刪除失敗
      When 管理員 "Admin" 刪除郵件模板 []
      Then 操作失敗，錯誤訊息包含 "ids"

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 支援批次刪除郵件模板（DELETE /power-email/emails）

    Example: 成功刪除郵件模板
      When 管理員 "Admin" 刪除郵件模板 [400]
      Then 操作成功
      And 郵件模板 400 應不存在

  Rule: 後置（狀態）- 已發送的郵件記錄不受影響（保留 pc_email_records 供審計）

    Example: 刪除模板後已發送記錄仍存在
      Given 郵件模板 400 有以下發送記錄：
        | id | user_id | email_subject | mark_as_sent |
        | 1  | 2       | 課程開通通知  | true         |
      When 管理員 "Admin" 刪除郵件模板 [400]
      Then 操作成功
      And pc_email_records 中 email_id 400 的記錄仍存在
