@query
Feature: 匯出單一作業繳交CSV

  管理員匯出單一作業的所有繳交為 CSV（UTF-8 BOM，Excel 可直接開啟中文），供離線檢視。（實作第一波）

  業務依據：zenbu-site-2.0 `GET admin/course-assignments/:id/submissions/export.csv` + `course-submission-csv.service.ts::serializeSubmissionsToCsv`
  （欄位：繳交時間 / 學員 Email / 學員姓名 / 狀態 / 文字內容 / 附件數 / 附件清單 / 批改時間 / 講師回饋；時間為 ISO 8601 UTC；
    檔名 assignment-{標題中非文字字元換成 _，取前 40 字}-{UTC 時間戳}.csv）
  修正：參考實作傳入上限 5000 筆，但列表服務內部把上限夾到 200，實際只匯出 200 筆；本規格依原意以 5000 筆為上限
  架構對應：垃圾桶中的作業視為不存在

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
      | 400          | 201       | 練習一：變數宣告 | yes        | yes        | no            | no            | 1         | 0      | 0          | publish     |
      | 402          | 201       | 已刪除練習       | yes        | no         | no            | no            | 1         | 0      | 10         | trash       |
    And 系統中有以下作業附件：
      | attachmentId | userId | original_name | mime_type | file_size |
      | 700          | 2      | 作業截圖.png  | image/png | 20480     |
    And 系統中有以下作業繳交：
      | submissionId | assignmentId | userId | text_body    | attachment_ids | status  | feedback           | submitted_at        | graded_by | graded_at           |
      | 500          | 400          | 2      | let x = 1;   | [700]          | pending |                    | 2026-09-16 09:00:00 |           |                     |
      | 501          | 400          | 3      | const y = 2; | []             | pass    | 寫得很好, 繼續保持 | 2026-09-16 15:30:00 | 1         | 2026-09-17 09:30:00 |
    And 現在時間為 "2026-09-17 10:00:00"

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 操作者必須為管理員（manage_options 或 manage_woocommerce）

    Example: 非管理員匯出時操作失敗
      When 用戶 "Alice" 呼叫 GET /assignments/400/submissions/export
      Then 操作失敗，錯誤為「權限不足」

  Rule: 前置（狀態）- 作業必須存在，在垃圾桶中的作業視為不存在

    Scenario Outline: 匯出<情境>作業的繳交時操作失敗
      When 管理員 "Admin" 匯出作業 <assignmentId> 的繳交 CSV
      Then 操作失敗，錯誤為「作業不存在」

      Examples:
        | 情境       | assignmentId |
        | 不存在     | 9999         |
        | 已在垃圾桶 | 402          |

  # ========== 後置（回應）==========

  Rule: 後置（回應）- 依繳交時間由新到舊輸出繳交清單，狀態以中文標籤呈現，含逗號的值以雙引號包覆

    Example: 匯出作業 400 的繳交
      When 管理員 "Admin" 匯出作業 400 的繳交 CSV
      Then 操作成功
      And CSV 內容應為：
        | 繳交時間                 | 學員 Email     | 學員姓名 | 狀態   | 文字內容     | 附件數 | 附件清單     | 批改時間                 | 講師回饋             |
        | 2026-09-16T07:30:00.000Z | bob@test.com   | Bob      | 通過   | const y = 2; | 0      |              | 2026-09-17T01:30:00.000Z | "寫得很好, 繼續保持" |
        | 2026-09-16T01:00:00.000Z | alice@test.com | Alice    | 待批改 | let x = 1;   | 1      | 作業截圖.png |                          |                      |
      And CSV 應以 UTF-8 BOM 開頭且換行為 CRLF

  Rule: 後置（回應）- 含雙引號的值以雙引號包覆並將內部雙引號重複一次

    Example: 文字內容含雙引號
      Given 系統中有以下作業繳交：
        | submissionId | assignmentId | userId | text_body         | attachment_ids | status  | feedback | submitted_at        | graded_by | graded_at |
        | 502          | 400          | 1      | echo "hello";     | []             | pending |          | 2026-09-17 09:00:00 |           |           |
      When 管理員 "Admin" 匯出作業 400 的繳交 CSV
      Then 操作成功
      And CSV 內容應包含儲存格 "\"echo \"\"hello\"\";\""

  Rule: 後置（回應）- 下載檔名為 assignment-{作業標題}-{UTC 時間戳}.csv，標題中非文字字元換成底線

    Example: 作業標題含全形冒號
      When 管理員 "Admin" 匯出作業 400 的繳交 CSV
      Then 操作成功
      And 下載檔名應為 "assignment-練習一_變數宣告-2026-09-17-02-00-00.csv"

  Rule: 後置（回應）- 最多輸出 5000 筆繳交

    Example: 作業有 5001 筆繳交時只輸出 5000 筆
      Given 作業 400 有 5001 筆繳交
      When 管理員 "Admin" 匯出作業 400 的繳交 CSV
      Then 操作成功
      And CSV 資料列數應為 5000
