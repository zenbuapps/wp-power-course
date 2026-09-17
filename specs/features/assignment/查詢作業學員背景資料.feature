@query
Feature: 查詢作業學員背景資料

  管理員在作業批改頁右欄查看被批改學員的背景資料：基本資料、本課程作業統計與學習進度，作為批改參考。

  業務依據：zenbu-site-2.0 `course-submission-reporting.service.ts::getStudentOverview` + `GET admin/course-assignments/submissions/student-overview`
  （customerId 必填；課程優先用 courseId，否則由 assignmentId 反推；學員或課程不存在時對應欄位為空值，不報錯）
  架構對應：學習進度改用 Power Course 章節攤平計算（CourseUtils::get_course_progress，百分比取到小數一位）

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          | user_registered     |
      | 1      | Admin | admin@test.com | administrator | 2025-12-01 09:00:00 |
      | 2      | Alice | alice@test.com | subscriber    | 2026-01-05 10:00:00 |
      | 3      | Bob   | bob@test.com   | subscriber    | 2026-02-10 11:00:00 |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |
      | 101      | JS 入門課  | yes        | publish |
    And 課程 100 有以下章節：
      | chapterId | post_title | post_parent |
      | 200       | 第一章     | 100         |
      | 201       | 1-1 變數   | 200         |
      | 202       | 第二章     | 100         |
    And 課程 101 有以下章節：
      | chapterId | post_title | post_parent |
      | 300       | JS 第一章  | 101         |
    And 系統中有以下作業：
      | assignmentId | chapterId | title            | allow_text | allow_file | required_text | required_file | max_files | due_at | menu_order | post_status |
      | 400          | 201       | 練習一：變數宣告 | yes        | no         | no            | no            | 1         | 0      | 0          | publish     |
      | 401          | 201       | 練習二：上傳截圖 | yes        | yes        | no            | yes           | 2         | 0      | 10         | publish     |
      | 410          | 200       | 第一章總複習     | yes        | no         | no            | no            | 1         | 0      | 0          | publish     |
      | 420          | 300       | JS 練習          | yes        | no         | no            | no            | 1         | 0      | 0          | publish     |
    And 系統中有以下作業繳交：
      | submissionId | assignmentId | userId | text_body | attachment_ids | status  | feedback | submitted_at        | graded_by | graded_at           |
      | 500          | 400          | 2      | A         | []             | pass    | 很好     | 2026-09-16 09:00:00 | 1         | 2026-09-16 10:00:00 |
      | 501          | 401          | 2      | B         | []             | fail    | 再補充   | 2026-09-16 11:00:00 | 1         | 2026-09-16 12:00:00 |
      | 502          | 420          | 2      | C         | []             | pending |          | 2026-09-16 13:00:00 |           |                     |
      | 503          | 400          | 3      | D         | []             | pending |          | 2026-09-16 14:00:00 |           |                     |
    And 用戶 "Alice" 在章節 200 的 finished_at 為 "2026-09-15 10:00:00"
    And 用戶 "Alice" 在章節 201 的 finished_at 為 "2026-09-16 10:00:00"

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 操作者必須為管理員（manage_options 或 manage_woocommerce）

    Example: 非管理員查詢時操作失敗
      When 用戶 "Alice" 呼叫 GET /assignment-submissions/student-overview
      Then 操作失敗，錯誤為「權限不足」

  # ========== 前置（參數）==========

  Rule: 前置（參數）- user_id 為必填

    Example: 未提供 user_id 時操作失敗
      When 管理員 "Admin" 查詢作業學員背景資料，參數如下：
        | user_id | course_id |
        |         | 100       |
      Then 操作失敗，錯誤為「user_id 為必填」

  # ========== 後置（回應）==========

  Rule: 後置（回應）- 以 course_id 彙整學員基本資料、該學員在本課程作業的狀態統計與學習進度

    Example: Alice 在 PHP 基礎課的背景資料
      When 管理員 "Admin" 查詢作業學員背景資料，參數如下：
        | user_id | course_id |
        | 2       | 100       |
      Then 操作成功
      And 回應應包含：
        | user.id | user.name | user.email     | user.registered_at  | course.id | course.title | stats.submitted | stats.passed | stats.pending | stats.failed | progress.completed_chapters | progress.total_chapters | progress.percent |
        | 2       | Alice     | alice@test.com | 2026-01-05 10:00:00 | 100       | PHP 基礎課   | 2               | 1            | 0             | 1            | 2                           | 3                       | 66.7             |

  Rule: 後置（回應）- 未提供 course_id 時由 assignment_id 反推所屬課程

    Example: 由作業 420 反推到 JS 入門課
      When 管理員 "Admin" 查詢作業學員背景資料，參數如下：
        | user_id | assignment_id |
        | 2       | 420           |
      Then 操作成功
      And 回應應包含：
        | course.id | course.title | stats.submitted | stats.passed | stats.pending | stats.failed | progress.completed_chapters | progress.total_chapters | progress.percent |
        | 101       | JS 入門課    | 1               | 0            | 1             | 0            | 0                           | 1                       | 0                |

  Rule: 後置（回應）- 學員不存在時學員欄位為空值，不報錯

    Example: 查詢不存在的學員
      When 管理員 "Admin" 查詢作業學員背景資料，參數如下：
        | user_id | course_id |
        | 9999    | 100       |
      Then 操作成功
      And 回應應包含：
        | user.id | user.name | user.email | user.registered_at | course.id | stats.submitted | progress.completed_chapters | progress.total_chapters |
        | 9999    |           |            |                    | 100       | 0               | 0                           | 3                       |

  Rule: 後置（回應）- 無法解析課程時課程欄位為空、統計與進度皆為 0

    Example: 未提供課程與作業
      When 管理員 "Admin" 查詢作業學員背景資料，參數如下：
        | user_id |
        | 2       |
      Then 操作成功
      And 回應應包含：
        | course.id | course.title | stats.submitted | stats.passed | stats.pending | stats.failed | progress.completed_chapters | progress.total_chapters | progress.percent |
        |           |              | 0               | 0            | 0             | 0            | 0                           | 0                       | 0                |
