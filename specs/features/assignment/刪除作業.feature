@command
Feature: 刪除作業

  管理員刪除作業。Power Course 沿用章節的刪除慣例（`wp_trash_post` 移至垃圾桶），
  作業被永久刪除（WordPress 清空垃圾桶）時才刪除其繳交與討論訊息。

  業務依據：zenbu-site-2.0 `DELETE admin/course-assignments/:id`（硬刪，繳交經 FK CASCADE 刪除，討論訊息另行 purge，media 不刪）
  架構對應：硬刪 → 垃圾桶 + 永久刪除時清除

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
      | 2      | Alice | alice@test.com | subscriber    |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |
    And 課程 100 有以下章節：
      | chapterId | post_title | post_parent |
      | 201       | 1-1 變數   | 100         |
    And 系統中有以下作業：
      | assignmentId | chapterId | title            | allow_text | allow_file | required_text | required_file | max_files | due_at | menu_order | post_status |
      | 400          | 201       | 練習一：變數宣告 | yes        | yes        | no            | no            | 1         | 0      | 0          | publish     |
      | 402          | 201       | 已刪除練習       | yes        | no         | no            | no            | 1         | 0      | 0          | trash       |

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 操作者必須為管理員（manage_options 或 manage_woocommerce）

    Example: 非管理員刪除作業時操作失敗
      When 用戶 "Alice" 呼叫 DELETE /assignments/400
      Then 操作失敗，錯誤為「權限不足」

  Rule: 前置（狀態）- 作業必須存在，在垃圾桶中的作業視為不存在

    Scenario Outline: 刪除<情境>的作業時操作失敗
      When 管理員 "Admin" 刪除作業 <assignmentId>
      Then 操作失敗，錯誤為「作業不存在」

      Examples:
        | 情境       | assignmentId |
        | 不存在     | 9999         |
        | 已在垃圾桶 | 402          |

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 作業移至垃圾桶

    Example: 刪除作業後 post_status 為 trash
      When 管理員 "Admin" 刪除作業 400
      Then 操作成功
      And 作業 400 應為：
        | post_status |
        | trash       |

  Rule: 後置（狀態）- 移至垃圾桶時保留該作業的繳交紀錄與討論訊息

    Example: 刪除作業後學員繳交與討論訊息仍存在
      Given 系統中有以下作業繳交：
        | submissionId | assignmentId | userId | text_body  | attachment_ids | status  | feedback | submitted_at        | graded_by | graded_at |
        | 500          | 400          | 2      | let x = 1; | []             | pending |          | 2026-09-16 09:00:00 |           |           |
      And 系統中有以下作業討論訊息：
        | messageId | submissionId | sender_type | sender_id | body         | read_at | created_at          | deleted_at |
        | 600       | 500          | student     | 2         | 老師請問型別 |         | 2026-09-16 09:10:00 |            |
      When 管理員 "Admin" 刪除作業 400
      Then 操作成功
      And 作業繳交 500 應為：
        | assignment_id | user_id | status  |
        | 400           | 2       | pending |
      And 作業討論訊息 600 應仍存在

  Rule: 後置（狀態）- 作業被永久刪除時刪除其繳交與討論訊息，附件紀錄與檔案保留

    Example: 永久刪除作業後繳交與訊息被清除但附件仍在
      Given 系統中有以下作業附件：
        | attachmentId | userId | original_name | mime_type | file_size |
        | 700          | 2      | 作業截圖.png  | image/png | 20480     |
      And 系統中有以下作業繳交：
        | submissionId | assignmentId | userId | text_body | attachment_ids | status  | feedback | submitted_at        | graded_by | graded_at |
        | 500          | 402          | 2      | 如附件    | [700]          | pending |          | 2026-09-16 09:00:00 |           |           |
      And 系統中有以下作業討論訊息：
        | messageId | submissionId | sender_type | sender_id | body         | read_at | created_at          | deleted_at |
        | 600       | 500          | student     | 2         | 老師請問型別 |         | 2026-09-16 09:10:00 |            |
      When WordPress 永久刪除作業 402
      Then 作業 402 應不存在
      And 作業繳交 500 應不存在
      And 作業討論訊息 600 應不存在
      And 作業附件 700 應仍存在
