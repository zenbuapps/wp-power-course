@ignore @command
Feature: 建立郵件模板

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |

  # ========== 前置（參數）==========

  Rule: 前置（參數）- post_title（郵件主旨）為必填，post_content 和 course_id 可後補

    Example: 未提供郵件主旨時建立失敗
      When 管理員 "Admin" 建立郵件模板，參數如下：
        | post_title | post_content   | trigger_at     | course_id |
        |            | MJML 模板內容  | course_granted | 100       |
      Then 操作失敗，錯誤訊息包含 "post_title"

    Example: 僅提供主旨時建立成功（預設 trigger_at 為 course_granted）
      When 管理員 "Admin" 建立郵件模板，參數如下：
        | post_title |
        | 開通通知   |
      Then 操作成功
      And 新建郵件模板的 trigger_at meta 應為 "course_granted"
      And 新建郵件模板的 post_status 應為 "draft"

  Rule: 前置（參數）- trigger_at 必須為合法的觸發類型

    Example: 非法觸發類型時建立失敗
      When 管理員 "Admin" 建立郵件模板，參數如下：
        | post_title | trigger_at     |
        | 開通通知   | invalid_trigger |
      Then 操作失敗

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 建立 pe_email 自訂文章類型

    Example: 成功建立郵件模板
      When 管理員 "Admin" 建立郵件模板，參數如下：
        | post_title   | post_content  | trigger_at     | course_id |
        | 課程開通通知 | MJML 模板內容 | course_granted | 100       |
      Then 操作成功
      And 新建郵件模板的 post_type 應為 "pe_email"
      And 新建郵件模板的 trigger_at meta 應為 "course_granted"

  Rule: 後置（狀態）- 郵件模板可選擇性綁定課程（不綁定則適用全域）

    Scenario Outline: 建立不同觸發類型的郵件模板
      When 管理員 "Admin" 建立郵件模板，參數如下：
        | post_title | trigger_at   | course_id |
        | <title>    | <trigger_at> | 100       |
      Then 操作成功

      Examples:
        | title      | trigger_at      |
        | 開通通知   | course_granted  |
        | 完課通知   | course_finish   |
        | 開課通知   | course_launch   |
        | 進入章節   | chapter_enter   |
        | 完成章節   | chapter_finish  |

    Example: 建立不綁定課程的全域郵件模板
      When 管理員 "Admin" 建立郵件模板，參數如下：
        | post_title | trigger_at     |
        | 全域開通   | course_granted |
      Then 操作成功
      And 新建郵件模板不應有 course_id meta
