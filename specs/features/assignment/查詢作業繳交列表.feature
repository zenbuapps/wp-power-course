@query
Feature: 查詢作業繳交列表

  管理員在跨課程的「作業批改」頁查看學員繳交。可依批改狀態、課程、作業篩選，依繳交時間由新到舊排序並分頁。

  業務依據：zenbu-site-2.0 `course-submission-reporting.service.ts::listSubmissions` + `GET admin/course-assignments/submissions`
  （status 非法值視同 all；limit 預設 50、夾限 1-200；offset 夾限 ≥ 0；course 與 assignment 取交集；
    enrich 學員 / 作業 / 課程 / 附件大類 / 討論串未讀數、總數、最後訊息時間；不過濾學員觀看權）
  架構對應：垃圾桶中作業的繳交視為不存在（參考實作為硬刪）

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
      | 2      | Alice | alice@test.com | subscriber    |
      | 3      | Bob   | bob@test.com   | subscriber    |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |
      | 101      | JS 入門課  | yes        | publish |
    And 課程 100 有以下章節：
      | chapterId | post_title | post_parent |
      | 201       | 1-1 變數   | 100         |
    And 課程 101 有以下章節：
      | chapterId | post_title | post_parent |
      | 300       | JS 第一章  | 101         |
    And 系統中有以下作業：
      | assignmentId | chapterId | title            | allow_text | allow_file | required_text | required_file | max_files | due_at | menu_order | post_status |
      | 400          | 201       | 練習一：變數宣告 | yes        | yes       | no            | no            | 1         | 0      | 0          | publish     |
      | 401          | 201       | 練習二：上傳截圖 | yes        | yes        | no            | yes           | 2         | 0      | 10         | publish     |
      | 402          | 201       | 已刪除練習       | yes        | no         | no            | no            | 1         | 0      | 20         | trash       |
      | 420          | 300       | JS 練習          | yes        | no         | no            | no            | 1         | 0      | 0          | publish     |
    And 系統中有以下作業附件：
      | attachmentId | userId | original_name | mime_type | file_size |
      | 700          | 2      | 作業截圖.png  | image/png | 20480     |
    And 系統中有以下作業繳交：
      | submissionId | assignmentId | userId | text_body    | attachment_ids | status  | feedback       | submitted_at        | graded_by | graded_at           |
      | 500          | 400          | 2      | let x = 1;   | [700]          | pending |                | 2026-09-16 09:00:00 |           |                     |
      | 501          | 400          | 3      | const y = 2; | []             | pass    | 寫得很好       | 2026-09-16 15:30:00 | 1         | 2026-09-17 09:30:00 |
      | 502          | 401          | 2      | 如附件       | []             | fail    | 請補充型別說明 | 2026-09-15 20:00:00 | 1         | 2026-09-16 09:00:00 |
      | 503          | 420          | 2      | let z = 3;   | []             | pending |                | 2026-09-17 09:30:00 |           |                     |
      | 504          | 402          | 3      | 舊作業       | []             | pending |                | 2026-09-17 09:00:00 |           |                     |

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 操作者必須為管理員（manage_options 或 manage_woocommerce）

    Example: 非管理員查詢繳交列表時操作失敗
      When 用戶 "Alice" 呼叫 GET /assignment-submissions
      Then 操作失敗，錯誤為「權限不足」

  # ========== 前置（參數）==========

  Rule: 前置（參數）- status 不是 all、pending、pass、fail 時視同 all

    Example: status 為非法值時回傳全部繳交
      When 管理員 "Admin" 查詢作業繳交列表，參數如下：
        | status |
        | graded |
      Then 操作成功
      And 查詢結果的 id 依序應為 [503, 501, 500, 502]

  Rule: 前置（參數）- limit 夾限在 1 至 200、預設 50；offset 小於 0 時視為 0

    Scenario Outline: limit 為 <limit>、offset 為 <offset> 時回傳 <筆數> 筆
      When 管理員 "Admin" 查詢作業繳交列表，參數如下：
        | limit   | offset   |
        | <limit> | <offset> |
      Then 操作成功
      And 查詢結果的 id 依序應為 <ids>
      And 查詢結果總數應為 4

      Examples:
        | limit | offset | 筆數 | ids                  |
        | 0     | 0      | 1    | [503]                |
        | 500   | 0      | 4    | [503, 501, 500, 502] |
        | 50    | -5     | 4    | [503, 501, 500, 502] |

  # ========== 後置（回應）==========

  Rule: 後置（回應）- 未提供篩選時依 submitted_at 由新到舊回傳全部繳交並附總數，不含垃圾桶中作業的繳交

    Example: 預設查詢
      When 管理員 "Admin" 查詢作業繳交列表，參數如下：
        | status |
        | all    |
      Then 操作成功
      And 查詢結果的 id 依序應為 [503, 501, 500, 502]
      And 查詢結果總數應為 4

  Rule: 後置（回應）- 依批改狀態篩選

    Scenario Outline: 篩選 status 為 <status>
      When 管理員 "Admin" 查詢作業繳交列表，參數如下：
        | status   |
        | <status> |
      Then 操作成功
      And 查詢結果的 id 依序應為 <ids>

      Examples:
        | status  | ids        |
        | pending | [503, 500] |
        | pass    | [501]      |
        | fail    | [502]      |

  Rule: 後置（回應）- course_id 與 assignment_id 同時提供時取交集，作業不屬於該課程時回傳空列表

    Scenario Outline: course_id 為 <course_id>、assignment_id 為 <assignment_id>
      When 管理員 "Admin" 查詢作業繳交列表，參數如下：
        | course_id   | assignment_id   |
        | <course_id> | <assignment_id> |
      Then 操作成功
      And 查詢結果的 id 依序應為 <ids>

      Examples:
        | course_id | assignment_id | ids             |
        | 100       |               | [501, 500, 502] |
        | 100       | 400           | [501, 500]      |
        | 101       | 400           | []              |
        |           | 420           | [503]           |
        |           | 402           | []              |

  Rule: 後置（回應）- 每筆繳交附帶學員、作業、課程摘要與附件（含附件大類）

    Example: 查詢作業 400 的繳交摘要
      When 管理員 "Admin" 查詢作業繳交列表，參數如下：
        | assignment_id |
        | 400           |
      Then 操作成功
      And 查詢結果應為：
        | id  | customer_name | customer_email | assignment_title | course_title | status  | feedback | attachments                   |
        | 501 | Bob           | bob@test.com   | 練習一：變數宣告 | PHP 基礎課   | pass    | 寫得很好 | []                            |
        | 500 | Alice         | alice@test.com | 練習一：變數宣告 | PHP 基礎課   | pending |          | [{700, 作業截圖.png, image}]  |

  Rule: 後置（回應）- 每筆繳交附帶討論串未讀數（學員發送且管理員未讀）、訊息總數與最後訊息時間

    Example: 繳交 500 有 3 則訊息其中 1 則未讀
      Given 系統中有以下作業討論訊息：
        | messageId | submissionId | sender_type | sender_id | body             | read_at             | created_at          | deleted_at |
        | 600       | 500          | student     | 2         | 老師請問型別     |                     | 2026-09-16 09:10:00 |            |
        | 601       | 500          | teacher     | 1         | 參考 1-1 的範例  | 2026-09-16 10:05:00 | 2026-09-16 10:00:00 |            |
        | 602       | 500          | student     | 2         | 了解，謝謝       | 2026-09-16 10:30:00 | 2026-09-16 10:20:00 |            |
      When 管理員 "Admin" 查詢作業繳交列表，參數如下：
        | assignment_id |
        | 400           |
      Then 操作成功
      And 查詢結果應為：
        | id  | unread_count | message_count | last_message_at     |
        | 501 | 0            | 0             |                     |
        | 500 | 1            | 3             | 2026-09-16 10:20:00 |
