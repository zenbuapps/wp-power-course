@query
Feature: 查詢作業批改統計

  管理員在作業批改頁頂部查看批改總覽數字。

  業務依據：zenbu-site-2.0 `course-submission-reporting.service.ts::stats`
  （total / pending / pass / fail；passRate = round(pass ÷ (pass + fail) × 100)，分母為 0 時為 0；
    avgGradingSeconds = 所有 gradedAt 不為空的繳交之 (gradedAt − submittedAt) 平均秒數，無則 null；
    course 與 assignment 取交集，範圍為空時全部為 0）
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
    And 系統中有以下作業繳交：
      | submissionId | assignmentId | userId | text_body    | attachment_ids | status  | feedback       | submitted_at        | graded_by | graded_at           |
      | 500          | 400          | 2      | let x = 1;   | []             | pending |                | 2026-09-16 09:00:00 |           |                     |
      | 501          | 400          | 3      | const y = 2; | []             | pass    | 寫得很好       | 2026-09-16 15:30:00 | 1         | 2026-09-17 09:30:00 |
      | 502          | 401          | 2      | 如附件       | []             | fail    | 請補充型別說明 | 2026-09-15 20:00:00 | 1         | 2026-09-16 09:00:00 |
      | 503          | 420          | 2      | let z = 3;   | []             | pending |                | 2026-09-17 09:30:00 |           |                     |
      | 504          | 402          | 3      | 舊作業       | []             | pending |                | 2026-09-17 09:00:00 |           |                     |

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 操作者必須為管理員（manage_options 或 manage_woocommerce）

    Example: 非管理員查詢批改統計時操作失敗
      When 用戶 "Alice" 呼叫 GET /assignment-submissions/stats
      Then 操作失敗，錯誤為「權限不足」

  # ========== 後置（回應）==========

  Rule: 後置（回應）- 未提供範圍時統計全站，不含垃圾桶中作業的繳交

    Example: 全站批改統計
      When 管理員 "Admin" 查詢作業批改統計，參數如下：
        | course_id | assignment_id |
        |           |               |
      Then 操作成功
      And 統計結果應為：
        | total | pending | pass | fail | pass_rate | avg_grading_seconds |
        | 4     | 2       | 1    | 1    | 50        | 55800               |

  Rule: 後置（回應）- 依課程與作業範圍統計，兩者同時提供時取交集

    Scenario Outline: course_id 為 <course_id>、assignment_id 為 <assignment_id>
      When 管理員 "Admin" 查詢作業批改統計，參數如下：
        | course_id   | assignment_id   |
        | <course_id> | <assignment_id> |
      Then 操作成功
      And 統計結果應為：
        | total   | pending   | pass   | fail   | pass_rate   | avg_grading_seconds   |
        | <total> | <pending> | <pass> | <fail> | <pass_rate> | <avg_grading_seconds> |

      Examples:
        | course_id | assignment_id | total | pending | pass | fail | pass_rate | avg_grading_seconds |
        | 101       |               | 1     | 1       | 0    | 0    | 0         |                     |
        | 101       | 400           | 0     | 0       | 0    | 0    | 0         |                     |
        |           | 400           | 2     | 1       | 1    | 0    | 100       | 64800               |

  Rule: 後置（回應）- 平均批改時間計入所有 graded_at 不為空的繳交，包含被改回待批改者

    Example: 改回待批改的繳交仍計入平均批改時間
      Given 系統中有以下作業繳交：
        | submissionId | assignmentId | userId | text_body | attachment_ids | status  | feedback | submitted_at        | graded_by | graded_at           |
        | 505          | 401          | 3      | Bob 答案  | []             | pending | 再看看   | 2026-09-16 09:00:00 | 1         | 2026-09-16 15:30:00 |
      When 管理員 "Admin" 查詢作業批改統計，參數如下：
        | assignment_id |
        | 401           |
      Then 操作成功
      And 統計結果應為：
        | total | pending | pass | fail | pass_rate | avg_grading_seconds |
        | 2     | 1       | 0    | 1    | 0         | 35100               |

  Rule: 後置（回應）- 課程底下沒有任何作業時所有數字為 0

    Example: 查詢沒有作業的課程
      Given 系統中有以下課程：
        | courseId | name      | _is_course | status  |
        | 102      | CSS 入門課 | yes       | publish |
      When 管理員 "Admin" 查詢作業批改統計，參數如下：
        | course_id |
        | 102       |
      Then 操作成功
      And 統計結果應為：
        | total | pending | pass | fail | pass_rate | avg_grading_seconds |
        | 0     | 0       | 0    | 0    | 0         |                     |
