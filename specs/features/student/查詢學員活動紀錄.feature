@ignore @query
Feature: 查詢學員活動紀錄

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email           | role          |
      | 1      | Admin | admin@test.com  | administrator |
      | 2      | Alice | alice@test.com  | subscriber    |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |
    And 系統中有以下活動紀錄：
      | id | user_id | course_id | log_type       | title              | created_at          |
      | 1  | 2       | 100       | course_granted | 學員獲得課程存取權 | 2025-06-01 10:00:00 |
      | 2  | 2       | 100       | chapter_finish | 完成第一章         | 2025-06-02 14:30:00 |

  # ========== 前置（參數）==========

  Rule: 前置（參數）- course_id 必須提供且為正整數

    Example: 未提供 course_id 時操作失敗
      When 管理員 "Admin" 查詢活動紀錄，參數如下：
        | posts_per_page | paged |
        | 20             | 1     |
      Then 操作失敗，錯誤為「course_id 為必填參數」

  # ========== 後置（回應）==========

  Rule: 後置（回應）- 應回傳分頁的活動紀錄列表

    Example: 查詢特定課程的所有活動紀錄
      When 管理員 "Admin" 查詢活動紀錄，參數如下：
        | course_id | posts_per_page | paged |
        | 100       | 20             | 1     |
      Then 操作成功
      And 回應紀錄數量為 2
      And 回應中應包含以下紀錄：
        | log_type       | title              |
        | course_granted | 學員獲得課程存取權 |
        | chapter_finish | 完成第一章         |

  Rule: 後置（回應）- 無紀錄時回傳空列表

    Example: 查詢無紀錄的課程
      Given 系統中有以下課程：
        | courseId | name       | _is_course | status  |
        | 200      | 空白課程   | yes        | publish |
      When 管理員 "Admin" 查詢活動紀錄，參數如下：
        | course_id |
        | 200       |
      Then 操作成功
      And 回應紀錄數量為 0

  Rule: 後置（回應）- 支援依 user_id 和 log_type 篩選

    Example: 篩選特定用戶的特定類型紀錄
      When 管理員 "Admin" 查詢活動紀錄，參數如下：
        | course_id | user_id | log_type       |
        | 100       | 2       | chapter_finish |
      Then 操作成功
      And 回應紀錄數量為 1
