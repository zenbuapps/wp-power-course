@ignore @command
Feature: 更新郵件模板

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
    And 系統中有以下郵件模板：
      | emailId | post_title   | trigger_at     | course_id |
      | 400     | 課程開通通知 | course_granted | 100       |

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 郵件模板必須存在

    Example: 不存在的模板更新失敗
      When 管理員 "Admin" 更新郵件模板 9999，參數如下：
        | post_title |
        | 新主旨     |
      Then 操作失敗，錯誤為「郵件模板不存在」

  # ========== 前置（參數）==========

  Rule: 前置（參數）- id 不可為空且必須為正整數

    Example: 未提供模板 ID 時更新失敗
      When 管理員 "Admin" 更新郵件模板 ""，參數如下：
        | post_title |
        | 新主旨     |
      Then 操作失敗，錯誤訊息包含 "id"

  Rule: 前置（參數）- trigger_at 若提供必須為合法觸發類型

    Example: 更新為非法觸發類型時操作失敗
      When 管理員 "Admin" 更新郵件模板 400，參數如下：
        | trigger_at      |
        | invalid_trigger |
      Then 操作失敗，錯誤為「trigger_at 必須為合法的觸發類型」

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 更新郵件模板的 post 和 meta 資料

    Example: 成功更新郵件模板
      When 管理員 "Admin" 更新郵件模板 400，參數如下：
        | post_title       | post_content      | trigger_at    |
        | 課程完課恭喜通知 | 新的 MJML 模板內容 | course_finish |
      Then 操作成功
      And 郵件模板 400 的 post_title 應為 "課程完課恭喜通知"
      And 郵件模板 400 的 trigger_at meta 應為 "course_finish"
