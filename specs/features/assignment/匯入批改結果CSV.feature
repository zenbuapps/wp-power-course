@command
Feature: 匯入批改結果CSV

  管理員上傳修改過「狀態」「講師回饋」欄的批改用 CSV，批量完成批改。每一列等同執行一次「批改作業」，
  副作用（自動完成章節、批改通知）一併觸發；單列失敗不影響其他列。（實作第二波）

  業務依據：zenbu-site-2.0 `POST admin/course-assignments/submissions/import` + `course-submission-csv.service.ts::importGrades`
  （檔案上限 10MB；表頭正規化為小寫、空白換底線；狀態辨識 pending / 待批改、pass / passed / 通過、fail / failed / 未通過 / 不通過；
    講師回饋欄空白時評語清空；解析失敗回傳 row 0 錯誤；列號含表頭，第一筆資料為第 2 列）

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
      | 2      | Alice | alice@test.com | subscriber    |
      | 3      | Bob   | bob@test.com   | subscriber    |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |
    And 課程 100 有以下章節：
      | chapterId | post_title | post_parent |
      | 201       | 1-1 變數   | 100         |
    And 系統中有以下作業：
      | assignmentId | chapterId | title            | allow_text | allow_file | required_text | required_file | max_files | due_at | menu_order | post_status |
      | 400          | 201       | 練習一：變數宣告 | yes        | no         | no            | no            | 1         | 0      | 0          | publish     |
    And 系統中有以下作業繳交：
      | submissionId | assignmentId | userId | text_body    | attachment_ids | status  | feedback | submitted_at        | graded_by | graded_at |
      | 500          | 400          | 2      | let x = 1;   | []             | pending |          | 2026-09-16 09:00:00 |           |           |
      | 501          | 400          | 3      | const y = 2; | []             | pending |          | 2026-09-16 15:30:00 |           |           |
    And 現在時間為 "2026-09-17 10:00:00"

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 操作者必須為管理員（manage_options 或 manage_woocommerce）

    Example: 非管理員匯入時操作失敗
      When 用戶 "Alice" 呼叫 POST /assignment-submissions/import
      Then 操作失敗，錯誤為「權限不足」

  # ========== 前置（參數）==========

  Rule: 前置（參數）- 必須上傳 CSV 檔案

    Example: 未附檔案時操作失敗
      When 管理員 "Admin" 匯入批改結果 CSV 但未附檔案
      Then 操作失敗，錯誤為「請選擇要匯入的 CSV 檔案」

  Rule: 前置（參數）- 檔案大小不可超過 10MB

    Example: 檔案超過 10MB 時操作失敗
      When 管理員 "Admin" 匯入大小為 10485761 bytes 的批改結果 CSV
      Then 操作失敗，錯誤為「檔案過大（上限 10MB）」

  # ========== 後置（回應）==========

  Rule: 後置（回應）- CSV 無法解析時不處理任何列，回傳第 0 列的解析錯誤

    Example: 上傳無法解析的檔案
      When 管理員 "Admin" 匯入無法解析的批改結果 CSV
      Then 操作成功
      And 匯入結果應為：
        | total | updated | skipped | failed |
        | 0     | 0       | 0       | 0      |
      And 匯入錯誤明細應為：
        | row | message       |
        | 0   | CSV 解析失敗  |

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 依 submission_id 逐列套用狀態與講師回饋

    Example: 兩列分別批改為通過與未通過
      When 管理員 "Admin" 匯入批改結果 CSV，內容如下：
        | submission_id | 狀態(可改 pending/pass/fail) | 講師回饋(可改) |
        | 500           | pass                         | 寫得很好       |
        | 501           | fail                         | 請補充型別說明 |
      Then 操作成功
      And 作業繳交 500 應為：
        | status | feedback | graded_by | graded_at           |
        | pass   | 寫得很好 | 1         | 2026-09-17 10:00:00 |
      And 作業繳交 501 應為：
        | status | feedback       | graded_by | graded_at           |
        | fail   | 請補充型別說明 | 1         | 2026-09-17 10:00:00 |
      And 匯入結果應為：
        | total | updated | skipped | failed |
        | 2     | 2       | 0       | 0      |

  Rule: 後置（狀態）- 狀態欄接受中英文多種寫法（不分大小寫）

    Scenario Outline: 狀態欄寫 <寫法> 時批改為 <status>
      When 管理員 "Admin" 匯入批改結果 CSV，內容如下：
        | submission_id | 狀態(可改 pending/pass/fail) | 講師回饋(可改) |
        | 500           | <寫法>                       | 評語           |
      Then 操作成功
      And 作業繳交 500 應為：
        | status   |
        | <status> |

      Examples:
        | 寫法    | status  |
        | pending | pending |
        | 待批改  | pending |
        | PASS    | pass    |
        | passed  | pass    |
        | 通過    | pass    |
        | fail    | fail    |
        | failed  | fail    |
        | 未通過  | fail    |
        | 不通過  | fail    |

  Rule: 後置（狀態）- 表頭接受欄位名稱別名

    Scenario Outline: 使用表頭 <狀態欄> 與 <回饋欄>
      When 管理員 "Admin" 匯入批改結果 CSV，內容如下：
        | <id 欄> | <狀態欄> | <回饋欄> |
        | 500     | pass     | 寫得很好 |
      Then 操作成功
      And 作業繳交 500 應為：
        | status | feedback |
        | pass   | 寫得很好 |

      Examples:
        | id 欄        | 狀態欄     | 回饋欄   |
        | submissionId | 狀態(可改) | 講師回饋 |
        | submission_id | status    | 講師回饋 |

  Rule: 後置（狀態）- 講師回饋欄空白時清空評語

    Example: 回饋欄空白
      Given 系統中有以下作業繳交：
        | submissionId | assignmentId | userId | text_body | attachment_ids | status | feedback | submitted_at        | graded_by | graded_at           |
        | 502          | 400          | 1      | 管理員試交 | []            | fail   | 舊評語   | 2026-09-16 09:00:00 | 1         | 2026-09-16 10:00:00 |
      When 管理員 "Admin" 匯入批改結果 CSV，內容如下：
        | submission_id | 狀態(可改 pending/pass/fail) | 講師回饋(可改) |
        | 502           | pass                         |                |
      Then 操作成功
      And 作業繳交 502 應為：
        | status | feedback |
        | pass   |          |

  Rule: 後置（狀態）- 每一列觸發與批改作業相同的副作用

    Example: 匯入通過時自動完成章節並寄送批改通知
      Given 用戶 "Alice" 在章節 201 無 finished_at
      And 系統中有以下郵件模板：
        | emailId | post_title       | trigger_at        | course_id | post_status |
        | 800     | 作業批改結果通知 | assignment_graded |           | publish     |
      When 管理員 "Admin" 匯入批改結果 CSV，內容如下：
        | submission_id | 狀態(可改 pending/pass/fail) | 講師回饋(可改) |
        | 500           | pass                         | 寫得很好       |
      Then 操作成功
      And 用戶 "Alice" 在章節 201 的 finished_at 應為 "2026-09-17 10:00:00"
      And 應發送郵件給 "alice@test.com"

  # ========== 後置（回應）==========

  Rule: 後置（回應）- 缺少 submission_id 的列略過並記錄錯誤

    Example: 第 2 列缺少 submission_id
      When 管理員 "Admin" 匯入批改結果 CSV，內容如下：
        | submission_id | 狀態(可改 pending/pass/fail) | 講師回饋(可改) |
        |               | pass                         | 寫得很好       |
        | 501           | pass                         | 很好           |
      Then 操作成功
      And 匯入結果應為：
        | total | updated | skipped | failed |
        | 2     | 1       | 1       | 0      |
      And 匯入錯誤明細應為：
        | row | message            |
        | 2   | 缺少 submission_id |

  Rule: 後置（回應）- 狀態無法辨識的列略過並記錄錯誤

    Example: 狀態寫成 ok
      When 管理員 "Admin" 匯入批改結果 CSV，內容如下：
        | submission_id | 狀態(可改 pending/pass/fail) | 講師回饋(可改) |
        | 500           | ok                           | 寫得很好       |
      Then 操作成功
      And 匯入結果應為：
        | total | updated | skipped | failed |
        | 1     | 0       | 1       | 0      |
      And 匯入錯誤明細應為：
        | row | message                                    |
        | 2   | 狀態無法辨識（ok），需為 pending/pass/fail |

  Rule: 後置（回應）- 批改失敗的列計入失敗並記錄錯誤，不影響其他列

    Example: 第 2 列繳交不存在、第 3 列正常
      When 管理員 "Admin" 匯入批改結果 CSV，內容如下：
        | submission_id | 狀態(可改 pending/pass/fail) | 講師回饋(可改) |
        | 9999          | pass                         | 很好           |
        | 500           | pass                         | 寫得很好       |
      Then 操作成功
      And 匯入結果應為：
        | total | updated | skipped | failed |
        | 2     | 1       | 0       | 1      |
      And 匯入錯誤明細應為：
        | row | message        |
        | 2   | 繳交紀錄不存在 |
      And 作業繳交 500 應為：
        | status | feedback |
        | pass   | 寫得很好 |
