@query
Feature: 查詢有繳交紀錄的課程

  管理員在作業批改頁的課程分頁（Tab）選項中，看到有學員繳交紀錄的課程與各課程繳交數。

  業務依據：zenbu-site-2.0 `course-submission-reporting.service.ts::listCoursesWithSubmissions`
  （依繳交數 DESC，同數依課程名稱 ASC；無法解析課程的繳交略過）
  架構對應：垃圾桶中作業的繳交不計入

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
      | 102      | CSS 入門課 | yes        | publish |
    And 課程 100 有以下章節：
      | chapterId | post_title | post_parent |
      | 201       | 1-1 變數   | 100         |
    And 課程 101 有以下章節：
      | chapterId | post_title | post_parent |
      | 300       | JS 第一章  | 101         |
    And 系統中有以下作業：
      | assignmentId | chapterId | title            | allow_text | allow_file | required_text | required_file | max_files | due_at | menu_order | post_status |
      | 400          | 201       | 練習一：變數宣告 | yes        | no         | no            | no            | 1         | 0      | 0          | publish     |
      | 401          | 201       | 練習二：上傳截圖 | yes        | yes        | no            | yes           | 2         | 0      | 10         | publish     |
      | 402          | 201       | 已刪除練習       | yes        | no         | no            | no            | 1         | 0      | 20         | trash       |
      | 420          | 300       | JS 練習          | yes        | no         | no            | no            | 1         | 0      | 0          | publish     |

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 操作者必須為管理員（manage_options 或 manage_woocommerce）

    Example: 非管理員查詢時操作失敗
      When 用戶 "Alice" 呼叫 GET /assignment-submissions/courses
      Then 操作失敗，錯誤為「權限不足」

  # ========== 後置（回應）==========

  Rule: 後置（回應）- 回傳有繳交紀錄的課程與繳交數，依繳交數由多到少排序，不含垃圾桶中作業的繳交

    Example: PHP 基礎課 3 筆、JS 入門課 1 筆
      Given 系統中有以下作業繳交：
        | submissionId | assignmentId | userId | text_body | attachment_ids | status  | feedback | submitted_at        | graded_by | graded_at |
        | 500          | 400          | 2      | A         | []             | pending |          | 2026-09-16 09:00:00 |           |           |
        | 501          | 400          | 3      | B         | []             | pending |          | 2026-09-16 10:00:00 |           |           |
        | 502          | 401          | 2      | C         | []             | pending |          | 2026-09-16 11:00:00 |           |           |
        | 503          | 420          | 2      | D         | []             | pending |          | 2026-09-16 12:00:00 |           |           |
        | 504          | 402          | 3      | E         | []             | pending |          | 2026-09-16 13:00:00 |           |           |
      When 管理員 "Admin" 查詢有繳交紀錄的課程
      Then 操作成功
      And 查詢結果應為：
        | id  | title      | count |
        | 100 | PHP 基礎課 | 3     |
        | 101 | JS 入門課  | 1     |

  Rule: 後置（回應）- 繳交數相同時依課程名稱排序

    Example: 兩門課各 1 筆時依名稱排序
      Given 系統中有以下作業繳交：
        | submissionId | assignmentId | userId | text_body | attachment_ids | status  | feedback | submitted_at        | graded_by | graded_at |
        | 500          | 400          | 2      | A         | []             | pending |          | 2026-09-16 09:00:00 |           |           |
        | 503          | 420          | 2      | D         | []             | pending |          | 2026-09-16 12:00:00 |           |           |
      When 管理員 "Admin" 查詢有繳交紀錄的課程
      Then 操作成功
      And 查詢結果應為：
        | id  | title      | count |
        | 101 | JS 入門課  | 1     |
        | 100 | PHP 基礎課 | 1     |

  Rule: 後置（回應）- 沒有任何繳交時回傳空列表

    Example: 全站尚無繳交
      When 管理員 "Admin" 查詢有繳交紀錄的課程
      Then 操作成功
      And 查詢結果應為空
