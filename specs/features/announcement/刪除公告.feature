@ignore @command
Feature: 刪除公告

  管理員可刪除已建立的公告。刪除為軟刪除（trash），可在垃圾桶中還原。
  支援單筆刪除與批次刪除。

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |
    And 課程 100 有以下公告：
      | announcementId | post_title       | post_status |
      | 300            | 公告 A           | publish     |
      | 301            | 公告 B           | publish     |
      | 302            | 公告 C           | trash       |

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 公告必須存在

    Example: 不存在的公告刪除失敗
      When 管理員 "Admin" 刪除公告 9999
      Then 操作失敗，錯誤為「公告不存在」

  # ========== 前置（參數）==========

  Rule: 前置（參數）- 必要參數必須提供

    Example: 未提供公告 ID 時刪除失敗
      When 管理員 "Admin" 刪除公告 ""
      Then 操作失敗，錯誤訊息包含 "id"

    Example: 批次刪除時 ids 不可為空陣列
      When 管理員 "Admin" 批次刪除公告 []
      Then 操作失敗，錯誤訊息包含 "ids"

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 透過 DELETE /power-course/announcements/{id} 刪除公告（wp_trash_post）

    Example: 成功刪除公告（軟刪除）
      When 管理員 "Admin" 刪除公告 301
      Then 操作成功
      And 公告 301 的 post_status 應為 "trash"

  Rule: 後置（狀態）- 軟刪除的公告不會出現在前台銷售頁

    Example: 已 trash 的公告不顯示於銷售頁
      Given 公告 302 的 post_status 為 "trash"
      When 訪客瀏覽課程 100 的銷售頁
      Then 公告區塊不應顯示公告 302

  Rule: 後置（狀態）- 支援批次刪除（DELETE /power-course/announcements）

    Example: 成功批次刪除多個公告
      When 管理員 "Admin" 批次刪除公告 [300, 301]
      Then 操作成功
      And 公告 300 的 post_status 應為 "trash"
      And 公告 301 的 post_status 應為 "trash"

  Rule: 後置（狀態）- 已 trash 的公告再次刪除視為成功（避免 wp_trash_post 對已 trash 的 post 回傳 false 誤判）

    Example: 對已 trash 公告再次呼叫刪除
      When 管理員 "Admin" 刪除公告 302
      Then 操作成功
      And 公告 302 的 post_status 維持為 "trash"

  # ========== 還原與永久刪除 ==========

  Rule: 後置（狀態）- 已 trash 的公告可透過 POST /power-course/announcements/{id}/restore 還原

    Example: 還原已刪除公告
      When 管理員 "Admin" 還原公告 302
      Then 操作成功
      And 公告 302 的 post_status 應為 "publish"

  Rule: 後置（狀態）- 已 trash 的公告可透過 force=true 永久刪除

    Example: 永久刪除已 trash 的公告
      Given 公告 302 已在垃圾桶
      When 管理員 "Admin" 刪除公告 302，force=true
      Then 操作成功
      And 公告 302 的記錄不存在於 wp_posts
