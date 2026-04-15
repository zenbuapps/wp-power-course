@ignore @command
Feature: 排序章節

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |
    And 課程 100 有以下章節：
      | chapterId | post_title | post_parent | menu_order |
      | 200       | 第一章     | 100         | 1          |
      | 201       | 第二章     | 100         | 2          |
      | 202       | 第三章     | 100         | 3          |

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 所有章節 ID 必須存在

    Example: 包含不存在的章節 ID 時操作失敗
      When 管理員 "Admin" 排序章節，參數如下：
        | chapterId | menu_order |
        | 200       | 1          |
        | 9999      | 2          |
      Then 操作失敗

  # ========== 前置（參數）==========

  Rule: 前置（參數）- chapters 不可為空陣列

    Example: 未提供排序資料時操作失敗
      When 管理員 "Admin" 排序章節，參數如下：
        | chapterId | menu_order |
      Then 操作失敗，錯誤訊息包含 "chapters"

  Rule: 前置（參數）- 每項必須包含有效的 id 與 menu_order

    Example: 缺少 menu_order 時操作失敗
      When 管理員 "Admin" 排序章節，參數如下：
        | chapterId | menu_order |
        | 200       |            |
      Then 操作失敗

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 批量更新所有章節的 menu_order

    Example: 成功重新排序章節
      When 管理員 "Admin" 排序章節，參數如下：
        | chapterId | menu_order |
        | 202       | 1          |
        | 200       | 2          |
        | 201       | 3          |
      Then 操作成功
      And 章節 202 的 menu_order 應為 1
      And 章節 200 的 menu_order 應為 2
      And 章節 201 的 menu_order 應為 3
