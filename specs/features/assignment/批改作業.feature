@command
Feature: 批改作業

  管理員將學員繳交批改為通過（pass）、未通過（fail）或改回待批改（pending），並撰寫評語，可隨時改判。
  批改為通過時自動把作業所屬章節標為完成；批改為通過或未通過時寄送批改通知。

  業務依據：zenbu-site-2.0 `course-assignment-submissions.service.ts::grade` + `GradeSubmissionDto`
  （任何 status 都寫入 gradedBy / gradedAt；feedback 未提供為 null；pass → maybeMarkLessonComplete（已完成不覆寫、不檢查其他作業、改判不撤銷）；
    pass / fail → dispatchGradedNotification；副作用失敗只記 log）
  架構對應：章節完成改用 Chapter\Service\Progress::mark_finished（觸發既有 power_course_chapter_finished）；寄信改走 PowerEmail 觸發時機 assignment_graded；
            垃圾桶中作業的繳交視為不存在

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
      | 400          | 201       | 練習一：變數宣告 | yes        | no         | no            | no            | 1         | 0      | 0          | publish     |
      | 401          | 201       | 練習二：上傳截圖 | yes        | yes        | no            | yes           | 2         | 0      | 10         | publish     |
    And 用戶 "Alice" 已被加入課程 100，expire_date 0
    And 系統中有以下作業繳交：
      | submissionId | assignmentId | userId | text_body  | attachment_ids | status  | feedback | submitted_at        | graded_by | graded_at |
      | 500          | 400          | 2      | let x = 1; | []             | pending |          | 2026-09-16 09:00:00 |           |           |
    And 系統中有以下郵件模板：
      | emailId | post_title       | trigger_at        | course_id | post_status |
      | 800     | 作業批改結果通知 | assignment_graded |           | publish     |
    And 現在時間為 "2026-09-17 10:00:00"

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 操作者必須為管理員（manage_options 或 manage_woocommerce）

    Example: 非管理員批改時操作失敗
      When 用戶 "Alice" 呼叫 PATCH /assignment-submissions/500/grade
      Then 操作失敗，錯誤為「權限不足」

  Rule: 前置（狀態）- 繳交紀錄必須存在，所屬作業在垃圾桶中時視為不存在

    Example: 繳交紀錄不存在時批改失敗
      When 管理員 "Admin" 批改作業繳交 9999，參數如下：
        | status |
        | pass   |
      Then 操作失敗，錯誤為「繳交紀錄不存在」

    Example: 繳交所屬作業在垃圾桶時批改失敗
      Given 系統中有以下作業：
        | assignmentId | chapterId | title      | allow_text | allow_file | required_text | required_file | max_files | due_at | menu_order | post_status |
        | 402          | 201       | 已刪除練習 | yes        | no         | no            | no            | 1         | 0      | 20         | trash       |
      And 系統中有以下作業繳交：
        | submissionId | assignmentId | userId | text_body | attachment_ids | status  | feedback | submitted_at        | graded_by | graded_at |
        | 501          | 402          | 2      | 舊作業    | []             | pending |          | 2026-09-16 09:00:00 |           |           |
      When 管理員 "Admin" 批改作業繳交 501，參數如下：
        | status |
        | pass   |
      Then 操作失敗，錯誤為「繳交紀錄不存在」

  # ========== 前置（參數）==========

  Rule: 前置（參數）- status 必須為 pending、pass、fail 之一

    Scenario Outline: status 為 "<status>" 時批改失敗
      When 管理員 "Admin" 批改作業繳交 500，參數如下：
        | status   |
        | <status> |
      Then 操作失敗，錯誤為「status 必須為 pending、pass、fail 之一」

      Examples:
        | status |
        |        |
        | passed |

  Rule: 前置（參數）- feedback 不可超過 10000 字

    Example: 評語超過 10000 字時批改失敗
      When 管理員 "Admin" 批改作業繳交 500，參數如下：
        | status | feedback       |
        | fail   | {字元 x 10001} |
      Then 操作失敗，錯誤為「feedback 不可超過 10000 字」

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 寫入批改狀態、評語、批改者與批改時間（任何狀態皆寫入批改者與批改時間）

    Scenario Outline: 批改為 <status>
      When 管理員 "Admin" 批改作業繳交 500，參數如下：
        | status   | feedback   |
        | <status> | <feedback> |
      Then 操作成功
      And 作業繳交 500 應為：
        | status   | feedback   | graded_by | graded_at           |
        | <status> | <feedback> | 1         | 2026-09-17 10:00:00 |

      Examples:
        | status  | feedback       |
        | pass    | 寫得很好       |
        | fail    | 請補充型別說明 |
        | pending |                |

  Rule: 後置（狀態）- 未提供 feedback 時評語清空

    Example: 改判通過且未給評語時清除舊評語
      Given 系統中有以下作業繳交：
        | submissionId | assignmentId | userId | text_body | attachment_ids | status | feedback | submitted_at        | graded_by | graded_at           |
        | 502          | 401          | 2      | 如附件    | []             | fail   | 舊評語   | 2026-09-16 09:00:00 | 1         | 2026-09-16 10:00:00 |
      When 管理員 "Admin" 批改作業繳交 502，參數如下：
        | status |
        | pass   |
      Then 操作成功
      And 作業繳交 502 應為：
        | status | feedback | graded_at           |
        | pass   |          | 2026-09-17 10:00:00 |

  Rule: 後置（狀態）- 可重複改判，以最後一次批改為準

    Example: 已通過的繳交改判為未通過
      Given 系統中有以下作業繳交：
        | submissionId | assignmentId | userId | text_body | attachment_ids | status | feedback | submitted_at        | graded_by | graded_at           |
        | 502          | 401          | 2      | 如附件    | []             | pass   | 很好     | 2026-09-16 09:00:00 | 1         | 2026-09-16 10:00:00 |
      When 管理員 "Admin" 批改作業繳交 502，參數如下：
        | status | feedback       |
        | fail   | 截圖看不清楚   |
      Then 操作成功
      And 作業繳交 502 應為：
        | status | feedback     | graded_at           |
        | fail   | 截圖看不清楚 | 2026-09-17 10:00:00 |

  Rule: 後置（狀態）- 批改為 pass 時，若作業所屬章節對該學員尚未完成則自動標記完成，不檢查同章節其他作業

    Example: 練習一通過時自動完成章節 201（練習二尚未繳交）
      Given 用戶 "Alice" 在章節 201 無 finished_at
      When 管理員 "Admin" 批改作業繳交 500，參數如下：
        | status | feedback |
        | pass   | 寫得很好 |
      Then 操作成功
      And 用戶 "Alice" 在章節 201 的 finished_at 應為 "2026-09-17 10:00:00"
      And action "power_course_chapter_finished" 應以參數 (201, 100, 2) 被觸發

  Rule: 後置（狀態）- 章節已完成時不重複標記、不覆寫完成時間

    Example: 章節已完成時批改通過不改變完成時間
      Given 用戶 "Alice" 在章節 201 的 finished_at 為 "2026-09-10 08:00:00"
      When 管理員 "Admin" 批改作業繳交 500，參數如下：
        | status |
        | pass   |
      Then 操作成功
      And 用戶 "Alice" 在章節 201 的 finished_at 應為 "2026-09-10 08:00:00"
      And action "power_course_chapter_finished" 不應被觸發

  Rule: 後置（狀態）- 由通過改判為未通過或待批改時，不撤銷章節完成狀態

    Scenario Outline: 改判為 <status> 後章節仍為完成
      Given 系統中有以下作業繳交：
        | submissionId | assignmentId | userId | text_body | attachment_ids | status | feedback | submitted_at        | graded_by | graded_at           |
        | 502          | 401          | 2      | 如附件    | []             | pass   | 很好     | 2026-09-16 09:00:00 | 1         | 2026-09-16 10:00:00 |
      And 用戶 "Alice" 在章節 201 的 finished_at 為 "2026-09-16 10:00:00"
      When 管理員 "Admin" 批改作業繳交 502，參數如下：
        | status   |
        | <status> |
      Then 操作成功
      And 用戶 "Alice" 在章節 201 的 finished_at 應為 "2026-09-16 10:00:00"
      And action "power_course_chapter_unfinished" 不應被觸發

      Examples:
        | status  |
        | fail    |
        | pending |

  Rule: 後置（狀態）- 批改為 pass 或 fail 時觸發 assignment_graded 郵件；改回 pending 時不寄送

    Scenario Outline: 批改為 <status> 時寄送批改通知
      When 管理員 "Admin" 批改作業繳交 500，參數如下：
        | status   | feedback |
        | <status> | 評語     |
      Then 操作成功
      And 應發送郵件給 "alice@test.com"

      Examples:
        | status |
        | pass   |
        | fail   |

    Example: 改回待批改時不寄送批改通知
      When 管理員 "Admin" 批改作業繳交 500，參數如下：
        | status  |
        | pending |
      Then 操作成功
      And 不應發送任何郵件

  Rule: 後置（狀態）- 每次批改（含改判）各寄一次批改通知

    Example: 同一繳交第二次批改仍寄送通知
      Given 系統中有以下作業繳交：
        | submissionId | assignmentId | userId | text_body | attachment_ids | status | feedback | submitted_at        | graded_by | graded_at           |
        | 502          | 401          | 2      | 如附件    | []             | fail   | 再補充   | 2026-09-16 09:00:00 | 1         | 2026-09-16 10:00:00 |
      And 郵件模板 800 已針對作業繳交 502 在 "2026-09-16 10:00:00" 的批改寄出通知
      When 管理員 "Admin" 批改作業繳交 502，參數如下：
        | status | feedback       |
        | fail   | 截圖仍不清楚   |
      Then 操作成功
      And 應發送郵件給 "alice@test.com"

  Rule: 後置（狀態）- 自動完成章節或寄送通知失敗時，不影響批改結果

    Example: 寄信服務錯誤時批改仍成功
      Given 寄信服務發生錯誤
      When 管理員 "Admin" 批改作業繳交 500，參數如下：
        | status | feedback |
        | pass   | 寫得很好 |
      Then 操作成功
      And 作業繳交 500 應為：
        | status | feedback |
        | pass   | 寫得很好 |

  # ========== 後置（回應）==========

  Rule: 後置（回應）- 回傳批改後的繳交紀錄

    Example: 批改成功後回應繳交資料
      When 管理員 "Admin" 批改作業繳交 500，參數如下：
        | status | feedback |
        | pass   | 寫得很好 |
      Then 操作成功
      And 回應應包含：
        | id  | assignment_id | user_id | status | feedback | graded_by | graded_at           |
        | 500 | 400           | 2       | pass   | 寫得很好 | 1         | 2026-09-17 10:00:00 |
