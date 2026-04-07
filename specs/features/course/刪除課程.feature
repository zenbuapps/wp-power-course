@ignore @command
Feature: 刪除課程

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |
      | 101      | React 課程 | yes        | draft   |

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 所有 ids 對應的課程必須存在

    Example: 刪除不存在的課程時操作失敗
      When 管理員 "Admin" 刪除課程 ids [9999]
      Then 操作失敗

  # ========== 前置（參數）==========

  Rule: 前置（參數）- ids 不可為空陣列

    Example: 未提供 ids 時操作失敗
      When 管理員 "Admin" 刪除課程 ids []
      Then 操作失敗，錯誤訊息包含 "ids"

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 課程商品被刪除

    Example: 成功刪除單一課程
      When 管理員 "Admin" 刪除課程 ids [100]
      Then 操作成功
      And 課程 100 應不存在

    Example: 成功批量刪除多個課程
      When 管理員 "Admin" 刪除課程 ids [100, 101]
      Then 操作成功
      And 課程 100 應不存在
      And 課程 101 應不存在

  Rule: 後置（狀態）- 關聯的章節和學員紀錄不自動刪除

    Example: 刪除課程後章節仍存在
      Given 課程 100 有以下章節：
        | chapterId | post_title |
        | 200       | 第一章     |
      When 管理員 "Admin" 刪除課程 ids [100]
      Then 操作成功
      And 章節 200 仍然存在
