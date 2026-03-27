@ignore @command
Feature: 刪除章節

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
      | 210       | 1-1 小節   | 200         | 1          |

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 章節必須存在

    Example: 不存在的章節刪除失敗
      When 管理員 "Admin" 刪除章節 9999
      Then 操作失敗，錯誤為「章節不存在」

  # ========== 前置（參數）==========

  Rule: 前置（參數）- 必要參數必須提供

    Example: 未提供章節 ID 時刪除失敗
      When 管理員 "Admin" 刪除章節 ""
      Then 操作失敗，錯誤訊息包含 "id"

    Example: 批次刪除時 ids 不可為空陣列
      When 管理員 "Admin" 批次刪除章節 []
      Then 操作失敗，錯誤訊息包含 "ids"

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 透過自訂 API 端點 DELETE /power-course/chapters/{id} 刪除章節（wp_trash_post）

    Example: 成功刪除章節
      When 管理員 "Admin" 刪除章節 201
      Then 操作成功
      And 章節 201 的 post_status 應為 "trash"

  Rule: 後置（狀態）- 刪除父章節時連帶刪除所有子章節（cascade）

    Example: 刪除父章節時子章節一併被刪除
      When 管理員 "Admin" 刪除章節 200
      Then 操作成功
      And 章節 200 的 post_status 應為 "trash"
      And 章節 210 的 post_status 應為 "trash"

  Rule: 後置（狀態）- 支援批次刪除（DELETE /power-course/chapters）

    Example: 成功批次刪除多個章節
      When 管理員 "Admin" 批次刪除章節 [200, 201]
      Then 操作成功
      And 章節 200 的 post_status 應為 "trash"
      And 章節 201 的 post_status 應為 "trash"

    Example: 批次刪除時部分章節已在回收桶不影響結果
      Given 章節 201 已在回收桶
      When 管理員 "Admin" 批次刪除章節 [200, 201]
      Then 操作成功

  Rule: 後置（狀態）- 刪除章節後清除相關的 chaptermeta 進度記錄

    Example: 刪除章節後 chaptermeta 被清除
      Given 用戶 "Alice" 在章節 200 的 chaptermeta 有 finished_at
      When 管理員 "Admin" 刪除章節 200
      Then 操作成功
      And 章節 200 的 chaptermeta 記錄應被清除

  Rule: 後置（狀態）- 刪除章節後清除相關快取

    Example: 刪除章節後清除課程章節列表快取
      When 管理員 "Admin" 刪除章節 200
      Then 操作成功
      And 課程 100 的章節列表 transient 快取應被清除
