@ignore @command
Feature: 更新設定

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
    And 系統設定為預設值：
      | course_access_trigger | hide_myaccount_courses | pc_watermark_qty | pc_watermark_text                      |
      | completed             | no                     | 0                | {display_name} {post_title} IP:{ip}    |

  # ========== 前置（參數）==========

  Rule: 前置（參數）- course_access_trigger 必須是有效的 WooCommerce 訂單狀態

    Example: 無效的訂單狀態時操作失敗
      When 管理員 "Admin" 更新設定，參數如下：
        | course_access_trigger |
        | invalid_status        |
      Then 操作失敗，錯誤為「course_access_trigger 必須為有效的 WooCommerce 訂單狀態」

  Rule: 前置（參數）- pc_watermark_qty 和 pc_pdf_watermark_qty 必須為非負整數

    Example: 負數浮水印數量時操作失敗
      When 管理員 "Admin" 更新設定，參數如下：
        | pc_watermark_qty |
        | -1               |
      Then 操作失敗，錯誤為「pc_watermark_qty 必須為非負整數」

  Rule: 前置（參數）- 未知屬性會被忽略

    Example: 傳入未知屬性時不報錯
      When 管理員 "Admin" 更新設定，參數如下：
        | unknown_field | course_access_trigger |
        | some_value    | completed             |
      Then 操作成功
      And 設定中不應包含 "unknown_field"

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 更新 wp_options 中的 power_course_settings

    Example: 成功更新多項設定
      When 管理員 "Admin" 更新設定，參數如下：
        | course_access_trigger | hide_myaccount_courses | pc_watermark_qty | pc_watermark_text                  |
        | processing            | yes                    | 3                | {display_name} {email} IP:{ip}     |
      Then 操作成功
      And 設定 course_access_trigger 應為 "processing"
      And 設定 hide_myaccount_courses 應為 "yes"
      And 設定 pc_watermark_qty 應為 3
      And 設定 pc_watermark_text 應為 "{display_name} {email} IP:{ip}"

    Example: 成功更新 PDF 浮水印設定
      When 管理員 "Admin" 更新設定，參數如下：
        | pc_pdf_watermark_qty | pc_pdf_watermark_text |
        | 5                    | {display_name} 機密文件 |
      Then 操作成功
      And 設定 pc_pdf_watermark_qty 應為 5

  Rule: 後置（狀態）- 支援設定自動授權課程列表

    Example: 成功設定 auto_grant_course_ids
      When 管理員 "Admin" 更新設定，參數如下：
        | auto_grant_course_ids |
        | 100,101               |
      Then 操作成功
      And 設定 auto_grant_course_ids 應為 [100, 101]
